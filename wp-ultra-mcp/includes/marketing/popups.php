<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Popup / optin campaign engine (roadmap A4).
 *
 * Storage: private CPT `wpultra_popup` — post_title = campaign name,
 * post_content = variant A HTML, meta `_wpultra_popup` = settings + stats.
 * An autoloaded index option `wpultra_popups_index` ([id => enabled bool])
 * lets the front-end boot decide with ONE option read (no query) whether the
 * wp_footer renderer needs to be hooked at all.
 *
 * Layout: pure functions first (unit-tested via tests/popups.test.php with the
 * zero-dependency harness — no WordPress), WordPress wrappers after. The
 * controller calls wpultra_popups_boot() on plugins_loaded; the shared
 * marketing tracking endpoint (includes/marketing/track.php) dispatches
 * {kind:'popup'} beacons to wpultra_popup_handle_track().
 */

/* =====================================================================
 * Pure core — no WordPress calls, fully unit-testable.
 * ===================================================================== */

/** Valid trigger types. Pure. */
function wpultra_popup_triggers(): array {
    return ['exit-intent', 'scroll', 'time'];
}

/** Default meta document for a new popup campaign. Pure. */
function wpultra_popup_defaults(): array {
    return [
        'enabled'        => false,
        'trigger'        => 'time',
        'scroll_pct'     => 50,
        'delay_s'        => 5,
        'pages'          => 'all',
        'frequency_days' => 7,
        'variant_b_html' => '',
        'stats'          => [
            'a' => ['impressions' => 0, 'conversions' => 0],
            'b' => ['impressions' => 0, 'conversions' => 0],
        ],
        'created_at'     => '',
    ];
}

/**
 * Validate (partial) popup settings input. Only keys that are PRESENT are
 * checked, so the same validator serves create and partial update.
 * Returns true, or an error string describing the first problem. Pure.
 */
function wpultra_popup_validate(array $in) {
    if (array_key_exists('trigger', $in)
        && (!is_string($in['trigger']) || !in_array($in['trigger'], wpultra_popup_triggers(), true))) {
        return 'trigger must be one of: ' . implode(', ', wpultra_popup_triggers()) . '.';
    }

    foreach ([['scroll_pct', 1, 100], ['delay_s', 0, 300], ['frequency_days', 0, 365]] as [$key, $lo, $hi]) {
        if (!array_key_exists($key, $in)) { continue; }
        $v = $in[$key];
        $is_intish = is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?\d+$/', $v) === 1);
        if (!$is_intish) { return "$key must be an integer."; }
        $n = (int) $v;
        if ($n < $lo || $n > $hi) { return "$key must be between $lo and $hi."; }
    }

    if (array_key_exists('pages', $in)) {
        $p = $in['pages'];
        if (is_string($p)) {
            if (!in_array($p, ['all', 'home'], true)) {
                return "pages must be 'all', 'home', or an array of post IDs.";
            }
        } elseif (is_array($p)) {
            foreach ($p as $pid) {
                $is_idish = is_int($pid) || (is_string($pid) && preg_match('/^\d+$/', $pid) === 1);
                if (!$is_idish || (int) $pid <= 0) {
                    return 'pages array entries must be positive post IDs (integers).';
                }
            }
        } else {
            return "pages must be 'all', 'home', or an array of post IDs.";
        }
    }

    if (array_key_exists('variant_b_html', $in) && !is_string($in['variant_b_html'])) {
        return 'variant_b_html must be a string (empty string disables the A/B test).';
    }

    return true;
}

/**
 * Merge VALIDATED settings input into an existing meta document, casting to
 * canonical types. Never touches enabled / stats / created_at — those have
 * dedicated flows. Pure.
 */
function wpultra_popup_meta_merge(array $meta, array $in): array {
    if (array_key_exists('trigger', $in))        { $meta['trigger'] = (string) $in['trigger']; }
    if (array_key_exists('scroll_pct', $in))     { $meta['scroll_pct'] = (int) $in['scroll_pct']; }
    if (array_key_exists('delay_s', $in))        { $meta['delay_s'] = (int) $in['delay_s']; }
    if (array_key_exists('frequency_days', $in)) { $meta['frequency_days'] = (int) $in['frequency_days']; }
    if (array_key_exists('variant_b_html', $in)) { $meta['variant_b_html'] = (string) $in['variant_b_html']; }
    if (array_key_exists('pages', $in)) {
        $meta['pages'] = is_array($in['pages'])
            ? array_values(array_map('intval', $in['pages']))
            : (string) $in['pages'];
    }
    return $meta;
}

