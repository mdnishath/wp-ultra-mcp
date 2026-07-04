<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

// Defensively load the KB engine + the shared AI helper so this ability works
// regardless of the controller's load order.
if (!function_exists('wpultra_kb_answer') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/ai/kb.php')) {
    require_once WPULTRA_DIR . 'includes/ai/kb.php';
}
if (!function_exists('wpultra_ai_embed') && defined('WPULTRA_DIR') && is_readable(WPULTRA_DIR . 'includes/ai/setup.php')) {
    require_once WPULTRA_DIR . 'includes/ai/setup.php';
}

wp_register_ability('wpultra/ai-chatbot', [
    'label'       => __('AI Chatbot / Knowledge Base', 'wp-ultra-mcp'),
    'description' => __(
        'Turn the site\'s own published content into a retrieval-augmented (RAG) knowledge base that answers visitor '
        . 'questions, and expose it as an embeddable chat widget. '
        . 'INDEXING MODEL: build-index scans published posts/pages (and optional custom post types), cleans each to plain '
        . 'text, splits it into overlapping ~1000-char passages (never mid-word), embeds every passage via OpenAI '
        . '(text-embedding-3-small) and stores the whole index in a single non-autoloaded option (capped ~500 chunks). '
        . 'When NO OpenAI key is configured, passages are stored without embeddings and retrieval degrades gracefully to '
        . 'deterministic keyword scoring — the KB still answers, just less semantically. '
        . 'RAG FLOW (ask): the question is embedded, the stored passages are ranked by cosine similarity (or keyword '
        . 'overlap in the no-key path), the top_k (default 4) passages are fed to the chat model with a strict "answer '
        . 'ONLY from this context; if it is not here, say you do not know and suggest contacting the site" instruction. '
        . 'Sources are drawn ONLY from the passages actually used — never invented. Without a key, ask returns the most '
        . 'relevant passages plus a note instead of a synthesized answer. '
        . 'WIDGET EMBED: configure-widget toggles a floating chat bubble; place the [wpultra_chatbot] shortcode on any '
        . 'page, or enable auto-inject to show it site-wide via wp_footer. The widget calls a public REST route '
        . '(POST /wp-json/wpultra/v1/chat) which runs the same ask flow (soft-rate-limited per IP). '
        . 'ACTIONS: '
        . 'build-index {post_types?, post_scan?} -> (re)build the index and report {posts, chunks, embedded, model}. '
        . 'status -> {indexed, chunks, posts, embedded, model, built_at, widget_enabled, has_api_key}. '
        . 'ask {question, top_k?} -> the full RAG answer {answer, sources:[{title,url}], used_chunks} (this drives the '
        . 'public widget AND lets the calling AI query the KB directly). '
        . 'configure-widget {enable_widget, greeting?, title?} -> toggle + configure the widget. '
        . 'clear-index -> wipe the stored index. '
        . 'EXAMPLES: {action:"build-index", post_types:["post","page","product"], post_scan:200}; '
        . '{action:"ask", question:"Do you offer refunds?"}; '
        . '{action:"configure-widget", enable_widget:true, title:"Ask our shop", greeting:"Hi! How can I help?"}. '
        . 'Note: real embeddings + synthesized answers require an OpenAI key on the site (option wpultra_openai_api_key '
        . 'or constant WPULTRA_OPENAI_API_KEY); everything else works keyword-only without one.',
        'wp-ultra-mcp'
    ),
    'category'    => 'ai',
    'input_schema' => [
        'type'       => 'object',
        'properties' => [
            'action'         => ['type' => 'string', 'enum' => ['build-index', 'status', 'ask', 'configure-widget', 'clear-index']],
            'question'       => ['type' => 'string'],
            'post_types'     => ['type' => 'array', 'items' => ['type' => 'string']],
            'post_scan'      => ['type' => 'integer'],
            'top_k'          => ['type' => 'integer'],
            'enable_widget'  => ['type' => 'boolean'],
            'greeting'       => ['type' => 'string'],
            'title'          => ['type' => 'string'],
        ],
        'required'             => ['action'],
        'additionalProperties' => false,
    ],
    'output_schema' => [
        'type'       => 'object',
        'properties' => [
            'success'     => ['type' => 'boolean'],
            'action'      => ['type' => 'string'],
            'indexed'     => ['type' => 'boolean'],
            'chunks'      => ['type' => 'integer'],
            'posts'       => ['type' => 'integer'],
            'embedded'    => ['type' => 'boolean'],
            'model'       => ['type' => ['string', 'null']],
            'built_at'    => ['type' => ['string', 'null']],
            'answer'      => ['type' => ['string', 'null']],
            'sources'     => ['type' => 'array'],
            'passages'    => ['type' => 'array'],
            'used_chunks' => ['type' => 'integer'],
            'note'        => ['type' => ['string', 'null']],
        ],
        'required' => ['success'],
    ],
    'execute_callback'    => 'wpultra_ai_chatbot_cb',
    'permission_callback' => 'wpultra_permission_callback',
    'meta' => [
        'show_in_rest' => true,
        'mcp'          => ['public' => true, 'type' => 'tool'],
        'annotations'  => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
    ],
]);

