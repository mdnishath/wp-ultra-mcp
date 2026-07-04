<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * LMS / courses engine (Roadmap E3) — a real, self-contained learning-management
 * system: courses, ordered lessons, per-lesson quizzes, per-user progress and
 * print-ready completion certificates. No third-party LMS plugin required.
 *
 * Storage:
 *   - Private CPT `wpultra_course`  (post_title = course name) + meta
 *       `_wpultra_course`: {description, lesson_ids[] (ordered), pass_pct (70),
 *        certificate_enabled (bool), sequential (bool), enrolled[] (user ids)}
 *   - Private CPT `wpultra_lesson`  (post_title = lesson title,
 *       post_content = lesson body) + meta `_wpultra_lesson`:
 *       {course_id, order, quiz: {questions:[{q, choices:[], answer_index}]}|null,
 *        duration_min}
 *   - Per-user progress in user_meta `wpultra_lms_progress`:
 *       {course_id => {completed_lessons:[ids], quiz_scores:{lesson_id => pct},
 *        started (unix), completed (unix|null), certificate_id?}}
 *
 * Access models (documented on the ability):
 *   - sequential=true  → a lesson is locked until the lesson before it in the
 *     course order is completed (the first lesson is always accessible).
 *   - sequential=false → any lesson is accessible at any time ("free" navigation).
 *   Course completion requires EVERY lesson completed AND every quiz-bearing
 *   lesson passed (score pct >= course pass_pct).
 *
 * Layout: PURE functions first (no WordPress calls — unit-tested by
 * tests/lms.test.php), WP-touching wrappers after. The always-on runtime
 * contract is wpultra_lms_boot() (cheap + idempotent) which registers the two
 * CPTs on `init`.
 */

if (!defined('WPULTRA_COURSE_CPT'))   { define('WPULTRA_COURSE_CPT', 'wpultra_course'); }
if (!defined('WPULTRA_LESSON_CPT'))   { define('WPULTRA_LESSON_CPT', 'wpultra_lesson'); }
if (!defined('WPULTRA_COURSE_META'))  { define('WPULTRA_COURSE_META', '_wpultra_course'); }
if (!defined('WPULTRA_LESSON_META'))  { define('WPULTRA_LESSON_META', '_wpultra_lesson'); }
if (!defined('WPULTRA_LMS_PROGRESS')) { define('WPULTRA_LMS_PROGRESS', 'wpultra_lms_progress'); }
if (!defined('WPULTRA_LMS_PASS_PCT_DEFAULT')) { define('WPULTRA_LMS_PASS_PCT_DEFAULT', 70); }

/* =====================================================================
 * PURE — no WordPress calls. Unit-testable (tests/lms.test.php).
 * ===================================================================== */

/** PURE. Default meta blob for a new course. */
function wpultra_lms_default_course_meta(): array {
    return [
        'description'         => '',
        'lesson_ids'          => [],
        'pass_pct'            => WPULTRA_LMS_PASS_PCT_DEFAULT,
        'certificate_enabled' => true,
        'sequential'          => true,
        'enrolled'            => [],
    ];
}

/** PURE. Default meta blob for a new lesson. */
function wpultra_lms_default_lesson_meta(): array {
    return [
        'course_id'    => 0,
        'order'        => 0,
        'quiz'         => null,
        'duration_min' => 0,
    ];
}

/** PURE. Fresh per-course progress record for a user starting a course. */
function wpultra_lms_default_progress(int $started_ts): array {
    return [
        'completed_lessons' => [],
        'quiz_scores'       => [],
        'started'           => $started_ts,
        'completed'         => null,
    ];
}

/**
 * PURE. Clamp a pass percentage to a sane 0..100 integer.
 */
function wpultra_lms_clamp_pct(int $pct): int {
    if ($pct < 0) { return 0; }
    if ($pct > 100) { return 100; }
    return $pct;
}

/**
 * PURE. Validate a quiz structure. Returns true or a human-readable error string.
 * A valid quiz is {questions: [ {choices: [>=2 strings], answer_index: valid} ]}.
 * An empty quiz (no questions) is valid — it grades as 100% / passed.
 */
