<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

require_once WPULTRA_DIR . 'includes/system/brain.php';

wp_register_ability('wpultra/site-brain', [
    'label'       => __('Site Brain', 'wp-ultra-mcp'),
    'description' => __('THE first call of a session — everything the AI needs to know about this site in one response: snapshot (site info + active plugins), persistent memories, recent actions, failure hotspots, and the AI\'s own minted tools/playbooks/widgets. Returns markdown by default (compact, human/AI-readable) or json for programmatic use. Cached for 10 minutes; pass refresh:true to force a rebuild.', 'wp-ultra-mcp'),
    'category'    => 'memory',
    'input_schema'  => [
        'type'       => 'object',
        'properties' => [
            'format'  => ['type' => 'string', 'enum' => ['json', 'markdown'], 'default' => 'markdown'],
            'refresh' => ['type' => 'boolean'],
        ],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success' => ['type' => 'boolean'],
            'format'  => ['type' => 'string'],
            'brain'   => ['type' => 'object'],
            'markdown'=> ['type' => 'string'],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_site_brain_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
    ],
]);

function wpultra_site_brain_cb(array $input) {
    $format = (string) ($input['format'] ?? 'markdown');
    if (!in_array($format, ['json', 'markdown'], true)) { $format = 'markdown'; }
    $refresh = !empty($input['refresh']);

    $brain = wpultra_brain_build(['refresh' => $refresh]);

    if ($format === 'json') {
        return wpultra_ok(['format' => 'json', 'brain' => $brain]);
    }
    return wpultra_ok(['format' => 'markdown', 'markdown' => wpultra_brain_render_markdown($brain)]);
}