/**
 * Does a popup's `pages` targeting match the current page context?
 * $pages: 'all' | 'home' | array of post IDs. $ctx: {is_home: bool, post_id: int}. Pure.
 */
function wpultra_popup_page_match($pages, array $ctx): bool {
    if ($pages === 'all') { return true; }
    if ($pages === 'home') { return (bool) ($ctx['is_home'] ?? false); }
    if (is_array($pages)) {
        $ids = array_map('intval', $pages);
        return in_array((int) ($ctx['post_id'] ?? 0), $ids, true);
    }
    return false;
}

/**
 * Server-side 50/50 variant pick. No variant B html => always 'a'.
 * $rand is a callable(int $min, int $max): int so tests can inject determinism. Pure.
 */
function wpultra_popup_pick_variant(string $variant_b_html, callable $rand): string {
    if (trim($variant_b_html) === '') { return 'a'; }
    return ((int) $rand(0, 1)) === 0 ? 'a' : 'b';
}

/**
 * Increment a stats counter on the meta document. $event is 'impression' or
 * 'conversion'; $variant 'a' or 'b'. Unknown event/variant => meta unchanged.
 * Repairs a missing/partial stats subtree before incrementing. Pure.
 */
function wpultra_popup_stats_add(array $meta, string $variant, string $event): array {
    if (!in_array($variant, ['a', 'b'], true)) { return $meta; }
    $counter = $event === 'impression' ? 'impressions' : ($event === 'conversion' ? 'conversions' : '');
    if ($counter === '') { return $meta; }

    $zero  = wpultra_popup_defaults()['stats'];
    $stats = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];
    foreach ($zero as $v => $counters) {
        foreach ($counters as $k => $_) {
            $stats[$v][$k] = (int) ($stats[$v][$k] ?? 0);
        }
    }
    $stats[$variant][$counter]++;
    $meta['stats'] = $stats;
    return $meta;
}

/**
 * Per-variant conversion rates (pct, rounded 2dp, div0-guarded) plus a
 * 'winner' key: the variant with the strictly higher rate so far, or null
 * when tied / no data. Pure.
 */
function wpultra_popup_rates(array $stats): array {
    $out = [];
    foreach (['a', 'b'] as $v) {
        $i = (int) ($stats[$v]['impressions'] ?? 0);
        $c = (int) ($stats[$v]['conversions'] ?? 0);
        $out[$v] = [
            'impressions' => $i,
            'conversions' => $c,
            'rate_pct'    => $i > 0 ? round($c / $i * 100, 2) : 0.0,
        ];
    }
    $winner = null;
    if ($out['a']['rate_pct'] > $out['b']['rate_pct']) { $winner = 'a'; }
    elseif ($out['b']['rate_pct'] > $out['a']['rate_pct']) { $winner = 'b'; }
    $out['winner'] = $winner;
    return $out;
}

/**
 * Build the exact per-popup config array the front-end script receives via
 * wp_json_encode (the tracking REST URL travels separately at the top level).
 * Clamps ranges, normalizes types, drops entries without a positive id. Pure.
 */
function wpultra_popup_js_config(array $popups_runtime): array {
    $out = [];
    foreach ($popups_runtime as $p) {
        if (!is_array($p)) { continue; }
        $id = (int) ($p['id'] ?? 0);
        if ($id <= 0) { continue; }
        $trigger = (string) ($p['trigger'] ?? 'time');
        if (!in_array($trigger, wpultra_popup_triggers(), true)) { $trigger = 'time'; }
        $out[] = [
            'id'             => $id,
            'trigger'        => $trigger,
            'scroll_pct'     => max(1, min(100, (int) ($p['scroll_pct'] ?? 50))),
            'delay_s'        => max(0, min(300, (int) ($p['delay_s'] ?? 5))),
            'frequency_days' => max(0, min(365, (int) ($p['frequency_days'] ?? 7))),
            'variant'        => (($p['variant'] ?? 'a') === 'b') ? 'b' : 'a',
        ];
    }
    return $out;
}

