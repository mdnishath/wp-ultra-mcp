<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Third-party LMS adapters (LearnDash / LifterLMS / Tutor LMS).
 *
 * Mirrors includes/forms/setup.php: pure detection + driver resolution over a
 * detection map (unit-testable), thin WP-calling driver functions that degrade
 * gracefully when the plugin is absent. The built-in CPT LMS
 * (includes/verticals/lms.php) stays the default; these drivers let an agent on
 * a site ALREADY running one of the big LMS plugins list courses, read course
 * outlines, enroll users, and check progress through the same lms-manage
 * ability (pass `plugin`).
 *
 * Faithfulness note: every write goes through the plugin's OWN public API
 * (ld_update_course_access / llms_enroll_student / tutor_utils()->do_enroll) --
 * never direct table writes -- so each plugin's hooks, caches, and reporting
 * stay coherent.
 */

/** WP_Error factory that works under WP and the bare test harness. */
function wpultra_lmsx_err(string $code, string $message) {
    if (function_exists('wpultra_err')) { return wpultra_err($code, $message); }
    return new WP_Error($code, $message);
}

/* ------------------------------------------------------------------ *
 * PURE: detection + driver resolution
 * ------------------------------------------------------------------ */

/** All third-party LMS keys this domain knows about. Pure. */
function wpultra_lmsx_known(): array {
    return ['learndash', 'lifterlms', 'tutor'];
}

/** Human label for an LMS key. Pure. */
function wpultra_lmsx_label(string $key): string {
    return match ($key) {
        'learndash' => 'LearnDash',
        'lifterlms' => 'LifterLMS',
        'tutor'     => 'Tutor LMS',
        default     => $key,
    };
}

/**
 * Detect each supported LMS plugin and its version.
 * Value is the version string when installed, or null when absent.
 * @return array<string,?string>
 */
function wpultra_lmsx_detect(): array {
    $out = ['learndash' => null, 'lifterlms' => null, 'tutor' => null];
    if (defined('LEARNDASH_VERSION')) {
        $out['learndash'] = (string) LEARNDASH_VERSION;
    } elseif (function_exists('learndash_get_course_id') || class_exists('SFWD_LMS')) {
        $out['learndash'] = '';
    }
    if (defined('LLMS_VERSION')) {
        $out['lifterlms'] = (string) LLMS_VERSION;
    } elseif (class_exists('LifterLMS') || function_exists('llms_enroll_student')) {
        $out['lifterlms'] = '';
    }
    if (defined('TUTOR_VERSION')) {
        $out['tutor'] = (string) TUTOR_VERSION;
    } elseif (function_exists('tutor') || function_exists('tutor_utils')) {
        $out['tutor'] = '';
    }
    return $out;
}

/**
 * Resolve which third-party driver to use. Pure over the detection map.
 * @param string $explicit  '' for auto (first detected), or a known key
 * @param array<string,?string>|null $detected
 * @return string|WP_Error
 */
function wpultra_lmsx_driver(string $explicit = '', ?array $detected = null) {
    if ($detected === null) { $detected = wpultra_lmsx_detect(); }
    if ($explicit !== '') {
        if (!in_array($explicit, wpultra_lmsx_known(), true)) {
            return wpultra_lmsx_err('lms_unknown_plugin', "Unknown LMS plugin '{$explicit}'. Known: learndash, lifterlms, tutor (or builtin).");
        }
        if (($detected[$explicit] ?? null) === null) {
            return wpultra_lmsx_err('lms_plugin_unavailable', wpultra_lmsx_label($explicit) . ' is not active on this site.');
        }
        return $explicit;
    }
    foreach (wpultra_lmsx_known() as $key) {
        if (($detected[$key] ?? null) !== null) { return $key; }
    }
    return wpultra_lmsx_err('lms_plugin_unavailable', 'No supported LMS plugin (LearnDash, LifterLMS, Tutor LMS) is active.');
}

/** Orientation summary (used by lms-manage detect-plugins). */
function wpultra_lmsx_status(): array {
    $detected = wpultra_lmsx_detect();
    $out = [];
    foreach (wpultra_lmsx_known() as $key) {
        $version = $detected[$key];
        $out[] = [
            'plugin'    => $key,
            'label'     => wpultra_lmsx_label($key),
            'installed' => $version !== null,
            'version'   => $version !== null ? $version : null,
        ];
    }
    return $out;
}

/** Course CPT slug per plugin. Pure. */
function wpultra_lmsx_course_post_type(string $plugin): string {
    return match ($plugin) {
        'learndash' => 'sfwd-courses',
        'lifterlms' => 'course',
        'tutor'     => 'courses',
        default     => '',
    };
}