function wpultra_lms_validate_quiz(array $quiz) {
    if (!array_key_exists('questions', $quiz)) {
        return 'quiz must have a "questions" array.';
    }
    if (!is_array($quiz['questions'])) {
        return 'quiz.questions must be an array.';
    }
    foreach ($quiz['questions'] as $i => $q) {
        if (!is_array($q)) {
            return "question #$i must be an object.";
        }
        $choices = $q['choices'] ?? null;
        if (!is_array($choices) || count($choices) < 2) {
            return "question #$i must have at least 2 choices.";
        }
        if (!array_key_exists('answer_index', $q)) {
            return "question #$i is missing answer_index.";
        }
        $ai = $q['answer_index'];
        if (!is_int($ai) || $ai < 0 || $ai >= count($choices)) {
            return "question #$i has an out-of-range answer_index.";
        }
    }
    return true;
}

/**
 * PURE. Grade a quiz.
 *
 * @param array $quiz    {questions: [{answer_index:int, ...}]}
 * @param array $answers ordered chosen-index per question (positional).
 * @param int   $pass_pct the passing threshold (default course pass_pct).
 * @return array{correct:int, total:int, pct:int, passed:bool}
 *
 * A missing answer (fewer answers than questions) or an out-of-range / wrong
 * index counts as wrong. An empty quiz (no questions) scores 100% and passes.
 */
function wpultra_lms_grade_quiz(array $quiz, array $answers, int $pass_pct = WPULTRA_LMS_PASS_PCT_DEFAULT): array {
    $pass_pct  = wpultra_lms_clamp_pct($pass_pct);
    $questions = is_array($quiz['questions'] ?? null) ? $quiz['questions'] : [];
    $total     = count($questions);

    if ($total === 0) {
        return ['correct' => 0, 'total' => 0, 'pct' => 100, 'passed' => true];
    }

    // Normalize answers to a positional list so a sparse/keyed array still lines up.
    $answers = array_values($answers);

    $correct = 0;
    $idx = 0;
    foreach ($questions as $q) {
        $expected = is_array($q) && isset($q['answer_index']) ? $q['answer_index'] : null;
        $chosen   = array_key_exists($idx, $answers) ? $answers[$idx] : null;
        if ($chosen !== null && is_int($expected) && (int) $chosen === $expected) {
            $correct++;
        }
        $idx++;
    }

    $pct    = (int) round(($correct / $total) * 100);
    $passed = $pct >= $pass_pct;

    return ['correct' => $correct, 'total' => $total, 'pct' => $pct, 'passed' => $passed];
}

/**
 * PURE. The first not-yet-completed lesson id in course order, or 0 if all done.
 *
 * @param array<int,int> $ordered_lesson_ids
 * @param array<int,int> $completed
 */
function wpultra_lms_next_lesson(array $ordered_lesson_ids, array $completed): int {
    $done = array_map('intval', $completed);
    foreach ($ordered_lesson_ids as $lid) {
        if (!in_array((int) $lid, $done, true)) {
            return (int) $lid;
        }
    }
    return 0;
}

/**
 * PURE. Can a user access a given lesson?
 *
 * @return array{allowed:bool, reason:string}
 *
 * free mode (sequential=false): every lesson is accessible.
 * sequential mode: a lesson unlocks only once the lesson immediately before it
 * (in course order) is completed. The first lesson is always accessible. An
 * unknown lesson id is rejected.
 */
function wpultra_lms_can_access_lesson(array $ordered_lesson_ids, int $lesson_id, array $completed, bool $sequential): array {
    $ordered = array_values(array_map('intval', $ordered_lesson_ids));
    $pos = array_search($lesson_id, $ordered, true);

    if ($pos === false) {
        return ['allowed' => false, 'reason' => 'Lesson is not part of this course.'];
    }
    if (!$sequential) {
        return ['allowed' => true, 'reason' => 'Free navigation: all lessons are open.'];
    }
    if ($pos === 0) {
        return ['allowed' => true, 'reason' => 'First lesson is always accessible.'];
    }

    $done = array_map('intval', $completed);
    $prev = $ordered[$pos - 1];
    if (in_array($prev, $done, true)) {
        return ['allowed' => true, 'reason' => 'Previous lesson completed.'];
    }
    return ['allowed' => false, 'reason' => "Locked: complete lesson #$prev first."];
}