/**
 * Sync one entry of the [id => enabled] index. $enabled null removes the
 * entry (delete); true/false upserts it. Non-positive ids are ignored. Pure.
 */
function wpultra_popup_index_sync(array $index, int $id, ?bool $enabled): array {
    if ($id <= 0) { return $index; }
    if ($enabled === null) { unset($index[$id]); return $index; }
    $index[$id] = $enabled;
    return $index;
}

/* =====================================================================
 * WordPress wrappers — CPT, CRUD, index option, tracking, renderer, boot.
 * ===================================================================== */

function wpultra_popup_cpt(): string { return 'wpultra_popup'; }
function wpultra_popup_meta_key(): string { return '_wpultra_popup'; }
function wpultra_popup_index_option(): string { return 'wpultra_popups_index'; }

/** Register the private storage CPT (hooked on init by wpultra_popups_boot). */
function wpultra_popup_register_cpt(): void {
    if (!function_exists('register_post_type')) { return; }
    if (function_exists('post_type_exists') && post_type_exists(wpultra_popup_cpt())) { return; }
    register_post_type(wpultra_popup_cpt(), [
        'public'       => false,
        'show_ui'      => false,
        'show_in_rest' => false,
        'supports'     => ['title', 'editor'],
        'rewrite'      => false,
        'labels'       => ['name' => 'Ultra Popups'],
    ]);
}

/** Read + repair the meta document for a popup (defaults filled in). */
function wpultra_popup_get_meta(int $id): array {
    $meta = get_post_meta($id, wpultra_popup_meta_key(), true);
    if (!is_array($meta)) { $meta = []; }
    $defaults = wpultra_popup_defaults();
    // Shallow merge for settings; stats gets its own repair pass so partially
    // stored counters never lose the a/b sub-shape.
    $stats = is_array($meta['stats'] ?? null) ? $meta['stats'] : [];
    $merged = array_merge($defaults, $meta);
    foreach ($defaults['stats'] as $v => $counters) {
        foreach ($counters as $k => $zero) {
            $merged['stats'][$v][$k] = (int) ($stats[$v][$k] ?? $zero);
        }
    }
    return $merged;
}

/** Load a full popup record (post + meta) or null when missing / wrong type. */
function wpultra_popup_load(int $id): ?array {
    $post = get_post($id);
    if (!$post || $post->post_type !== wpultra_popup_cpt()) { return null; }
    $meta = wpultra_popup_get_meta($id);
    return array_merge($meta, [
        'id'   => (int) $post->ID,
        'name' => (string) $post->post_title,
        'html' => (string) $post->post_content,
    ]);
}

/** Rewrite one index entry (null enabled = remove) in the autoloaded option. */
function wpultra_popup_index_write(int $id, ?bool $enabled): void {
    $index = get_option(wpultra_popup_index_option(), []);
    if (!is_array($index)) { $index = []; }
    $index = wpultra_popup_index_sync($index, $id, $enabled);
    update_option(wpultra_popup_index_option(), $index, true); // autoload: one cheap read at boot
}

/** @return array|WP_Error Create a new campaign — DISABLED until explicitly enabled. */
function wpultra_popup_create(array $in) {
    $name = trim((string) ($in['name'] ?? ''));
    $html = (string) ($in['html'] ?? '');
    if ($name === '') { return wpultra_err('missing_name', 'name is required to create a popup campaign.'); }
    if (trim($html) === '') { return wpultra_err('missing_html', 'html (variant A content) is required to create a popup campaign.'); }

    $valid = wpultra_popup_validate($in);
    if ($valid !== true) { return wpultra_err('invalid_popup', (string) $valid); }

    $meta = wpultra_popup_meta_merge(wpultra_popup_defaults(), $in);
    $meta['enabled']    = false; // always created disabled — enable is an explicit action
    $meta['created_at'] = gmdate('Y-m-d H:i:s');
    if ($meta['variant_b_html'] !== '') { $meta['variant_b_html'] = wp_kses_post($meta['variant_b_html']); }

    $id = wp_insert_post([
        'post_type'    => wpultra_popup_cpt(),
        'post_status'  => 'publish',
        'post_title'   => sanitize_text_field($name),
        'post_content' => wp_kses_post($html),
    ], true);
    if (is_wp_error($id)) { return $id; }
    $id = (int) $id;
    if ($id <= 0) { return wpultra_err('create_failed', 'wp_insert_post returned no ID.'); }

    update_post_meta($id, wpultra_popup_meta_key(), $meta);
    wpultra_popup_index_write($id, false);
    return wpultra_popup_load($id);
}

