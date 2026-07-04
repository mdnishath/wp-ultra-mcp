<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_lms/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/verticals/lms.php';

/* ============================================================
 * grade_quiz
 * ============================================================ */

function lms_quiz(array $answer_indexes): array {
    $questions = [];
    foreach ($answer_indexes as $ai) {
        $questions[] = ['q' => 'Q?', 'choices' => ['A', 'B', 'C'], 'answer_index' => $ai];
    }
    return ['questions' => $questions];
}

it('grade_quiz: all correct → 100 / passed', function () {
    $quiz = lms_quiz([0, 1, 2]);
    $r = wpultra_lms_grade_quiz($quiz, [0, 1, 2], 70);
    assert_eq(3, $r['correct']);
    assert_eq(3, $r['total']);
    assert_eq(100, $r['pct']);
    assert_true($r['passed']);
});

it('grade_quiz: partial score rounds and can fail', function () {
    // 1 of 3 correct = 33% < 70 → fail.
    $quiz = lms_quiz([0, 1, 2]);
    $r = wpultra_lms_grade_quiz($quiz, [0, 0, 0], 70);
    assert_eq(1, $r['correct']);
    assert_eq(33, $r['pct']);
    assert_true($r['passed'] === false);
});

it('grade_quiz: missing answers count as wrong', function () {
    $quiz = lms_quiz([0, 1, 2]);
    // Only one answer supplied for three questions.
    $r = wpultra_lms_grade_quiz($quiz, [0], 70);
    assert_eq(1, $r['correct']);
    assert_eq(3, $r['total']);
    assert_eq(33, $r['pct']);
    assert_true($r['passed'] === false);
});

it('grade_quiz: out-of-range answer index counts as wrong', function () {
    $quiz = lms_quiz([0, 1]);
    $r = wpultra_lms_grade_quiz($quiz, [99, 1], 50);
    assert_eq(1, $r['correct']);
    assert_eq(50, $r['pct']);
    assert_true($r['passed']); // exactly at threshold
});

it('grade_quiz: empty quiz scores 100 and passes', function () {
    $r = wpultra_lms_grade_quiz(['questions' => []], [], 70);
    assert_eq(0, $r['correct']);
    assert_eq(0, $r['total']);
    assert_eq(100, $r['pct']);
    assert_true($r['passed']);
});

it('grade_quiz: missing questions key treated as empty → passed', function () {
    $r = wpultra_lms_grade_quiz([], [], 70);
    assert_eq(100, $r['pct']);
    assert_true($r['passed']);
});

it('grade_quiz: pass_pct boundary — pct exactly equal passes', function () {
    $quiz = lms_quiz([0, 1, 2, 0]); // 4 questions
    // 3 of 4 = 75%.
    $r = wpultra_lms_grade_quiz($quiz, [0, 1, 2, 9], 75);
    assert_eq(75, $r['pct']);
    assert_true($r['passed']);
    // Just above threshold fails.
    $r2 = wpultra_lms_grade_quiz($quiz, [0, 1, 2, 9], 76);
    assert_true($r2['passed'] === false);
});

it('grade_quiz: pass_pct clamped out-of-range', function () {
    $quiz = lms_quiz([0]);
    // pass_pct 200 clamps to 100 — a perfect 100 passes.
    $r = wpultra_lms_grade_quiz($quiz, [0], 200);
    assert_eq(100, $r['pct']);
    assert_true($r['passed']);
});

/* ============================================================
 * next_lesson
 * ============================================================ */

it('next_lesson: first incomplete lesson', function () {
    assert_eq(20, wpultra_lms_next_lesson([10, 20, 30], [10]));
});

it('next_lesson: skips completed to find next', function () {
    assert_eq(30, wpultra_lms_next_lesson([10, 20, 30], [10, 20]));
});

it('next_lesson: all done → 0', function () {
    assert_eq(0, wpultra_lms_next_lesson([10, 20], [10, 20]));
});

it('next_lesson: empty course → 0', function () {
    assert_eq(0, wpultra_lms_next_lesson([], []));
});

/* ============================================================
 * course_progress
 * ============================================================ */

function lms_course(array $lesson_ids, int $pass_pct = 70, array $lessons = []): array {
    return [
        'meta'    => ['lesson_ids' => $lesson_ids, 'pass_pct' => $pass_pct],
        'lessons' => $lessons,
    ];
}

