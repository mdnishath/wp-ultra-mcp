<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/verticals/lms.php — require it defensively so
// this ability works regardless of bootstrap load order (mirrors woo-bulk-edit).
if (!function_exists('wpultra_lms_grade_quiz') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/verticals/lms.php')) {
    require_once WPULTRA_DIR . 'includes/verticals/lms.php';
}

wp_register_ability('wpultra/lms-manage', [
    'label'       => __('LMS: Courses, Lessons, Quizzes, Certificates', 'wp-ultra-mcp'),
    'description' => __(
        'A self-contained learning-management system on private CPTs (wpultra_course + wpultra_lesson): '
        . 'build courses with ordered lessons, per-lesson multiple-choice quizzes, per-user progress tracking, and print-ready completion certificates. No third-party LMS plugin required. '
        . 'Actions: '
        . 'manage-course {id?, name, description?, pass_pct? (default 70), certificate_enabled? (default true), sequential? (default true), lesson_ids?} — upsert a course; '
        . 'manage-lesson {id?, course_id, title, body?, order?, duration_min?, quiz?} — upsert a lesson under a course (lessons auto-sort by their order; quiz is {questions:[{q, choices:[>=2 strings], answer_index}]} or null); '
        . 'list-courses {limit?} — all courses newest-first; '
        . 'get-course {course_id} — course + its ordered lessons (quiz answers stripped); '
        . 'enroll {user_id, course_id} — start a user\'s progress (standalone, not tied to membership); '
        . 'submit-quiz {user_id, lesson_id, answers:[chosen_index per question]} — grade against the course pass_pct, record the score, and auto-complete the lesson when passed → {score, passed}; '
        . 'mark-complete {user_id, lesson_id} — mark a (non-quiz) lesson read/done; '
        . 'progress {user_id, course_id} — the full rollup {lessons_total, lessons_done, pct, complete, next_lesson_id, quiz_scores}; '
        . 'certificate {user_id, course_id} — issue + return the certificate HTML (ONLY when the course is complete, else an error). '
        . 'ACCESS MODELS: sequential=true locks a lesson until the lesson before it (in course order) is completed — the first lesson is always open; sequential=false allows free navigation to any lesson. '
        . 'COMPLETION: a course is complete when EVERY lesson is completed AND every quiz-bearing lesson is passed (recorded score pct >= course pass_pct). '
        . 'Examples: {action:"manage-course", name:"Plumbing 101", pass_pct:80} · '
        . '{action:"manage-lesson", course_id:12, title:"Pipes", order:1, quiz:{questions:[{q:"Which is copper?", choices:["A","B"], answer_index:0}]}} · '
        . '{action:"enroll", user_id:5, course_id:12} · {action:"submit-quiz", user_id:5, lesson_id:34, answers:[0]} · '
        . '{action:"progress", user_id:5, course_id:12} · {action:"certificate", user_id:5, course_id:12}.',
        'wp-ultra-mcp'
    ),
    'category'    => 'verticals',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['manage-course', 'manage-lesson', 'list-courses', 'get-course', 'enroll', 'submit-quiz', 'mark-complete', 'progress', 'certificate'],
            ],
            'id'                  => ['type' => 'integer'],
            'course_id'           => ['type' => 'integer'],
            'lesson_id'           => ['type' => 'integer'],
            'user_id'             => ['type' => 'integer'],
            'name'                => ['type' => 'string'],
            'title'               => ['type' => 'string'],
            'description'         => ['type' => 'string'],
            'body'                => ['type' => 'string'],
            'pass_pct'            => ['type' => 'integer'],
            'certificate_enabled' => ['type' => 'boolean'],
            'sequential'          => ['type' => 'boolean'],
            'order'               => ['type' => 'integer'],
            'duration_min'        => ['type' => 'integer'],
            'lesson_ids'          => ['type' => 'array', 'items' => ['type' => 'integer']],
            'answers'             => ['type' => 'array', 'items' => ['type' => 'integer']],
            'quiz'                => [
                'type'       => 'object',
                'properties' => [
                    'questions' => [
                        'type'  => 'array',
                        'items' => [
                            'type'       => 'object',
                            'properties' => [
                                'q'            => ['type' => 'string'],
                                'choices'      => ['type' => 'array', 'items' => ['type' => 'string']],
                                'answer_index' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                ],
            ],
            'limit'               => ['type' => 'integer'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'  => ['type' => 'boolean'],
            'course'   => ['type' => 'object'],
            'courses'  => ['type' => 'array'],
            'lesson'   => ['type' => 'object'],
            'lessons'  => ['type' => 'array'],
            'progress' => ['type' => 'object'],
            'score'    => ['type' => 'integer'],
            'passed'   => ['type' => 'boolean'],
            'cert'     => ['type' => 'object'],
            'html'     => ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_lms_manage_ability',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_lms_manage_ability(array $input) {
    if (!function_exists('wpultra_lms_grade_quiz')) {
        return wpultra_err('lms_engine_missing', 'The LMS engine (includes/verticals/lms.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'manage-course':
            $course = wpultra_lms_upsert_course($input);
            if (is_wp_error($course)) { wpultra_audit_log('lms-manage', 'manage-course failed: ' . $course->get_error_message(), false); return $course; }
            wpultra_audit_log('lms-manage', "manage-course #{$course['id']} '{$course['name']}'", true);
            return wpultra_ok(['course' => $course]);

        case 'manage-lesson':
            $lesson = wpultra_lms_upsert_lesson($input);
            if (is_wp_error($lesson)) { wpultra_audit_log('lms-manage', 'manage-lesson failed: ' . $lesson->get_error_message(), false); return $lesson; }
            wpultra_audit_log('lms-manage', "manage-lesson #{$lesson['id']} '{$lesson['title']}'", true);
            return wpultra_ok(['lesson' => $lesson]);

        case 'list-courses':
            $limit = max(1, min(500, (int) ($input['limit'] ?? 100)));
            return wpultra_ok(['courses' => wpultra_lms_list_courses($limit)]);

        case 'get-course':
            return wpultra_lms_action_get_course($input);

        case 'enroll':
            $res = wpultra_lms_enroll((int) ($input['user_id'] ?? 0), (int) ($input['course_id'] ?? 0));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('lms-manage', "enroll user #{$res['user_id']} course #{$res['course_id']}", true);
            return wpultra_ok(['progress' => $res['progress'], 'course_id' => $res['course_id'], 'user_id' => $res['user_id']]);

        case 'submit-quiz':
            $answers = is_array($input['answers'] ?? null) ? array_map('intval', $input['answers']) : [];
            $res = wpultra_lms_submit_quiz((int) ($input['user_id'] ?? 0), (int) ($input['lesson_id'] ?? 0), $answers);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('lms-manage', "submit-quiz user #{$input['user_id']} lesson #{$input['lesson_id']} score={$res['pct']} passed=" . ($res['passed'] ? '1' : '0'), true);
            return wpultra_ok(['score' => $res['pct'], 'passed' => $res['passed'], 'correct' => $res['correct'], 'total' => $res['total']]);

        case 'mark-complete':
            $res = wpultra_lms_mark_complete((int) ($input['user_id'] ?? 0), (int) ($input['lesson_id'] ?? 0));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('lms-manage', "mark-complete user #{$input['user_id']} lesson #{$input['lesson_id']}", true);
            return wpultra_ok(['completed' => true, 'lesson_id' => $res['lesson_id'], 'course_id' => $res['course_id']]);

        case 'progress':
            $res = wpultra_lms_progress_rollup((int) ($input['user_id'] ?? 0), (int) ($input['course_id'] ?? 0));
            if (is_wp_error($res)) { return $res; }
            return wpultra_ok(['progress' => $res]);

        case 'certificate':
            $res = wpultra_lms_issue_certificate((int) ($input['user_id'] ?? 0), (int) ($input['course_id'] ?? 0));
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('lms-manage', "certificate user #{$input['user_id']} course #{$input['course_id']} {$res['cert']['cert_id']}", true);
            return wpultra_ok(['cert' => $res['cert'], 'html' => $res['html']]);

        default:
            return wpultra_err('unknown_action', "Unknown action '$action'. Known: manage-course, manage-lesson, list-courses, get-course, enroll, submit-quiz, mark-complete, progress, certificate.");
    }
}

/** get-course: course + ordered lessons with quiz answers stripped. @return array|WP_Error */
function wpultra_lms_action_get_course(array $input) {
    $course_id = (int) ($input['course_id'] ?? ($input['id'] ?? 0));
    $course = wpultra_lms_load_course($course_id);
    if ($course === null) { return wpultra_err('course_not_found', "Course #$course_id not found."); }

    $lessons = [];
    foreach (array_map('intval', $course['meta']['lesson_ids']) as $lid) {
        $l = wpultra_lms_load_lesson($lid);
        if ($l === null) { continue; }
        // Strip quiz answer keys so the ability output cannot leak the answers.
        $has_quiz = !empty($l['meta']['quiz']) && is_array($l['meta']['quiz']);
        if ($has_quiz && isset($l['meta']['quiz']['questions']) && is_array($l['meta']['quiz']['questions'])) {
            foreach ($l['meta']['quiz']['questions'] as $qi => $q) {
                unset($l['meta']['quiz']['questions'][$qi]['answer_index']);
            }
        }
        $l['has_quiz'] = $has_quiz;
        $lessons[] = $l;
    }

    return wpultra_ok(['course' => $course, 'lessons' => $lessons]);
}