/** @return array|WP_Error Partial update: only supplied keys change; stats/enabled preserved. */
function wpultra_popup_update(int $id, array $in) {
    $existing = wpultra_popup_load($id);
    if ($existing === null) { return wpultra_err('popup_not_found', "No popup campaign with id $id."); }

    $valid = wpultra_popup_validate($in);
    if ($valid !== true) { return wpultra_err('invalid_popup', (string) $valid); }

    $postarr = ['ID' => $id];
    if (array_key_exists('name', $in)) {
        $name = trim((string) $in['name']);
        if ($name === '') { return wpultra_err('missing_name', 'name cannot be blank.'); }
        $postarr['post_title'] = sanitize_text_field($name);
    }
    if (array_key_exists('html', $in)) {
        $html = (string) $in['html'];
        if (trim($html) === '') { return wpultra_err('missing_html', 'html (variant A content) cannot be blank.'); }
        $postarr['post_content'] = wp_kses_post($html);
    }
    if (count($postarr) > 1) {
        $res = wp_update_post($postarr, true);
        if (is_wp_error($res)) { return $res; }
    }

    $meta = wpultra_popup_meta_merge(wpultra_popup_get_meta($id), $in);
    if ($meta['variant_b_html'] !== '') { $meta['variant_b_html'] = wp_kses_post((string) $meta['variant_b_html']); }
    update_post_meta($id, wpultra_popup_meta_key(), $meta);
    // Keep the index authoritative (id present, enabled state unchanged).
    wpultra_popup_index_write($id, (bool) $meta['enabled']);
    return wpultra_popup_load($id);
}

/** @return array|WP_Error Enable/disable a campaign and sync the index. */
function wpultra_popup_set_enabled(int $id, bool $enabled) {
    $existing = wpultra_popup_load($id);
    if ($existing === null) { return wpultra_err('popup_not_found', "No popup campaign with id $id."); }
    $meta = wpultra_popup_get_meta($id);
    $meta['enabled'] = $enabled;
    update_post_meta($id, wpultra_popup_meta_key(), $meta);
    wpultra_popup_index_write($id, $enabled);
    return wpultra_popup_load($id);
}

/** @return true|WP_Error Hard-delete a campaign and drop it from the index. */
function wpultra_popup_delete(int $id) {
    $existing = wpultra_popup_load($id);
    if ($existing === null) { return wpultra_err('popup_not_found', "No popup campaign with id $id."); }
    $res = wp_delete_post($id, true);
    if (!$res) { return wpultra_err('delete_failed', "wp_delete_post failed for popup $id."); }
    wpultra_popup_index_write($id, null);
    return true;
}

/** List campaign summaries (capped). */
function wpultra_popup_list(int $limit = 50): array {
    $limit = max(1, min(50, $limit));
    $posts = get_posts([
        'post_type'   => wpultra_popup_cpt(),
        'post_status' => 'any',
        'numberposts' => $limit,
        'orderby'     => 'ID',
        'order'       => 'DESC',
    ]);
    $out = [];
    foreach ($posts as $post) {
        $meta = wpultra_popup_get_meta((int) $post->ID);
        $out[] = [
            'id'            => (int) $post->ID,
            'name'          => (string) $post->post_title,
            'enabled'       => (bool) $meta['enabled'],
            'trigger'       => (string) $meta['trigger'],
            'has_variant_b' => trim((string) $meta['variant_b_html']) !== '',
            'impressions'   => (int) $meta['stats']['a']['impressions'] + (int) $meta['stats']['b']['impressions'],
            'conversions'   => (int) $meta['stats']['a']['conversions'] + (int) $meta['stats']['b']['conversions'],
        ];
    }
    return $out;
}

/**
 * Tracking handler for the shared marketing endpoint (track.php dispatches
 * {kind:'popup'} here). Payload keys {event,id,variant} arrive pre-sanitized
 * but are still hostile: the popup must exist, be enabled, and the variant
 * must be valid ('b' only when a B variant is configured). Increments the
 * stats counter; true on success.
 */