it('course_progress: none done', function () {
    $c = lms_course([1, 2, 3]);
    $p = ['completed_lessons' => [], 'quiz_scores' => []];
    $r = wpultra_lms_course_progress($c, $p);
    assert_eq(3, $r['lessons_total']);
    assert_eq(0, $r['lessons_done']);
    assert_eq(0, $r['pct']);
    assert_true($r['complete'] === false);
    assert_eq(1, $r['next_lesson_id']);
});

it('course_progress: partial done', function () {
    $c = lms_course([1, 2, 3, 4]);
    $p = ['completed_lessons' => [1, 2], 'quiz_scores' => []];
    $r = wpultra_lms_course_progress($c, $p);
    assert_eq(2, $r['lessons_done']);
    assert_eq(50, $r['pct']);
    assert_true($r['complete'] === false);
    assert_eq(3, $r['next_lesson_id']);
});

it('course_progress: all done, no quizzes → complete', function () {
    $c = lms_course([1, 2]);
    $p = ['completed_lessons' => [1, 2], 'quiz_scores' => []];
    $r = wpultra_lms_course_progress($c, $p);
    assert_eq(100, $r['pct']);
    assert_true($r['complete']);
    assert_eq(0, $r['next_lesson_id']);
});

it('course_progress: all lessons done but a quiz not passed blocks complete', function () {
    $lessons = [
        1 => ['quiz' => null],
        2 => ['quiz' => ['questions' => [['answer_index' => 0, 'choices' => ['A', 'B']]]]],
    ];
    $c = lms_course([1, 2], 70, $lessons);
    // Both lessons completed but lesson 2's quiz scored below pass_pct.
    $p = ['completed_lessons' => [1, 2], 'quiz_scores' => [2 => 40]];
    $r = wpultra_lms_course_progress($c, $p);
    assert_eq(100, $r['pct']); // all lessons done
    assert_true($r['complete'] === false); // but quiz not passed
});

it('course_progress: quiz passed at threshold → complete', function () {
    $lessons = [
        1 => ['quiz' => ['questions' => [['answer_index' => 0, 'choices' => ['A', 'B']]]]],
    ];
    $c = lms_course([1], 70, $lessons);
    $p = ['completed_lessons' => [1], 'quiz_scores' => [1 => 70]];
    $r = wpultra_lms_course_progress($c, $p);
    assert_true($r['complete']);
});

it('course_progress: quiz-bearing lesson with no recorded score blocks complete', function () {
    $lessons = [
        1 => ['quiz' => ['questions' => [['answer_index' => 0, 'choices' => ['A', 'B']]]]],
    ];
    $c = lms_course([1], 70, $lessons);
    $p = ['completed_lessons' => [1], 'quiz_scores' => []];
    $r = wpultra_lms_course_progress($c, $p);
    assert_true($r['complete'] === false);
});

it('course_progress: empty course is complete', function () {
    $c = lms_course([]);
    $p = ['completed_lessons' => [], 'quiz_scores' => []];
    $r = wpultra_lms_course_progress($c, $p);
    assert_eq(0, $r['lessons_total']);
    assert_true($r['complete']);
});

/* ============================================================
 * can_access_lesson
 * ============================================================ */

it('can_access_lesson: free mode allows any lesson', function () {
    $r = wpultra_lms_can_access_lesson([1, 2, 3], 3, [], false);
    assert_true($r['allowed']);
});

it('can_access_lesson: first lesson always accessible in sequential mode', function () {
    $r = wpultra_lms_can_access_lesson([1, 2, 3], 1, [], true);
    assert_true($r['allowed']);
});

it('can_access_lesson: sequential locks a lesson until the prior is done', function () {
    $r = wpultra_lms_can_access_lesson([1, 2, 3], 2, [], true);
    assert_true($r['allowed'] === false);
    assert_contains('Locked', $r['reason']);
});

it('can_access_lesson: sequential unlocks once prior completed', function () {
    $r = wpultra_lms_can_access_lesson([1, 2, 3], 2, [1], true);
    assert_true($r['allowed']);
});

it('can_access_lesson: unknown lesson id rejected', function () {
    $r = wpultra_lms_can_access_lesson([1, 2, 3], 99, [1, 2, 3], true);
    assert_true($r['allowed'] === false);
});