/**
 * PURE. Roll up a user's progress through a course.
 *
 * @param array $course   {meta:{lesson_ids[], pass_pct}, lessons:{lesson_id => {quiz|null}}}
 *                        `lessons` maps each lesson id to at least {quiz} so we
 *                        know which lessons require a passing quiz.
 * @param array $progress {completed_lessons:[ids], quiz_scores:{lesson_id => pct}}
 * @return array{lessons_total:int, lessons_done:int, pct:int, complete:bool, next_lesson_id:int}
 *
 * complete = every lesson completed AND every quiz-bearing lesson passed
 * (recorded score pct >= course pass_pct).
 */
function wpultra_lms_course_progress(array $course, array $progress): array {
    $meta        = is_array($course['meta'] ?? null) ? $course['meta'] : [];
    $lesson_ids  = array_values(array_map('intval', $meta['lesson_ids'] ?? []));
    $pass_pct    = wpultra_lms_clamp_pct((int) ($meta['pass_pct'] ?? WPULTRA_LMS_PASS_PCT_DEFAULT));
    $lessons     = is_array($course['lessons'] ?? null) ? $course['lessons'] : [];

    $completed   = array_map('intval', $progress['completed_lessons'] ?? []);
    $quiz_scores = is_array($progress['quiz_scores'] ?? null) ? $progress['quiz_scores'] : [];

    $total = count($lesson_ids);
    $done  = 0;
    foreach ($lesson_ids as $lid) {
        if (in_array($lid, $completed, true)) { $done++; }
    }

    $pct = $total > 0 ? (int) round(($done / $total) * 100) : 100;

    // Completion also requires every quiz-bearing lesson to be passed.
    $quizzes_passed = true;
    foreach ($lesson_ids as $lid) {
        $lesson = $lessons[$lid] ?? ($lessons[(string) $lid] ?? null);
        $has_quiz = is_array($lesson) && !empty($lesson['quiz']) && is_array($lesson['quiz'])
            && !empty($lesson['quiz']['questions']);
        if (!$has_quiz) { continue; }
        $score = $quiz_scores[$lid] ?? ($quiz_scores[(string) $lid] ?? null);
        if ($score === null || (int) $score < $pass_pct) {
            $quizzes_passed = false;
            break;
        }
    }

    $complete = $total > 0 ? ($done === $total && $quizzes_passed) : $quizzes_passed;
    if ($total === 0) { $complete = true; }

    return [
        'lessons_total'  => $total,
        'lessons_done'   => $done,
        'pct'            => $pct,
        'complete'       => $complete,
        'next_lesson_id' => wpultra_lms_next_lesson($lesson_ids, $completed),
    ];
}

/**
 * PURE. Assemble the data payload for a completion certificate.
 *
 * @param array $course       {name, ...}
 * @param array $member       {name, id?} the student.
 * @param int   $completed_ts unix time the course was completed.
 * @return array{course_name:string, student_name:string, completed_date:string, cert_id:string}
 */
function wpultra_lms_certificate_data(array $course, array $member, int $completed_ts): array {
    $course_name  = (string) ($course['name'] ?? ($course['title'] ?? 'Course'));
    $student_name = (string) ($member['name'] ?? ($member['display_name'] ?? 'Student'));
    $course_id    = (int) ($course['id'] ?? 0);
    $member_id    = (int) ($member['id'] ?? 0);

    $cert_id = strtoupper(substr(
        hash('crc32b', $course_id . '|' . $member_id . '|' . $completed_ts) . 'CERT',
        0,
        10
    ));
    $cert_id = 'CERT-' . $cert_id;

    return [
        'course_name'    => $course_name,
        'student_name'   => $student_name,
        'completed_date' => gmdate('F j, Y', $completed_ts),
        'cert_id'        => $cert_id,
    ];
}

