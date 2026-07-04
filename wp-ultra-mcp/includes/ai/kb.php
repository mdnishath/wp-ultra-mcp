<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * AI chatbot / knowledge base engine (Roadmap Group F — F1).
 *
 * Turns the site's own published content into a retrieval-augmented (RAG)
 * knowledge base that answers visitor questions from an embeddable chat widget.
 *
 * Flow:
 *   1. BUILD  — iterate published posts/pages (+ optional CPTs), clean each to
 *      plain text, chunk it into overlapping ~1000-char passages, embed every
 *      chunk via the shared wpultra_ai_embed() helper, and store the whole index
 *      in the (non-autoloaded) option `wpultra_kb_index`. When there is no
 *      OpenAI key the chunks are stored with embedding:null and retrieval falls
 *      back to deterministic keyword scoring — the KB still works, just less
 *      semantically.
 *   2. RETRIEVE — embed the question (if a key is present), rank the stored
 *      chunks by cosine similarity (or keyword overlap in the no-key path) and
 *      keep the top_k.
 *   3. ANSWER  — build a grounded prompt ("answer ONLY from this context") from
 *      the retrieved chunks and ask wpultra_ai_chat(). Sources are ALWAYS drawn
 *      from the chunks that were actually used — never invented.
 *
 * The public surface:
 *   - shortcode [wpultra_chatbot] renders the widget on any page.
 *   - optional wp_footer auto-inject when option wpultra_kb_widget_enabled='1'.
 *   - PUBLIC REST route POST /wp-json/wpultra/v1/chat drives the widget.
 *
 * The PURE functions (wpultra_kb_clean_html, wpultra_kb_chunk_text,
 * wpultra_kb_keyword_score, wpultra_kb_rank, wpultra_kb_build_prompt,
 * wpultra_kb_widget_html) are the testable core: no WordPress calls.
 */

/* =====================================================================
 * PURE — text cleaning + chunking.
 * ===================================================================== */

/**
 * PURE. Strip HTML down to readable plain text: drop <script>/<style>/<template>
 * (tag AND contents), strip all remaining tags, decode entities, collapse
 * whitespace runs to single spaces. Returns a trimmed one-line-ish string.
 */