/* ============================================================
 * certificate_data + certificate_html
 * ============================================================ */

it('certificate_data: assembles course + student + date + id', function () {
    $d = wpultra_lms_certificate_data(
        ['id' => 12, 'name' => 'Plumbing 101'],
        ['id' => 5, 'name' => 'Rahim Uddin'],
        1700000000
    );
    assert_eq('Plumbing 101', $d['course_name']);
    assert_eq('Rahim Uddin', $d['student_name']);
    assert_contains('CERT-', $d['cert_id']);
    assert_contains('2023', $d['completed_date']);
});

it('certificate_data: cert_id is stable for the same inputs', function () {
    $a = wpultra_lms_certificate_data(['id' => 1, 'name' => 'X'], ['id' => 2, 'name' => 'Y'], 1700000000);
    $b = wpultra_lms_certificate_data(['id' => 1, 'name' => 'X'], ['id' => 2, 'name' => 'Y'], 1700000000);
    assert_eq($a['cert_id'], $b['cert_id']);
});

it('certificate_html: contains course, student, date and is escaped', function () {
    $d = wpultra_lms_certificate_data(
        ['id' => 1, 'name' => 'Safety & Health'],
        ['id' => 2, 'name' => '<script>evil</script>'],
        1700000000
    );
    $html = wpultra_lms_certificate_html($d);
    assert_contains('Certificate', $html);
    assert_contains('Safety &amp; Health', $html);
    // Student name must be escaped, not raw.
    assert_contains('&lt;script&gt;', $html);
    assert_true(str_contains($html, '<script>evil') === false);
    assert_contains('2023', $html);
});

/* ============================================================
 * validate_quiz
 * ============================================================ */

it('validate_quiz: valid quiz passes', function () {
    $quiz = ['questions' => [
        ['q' => 'A?', 'choices' => ['x', 'y'], 'answer_index' => 0],
        ['q' => 'B?', 'choices' => ['x', 'y', 'z'], 'answer_index' => 2],
    ]];
    assert_true(wpultra_lms_validate_quiz($quiz) === true);
});

it('validate_quiz: empty questions is valid', function () {
    assert_true(wpultra_lms_validate_quiz(['questions' => []]) === true);
});

it('validate_quiz: too few choices rejected', function () {
    $quiz = ['questions' => [['choices' => ['only-one'], 'answer_index' => 0]]];
    assert_true(is_string(wpultra_lms_validate_quiz($quiz)));
});

it('validate_quiz: out-of-range answer_index rejected', function () {
    $quiz = ['questions' => [['choices' => ['a', 'b'], 'answer_index' => 5]]];
    assert_true(is_string(wpultra_lms_validate_quiz($quiz)));
});

it('validate_quiz: negative answer_index rejected', function () {
    $quiz = ['questions' => [['choices' => ['a', 'b'], 'answer_index' => -1]]];
    assert_true(is_string(wpultra_lms_validate_quiz($quiz)));
});

it('validate_quiz: missing answer_index rejected', function () {
    $quiz = ['questions' => [['choices' => ['a', 'b']]]];
    assert_true(is_string(wpultra_lms_validate_quiz($quiz)));
});

it('validate_quiz: non-integer answer_index rejected', function () {
    $quiz = ['questions' => [['choices' => ['a', 'b'], 'answer_index' => '0']]];
    assert_true(is_string(wpultra_lms_validate_quiz($quiz)));
});

it('validate_quiz: missing questions key rejected', function () {
    assert_true(is_string(wpultra_lms_validate_quiz([])));
});

/* ============================================================
 * clamp_pct + defaults
 * ============================================================ */

it('clamp_pct: bounds to 0..100', function () {
    assert_eq(0, wpultra_lms_clamp_pct(-5));
    assert_eq(100, wpultra_lms_clamp_pct(150));
    assert_eq(70, wpultra_lms_clamp_pct(70));
});

it('default_course_meta: sane defaults', function () {
    $m = wpultra_lms_default_course_meta();
    assert_eq(70, $m['pass_pct']);
    assert_true($m['certificate_enabled']);
    assert_true($m['sequential']);
    assert_eq([], $m['lesson_ids']);
});

run_tests();
