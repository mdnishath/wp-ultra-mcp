<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

wp_register_ability('wpultra/elementor-clone-url', [
    'label'       => __('Elementor: Clone Page From Reference', 'wp-ultra-mcp'),
    'description' => __('Build a whole Elementor v4 page from a reference in ONE call: mints design-token Variables, composes blueprint sections (navbar/hero/feature-grid/cta/footer/custom) filled with real content, styles sections via global classes, validates strictly, writes atomically, and returns a render-check + preview URL. TWO modes — (1) RECOMMENDED `brief`: YOU (the AI) look at the reference URL/screenshot yourself and pass {tokens:{colors:[{role,title,hex}],fonts:[{role,title,family}],sizes:[]}, sections:[{type, heading, subheading, paragraphs[], buttons[], items:[{heading,paragraph}], background:"#hex", text_color:"#hex"}]}; (2) `url`: the server fetches the page and derives a rough brief with static-HTML heuristics — works only for non-JS-rendered sites and is a starting point, not a faithful clone. Target: pass page_id to rebuild an existing page (replace:true wipes its Elementor tree) or title to create a new draft page. AFTER the call: screenshot the preview_url, compare against the reference, then refine with elementor-edit-element / global classes / apply-design-tokens (the elementor-v4-architect skill describes the loop).', 'wp-ultra-mcp'),
    'category'    => 'elementor',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'brief'   => ['type' => 'object', 'description' => 'AI-perceived clone brief (preferred).'],
            'url'     => ['type' => 'string', 'description' => 'Server-side best-effort extraction (static sites only).'],
            'page_id' => ['type' => 'integer', 'description' => 'Existing page to (re)build.'],
            'title'   => ['type' => 'string', 'description' => 'Create a new draft page with this title.'],
            'replace' => ['type' => 'boolean', 'description' => 'Wipe the page\'s current Elementor tree first (default true).'],
            'confirm' => ['type' => 'boolean', 'description' => 'Required when replacing an existing page\'s content.'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'         => ['type' => 'boolean'],
            'post_id'         => ['type' => 'integer'],
            'preview_url'     => ['type' => 'string'],
            'sections_built'  => ['type' => 'integer'],
            'tokens_created'  => ['type' => 'integer'],
            'classes_applied' => ['type' => 'integer'],
            'render'          => ['type' => ['object', 'null']],
            'brief_used'      => ['type' => 'object'],
            'notes'           => ['type' => 'array'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_elementor_clone_url_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => true, 'idempotent' => false],
    ],
]);

function wpultra_elementor_clone_url_cb(array $input) {
    if (function_exists('wpultra_el_require_atomic')) {
        $gate = wpultra_el_require_atomic();
        if (is_wp_error($gate)) { return $gate; }
    }

    // Resolve the brief: explicit > URL extraction.
    $notes = [];
    if (isset($input['brief']) && is_array($input['brief'])) {
        $brief = $input['brief'];
    } elseif (!empty($input['url'])) {
        $url = (string) $input['url'];
        if (!preg_match('#^https?://#i', $url)) { return wpultra_err('bad_url', 'url must be http(s).'); }
        $resp = wp_safe_remote_get($url, ['timeout' => 20, 'redirection' => 3, 'user-agent' => 'Mozilla/5.0 (compatible; wp-ultra-mcp)']);
        if (is_wp_error($resp)) { return $resp; }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) { return wpultra_err('fetch_http_' . $code, "Reference URL returned HTTP $code."); }
        [$brief, $notes] = wpultra_clone_extract_brief((string) wp_remote_retrieve_body($resp), $url);
    } else {
        return wpultra_err('missing_reference', 'Provide a brief (preferred) or a url.');
    }

    $norm = wpultra_clone_normalize_brief($brief);
    if (is_string($norm)) { return wpultra_err('bad_brief', $norm); }

    // Resolve the target page.
    $replace = array_key_exists('replace', $input) ? ($input['replace'] === true) : true;
    if (!empty($input['page_id'])) {
        $post_id = (int) $input['page_id'];
        $post = get_post($post_id);
        if (!$post) { return wpultra_err('not_found', "No post with id $post_id."); }
        if (in_array($post->post_type, wpultra_reserved_post_types(), true)) {
            return wpultra_err('reserved_post_type', 'Refusing to build on a plugin-internal post.');
        }
        if ($replace && wpultra_el_raw($post_id) !== [] && ($input['confirm'] ?? false) !== true) {
            return wpultra_err('confirm_required', "Page $post_id already has Elementor content that replace:true would wipe. Re-run with confirm: true.");
        }
    } elseif (!empty($input['title'])) {
        $post_id = (int) wp_insert_post([
            'post_title'  => sanitize_text_field((string) $input['title']),
            'post_type'   => 'page',
            'post_status' => 'draft',
        ], true);
        if (is_wp_error($post_id) || $post_id <= 0) { return wpultra_err('create_failed', 'Could not create the target page.'); }
        update_post_meta($post_id, '_elementor_edit_mode', 'builder');
    } else {
        return wpultra_err('missing_target', 'Provide page_id or title.');
    }

    $res = wpultra_clone_build($post_id, $norm, $replace);
    if (is_wp_error($res)) { return $res; }
    $res['notes'] = array_merge($notes, (array) $res['notes']);
    $res['brief_used'] = $norm;
    wpultra_audit_log('elementor-clone-url', "post $post_id built from " . (isset($input['brief']) ? 'brief' : 'url') . " ({$res['sections_built']} sections)", true);
    return wpultra_ok($res);
}