/**
 * PURE. Render a print-ready certificate as a self-contained HTML document.
 * Honest "print to PDF" model (same as the packing slip) — inline CSS, fully
 * escaped values, no fake binary. All dynamic values are htmlspecialchars'd.
 */
function wpultra_lms_certificate_html(array $data): string {
    $course  = htmlspecialchars((string) ($data['course_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $student = htmlspecialchars((string) ($data['student_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $date    = htmlspecialchars((string) ($data['completed_date'] ?? ''), ENT_QUOTES, 'UTF-8');
    $cert_id = htmlspecialchars((string) ($data['cert_id'] ?? ''), ENT_QUOTES, 'UTF-8');

    return '<!DOCTYPE html>'
        . '<html lang="en"><head><meta charset="utf-8">'
        . '<title>Certificate of Completion</title>'
        . '<style>'
        . 'body{font-family:Georgia,"Times New Roman",serif;margin:0;padding:40px;'
        . 'background:#f5f2e8;color:#2c2c2c;}'
        . '.cert{max-width:760px;margin:0 auto;background:#fff;border:12px double #b8912f;'
        . 'padding:56px 48px;text-align:center;box-shadow:0 2px 12px rgba(0,0,0,.12);}'
        . '.cert h1{font-size:38px;letter-spacing:3px;text-transform:uppercase;margin:0 0 8px;color:#b8912f;}'
        . '.cert .sub{font-size:16px;letter-spacing:2px;text-transform:uppercase;color:#777;margin-bottom:36px;}'
        . '.cert .who{font-size:34px;font-weight:bold;margin:18px 0;border-bottom:2px solid #ddd;'
        . 'display:inline-block;padding:0 24px 8px;}'
        . '.cert .body{font-size:17px;line-height:1.6;margin:24px auto;max-width:560px;}'
        . '.cert .course{font-size:22px;font-style:italic;color:#333;margin:12px 0;}'
        . '.cert .meta{display:flex;justify-content:space-between;margin-top:48px;font-size:13px;color:#555;}'
        . '.cert .meta div{text-align:center;flex:1;}'
        . '.cert .meta .line{border-top:1px solid #999;margin:0 20px 6px;padding-top:6px;}'
        . '@media print{body{background:#fff;padding:0;}.cert{box-shadow:none;border-color:#b8912f;}}'
        . '</style></head><body>'
        . '<div class="cert">'
        . '<h1>Certificate</h1>'
        . '<div class="sub">of Completion</div>'
        . '<div class="body">This certifies that</div>'
        . '<div class="who">' . $student . '</div>'
        . '<div class="body">has successfully completed the course</div>'
        . '<div class="course">' . $course . '</div>'
        . '<div class="meta">'
        . '<div><div class="line">' . $date . '</div>Date</div>'
        . '<div><div class="line">' . $cert_id . '</div>Certificate ID</div>'
        . '</div>'
        . '</div>'
        . '</body></html>';
}

/* =====================================================================
 * WP wrappers — guarded (only run inside WordPress). Not unit-tested here.
 * ===================================================================== */

/** WP-error helper local to this engine (falls through to the shared one). */
function wpultra_lms_err(string $code, string $message, $data = '') {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message, $data); }
    return new WP_Error($code, $message, $data);
}

/** Runtime contract: register the two CPTs on init. Cheap + idempotent. */
function wpultra_lms_boot(): void {
    static $booted = false;
    if ($booted) { return; }
    $booted = true;
    if (!function_exists('add_action')) { return; }

    if (function_exists('did_action') && did_action('init')) {
        wpultra_lms_register_cpts();
    } else {
        add_action('init', 'wpultra_lms_register_cpts');
    }
}

function wpultra_lms_register_cpts(): void {
    if (!function_exists('register_post_type')) { return; }
    $args = [
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => false,
        'supports'     => ['title', 'editor'],
        'rewrite'      => false,
    ];
    register_post_type(WPULTRA_COURSE_CPT, $args);
    register_post_type(WPULTRA_LESSON_CPT, $args);
}

/** Load a course. @return array{id:int,name:string,meta:array}|null */
function wpultra_lms_load_course(int $id): ?array {
    if (!function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_COURSE_CPT) { return null; }
    $meta = function_exists('get_post_meta') ? get_post_meta($id, WPULTRA_COURSE_META, true) : [];
    if (!is_array($meta)) { $meta = []; }
    return [
        'id'   => $id,
        'name' => (string) $post->post_title,
        'meta' => array_merge(wpultra_lms_default_course_meta(), $meta),
    ];
}

/** Load a lesson. @return array{id:int,title:string,body:string,meta:array}|null */
function wpultra_lms_load_lesson(int $id): ?array {
    if (!function_exists('get_post')) { return null; }
    $post = get_post($id);
    if (!$post || $post->post_type !== WPULTRA_LESSON_CPT) { return null; }
    $meta = function_exists('get_post_meta') ? get_post_meta($id, WPULTRA_LESSON_META, true) : [];
    if (!is_array($meta)) { $meta = []; }
    return [
        'id'    => $id,
        'title' => (string) $post->post_title,
        'body'  => (string) $post->post_content,
        'meta'  => array_merge(wpultra_lms_default_lesson_meta(), $meta),
    ];
}

/**
 * Upsert a course. $data: {id?, name, description?, pass_pct?, certificate_enabled?,
 * sequential?, lesson_ids?}. @return array|WP_Error the loaded course.
 */
function wpultra_lms_upsert_course(array $data) {
    if (!function_exists('wp_insert_post')) {
        return wpultra_lms_err('wp_unavailable', 'wp_insert_post() is unavailable.');
    }
    $id   = (int) ($data['id'] ?? 0);
    $name = trim((string) ($data['name'] ?? ''));

    if ($id > 0) {
        $existing = wpultra_lms_load_course($id);
        if ($existing === null) { return wpultra_lms_err('course_not_found', "Course #$id not found."); }
        $meta = $existing['meta'];
        if ($name === '') { $name = $existing['name']; }
    } else {
        if ($name === '') { return wpultra_lms_err('missing_name', 'A course name is required.'); }
        $meta = wpultra_lms_default_course_meta();
    }

    if (array_key_exists('description', $data))         { $meta['description'] = (string) $data['description']; }
    if (array_key_exists('pass_pct', $data))            { $meta['pass_pct'] = wpultra_lms_clamp_pct((int) $data['pass_pct']); }
    if (array_key_exists('certificate_enabled', $data)) { $meta['certificate_enabled'] = (bool) $data['certificate_enabled']; }
    if (array_key_exists('sequential', $data))          { $meta['sequential'] = (bool) $data['sequential']; }
    if (array_key_exists('lesson_ids', $data) && is_array($data['lesson_ids'])) {
        $meta['lesson_ids'] = array_values(array_unique(array_map('intval', $data['lesson_ids'])));
    }

    $postarr = ['post_type' => WPULTRA_COURSE_CPT, 'post_status' => 'publish', 'post_title' => $name];
    if ($id > 0) { $postarr['ID'] = $id; }

    $res = wp_insert_post($postarr, true);
    if (is_wp_error($res)) { return $res; }
    $id = (int) $res;

    if (function_exists('update_post_meta')) { update_post_meta($id, WPULTRA_COURSE_META, $meta); }
    return wpultra_lms_load_course($id);
}

/**
 * Upsert a lesson. $data: {id?, course_id, title, body?, order?, quiz?, duration_min?}.
 * The lesson is appended to its course's ordered lesson_ids (deduped) and the
 * course lesson list is re-sorted by each lesson's order. @return array|WP_Error.
 */
function wpultra_lms_upsert_lesson(array $data) {
    if (!function_exists('wp_insert_post')) {
        return wpultra_lms_err('wp_unavailable', 'wp_insert_post() is unavailable.');
    }
    $id        = (int) ($data['id'] ?? 0);
    $course_id = (int) ($data['course_id'] ?? 0);
    $title     = trim((string) ($data['title'] ?? ''));

    if ($id > 0) {
        $existing = wpultra_lms_load_lesson($id);
        if ($existing === null) { return wpultra_lms_err('lesson_not_found', "Lesson #$id not found."); }
        $meta = $existing['meta'];
        if ($title === '') { $title = $existing['title']; }
        if ($course_id === 0) { $course_id = (int) $meta['course_id']; }
    } else {
        if ($title === '') { return wpultra_lms_err('missing_title', 'A lesson title is required.'); }
        if ($course_id === 0) { return wpultra_lms_err('missing_course_id', 'A course_id is required for a new lesson.'); }
        $meta = wpultra_lms_default_lesson_meta();
    }

    $course = wpultra_lms_load_course($course_id);
    if ($course === null) { return wpultra_lms_err('course_not_found', "Course #$course_id not found."); }

    $meta['course_id'] = $course_id;
    if (array_key_exists('order', $data))        { $meta['order'] = (int) $data['order']; }
    if (array_key_exists('duration_min', $data)) { $meta['duration_min'] = max(0, (int) $data['duration_min']); }
    if (array_key_exists('quiz', $data)) {
        $quiz = $data['quiz'];
        if ($quiz === null || $quiz === []) {
            $meta['quiz'] = null;
        } elseif (is_array($quiz)) {
            $valid = wpultra_lms_validate_quiz($quiz);
            if ($valid !== true) { return wpultra_lms_err('invalid_quiz', (string) $valid); }
            $meta['quiz'] = $quiz;
        }
    }

    $postarr = [
        'post_type'    => WPULTRA_LESSON_CPT,
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => (string) ($data['body'] ?? ($existing['body'] ?? '')),
    ];
    if ($id > 0) { $postarr['ID'] = $id; }

    $res = wp_insert_post($postarr, true);
    if (is_wp_error($res)) { return $res; }
    $id = (int) $res;

    if (function_exists('update_post_meta')) { update_post_meta($id, WPULTRA_LESSON_META, $meta); }

    // Register + re-order the lesson under its course.
    $cmeta = $course['meta'];
    $lesson_ids = array_values(array_map('intval', $cmeta['lesson_ids']));
    if (!in_array($id, $lesson_ids, true)) { $lesson_ids[] = $id; }

    // Sort by each lesson's order meta (stable on ties by id).
    $orders = [];
    foreach ($lesson_ids as $lid) {
        $l = wpultra_lms_load_lesson($lid);
        $orders[$lid] = $l ? (int) $l['meta']['order'] : 0;
    }
    usort($lesson_ids, static function ($a, $b) use ($orders) {
        if ($orders[$a] === $orders[$b]) { return $a <=> $b; }
        return $orders[$a] <=> $orders[$b];
    });

    $cmeta['lesson_ids'] = $lesson_ids;
    if (function_exists('update_post_meta')) { update_post_meta($course_id, WPULTRA_COURSE_META, $cmeta); }

    return wpultra_lms_load_lesson($id);
}

/** List all courses (newest first). @return array<int,array> */
function wpultra_lms_list_courses(int $limit = 100): array {
    if (!function_exists('get_posts')) { return []; }
    $posts = get_posts([
        'post_type'      => WPULTRA_COURSE_CPT,
        'post_status'    => 'publish',
        'numberposts'    => $limit,
        'orderby'        => 'date',
        'order'          => 'DESC',
    ]);
    $out = [];
    foreach ((array) $posts as $p) {
        $c = wpultra_lms_load_course((int) $p->ID);
        if ($c !== null) { $out[] = $c; }
    }
    return $out;
}

/**
 * Build the {lessons: {id => {quiz}}} companion map for wpultra_lms_course_progress.
 * @return array<int,array>
 */
function wpultra_lms_course_lesson_map(array $course): array {
    $map = [];
    foreach (array_map('intval', $course['meta']['lesson_ids'] ?? []) as $lid) {
        $l = wpultra_lms_load_lesson($lid);
        $map[$lid] = ['quiz' => $l ? $l['meta']['quiz'] : null];
    }
    return $map;
}

/** Read a user's full progress map. @return array<int|string,array> */
function wpultra_lms_get_user_progress(int $user_id): array {
    if (!function_exists('get_user_meta')) { return []; }
    $p = get_user_meta($user_id, WPULTRA_LMS_PROGRESS, true);
    return is_array($p) ? $p : [];
}

function wpultra_lms_save_user_progress(int $user_id, array $progress): void {
    if (function_exists('update_user_meta')) {
        update_user_meta($user_id, WPULTRA_LMS_PROGRESS, $progress);
    }
}

/** Enroll a user in a course: start their progress + add to the course enrolled list. */
function wpultra_lms_enroll(int $user_id, int $course_id) {
    $course = wpultra_lms_load_course($course_id);
    if ($course === null) { return wpultra_lms_err('course_not_found', "Course #$course_id not found."); }

    $now = function_exists('current_time') ? (int) current_time('timestamp', true) : time();

    $all = wpultra_lms_get_user_progress($user_id);
    if (!isset($all[$course_id]) || !is_array($all[$course_id])) {
        $all[$course_id] = wpultra_lms_default_progress($now);
        wpultra_lms_save_user_progress($user_id, $all);
    }

    // Track enrollment on the course (standalone — not tied to membership).
    $meta = $course['meta'];
    $enrolled = array_values(array_unique(array_map('intval', $meta['enrolled'] ?? [])));
    if (!in_array($user_id, $enrolled, true)) {
        $enrolled[] = $user_id;
        $meta['enrolled'] = $enrolled;
        if (function_exists('update_post_meta')) { update_post_meta($course_id, WPULTRA_COURSE_META, $meta); }
    }

    return ['enrolled' => true, 'course_id' => $course_id, 'user_id' => $user_id,
            'progress' => $all[$course_id]];
}

/** True if the user has a progress record (is enrolled) for this course. */
function wpultra_lms_is_enrolled(int $user_id, int $course_id): bool {
    $all = wpultra_lms_get_user_progress($user_id);
    return isset($all[$course_id]) && is_array($all[$course_id]);
}

/**
 * Submit a quiz for a lesson: grade it against the course pass_pct, record the
 * score, and (when passed) mark the lesson complete. @return array|WP_Error.
 */
function wpultra_lms_submit_quiz(int $user_id, int $lesson_id, array $answers) {
    $lesson = wpultra_lms_load_lesson($lesson_id);
    if ($lesson === null) { return wpultra_lms_err('lesson_not_found', "Lesson #$lesson_id not found."); }
    $quiz = $lesson['meta']['quiz'];
    if (empty($quiz) || !is_array($quiz)) {
        return wpultra_lms_err('no_quiz', "Lesson #$lesson_id has no quiz.");
    }
    $course_id = (int) $lesson['meta']['course_id'];
    $course = wpultra_lms_load_course($course_id);
    if ($course === null) { return wpultra_lms_err('course_not_found', "Course #$course_id not found."); }

    if (!wpultra_lms_is_enrolled($user_id, $course_id)) {
        return wpultra_lms_err('not_enrolled', "User #$user_id is not enrolled in course #$course_id.");
    }

    $pass_pct = (int) $course['meta']['pass_pct'];
    $graded = wpultra_lms_grade_quiz($quiz, $answers, $pass_pct);

    $all = wpultra_lms_get_user_progress($user_id);
    if (!isset($all[$course_id]) || !is_array($all[$course_id])) {
        $now = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
        $all[$course_id] = wpultra_lms_default_progress($now);
    }
    $all[$course_id]['quiz_scores'][$lesson_id] = $graded['pct'];

    if ($graded['passed']) {
        $completed = array_map('intval', $all[$course_id]['completed_lessons'] ?? []);
        if (!in_array($lesson_id, $completed, true)) { $completed[] = $lesson_id; }
        $all[$course_id]['completed_lessons'] = array_values($completed);
    }

    wpultra_lms_save_user_progress($user_id, $all);

    return array_merge($graded, ['score' => $graded['pct'], 'lesson_id' => $lesson_id, 'course_id' => $course_id]);
}

/** Mark a lesson complete for a user (no quiz gate — used for reading lessons). @return array|WP_Error. */
function wpultra_lms_mark_complete(int $user_id, int $lesson_id) {
    $lesson = wpultra_lms_load_lesson($lesson_id);
    if ($lesson === null) { return wpultra_lms_err('lesson_not_found', "Lesson #$lesson_id not found."); }
    $course_id = (int) $lesson['meta']['course_id'];
    if (!wpultra_lms_is_enrolled($user_id, $course_id)) {
        return wpultra_lms_err('not_enrolled', "User #$user_id is not enrolled in course #$course_id.");
    }

    $all = wpultra_lms_get_user_progress($user_id);
    $completed = array_map('intval', $all[$course_id]['completed_lessons'] ?? []);
    if (!in_array($lesson_id, $completed, true)) { $completed[] = $lesson_id; }
    $all[$course_id]['completed_lessons'] = array_values($completed);
    wpultra_lms_save_user_progress($user_id, $all);

    return ['completed' => true, 'lesson_id' => $lesson_id, 'course_id' => $course_id];
}

/** Full progress rollup for a user in a course. @return array|WP_Error. */
function wpultra_lms_progress_rollup(int $user_id, int $course_id) {
    $course = wpultra_lms_load_course($course_id);
    if ($course === null) { return wpultra_lms_err('course_not_found', "Course #$course_id not found."); }
    if (!wpultra_lms_is_enrolled($user_id, $course_id)) {
        return wpultra_lms_err('not_enrolled', "User #$user_id is not enrolled in course #$course_id.");
    }

    $all = wpultra_lms_get_user_progress($user_id);
    $progress = is_array($all[$course_id] ?? null) ? $all[$course_id] : wpultra_lms_default_progress(0);

    $course_for_rollup = ['meta' => $course['meta'], 'lessons' => wpultra_lms_course_lesson_map($course)];
    $rollup = wpultra_lms_course_progress($course_for_rollup, $progress);

    return array_merge($rollup, [
        'course_id'   => $course_id,
        'user_id'     => $user_id,
        'started'     => $progress['started'] ?? null,
        'completed'   => $progress['completed'] ?? null,
        'quiz_scores' => $progress['quiz_scores'] ?? [],
    ]);
}

/**
 * Issue a certificate — only when the course is complete. Records the completion
 * timestamp + certificate id on progress and returns the print-ready HTML.
 * @return array|WP_Error {cert, html}.
 */
function wpultra_lms_issue_certificate(int $user_id, int $course_id) {
    $course = wpultra_lms_load_course($course_id);
    if ($course === null) { return wpultra_lms_err('course_not_found', "Course #$course_id not found."); }
    if (empty($course['meta']['certificate_enabled'])) {
        return wpultra_lms_err('certificate_disabled', "Certificates are disabled for course #$course_id.");
    }

    $rollup = wpultra_lms_progress_rollup($user_id, $course_id);
    if (is_wp_error($rollup)) { return $rollup; }
    if (empty($rollup['complete'])) {
        return wpultra_lms_err('course_incomplete', 'Certificate can only be issued once the course is complete.');
    }

    $all = wpultra_lms_get_user_progress($user_id);
    $progress = is_array($all[$course_id] ?? null) ? $all[$course_id] : wpultra_lms_default_progress(0);
    $completed_ts = (int) ($progress['completed'] ?? 0);
    if ($completed_ts === 0) {
        $completed_ts = function_exists('current_time') ? (int) current_time('timestamp', true) : time();
        $progress['completed'] = $completed_ts;
    }

    // Resolve the student name.
    $student_name = 'Student';
    if (function_exists('get_userdata')) {
        $u = get_userdata($user_id);
        if ($u) { $student_name = (string) ($u->display_name ?: $u->user_login); }
    }

    $data = wpultra_lms_certificate_data(
        ['id' => $course_id, 'name' => $course['name']],
        ['id' => $user_id, 'name' => $student_name],
        $completed_ts
    );
    $html = wpultra_lms_certificate_html($data);

    $progress['certificate_id'] = $data['cert_id'];
    $all[$course_id] = $progress;
    wpultra_lms_save_user_progress($user_id, $all);

    return ['cert' => $data, 'html' => $html];
}
