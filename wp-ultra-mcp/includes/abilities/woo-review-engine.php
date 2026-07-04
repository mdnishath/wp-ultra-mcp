<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// The engine ships under includes/woocommerce/reviews-engine.php — require it
// defensively so this ability works regardless of load order (mirrors how
// woo-bulk-edit leans on its engine file).
if (!function_exists('wpultra_rvx_filter_candidates') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/woocommerce/reviews-engine.php')) {
    require_once WPULTRA_DIR . 'includes/woocommerce/reviews-engine.php';
}

wp_register_ability('wpultra/woo-review-engine', [
    'label'       => __('WooCommerce: Reviews + Q&A Engine', 'wp-ultra-mcp'),
    'description' => __(
        'Power layer for product reviews and Q&A (beyond the basic woo-manage-review CRUD): review-request emails, photo reviews with verified-buyer badges, a product Q&A system, and review stats. '
        . 'Actions: '
        . 'candidates {days? default 7, cap 90} = list completed orders older than N days (within a 90-day window) whose customers have not reviewed what they bought and were not asked yet — returns [{order_id, email, name, products:[{id,name}]}] (cap 50). '
        . 'send-requests {days?, confirm:true} = email each candidate a review-request (per-product links to the product page #reviews) and flag the order so it is never asked twice; CONFIRM-GATED because it sends real email. '
        . 'create-review {product_id, author_name, author_email, rating 1-5, content, photo_ids?: attachment ids (cap 5, non-images dropped), verified?: bool} = create an approved review; when verified is requested the badge is granted ONLY if that email actually bought the product (wc_customer_bought_product) — the response reports the actual verified value. '
        . 'list-reviews {product_id?, verified_only?, with_photos_only?, limit default 50 cap 200} = shaped approved reviews incl. rating, verified flag, and photo thumbnail urls. '
        . 'qa-ask {product_id, question (tags stripped, max 1000 chars), author_name?, author_email?} = public question, lands in moderation (pending). '
        . 'qa-answer {question_id, answer, author_name? default "Store"} = admin answer, auto-approved, nested under the question. '
        . 'qa-list {product_id, include_pending?} = nested Q&A tree [{question, author, date, status, answers:[...]}]. '
        . 'qa-moderate {id, decision: approve|spam|trash} = moderate a question or answer. '
        . 'stats {product_id?} = avg rating (2dp), review count, verified count, photo-review count, pending questions — for one product or store-wide. '
        . 'Front-end: the [wpultra_product_qa] shortcode renders approved Q&A on any page, and photo reviews automatically show thumbnails under the review text. '
        . 'Examples: {action:"candidates", days:14} = "who should we ask for a review?". {action:"send-requests", days:14, confirm:true} = actually send the emails. '
        . '{action:"create-review", product_id:15, author_name:"Jane", author_email:"jane@x.com", rating:5, content:"Love it", photo_ids:[321], verified:true}. '
        . '{action:"qa-list", product_id:15, include_pending:true} = review the moderation queue for one product.',
        'wp-ultra-mcp'
    ),
    'category'    => 'woocommerce',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action' => [
                'type' => 'string',
                'enum' => ['candidates', 'send-requests', 'create-review', 'list-reviews', 'qa-ask', 'qa-answer', 'qa-list', 'qa-moderate', 'stats'],
            ],
            'days'             => ['type' => 'integer', 'default' => 7],
            'confirm'          => ['type' => 'boolean'],
            'product_id'       => ['type' => 'integer'],
            'author_name'      => ['type' => 'string'],
            'author_email'     => ['type' => 'string'],
            'rating'           => ['type' => 'integer', 'minimum' => 1, 'maximum' => 5],
            'content'          => ['type' => 'string'],
            'photo_ids'        => ['type' => 'array', 'items' => ['type' => 'integer']],
            'verified'         => ['type' => 'boolean'],
            'question'         => ['type' => 'string'],
            'question_id'      => ['type' => 'integer'],
            'answer'           => ['type' => 'string'],
            'id'               => ['type' => 'integer'],
            'decision'         => ['type' => 'string', 'enum' => ['approve', 'spam', 'trash']],
            'include_pending'  => ['type' => 'boolean'],
            'verified_only'    => ['type' => 'boolean'],
            'with_photos_only' => ['type' => 'boolean'],
            'limit'            => ['type' => 'integer', 'default' => 50],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'action'  => ['type' => 'string'],
            'result'  => ['type' => 'object'],
            'rows'    => ['type' => 'array'],
            'count'   => ['type' => 'integer'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_woo_review_engine_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

function wpultra_woo_review_engine_cb(array $input) {
    if (!wpultra_woo_active()) { return wpultra_err('woocommerce_inactive', 'WooCommerce is not active.'); }
    if (!function_exists('wpultra_rvx_filter_candidates')) {
        return wpultra_err('reviews_engine_missing', 'The reviews engine (includes/woocommerce/reviews-engine.php) is not loaded.');
    }

    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'candidates': {
            $rows = wpultra_rvx_candidates((int) ($input['days'] ?? 7));
            return wpultra_ok(['action' => $action, 'rows' => $rows, 'count' => count($rows)]);
        }

        case 'send-requests': {
            // Sends real email to customers — require explicit confirmation.
            if (($input['confirm'] ?? false) !== true) {
                return wpultra_err('send_requests_unconfirmed', 'send-requests emails real customers. Re-run with confirm:true.');
            }
            $res = wpultra_rvx_send_requests((int) ($input['days'] ?? 7));
            if (is_wp_error($res)) {
                wpultra_audit_log('woo-review-engine', 'send-requests failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('woo-review-engine', "send-requests sent={$res['sent']} failed={$res['failed']}", true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'create-review': {
            $res = wpultra_rvx_create_review($input);
            if (is_wp_error($res)) {
                wpultra_audit_log('woo-review-engine', 'create-review failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('woo-review-engine', "create-review #{$res['review_id']} product={$res['product_id']} rating={$res['rating']}" . ($res['verified'] ? ' verified' : ''), true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'list-reviews': {
            $rows = wpultra_rvx_list_reviews($input);
            return wpultra_ok(['action' => $action, 'rows' => $rows, 'count' => count($rows)]);
        }

        case 'qa-ask': {
            $res = wpultra_rvx_qa_ask($input);
            if (is_wp_error($res)) {
                wpultra_audit_log('woo-review-engine', 'qa-ask failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('woo-review-engine', "qa-ask #{$res['question_id']} product={$res['product_id']}", true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'qa-answer': {
            $res = wpultra_rvx_qa_answer($input);
            if (is_wp_error($res)) {
                wpultra_audit_log('woo-review-engine', 'qa-answer failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('woo-review-engine', "qa-answer #{$res['answer_id']} question={$res['question_id']}", true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'qa-list': {
            $pid = (int) ($input['product_id'] ?? 0);
            if ($pid <= 0) { return wpultra_err('missing_product_id', 'qa-list requires product_id.'); }
            $tree = wpultra_rvx_qa_tree(wpultra_rvx_qa_flat_rows($pid), ($input['include_pending'] ?? false) === true);
            return wpultra_ok(['action' => $action, 'rows' => $tree, 'count' => count($tree)]);
        }

        case 'qa-moderate': {
            $res = wpultra_rvx_qa_moderate((int) ($input['id'] ?? 0), (string) ($input['decision'] ?? ''));
            if (is_wp_error($res)) {
                wpultra_audit_log('woo-review-engine', 'qa-moderate failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('woo-review-engine', "qa-moderate #{$res['id']} {$res['decision']}", true);
            return wpultra_ok(['action' => $action, 'result' => $res]);
        }

        case 'stats': {
            return wpultra_ok(['action' => $action, 'result' => wpultra_rvx_stats_live((int) ($input['product_id'] ?? 0))]);
        }

        default:
            return wpultra_err('invalid_action', 'action must be one of: candidates, send-requests, create-review, list-reviews, qa-ask, qa-answer, qa-list, qa-moderate, stats.');
    }
}