/**
 * Shape one WP_Post-ish course row to the unified course shape. Pure.
 * @param array{ID:int,post_title:string,post_status:string} $post
 */
function wpultra_lmsx_shape_course(array $post, string $plugin): array {
    return [
        'id'     => (int) $post['ID'],
        'name'   => (string) $post['post_title'],
        'status' => (string) $post['post_status'],
        'plugin' => $plugin,
    ];
}

/**
 * Normalize a driver progress result to the unified shape. Pure.
 * @param float|int $pct 0-100, any numeric flavor the plugin returns
 */
function wpultra_lmsx_shape_progress($pct, int $done, int $total, string $plugin): array {
    $pct = max(0, min(100, (int) round((float) $pct)));
    return [
        'pct'           => $pct,
        'lessons_done'  => max(0, $done),
        'lessons_total' => max(0, $total),
        'complete'      => $pct >= 100,
        'plugin'        => $plugin,
    ];
}

/* ------------------------------------------------------------------ *
 * THIN WP-calling drivers -- dispatch via wpultra_lmsx_{op}($plugin, ...)
 * ------------------------------------------------------------------ */

/** List courses (newest first). @return array<int,array>|WP_Error */
function wpultra_lmsx_list_courses(string $plugin, int $limit = 100) {
    $pt = wpultra_lmsx_course_post_type($plugin);
    if ($pt === '' || !function_exists('get_posts')) {
        return wpultra_lmsx_err('lms_plugin_unavailable', wpultra_lmsx_label($plugin) . ' driver has no course post type.');
    }
    $posts = get_posts(['post_type' => $pt, 'post_status' => ['publish', 'draft', 'private'], 'numberposts' => max(1, min(500, $limit)), 'orderby' => 'date', 'order' => 'DESC']);
    $out = [];
    foreach ($posts as $p) {
        $out[] = wpultra_lmsx_shape_course(['ID' => (int) $p->ID, 'post_title' => (string) $p->post_title, 'post_status' => (string) $p->post_status], $plugin);
    }
    return $out;
}

/** Course + its ordered lessons. @return array|WP_Error */
function wpultra_lmsx_get_course(string $plugin, int $course_id) {
    $post = get_post($course_id);
    $pt = wpultra_lmsx_course_post_type($plugin);
    if (!$post || $post->post_type !== $pt) {
        return wpultra_lmsx_err('course_not_found', "Course #$course_id not found in " . wpultra_lmsx_label($plugin) . " (post type '$pt').");
    }
    $course = wpultra_lmsx_shape_course(['ID' => (int) $post->ID, 'post_title' => (string) $post->post_title, 'post_status' => (string) $post->post_status], $plugin);
    $course['description'] = (string) $post->post_excerpt;
    $lessons = [];
    try {
        if ($plugin === 'learndash' && function_exists('learndash_get_course_steps')) {
            foreach ((array) learndash_get_course_steps($course_id) as $step_id) {
                $sp = get_post((int) $step_id);
                if ($sp) { $lessons[] = ['id' => (int) $sp->ID, 'title' => (string) $sp->post_title, 'type' => (string) $sp->post_type]; }
            }
        } elseif ($plugin === 'lifterlms') {
            $ids = get_posts(['post_type' => 'lesson', 'post_status' => 'any', 'numberposts' => 500, 'fields' => 'ids', 'meta_key' => '_llms_parent_course', 'meta_value' => (string) $course_id, 'orderby' => 'menu_order', 'order' => 'ASC']);
            foreach ($ids as $lid) {
                $lessons[] = ['id' => (int) $lid, 'title' => (string) get_the_title((int) $lid), 'type' => 'lesson'];
            }
        } elseif ($plugin === 'tutor') {
            // Tutor nests lessons under topic posts whose post_parent is the course.
            $topics = get_posts(['post_type' => 'topics', 'post_status' => 'any', 'numberposts' => 100, 'fields' => 'ids', 'post_parent' => $course_id, 'orderby' => 'menu_order', 'order' => 'ASC']);
            $lesson_pt = function_exists('tutor') ? (string) (tutor()->lesson_post_type ?? 'lesson') : 'lesson';
            foreach ($topics as $tid) {
                $children = get_posts(['post_type' => $lesson_pt, 'post_status' => 'any', 'numberposts' => 200, 'post_parent' => (int) $tid, 'orderby' => 'menu_order', 'order' => 'ASC']);
                foreach ($children as $cp) {
                    $lessons[] = ['id' => (int) $cp->ID, 'title' => (string) $cp->post_title, 'type' => (string) $cp->post_type, 'topic_id' => (int) $tid];
                }
            }
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'lms-adapter'); }
    }
    return ['course' => $course, 'lessons' => $lessons];
}