function wpultra_popup_handle_track(array $payload): bool {
    $event   = (string) ($payload['event'] ?? '');
    $id      = (int) ($payload['id'] ?? 0);
    $variant = (string) ($payload['variant'] ?? '');

    if ($id <= 0) { return false; }
    if (!in_array($event, ['impression', 'conversion'], true)) { return false; }
    if (!in_array($variant, ['a', 'b'], true)) { return false; }

    $post = get_post($id);
    if (!$post || $post->post_type !== wpultra_popup_cpt()) { return false; }

    $meta = wpultra_popup_get_meta($id);
    if (empty($meta['enabled'])) { return false; }
    if ($variant === 'b' && trim((string) $meta['variant_b_html']) === '') { return false; }

    $meta = wpultra_popup_stats_add($meta, $variant, $event);
    update_post_meta($id, wpultra_popup_meta_key(), $meta);
    return true;
}

/* =====================================================================
 * Front-end renderer (wp_footer) — markup + one shared script block.
 * ===================================================================== */

/** Echo overlay markup + config + vanilla-JS runtime for every applicable popup. */
function wpultra_popup_render_footer(): void {
    if (function_exists('is_admin') && is_admin()) { return; }

    $index = get_option(wpultra_popup_index_option(), []);
    if (!is_array($index) || $index === []) { return; }

    $ctx = [
        'is_home' => (function_exists('is_front_page') && is_front_page()) || (function_exists('is_home') && is_home()),
        'post_id' => function_exists('get_queried_object_id') ? (int) get_queried_object_id() : 0,
    ];

    $runtime = [];
    $boxes   = '';
    foreach ($index as $id => $enabled) {
        if ($enabled !== true) { continue; }
        $id   = (int) $id;
        $post = get_post($id);
        if (!$post || $post->post_type !== wpultra_popup_cpt() || $post->post_status !== 'publish') { continue; }
        $meta = wpultra_popup_get_meta($id);
        if (empty($meta['enabled'])) { continue; }
        if (!wpultra_popup_page_match($meta['pages'], $ctx)) { continue; }

        $variant = wpultra_popup_pick_variant(
            (string) $meta['variant_b_html'],
            static function (int $lo, int $hi): int { return random_int($lo, $hi); }
        );
        $html = $variant === 'b' ? (string) $meta['variant_b_html'] : (string) $post->post_content;

        $runtime[] = [
            'id'             => $id,
            'trigger'        => $meta['trigger'],
            'scroll_pct'     => $meta['scroll_pct'],
            'delay_s'        => $meta['delay_s'],
            'frequency_days' => $meta['frequency_days'],
            'variant'        => $variant,
        ];

        $boxes .= '<div id="wpultra-popup-' . $id . '" class="wpultra-popup-wrap" style="display:none" data-variant="' . esc_attr($variant) . '">'
            . '<div class="wpultra-popup-overlay"></div>'
            . '<div class="wpultra-popup-box" role="dialog" aria-modal="true">'
            . '<button type="button" class="wpultra-popup-close" aria-label="' . esc_attr__('Close', 'wp-ultra-mcp') . '">&times;</button>'
            . '<div class="wpultra-popup-content">' . wp_kses_post($html) . '</div>'
            . '</div></div>';
    }
    if ($runtime === []) { return; }

    $config = [
        'rest'   => esc_url_raw(rest_url('wpultra/v1/track')),
        'popups' => wpultra_popup_js_config($runtime),
    ];

    echo '<style id="wpultra-popup-css">'
        . '.wpultra-popup-wrap{position:fixed;inset:0;z-index:999999}'
        . '.wpultra-popup-overlay{position:absolute;inset:0;background:rgba(0,0,0,.55)}'
        . '.wpultra-popup-box{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;color:#222;max-width:520px;width:calc(100% - 40px);max-height:80vh;overflow:auto;border-radius:8px;padding:28px;box-shadow:0 12px 40px rgba(0,0,0,.35)}'
        . '.wpultra-popup-close{position:absolute;top:8px;right:12px;background:none;border:0;font-size:24px;line-height:1;cursor:pointer;color:#666;padding:2px 6px}'
        . '.wpultra-popup-close:hover{color:#000}'
        . '</style>';
    echo $boxes;
    echo '<script id="wpultra-popup-js">' . wpultra_popup_inline_js(wp_json_encode($config)) . '</script>';
}

