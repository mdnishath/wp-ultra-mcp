<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

it('harness equality works', function () {
    assert_eq(4, 2 + 2, '2+2');
});
it('WP_Error stub works', function () {
    $e = new WP_Error('x', 'boom');
    assert_true(is_wp_error($e), 'is_wp_error');
    assert_eq('boom', $e->get_error_message(), 'message');
});

run_tests();