function wpultra_kb_clean_html(string $html): string {
    if ($html === '') { return ''; }
    // Remove script/style/template/noscript blocks including their contents.
    $html = preg_replace('#<(script|style|template|noscript)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
    // Turn block boundaries into spaces so words don't run together.
    $html = preg_replace('#<[^>]+>#', ' ', $html) ?? $html;
    // Decode entities (&amp; &nbsp; &#8217; ...).
    $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Normalise non-breaking spaces then collapse all whitespace.
    $text = str_replace("\xC2\xA0", ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
    return trim($text);
}

/**
 * PURE. Split cleaned plain text into overlapping chunks of at most $max_chars,
 * breaking on sentence/paragraph boundaries where possible and NEVER mid-word.
 * Consecutive chunks overlap by roughly $overlap trailing characters (snapped to
 * a word boundary) so context isn't lost across a split.
 *
 * Empty/whitespace input → []. Input shorter than $max_chars → a single chunk.
 *
 * @return array<int,string>
 */
function wpultra_kb_chunk_text(string $plain, int $max_chars = 1000, int $overlap = 100): array {
    $plain = trim(preg_replace('/\s+/u', ' ', $plain) ?? $plain);
    if ($plain === '') { return []; }
    if ($max_chars < 1) { $max_chars = 1000; }
    if ($overlap < 0) { $overlap = 0; }
    if ($overlap >= $max_chars) { $overlap = (int) floor($max_chars / 4); }

    $len = function_exists('mb_strlen') ? mb_strlen($plain, 'UTF-8') : strlen($plain);
    if ($len <= $max_chars) { return [$plain]; }

    $sub = static function (string $s, int $start, ?int $length = null): string {
        if (function_exists('mb_substr')) {
            return $length === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $length, 'UTF-8');
        }
        return $length === null ? substr($s, $start) : substr($s, $start, $length);
    };

    $chunks = [];
    $pos = 0;
    while ($pos < $len) {
        $window = $sub($plain, $pos, $max_chars);
        if ($pos + $max_chars >= $len) {
            // Last piece — take the remainder whole.
            $chunk = $sub($plain, $pos);
            $chunk = trim($chunk);
            if ($chunk !== '') { $chunks[] = $chunk; }
            break;
        }
        // Prefer to end the window at the last sentence terminator, else last space.
        $cut = -1;
        if (preg_match_all('/[.!?](?=\s)/u', $window, $m, PREG_OFFSET_CAPTURE)) {
            $last = end($m[0]);
            // offset is a BYTE offset; convert to char count for mb-safe slicing.
            $cut = function_exists('mb_strlen') ? mb_strlen(substr($window, 0, (int) $last[1] + 1), 'UTF-8') : (int) $last[1] + 1;
        }
        if ($cut < (int) ($max_chars * 0.5)) {
            // No good sentence break in the back half — fall back to a word boundary.
            $sp = function_exists('mb_strrpos') ? mb_strrpos($window, ' ', 0, 'UTF-8') : strrpos($window, ' ');
            if ($sp !== false && $sp > 0) { $cut = (int) $sp; }
            else { $cut = $max_chars; } // pathological: one giant token — hard cut.
        }
        $chunk = trim($sub($window, 0, $cut));
        if ($chunk !== '') { $chunks[] = $chunk; }

        // Advance, backing up by ~$overlap chars snapped to a word boundary.
        $next = $pos + $cut;
        if ($overlap > 0 && $next < $len) {
            $backTo = max($pos + 1, $next - $overlap);
            $slice = $sub($plain, $backTo, $next - $backTo);
            $sp = function_exists('mb_strpos') ? mb_strpos($slice, ' ', 0, 'UTF-8') : strpos($slice, ' ');
            if ($sp !== false) { $backTo += $sp + 1; }
            $next = $backTo;
        }
        if ($next <= $pos) { $next = $pos + $cut; } // guarantee forward progress
        $pos = $next;
    }
    return $chunks;
}

/* =====================================================================
 * PURE — keyword scoring + ranking (retrieval core).
 * ===================================================================== */

/** PURE. A small stopword set so ranking isn't dominated by "the", "a", ... */
function wpultra_kb_stopwords(): array {
    return [
        'the' => 1, 'a' => 1, 'an' => 1, 'and' => 1, 'or' => 1, 'but' => 1, 'of' => 1,
        'to' => 1, 'in' => 1, 'on' => 1, 'at' => 1, 'for' => 1, 'is' => 1, 'are' => 1,
        'was' => 1, 'were' => 1, 'be' => 1, 'been' => 1, 'it' => 1, 'this' => 1,
        'that' => 1, 'with' => 1, 'as' => 1, 'by' => 1, 'from' => 1, 'do' => 1,
        'does' => 1, 'how' => 1, 'what' => 1, 'when' => 1, 'where' => 1, 'which' => 1,
        'who' => 1, 'why' => 1, 'can' => 1, 'i' => 1, 'you' => 1, 'we' => 1, 'my' => 1,
    ];
}

/** PURE. Lowercase word tokens (>=2 chars, unicode letters/digits). */
function wpultra_kb_tokenize(string $text): array {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    if (!preg_match_all('/[\p{L}\p{N}]{2,}/u', $text, $m)) { return []; }
    return $m[0];
}

/**
 * PURE. Score how well $text answers $query by weighted token overlap. Case-
 * insensitive, stopword-lite. Each distinct non-stopword query term that appears
 * in the text contributes proportionally to how often it appears (tf, damped),
 * normalised by the number of scorable query terms so the result is ~0..1+.
 * No overlap → 0.0.
 */
function wpultra_kb_keyword_score(string $query, string $text): float {
    $stop = wpultra_kb_stopwords();
    $qTokens = wpultra_kb_tokenize($query);
    $terms = [];
    foreach ($qTokens as $t) { if (!isset($stop[$t])) { $terms[$t] = true; } }
    if ($terms === []) {
        // Query was all stopwords — fall back to raw tokens so we still rank.
        foreach ($qTokens as $t) { $terms[$t] = true; }
    }
    if ($terms === []) { return 0.0; }

    $tf = [];
    foreach (wpultra_kb_tokenize($text) as $t) { $tf[$t] = ($tf[$t] ?? 0) + 1; }
    if ($tf === []) { return 0.0; }

    $score = 0.0;
    foreach (array_keys($terms) as $term) {
        if (isset($tf[$term])) {
            // Damped term frequency: first hit worth 1, extra hits add less.
            $score += 1.0 + log(1.0 + ($tf[$term] - 1));
        }
    }
    return $score / count($terms);
}

/**
 * PURE. Rank $chunks for a query and return the top $top_k.
 *
 * When $query_vec is a non-empty vector, rank by cosine similarity against each
 * chunk's stored embedding (chunks with a null/empty/length-mismatched embedding
 * are skipped). Otherwise rank by keyword overlap of $query against chunk text.
 *
 * Each chunk is [{id, post_id, title, url, text, embedding}]. Returns
 * [{id, post_id, title, url, text, score}] sorted by score desc, capped to top_k.
 * Empty chunks → [].
 *
 * @param array<int,array<string,mixed>> $chunks
 * @param array<int,float>|null          $query_vec
 * @return array<int,array<string,mixed>>
 */
function wpultra_kb_rank(array $chunks, ?array $query_vec, string $query, int $top_k): array {
    if ($chunks === []) { return []; }
    if ($top_k < 1) { $top_k = 1; }
    $useVec = is_array($query_vec) && $query_vec !== [];

    $scored = [];
    foreach ($chunks as $c) {
        if (!is_array($c)) { continue; }
        $text = (string) ($c['text'] ?? '');
        if ($useVec) {
            $emb = $c['embedding'] ?? null;
            if (!is_array($emb) || $emb === [] || count($emb) !== count($query_vec)) { continue; }
            $score = wpultra_ai_cosine($query_vec, $emb);
        } else {
            $score = wpultra_kb_keyword_score($query, $text);
        }
        $scored[] = [
            'id'      => $c['id'] ?? null,
            'post_id' => isset($c['post_id']) ? (int) $c['post_id'] : 0,
            'title'   => (string) ($c['title'] ?? ''),
            'url'     => (string) ($c['url'] ?? ''),
            'text'    => $text,
            'score'   => (float) $score,
        ];
    }
    // Stable-ish sort by score desc.
    usort($scored, static function ($a, $b) {
        if ($a['score'] === $b['score']) { return 0; }
        return $a['score'] < $b['score'] ? 1 : -1;
    });
    return array_slice($scored, 0, $top_k);
}

/* =====================================================================
 * PURE — grounded prompt + widget HTML builders.
 * ===================================================================== */

/**
 * PURE. Build the {system, user} messages for a grounded answer. The system
 * message instructs the model to answer ONLY from the supplied context and to
 * say it doesn't know (and suggest contacting the site) when the answer isn't
 * present. The user message embeds the numbered context passages then the
 * question.
 *
 * @param array<int,array<string,mixed>> $context_chunks
 * @return array{system:string,user:string}
 */
function wpultra_kb_build_prompt(string $question, array $context_chunks): array {
    $system = 'You are a helpful assistant for this website. Answer the user\'s question ONLY from the '
        . 'numbered context passages provided below. Do not use any outside knowledge. If the answer is '
        . 'not contained in the context, say that you don\'t know and suggest contacting the site directly '
        . 'rather than guessing. Be concise and quote relevant details from the context when useful.';

    $parts = [];
    $n = 0;
    foreach ($context_chunks as $c) {
        $n++;
        $title = trim((string) (is_array($c) ? ($c['title'] ?? '') : ''));
        $text  = trim((string) (is_array($c) ? ($c['text'] ?? '') : ''));
        $head = $title !== '' ? " (from \"$title\")" : '';
        $parts[] = "[$n]$head\n$text";
    }
    $context = $parts === [] ? '(no context passages were found)' : implode("\n\n", $parts);

    $user = "Context passages:\n\n" . $context . "\n\n---\nQuestion: " . trim($question) . "\n\nAnswer using only the context above.";
    return ['system' => $system, 'user' => $user];
}

/**
 * PURE. Build the self-contained widget HTML (floating bubble + panel), inline
 * CSS + a single vanilla-JS block. Everything user-facing is escaped. $cfg:
 *   {rest_url, title?, greeting?, placeholder?, nonce?}
 * On send the JS POSTs {question} to rest_url and renders answer + source links.
 */
function wpultra_kb_widget_html(array $cfg): string {
    $esc = static function ($v): string {
        if (function_exists('esc_html')) { return (string) esc_html((string) $v); }
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $escAttr = static function ($v): string {
        if (function_exists('esc_attr')) { return (string) esc_attr((string) $v); }
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $jsonEnc = static function ($v): string {
        $out = json_encode($v, JSON_UNESCAPED_SLASHES);
        if (!is_string($out)) { $out = '{}'; }
        // Neutralise any "</script" so the config block can't break out of <script>.
        return str_replace('<', '<', $out);
    };

    $title       = $esc($cfg['title'] ?? 'Ask us anything');
    $greeting    = $esc($cfg['greeting'] ?? 'Hi! Ask a question and I\'ll answer from this site.');
    $placeholder = $escAttr($cfg['placeholder'] ?? 'Type your question...');

    $conf = $jsonEnc([
        'restUrl' => (string) ($cfg['rest_url'] ?? ''),
        'nonce'   => (string) ($cfg['nonce'] ?? ''),
    ]);

    // Namespaced CSS (all .wpultra-chat-*). Minimal, self-contained.
    $css = <<<CSS
.wpultra-chat-launch{position:fixed;right:20px;bottom:20px;z-index:99999;width:56px;height:56px;border-radius:50%;border:0;cursor:pointer;background:#2563eb;color:#fff;font-size:24px;box-shadow:0 4px 14px rgba(0,0,0,.25)}
.wpultra-chat-panel{position:fixed;right:20px;bottom:88px;z-index:99999;width:340px;max-width:calc(100vw - 40px);max-height:70vh;display:none;flex-direction:column;background:#fff;color:#111;border-radius:12px;overflow:hidden;box-shadow:0 8px 30px rgba(0,0,0,.3);font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;font-size:14px}
.wpultra-chat-panel.wpultra-chat-open{display:flex}
.wpultra-chat-head{background:#2563eb;color:#fff;padding:12px 14px;font-weight:600}
.wpultra-chat-log{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px}
.wpultra-chat-msg{padding:8px 10px;border-radius:10px;max-width:85%;white-space:pre-wrap;word-wrap:break-word}
.wpultra-chat-bot{background:#f1f5f9;align-self:flex-start}
.wpultra-chat-user{background:#2563eb;color:#fff;align-self:flex-end}
.wpultra-chat-src{font-size:12px;margin-top:4px}
.wpultra-chat-src a{color:#2563eb;text-decoration:underline;display:block}
.wpultra-chat-form{display:flex;border-top:1px solid #e2e8f0}
.wpultra-chat-input{flex:1;border:0;padding:12px;font-size:14px;outline:none}
.wpultra-chat-send{border:0;background:#2563eb;color:#fff;padding:0 16px;cursor:pointer;font-weight:600}
CSS;

    $js = <<<JS
(function(){
  var CFG = $conf;
  var launch = document.getElementById('wpultra-chat-launch');
  var panel  = document.getElementById('wpultra-chat-panel');
  var log    = document.getElementById('wpultra-chat-log');
  var form   = document.getElementById('wpultra-chat-form');
  var input  = document.getElementById('wpultra-chat-input');
  if(!launch||!panel||!form||!input||!log){return;}
  function esc(s){var d=document.createElement('div');d.textContent=String(s==null?'':s);return d.innerHTML;}
  function add(cls,html){var el=document.createElement('div');el.className='wpultra-chat-msg '+cls;el.innerHTML=html;log.appendChild(el);log.scrollTop=log.scrollHeight;return el;}
  launch.addEventListener('click',function(){panel.classList.toggle('wpultra-chat-open');if(panel.classList.contains('wpultra-chat-open')){input.focus();}});
  form.addEventListener('submit',function(e){
    e.preventDefault();
    var q=input.value.trim();
    if(!q){return;}
    add('wpultra-chat-user',esc(q));
    input.value='';
    var pending=add('wpultra-chat-bot','&hellip;');
    var headers={'Content-Type':'application/json'};
    if(CFG.nonce){headers['X-WP-Nonce']=CFG.nonce;}
    fetch(CFG.restUrl,{method:'POST',headers:headers,body:JSON.stringify({question:q})})
      .then(function(r){return r.json();})
      .then(function(d){
        var ans=(d&&d.answer)?d.answer:(d&&d.note?d.note:'Sorry, I could not answer that.');
        var html=esc(ans);
        if(d&&d.sources&&d.sources.length){
          var s='<div class="wpultra-chat-src">';
          for(var i=0;i<d.sources.length;i++){
            var src=d.sources[i];
            if(src&&src.url){s+='<a href="'+esc(src.url)+'" target="_blank" rel="noopener">'+esc(src.title||src.url)+'</a>';}
          }
          s+='</div>';
          html+=s;
        }
        pending.innerHTML=html;
      })
      .catch(function(){pending.textContent='Sorry, something went wrong. Please try again.';});
  });
})();
JS;

    $html  = '<style>' . $css . '</style>';
    $html .= '<button id="wpultra-chat-launch" class="wpultra-chat-launch" aria-label="' . $escAttr('Open chat') . '">&#128172;</button>';
    $html .= '<div id="wpultra-chat-panel" class="wpultra-chat-panel" role="dialog" aria-label="' . $title . '">';
    $html .= '<div class="wpultra-chat-head">' . $title . '</div>';
    $html .= '<div id="wpultra-chat-log" class="wpultra-chat-log"><div class="wpultra-chat-msg wpultra-chat-bot">' . $greeting . '</div></div>';
    $html .= '<form id="wpultra-chat-form" class="wpultra-chat-form">';
    $html .= '<input id="wpultra-chat-input" class="wpultra-chat-input" type="text" autocomplete="off" placeholder="' . $placeholder . '" />';
    $html .= '<button type="submit" class="wpultra-chat-send">' . $esc('Send') . '</button>';
    $html .= '</form></div>';
    $html .= '<script>' . $js . '</script>';
    return $html;
}

/* =====================================================================
 * WP wrappers — index storage, build, answer, REST, widget, boot.
 * Every WordPress function is guarded with function_exists().
 * ===================================================================== */

/** The option name that stores the (possibly large, non-autoloaded) index. */
function wpultra_kb_option(): string { return 'wpultra_kb_index'; }

/** Hard cap on stored chunks (keeps the option a sane size). */
function wpultra_kb_max_chunks(): int {
    $n = (int) (function_exists('apply_filters') ? apply_filters('wpultra_kb_max_chunks', 500) : 500);
    return $n > 0 ? $n : 500;
}

/** Load the stored index array, or null when none is built. */
function wpultra_kb_get_index(): ?array {
    if (!function_exists('get_option')) { return null; }
    $idx = get_option(wpultra_kb_option(), null);
    return is_array($idx) && isset($idx['chunks']) && is_array($idx['chunks']) ? $idx : null;
}

/** Wipe the stored index. */
function wpultra_kb_clear(): void {
    if (function_exists('delete_option')) { delete_option(wpultra_kb_option()); }
}

/**
 * WP wrapper. Build the index: scan published posts/pages (+ optional CPTs),
 * clean + chunk each, embed all chunk texts (batched <=100/call), store the
 * result. $opts: {post_types?: string[], post_scan?: int}.
 *
 * @return array{posts:int,chunks:int,embedded:bool,model:?string}|WP_Error
 */
function wpultra_kb_build(array $opts) {
    if (!function_exists('get_posts')) { return wpultra_err('wp_unavailable', 'WordPress query functions unavailable.'); }

    $types = array_values(array_filter(array_map('strval', (array) ($opts['post_types'] ?? ['post', 'page']))));
    if ($types === []) { $types = ['post', 'page']; }
    // Never index the plugin's own private CPTs.
    if (function_exists('wpultra_reserved_post_types')) {
        $types = array_values(array_diff($types, wpultra_reserved_post_types()));
        if ($types === []) { $types = ['post', 'page']; }
    }
    $scan = isset($opts['post_scan']) ? max(1, (int) $opts['post_scan']) : 100;
    $scan = min($scan, 1000);

    $posts = get_posts([
        'post_type'      => $types,
        'post_status'    => 'publish',
        'posts_per_page' => $scan,
        'orderby'        => 'modified',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'suppress_filters' => false,
    ]);
    if (!is_array($posts)) { $posts = []; }

    $maxChunks = wpultra_kb_max_chunks();
    $chunks = [];
    $postCount = 0;
    foreach ($posts as $p) {
        if (count($chunks) >= $maxChunks) { break; }
        $postId = (int) ($p->ID ?? 0);
        if ($postId <= 0) { continue; }
        $title = (string) ($p->post_title ?? '');
        $raw   = (string) ($p->post_content ?? '');
        if (function_exists('apply_filters')) {
            // Let shortcodes/blocks render so the indexed text matches what visitors read.
            $raw = (string) apply_filters('the_content', $raw);
        }
        $plain = wpultra_kb_clean_html($raw);
        if ($title !== '') { $plain = $title . '. ' . $plain; }
        $plain = trim($plain);
        if ($plain === '') { continue; }
        $url = function_exists('get_permalink') ? (string) get_permalink($postId) : '';
        $postCount++;
        foreach (wpultra_kb_chunk_text($plain) as $i => $chunk) {
            if (count($chunks) >= $maxChunks) { break; }
            $chunks[] = [
                'id'        => $postId . '-' . $i,
                'post_id'   => $postId,
                'title'     => $title,
                'url'       => $url,
                'text'      => $chunk,
                'embedding' => null,
            ];
        }
    }

    $embedded = false;
    $model = null;
    if ($chunks !== [] && function_exists('wpultra_ai_has_key') && wpultra_ai_has_key()) {
        $ok = true;
        $offset = 0;
        $total = count($chunks);
        while ($offset < $total) {
            $batch = array_slice($chunks, $offset, 100);
            $texts = array_map(static fn($c) => (string) $c['text'], $batch);
            $vecs = wpultra_ai_embed($texts);
            if (is_wp_error($vecs) || !is_array($vecs) || count($vecs) !== count($batch)) {
                // Keyword-only mode: leave embeddings null, stop trying.
                $ok = false;
                break;
            }
            foreach ($vecs as $j => $vec) {
                $chunks[$offset + $j]['embedding'] = array_map('floatval', (array) $vec);
            }
            $offset += 100;
        }
        if ($ok) {
            $embedded = true;
            $model = function_exists('wpultra_ai_embed_model') ? wpultra_ai_embed_model() : 'unknown';
        } else {
            // Partial failure — reset all embeddings to null for a consistent keyword-only index.
            foreach ($chunks as $k => $_c) { $chunks[$k]['embedding'] = null; }
        }
    }

    $index = [
        'model'    => $model,
        'built_at' => function_exists('current_time') ? current_time('mysql', true) : gmdate('Y-m-d H:i:s'),
        'chunks'   => $chunks,
    ];
    if (function_exists('update_option')) {
        update_option(wpultra_kb_option(), $index, false); // non-autoloaded — can be large
    }

    return [
        'posts'    => $postCount,
        'chunks'   => count($chunks),
        'embedded' => $embedded,
        'model'    => $model,
    ];
}

/**
 * WP wrapper. Answer a question from the built index. $opts: {top_k?: int}.
 *
 * With an API key: embed the question, rank by cosine, ground the top_k chunks,
 * ask the chat model. Returns {answer, sources:[{title,url}], used_chunks}.
 *
 * Without a key (or embedding failure): returns a graceful degraded payload
 * {answer:null, sources, note, passages} so the widget can still surface the
 * most relevant passages. Sources are ALWAYS taken from the ranked chunks used.
 *
 * @return array<string,mixed>|WP_Error
 */
function wpultra_kb_answer(string $question, array $opts = []) {
    $question = trim($question);
    if ($question === '') { return wpultra_err('empty_question', 'Question is required.'); }

    $index = wpultra_kb_get_index();
    if ($index === null || $index['chunks'] === []) {
        return wpultra_err('no_index', 'The knowledge base has not been built yet. Run build-index first.');
    }
    $top_k = isset($opts['top_k']) ? max(1, min(10, (int) $opts['top_k'])) : 4;

    $hasKey = function_exists('wpultra_ai_has_key') && wpultra_ai_has_key();
    $queryVec = null;
    if ($hasKey) {
        $vecs = wpultra_ai_embed([$question]);
        if (!is_wp_error($vecs) && is_array($vecs) && isset($vecs[0]) && is_array($vecs[0])) {
            $queryVec = $vecs[0];
        }
    }

    $ranked = wpultra_kb_rank($index['chunks'], $queryVec, $question, $top_k);

    // Sources come ONLY from the chunks we actually retrieved. Dedupe by URL.
    $sources = [];
    $seen = [];
    foreach ($ranked as $c) {
        $url = (string) $c['url'];
        $key = $url !== '' ? $url : ('t:' . $c['title']);
        if (isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $sources[] = ['title' => (string) $c['title'], 'url' => $url];
    }

    if (!$hasKey) {
        // No AI key — return the relevant passages so the widget can still help.
        $passages = [];
        foreach ($ranked as $c) {
            $passages[] = ['title' => (string) $c['title'], 'url' => (string) $c['url'], 'text' => (string) $c['text']];
        }
        return [
            'answer'      => null,
            'sources'     => $sources,
            'used_chunks' => count($ranked),
            'note'        => 'no AI key — returning the most relevant passages',
            'passages'    => $passages,
        ];
    }

    if ($ranked === []) {
        return [
            'answer'      => 'I could not find anything relevant on this site. Please contact us directly.',
            'sources'     => [],
            'used_chunks' => 0,
        ];
    }

    $prompt = wpultra_kb_build_prompt($question, $ranked);
    $answer = wpultra_ai_chat($prompt['system'], $prompt['user'], ['temperature' => 0.2, 'max_tokens' => 500]);
    if (is_wp_error($answer)) { return $answer; }

    return [
        'answer'      => (string) $answer,
        'sources'     => $sources,
        'used_chunks' => count($ranked),
    ];
}

/**
 * WP glue. The public REST handler for POST /wpultra/v1/chat. Reads `question`,
 * sanitizes + caps it, soft rate-limits per IP, calls wpultra_kb_answer, returns
 * a lean public payload. Never exposes internals on error.
 *
 * @param mixed $req WP_REST_Request
 * @return mixed WP_REST_Response|array
 */
function wpultra_kb_rest_chat($req) {
    $raw = '';
    if (is_object($req) && method_exists($req, 'get_param')) {
        $raw = (string) $req->get_param('question');
    } elseif (is_array($req)) {
        $raw = (string) ($req['question'] ?? '');
    }
    $question = function_exists('sanitize_text_field') ? sanitize_text_field($raw) : trim(strip_tags($raw));
    $question = function_exists('mb_substr') ? mb_substr($question, 0, 500) : substr($question, 0, 500);
    $question = trim($question);

    $respond = static function (array $payload, int $status = 200) {
        if (function_exists('rest_ensure_response')) {
            $r = rest_ensure_response($payload);
            if (is_object($r) && method_exists($r, 'set_status')) { $r->set_status($status); }
            return $r;
        }
        return $payload;
    };

    if ($question === '') {
        return $respond(['answer' => null, 'sources' => [], 'note' => 'Please enter a question.'], 400);
    }

    // Soft per-IP rate limit: 30 requests/minute.
    if (function_exists('get_transient') && function_exists('set_transient')) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
        $bucket = 'wpultra_kb_rl_' . md5($ip);
        $hits = (int) get_transient($bucket);
        if ($hits >= 30) {
            return $respond(['answer' => null, 'sources' => [], 'note' => 'Too many requests. Please wait a moment.'], 429);
        }
        set_transient($bucket, $hits + 1, 60);
    }

    $res = wpultra_kb_answer($question);
    if (is_wp_error($res)) {
        // Do not leak internal error codes/messages to the public endpoint.
        return $respond(['answer' => null, 'sources' => [], 'note' => 'The knowledge base is not ready yet. Please try again later.'], 200);
    }

    $out = [
        'answer'  => $res['answer'] ?? null,
        'sources' => $res['sources'] ?? [],
    ];
    if (isset($res['note'])) { $out['note'] = $res['note']; }
    if (isset($res['passages'])) { $out['passages'] = $res['passages']; }
    return $respond($out, 200);
}

/**
 * WP glue. Register the PUBLIC chat REST route. permission_callback is
 * __return_true because the widget lives on public pages. The CONTROLLER hooks
 * this on rest_api_init — this file only defines it.
 */
function wpultra_kb_register_routes(): void {
    if (!function_exists('register_rest_route')) { return; }
    register_rest_route('wpultra/v1', '/chat', [
        'methods'             => 'POST',
        'permission_callback' => '__return_true',
        'callback'            => 'wpultra_kb_rest_chat',
    ]);
}

/** Assemble the widget config from options + build the HTML for the current page. */
function wpultra_kb_render_widget(): string {
    $cfg = [
        'rest_url' => function_exists('rest_url') ? rest_url('wpultra/v1/chat') : '/wp-json/wpultra/v1/chat',
        'nonce'    => function_exists('wp_create_nonce') ? wp_create_nonce('wp_rest') : '',
    ];
    $wcfg = function_exists('get_option') ? get_option('wpultra_kb_widget_config', []) : [];
    if (is_array($wcfg)) {
        if (!empty($wcfg['title']))    { $cfg['title'] = (string) $wcfg['title']; }
        if (!empty($wcfg['greeting'])) { $cfg['greeting'] = (string) $wcfg['greeting']; }
    }
    return wpultra_kb_widget_html($cfg);
}

/** The [wpultra_chatbot] shortcode callback. */
function wpultra_kb_shortcode($atts = []): string {
    return wpultra_kb_render_widget();
}

/** wp_footer auto-inject when the widget is enabled. */
function wpultra_kb_footer_widget(): void {
    if (!function_exists('get_option') || get_option('wpultra_kb_widget_enabled') !== '1') { return; }
    // The shortcode already rendered it on this page? Avoid double-render best-effort.
    echo wpultra_kb_render_widget();
}

/**
 * Boot: register the shortcode + the optional footer auto-inject. Cheap. The
 * controller calls this on plugins_loaded and hooks wpultra_kb_register_routes
 * on rest_api_init separately.
 */
function wpultra_kb_boot(): void {
    if (function_exists('add_shortcode')) {
        add_shortcode('wpultra_chatbot', 'wpultra_kb_shortcode');
    }
    if (function_exists('add_action')) {
        add_action('wp_footer', 'wpultra_kb_footer_widget');
    }
}