/** @return array|WP_Error */
function wpultra_ai_chatbot_cb(array $input) {
    if (!function_exists('wpultra_kb_answer')) {
        return wpultra_err('kb_engine_missing', 'The knowledge base engine (includes/ai/kb.php) is not loaded.');
    }
    $action = (string) ($input['action'] ?? '');

    switch ($action) {
        case 'build-index': {
            $opts = [];
            if (isset($input['post_types'])) { $opts['post_types'] = (array) $input['post_types']; }
            if (isset($input['post_scan']))  { $opts['post_scan']  = (int) $input['post_scan']; }
            $res = wpultra_kb_build($opts);
            if (is_wp_error($res)) {
                wpultra_audit_log('ai-chatbot', 'build-index failed: ' . $res->get_error_message(), false);
                return $res;
            }
            wpultra_audit_log('ai-chatbot', "build-index posts={$res['posts']} chunks={$res['chunks']} embedded=" . ($res['embedded'] ? '1' : '0'), true);
            return wpultra_ok([
                'action'   => 'build-index',
                'posts'    => (int) $res['posts'],
                'chunks'   => (int) $res['chunks'],
                'embedded' => (bool) $res['embedded'],
                'model'    => (string) ($res['model'] ?? ''),
            ]);
        }

        case 'status': {
            $index = function_exists('wpultra_kb_get_index') ? wpultra_kb_get_index() : null;
            $chunks = $index !== null ? $index['chunks'] : [];
            $posts = [];
            $embedded = false;
            foreach ($chunks as $c) {
                if (isset($c['post_id'])) { $posts[(int) $c['post_id']] = true; }
                if (!empty($c['embedding']) && is_array($c['embedding'])) { $embedded = true; }
            }
            return wpultra_ok([
                'action'         => 'status',
                'indexed'        => $index !== null && $chunks !== [],
                'chunks'         => count($chunks),
                'posts'          => count($posts),
                'embedded'       => $embedded,
                'model'          => (string) ($index['model'] ?? ''),
                'built_at'       => (string) ($index['built_at'] ?? ''),
                'widget_enabled' => function_exists('get_option') && get_option('wpultra_kb_widget_enabled') === '1',
                'has_api_key'    => function_exists('wpultra_ai_has_key') && wpultra_ai_has_key(),
            ]);
        }

        case 'ask': {
            $question = trim((string) ($input['question'] ?? ''));
            if ($question === '') { return wpultra_err('missing_question', 'question is required for ask.'); }
            $opts = [];
            if (isset($input['top_k'])) { $opts['top_k'] = (int) $input['top_k']; }
            $res = wpultra_kb_answer($question, $opts);
            if (is_wp_error($res)) { return $res; }
            wpultra_audit_log('ai-chatbot', 'ask used_chunks=' . (int) ($res['used_chunks'] ?? 0), true);
            return wpultra_ok(array_merge(['action' => 'ask'], $res));
        }

        case 'configure-widget': {
            $enable = ($input['enable_widget'] ?? null) === true;
            if (function_exists('update_option')) {
                update_option('wpultra_kb_widget_enabled', $enable ? '1' : '0', false);
                $cfg = get_option('wpultra_kb_widget_config', []);
                if (!is_array($cfg)) { $cfg = []; }
                if (isset($input['title']))    { $cfg['title']    = (string) $input['title']; }
                if (isset($input['greeting'])) { $cfg['greeting'] = (string) $input['greeting']; }
                update_option('wpultra_kb_widget_config', $cfg, false);
            }
            wpultra_audit_log('ai-chatbot', 'configure-widget enabled=' . ($enable ? '1' : '0'), true);
            return wpultra_ok([
                'action'         => 'configure-widget',
                'widget_enabled' => $enable,
                'note'           => 'Add the [wpultra_chatbot] shortcode to a page, or enable auto-inject to show the widget site-wide.',
            ]);
        }

        case 'clear-index': {
            if (function_exists('wpultra_kb_clear')) { wpultra_kb_clear(); }
            wpultra_audit_log('ai-chatbot', 'clear-index', true);
            return wpultra_ok(['action' => 'clear-index', 'indexed' => false, 'chunks' => 0]);
        }

        default:
            return wpultra_err('unknown_action', 'Unknown action. Use one of: build-index, status, ask, configure-widget, clear-index.');
    }
}