/** Enroll a user via the plugin's own API. @return array|WP_Error */
function wpultra_lmsx_enroll(string $plugin, int $user_id, int $course_id) {
    if ($user_id <= 0 || !get_userdata($user_id)) { return wpultra_lmsx_err('user_not_found', "User #$user_id not found."); }
    $found = get_post($course_id);
    if (!$found || $found->post_type !== wpultra_lmsx_course_post_type($plugin)) {
        return wpultra_lmsx_err('course_not_found', "Course #$course_id not found in " . wpultra_lmsx_label($plugin) . '.');
    }
    try {
        if ($plugin === 'learndash') {
            if (!function_exists('ld_update_course_access')) { return wpultra_lmsx_err('lms_plugin_unavailable', 'LearnDash API (ld_update_course_access) missing.'); }
            ld_update_course_access($user_id, $course_id, false);
        } elseif ($plugin === 'lifterlms') {
            if (!function_exists('llms_enroll_student')) { return wpultra_lmsx_err('lms_plugin_unavailable', 'LifterLMS API (llms_enroll_student) missing.'); }
            $ok = llms_enroll_student($user_id, $course_id, 'wp-ultra-mcp');
            if (!$ok) { return wpultra_lmsx_err('lms_enroll_failed', 'LifterLMS rejected the enrollment (already enrolled or invalid course).'); }
        } elseif ($plugin === 'tutor') {
            if (!function_exists('tutor_utils')) { return wpultra_lmsx_err('lms_plugin_unavailable', 'Tutor LMS API (tutor_utils) missing.'); }
            if (tutor_utils()->is_enrolled($course_id, $user_id)) { return wpultra_lmsx_err('lms_enroll_failed', "User #$user_id is already enrolled in course #$course_id."); }
            $ok = tutor_utils()->do_enroll($course_id, 0, $user_id);
            if (!$ok) { return wpultra_lmsx_err('lms_enroll_failed', 'Tutor LMS rejected the enrollment.'); }
        } else {
            return wpultra_lmsx_err('lms_unknown_plugin', "No enroll driver for '$plugin'.");
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'lms-adapter'); }
        return wpultra_lmsx_err('lms_enroll_failed', wpultra_lmsx_label($plugin) . ' enroll failed: ' . $e->getMessage());
    }
    return ['user_id' => $user_id, 'course_id' => $course_id, 'plugin' => $plugin, 'enrolled' => true];
}

/** Progress rollup via the plugin's own reporting API. @return array|WP_Error */
function wpultra_lmsx_progress(string $plugin, int $user_id, int $course_id) {
    if ($user_id <= 0 || !get_userdata($user_id)) { return wpultra_lmsx_err('user_not_found', "User #$user_id not found."); }
    try {
        if ($plugin === 'learndash') {
            if (!function_exists('learndash_course_progress')) { return wpultra_lmsx_err('lms_plugin_unavailable', 'LearnDash API (learndash_course_progress) missing.'); }
            $p = (array) learndash_course_progress(['user_id' => $user_id, 'course_id' => $course_id, 'array' => true]);
            return wpultra_lmsx_shape_progress($p['percentage'] ?? 0, (int) ($p['completed'] ?? 0), (int) ($p['total'] ?? 0), $plugin);
        }
        if ($plugin === 'lifterlms') {
            if (!class_exists('LLMS_Student')) { return wpultra_lmsx_err('lms_plugin_unavailable', 'LifterLMS API (LLMS_Student) missing.'); }
            $student = new LLMS_Student($user_id);
            $pct = $student->get_progress($course_id, 'course');
            // Lesson counts vary by LLMS version; the percentage is the contract.
            return wpultra_lmsx_shape_progress(is_numeric($pct) ? $pct : 0, 0, 0, $plugin);
        }
        if ($plugin === 'tutor') {
            if (!function_exists('tutor_utils')) { return wpultra_lmsx_err('lms_plugin_unavailable', 'Tutor LMS API (tutor_utils) missing.'); }
            $pct = tutor_utils()->get_course_completed_percent($course_id, $user_id);
            $total = (int) tutor_utils()->get_lesson_count_by_course($course_id);
            $done  = (int) tutor_utils()->get_completed_lesson_count_by_course($course_id, $user_id);
            return wpultra_lmsx_shape_progress(is_numeric($pct) ? $pct : 0, $done, $total, $plugin);
        }
    } catch (\Throwable $e) {
        if (function_exists('wpultra_log_throwable')) { wpultra_log_throwable($e, 'lms-adapter'); }
        return wpultra_lmsx_err('lms_progress_failed', wpultra_lmsx_label($plugin) . ' progress read failed: ' . $e->getMessage());
    }
    return wpultra_lmsx_err('lms_unknown_plugin', "No progress driver for '$plugin'.");
}