/** The inline runtime script with the JSON config injected. */
function wpultra_popup_inline_js(string $config_json): string {
    $js = <<<'JS'
(function(){
"use strict";
var CFG=__WPULTRA_POPUP_CFG__;
if(!CFG||!CFG.popups||!CFG.popups.length){return;}
function beacon(ev,id,variant){
  var body=JSON.stringify({kind:"popup",event:ev,id:id,variant:variant});
  try{
    if(navigator.sendBeacon){
      var blob=new Blob([body],{type:"application/json"});
      if(navigator.sendBeacon(CFG.rest,blob)){return;}
    }
  }catch(e){}
  try{fetch(CFG.rest,{method:"POST",headers:{"Content-Type":"application/json"},body:body,keepalive:true});}catch(e){}
}
function lsKey(id){return "wpultra_popup_"+id;}
function dismissedRecently(id,days){
  if(days<=0){return false;}
  try{
    var raw=localStorage.getItem(lsKey(id));
    if(!raw){return false;}
    var t=(JSON.parse(raw)||{}).t||0;
    return (Date.now()-t)<days*86400000;
  }catch(e){return false;}
}
function markDismissed(id){try{localStorage.setItem(lsKey(id),JSON.stringify({t:Date.now()}));}catch(e){}}
CFG.popups.forEach(function(p){
  var el=document.getElementById("wpultra-popup-"+p.id);
  if(!el){return;}
  var shown=false;
  function hide(){el.style.display="none";}
  function close(){hide();markDismissed(p.id);}
  function show(){
    if(shown){return;}
    shown=true;
    if(dismissedRecently(p.id,p.frequency_days)){return;}
    el.style.display="block";
    beacon("impression",p.id,p.variant);
  }
  function convert(){
    beacon("conversion",p.id,p.variant);
    markDismissed(p.id);
    hide();
  }
  if(p.trigger==="time"){
    setTimeout(show,Math.max(0,p.delay_s)*1000);
  }else if(p.trigger==="scroll"){
    var onScroll=function(){
      var doc=document.documentElement;
      var max=doc.scrollHeight-window.innerHeight;
      var pct=max>0?(window.pageYOffset/max)*100:100;
      if(pct>=p.scroll_pct){
        window.removeEventListener("scroll",onScroll);
        show();
      }
    };
    window.addEventListener("scroll",onScroll,{passive:true});
    onScroll();
  }else{
    var armedOnce=false;
    var onOut=function(e){
      if(armedOnce){return;}
      if(e.clientY<=0){
        armedOnce=true;
        document.removeEventListener("mouseout",onOut);
        show();
      }
    };
    document.addEventListener("mouseout",onOut);
  }
  var closeBtn=el.querySelector(".wpultra-popup-close");
  if(closeBtn){closeBtn.addEventListener("click",close);}
  var overlay=el.querySelector(".wpultra-popup-overlay");
  if(overlay){overlay.addEventListener("click",close);}
  document.addEventListener("keydown",function(e){
    if(e.key==="Escape"&&el.style.display!=="none"){close();}
  });
  var content=el.querySelector(".wpultra-popup-content");
  if(content){
    content.addEventListener("click",function(e){
      var t=e.target;
      while(t&&t!==el){
        var tag=(t.tagName||"").toLowerCase();
        var type=(t.getAttribute&&t.getAttribute("type")||"").toLowerCase();
        if(tag==="a"||tag==="button"||type==="submit"){convert();return;}
        t=t.parentNode;
      }
    });
    content.addEventListener("submit",function(){convert();});
  }
});
})();
JS;
    return str_replace('__WPULTRA_POPUP_CFG__', $config_json, $js);
}

/* =====================================================================
 * Boot — the controller calls this on plugins_loaded.
 * ===================================================================== */

/**
 * Register the storage CPT on init, and hook the wp_footer renderer ONLY when
 * at least one enabled popup exists — decided by ONE read of the autoloaded
 * index option, never a query.
 */
function wpultra_popups_boot(): void {
    if (!function_exists('add_action')) { return; }
    add_action('init', 'wpultra_popup_register_cpt');

    $index = function_exists('get_option') ? get_option(wpultra_popup_index_option(), []) : [];
    if (is_array($index) && in_array(true, $index, true)) {
        add_action('wp_footer', 'wpultra_popup_render_footer', 90);
    }
}
