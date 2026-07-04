<?php
declare(strict_types=1);
require __DIR__ . '/harness.php';

if (!defined('ABSPATH')) { define('ABSPATH', sys_get_temp_dir() . '/wpultra_headless/'); }
// helpers.php provides wpultra_err / wpultra_ok (uses WP_Error stub from harness).
require __DIR__ . '/../wp-ultra-mcp/includes/helpers.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/setup.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/introspect.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/query.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/expose.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/rest.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/scaffold.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/preview.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/auth.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/revalidate.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/build.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/woo.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/seo.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/deploy.php';
require __DIR__ . '/../wp-ultra-mcp/includes/headless/persisted.php';

/* ============================================================
 * Detection (clean environment: nothing installed).
 * ============================================================ */

it('detect: clean env → all five plugin keys present and null', function () {
    $d = wpultra_headless_detect();
    assert_eq(['wp-graphql', 'wpgraphql-jwt', 'wpgraphql-acf', 'woographql', 'wpgraphql-smart-cache'], array_keys($d));
    foreach ($d as $k => $v) { assert_eq(null, $v, "key $k should be null"); }
});

/* ============================================================
 * Permalinks (pure over the structure string).
 * ============================================================ */

it('permalinks: empty structure = plain = not pretty', function () {
    $p = wpultra_headless_permalinks('');
    assert_eq(false, $p['pretty']);
    assert_eq('', $p['structure']);
});

it('permalinks: /%postname%/ is pretty', function () {
    $p = wpultra_headless_permalinks('/%postname%/');
    assert_eq(true, $p['pretty']);
    assert_eq('/%postname%/', $p['structure']);
});

/* ============================================================
 * CORS config shape (pure over the stored option value).
 * ============================================================ */

it('cors shape: non-array / empty → disabled with no origins', function () {
    foreach ([null, false, '', [], 'x'] as $raw) {
        $c = wpultra_headless_shape_cors($raw);
        assert_eq(false, $c['enabled']);
        assert_eq([], $c['origins']);
    }
});

it('cors shape: origins list survives, junk filtered', function () {
    $c = wpultra_headless_shape_cors(['origins' => ['https://front.example.com', 'http://localhost:3000', 123, '']]);
    assert_eq(true, $c['enabled']);
    assert_eq(['https://front.example.com', 'http://localhost:3000'], $c['origins']);
});

/* ============================================================
 * Auth mode (pure over detection + secret flag).
 * ============================================================ */

it('auth mode: jwt plugin + secret → jwt', function () {
    $d = ['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0', 'wpgraphql-acf' => null, 'woographql' => null, 'wpgraphql-smart-cache' => null];
    assert_eq('jwt', wpultra_headless_auth_mode($d, true));
});

it('auth mode: jwt plugin without secret → application-passwords', function () {
    $d = ['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0', 'wpgraphql-acf' => null, 'woographql' => null, 'wpgraphql-smart-cache' => null];
    assert_eq('application-passwords', wpultra_headless_auth_mode($d, false));
});

it('auth mode: no jwt plugin → application-passwords', function () {
    $d = ['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => null, 'wpgraphql-acf' => null, 'woographql' => null, 'wpgraphql-smart-cache' => null];
    assert_eq('application-passwords', wpultra_headless_auth_mode($d, true));
});

/* ============================================================
 * Readiness score (pure; weights: graphql 50, permalinks 15,
 * jwt 10, smart-cache 10, cors 10; +10 acf addon when ACF present;
 * +10 woo addon when Woo present; score = round(100*earned/total)).
 * ============================================================ */

function hl_detected(array $overrides = []): array {
    return array_merge([
        'wp-graphql' => null, 'wpgraphql-jwt' => null, 'wpgraphql-acf' => null,
        'woographql' => null, 'wpgraphql-smart-cache' => null,
    ], $overrides);
}

it('readiness: nothing installed → score 0, wp-graphql in missing, not ready', function () {
    $r = wpultra_headless_readiness(hl_detected(), false, false, []);
    assert_eq(0, $r['score']);
    assert_eq(false, $r['ready']);
    assert_true(in_array('wp-graphql', $r['missing'], true), 'wp-graphql missing');
    assert_true(in_array('pretty-permalinks', $r['missing'], true), 'permalinks missing');
});

it('readiness: graphql only (plain permalinks) → 53, not ready', function () {
    $r = wpultra_headless_readiness(hl_detected(['wp-graphql' => '2.0.0']), false, false, []);
    // 50 of 95 applicable → round(52.63) = 53
    assert_eq(53, $r['score']);
    assert_eq(false, $r['ready'], 'pretty permalinks still required');
    assert_true(!in_array('wp-graphql', $r['missing'], true), 'graphql not missing');
});

it('readiness: graphql + pretty permalinks → ready even below 100', function () {
    $r = wpultra_headless_readiness(hl_detected(['wp-graphql' => '2.0.0']), true, false, []);
    // 65 of 95 → round(68.42) = 68
    assert_eq(68, $r['score']);
    assert_eq(true, $r['ready']);
});

it('readiness: full stack, no acf/woo → 100, nothing missing', function () {
    $d = hl_detected([
        'wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0', 'wpgraphql-smart-cache' => '1.3.0',
    ]);
    $r = wpultra_headless_readiness($d, true, true, []);
    assert_eq(100, $r['score']);
    assert_eq([], $r['missing']);
    assert_eq(true, $r['ready']);
});

it('readiness: ACF present but wpgraphql-acf absent → addon counted + missing', function () {
    $d = hl_detected(['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0', 'wpgraphql-smart-cache' => '1.3.0']);
    $r = wpultra_headless_readiness($d, true, true, ['acf' => true]);
    // earned 95 of 105 → round(90.48) = 90
    assert_eq(90, $r['score']);
    assert_true(in_array('wpgraphql-acf', $r['missing'], true), 'acf addon missing');
});

it('readiness: Woo present but woographql absent → addon counted + missing', function () {
    $d = hl_detected(['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0', 'wpgraphql-smart-cache' => '1.3.0']);
    $r = wpultra_headless_readiness($d, true, true, ['woo' => true]);
    assert_eq(90, $r['score']);
    assert_true(in_array('woographql', $r['missing'], true), 'woo addon missing');
});

it('readiness: ACF + addon both present → back to 100', function () {
    $d = hl_detected(['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0', 'wpgraphql-acf' => '2.4.0', 'wpgraphql-smart-cache' => '1.3.0']);
    $r = wpultra_headless_readiness($d, true, true, ['acf' => true]);
    assert_eq(100, $r['score']);
    assert_eq([], $r['missing']);
});

it('readiness: recommendations mention headless-setup when graphql missing', function () {
    $r = wpultra_headless_readiness(hl_detected(), false, false, []);
    assert_true(count($r['recommendations']) > 0, 'has recommendations');
    assert_contains('headless-setup', implode(' ', $r['recommendations']));
});

it('readiness: empty-string version (present, version unknown) still counts as installed', function () {
    $r = wpultra_headless_readiness(hl_detected(['wp-graphql' => '']), true, false, []);
    assert_true(!in_array('wp-graphql', $r['missing'], true), 'graphql detected via empty-string version');
    assert_eq(true, $r['ready']);
});

/* ============================================================
 * H1.2 — bundle plan (pure over detection + ctx).
 * ============================================================ */

it('bundle plan: clean env, no acf/woo → 3 installs (core, jwt, smart-cache)', function () {
    $plan = wpultra_headless_bundle_plan(hl_detected(), []);
    assert_eq(['wp-graphql', 'wpgraphql-jwt', 'wpgraphql-smart-cache'], array_column($plan, 'key'));
    foreach ($plan as $p) { assert_eq('install', $p['action'], $p['key']); assert_true($p['source'] !== '', 'source set'); }
});

it('bundle plan: wp.org slugs for core/smart-cache, zip URL for jwt', function () {
    $plan = wpultra_headless_bundle_plan(hl_detected(), []);
    $by = array_column($plan, 'source', 'key');
    assert_eq('wp-graphql', $by['wp-graphql']);
    assert_eq('wpgraphql-smart-cache', $by['wpgraphql-smart-cache']);
    assert_contains('https://github.com/wp-graphql/wp-graphql-jwt-authentication', $by['wpgraphql-jwt']);
});

it('bundle plan: acf + woo present → addons appended (acf slug, woo zip)', function () {
    $plan = wpultra_headless_bundle_plan(hl_detected(), ['acf' => true, 'woo' => true]);
    $by = array_column($plan, 'source', 'key');
    assert_eq(5, count($plan));
    assert_eq('wpgraphql-acf', $by['wpgraphql-acf']);
    assert_contains('https://github.com/wp-graphql/wp-graphql-woocommerce', $by['woographql']);
});

it('bundle plan: already-installed items marked "already"', function () {
    $plan = wpultra_headless_bundle_plan(hl_detected(['wp-graphql' => '2.17.0']), []);
    $by = array_column($plan, 'action', 'key');
    assert_eq('already', $by['wp-graphql']);
    assert_eq('install', $by['wpgraphql-jwt']);
});

/* ============================================================
 * H1.2 — origin validation (pure).
 * ============================================================ */

it('origins: valid list normalized to scheme://host[:port]', function () {
    $r = wpultra_headless_validate_origins(['https://front.example.com/', 'http://localhost:3000/some/path']);
    assert_eq(['https://front.example.com', 'http://localhost:3000'], $r);
});

it('origins: empty list is fine (no CORS)', function () {
    assert_eq([], wpultra_headless_validate_origins([]));
});

it('origins: duplicates collapse', function () {
    $r = wpultra_headless_validate_origins(['https://a.com', 'https://a.com/']);
    assert_eq(['https://a.com'], $r);
});

it('origins: non-http scheme / garbage / wildcard rejected with error string', function () {
    foreach ([['ftp://x.com'], ['not a url'], ['*'], ['javascript:alert(1)']] as $bad) {
        assert_true(is_string(wpultra_headless_validate_origins($bad)), json_encode($bad) . ' rejected');
    }
});

/* ============================================================
 * H1.2 — CORS response headers (pure).
 * ============================================================ */

it('cors headers: allowed origin echoed back with credentials + vary', function () {
    $h = wpultra_headless_cors_headers('https://front.example.com', ['https://front.example.com', 'http://localhost:3000']);
    assert_eq('https://front.example.com', $h['Access-Control-Allow-Origin']);
    assert_eq('true', $h['Access-Control-Allow-Credentials']);
    assert_eq('Origin', $h['Vary']);
});

it('cors headers: unknown origin or empty allowlist → no headers', function () {
    assert_eq([], wpultra_headless_cors_headers('https://evil.example.com', ['https://front.example.com']));
    assert_eq([], wpultra_headless_cors_headers('https://front.example.com', []));
    assert_eq([], wpultra_headless_cors_headers('', ['https://front.example.com']));
});

/* ============================================================
 * H1.2 — installed-but-inactive matcher (pure over get_plugins() keys).
 * ============================================================ */

it('match installed: exact wp-graphql dir does not match the jwt plugin dir', function () {
    $files = ['wp-graphql-jwt-authentication-0.7.0/wp-graphql-jwt-authentication.php', 'akismet/akismet.php'];
    assert_eq('', wpultra_headless_match_installed($files, 'wp-graphql'));
    assert_eq('wp-graphql-jwt-authentication-0.7.0/wp-graphql-jwt-authentication.php', wpultra_headless_match_installed($files, 'wpgraphql-jwt'));
});

it('match installed: core, smart-cache (both dir spellings), acf, woo', function () {
    $files = [
        'wp-graphql/wp-graphql.php',
        'wpgraphql-smart-cache/wp-graphql-smart-cache.php',
        'wpgraphql-acf/wpgraphql-acf.php',
        'wp-graphql-woocommerce/wp-graphql-woocommerce.php',
    ];
    assert_eq('wp-graphql/wp-graphql.php', wpultra_headless_match_installed($files, 'wp-graphql'));
    assert_eq('wpgraphql-smart-cache/wp-graphql-smart-cache.php', wpultra_headless_match_installed($files, 'wpgraphql-smart-cache'));
    assert_eq('wpgraphql-acf/wpgraphql-acf.php', wpultra_headless_match_installed($files, 'wpgraphql-acf'));
    assert_eq('wp-graphql-woocommerce/wp-graphql-woocommerce.php', wpultra_headless_match_installed($files, 'woographql'));
});

it('match installed: nothing installed → empty string', function () {
    assert_eq('', wpultra_headless_match_installed(['akismet/akismet.php'], 'wpgraphql-smart-cache'));
});

/* ============================================================
 * H1.2 — JWT secret generation (pure fallback path; no WP funcs in harness).
 * ============================================================ */

it('jwt secret: 64 hex chars, two calls differ', function () {
    $a = wpultra_headless_generate_secret();
    $b = wpultra_headless_generate_secret();
    assert_eq(64, strlen($a));
    assert_true((bool) preg_match('/^[0-9a-f]{64}$/', $a), 'hex');
    assert_true($a !== $b, 'unique');
});

/* ============================================================
 * H1.3 — introspection shaping (pure over introspection JSON).
 * ============================================================ */

it('typeref: scalars, non-null and list nesting render GraphQL-style', function () {
    assert_eq('String', wpultra_headless_render_typeref(['kind' => 'SCALAR', 'name' => 'String']));
    assert_eq('String!', wpultra_headless_render_typeref(['kind' => 'NON_NULL', 'ofType' => ['kind' => 'SCALAR', 'name' => 'String']]));
    assert_eq('[Post!]!', wpultra_headless_render_typeref([
        'kind' => 'NON_NULL', 'ofType' => ['kind' => 'LIST', 'ofType' => ['kind' => 'NON_NULL', 'ofType' => ['kind' => 'OBJECT', 'name' => 'Post']]],
    ]));
    assert_eq('?', wpultra_headless_render_typeref([]));
});

it('shape type: fields flattened to name/type/args strings', function () {
    $t = [
        'name' => 'Post', 'kind' => 'OBJECT', 'description' => 'A post.',
        'fields' => [[
            'name' => 'title',
            'args' => [['name' => 'format', 'type' => ['kind' => 'ENUM', 'name' => 'PostObjectFieldFormatEnum']]],
            'type' => ['kind' => 'SCALAR', 'name' => 'String'],
        ]],
    ];
    $s = wpultra_headless_shape_type($t);
    assert_eq('Post', $s['name']);
    assert_eq('OBJECT', $s['kind']);
    assert_eq([['name' => 'title', 'type' => 'String', 'args' => ['format: PostObjectFieldFormatEnum']]], $s['fields']);
});

it('shape type: enum values and input fields survive, missing keys tolerated', function () {
    $e = wpultra_headless_shape_type(['name' => 'OrderEnum', 'kind' => 'ENUM', 'enumValues' => [['name' => 'ASC'], ['name' => 'DESC']]]);
    assert_eq(['ASC', 'DESC'], $e['enumValues']);
    $i = wpultra_headless_shape_type(['name' => 'In', 'kind' => 'INPUT_OBJECT', 'inputFields' => [['name' => 'id', 'type' => ['kind' => 'SCALAR', 'name' => 'ID']]]]);
    assert_eq([['name' => 'id', 'type' => 'ID']], $i['inputFields']);
});

it('filter types: __internal dropped, search + kind narrow the list', function () {
    $types = [
        ['name' => '__Schema', 'kind' => 'OBJECT'],
        ['name' => 'Post', 'kind' => 'OBJECT'],
        ['name' => 'PostToTagConnection', 'kind' => 'OBJECT'],
        ['name' => 'OrderEnum', 'kind' => 'ENUM'],
    ];
    $all = wpultra_headless_filter_types($types, '', '');
    assert_eq(['Post', 'PostToTagConnection', 'OrderEnum'], array_column($all, 'name'));
    assert_eq(['Post', 'PostToTagConnection'], array_column(wpultra_headless_filter_types($types, 'post', ''), 'name'));
    assert_eq(['OrderEnum'], array_column(wpultra_headless_filter_types($types, '', 'ENUM'), 'name'));
});

it('schema summary: counts by kind + root type names', function () {
    $schema = [
        'queryType'    => ['name' => 'RootQuery'],
        'mutationType' => ['name' => 'RootMutation'],
        'types'        => [
            ['name' => '__Schema', 'kind' => 'OBJECT'],
            ['name' => 'Post', 'kind' => 'OBJECT'],
            ['name' => 'OrderEnum', 'kind' => 'ENUM'],
            ['name' => 'String', 'kind' => 'SCALAR'],
        ],
    ];
    $s = wpultra_headless_schema_summary($schema);
    assert_eq('RootQuery', $s['query_type']);
    assert_eq('RootMutation', $s['mutation_type']);
    assert_eq(3, $s['type_count'], 'internal __ types excluded');
    assert_eq(['OBJECT' => 1, 'ENUM' => 1, 'SCALAR' => 1], $s['kinds']);
});

it('root fields: shaped to name/type/args with search filter', function () {
    $fields = [
        ['name' => 'posts', 'args' => [['name' => 'first', 'type' => ['kind' => 'SCALAR', 'name' => 'Int']]], 'type' => ['kind' => 'OBJECT', 'name' => 'RootQueryToPostConnection']],
        ['name' => 'menus', 'args' => [], 'type' => ['kind' => 'OBJECT', 'name' => 'RootQueryToMenuConnection']],
    ];
    $all = wpultra_headless_shape_root_fields($fields, '');
    assert_eq(2, count($all));
    assert_eq([['name' => 'posts', 'type' => 'RootQueryToPostConnection', 'args' => ['first: Int']]], wpultra_headless_shape_root_fields($fields, 'post'));
});

/* ============================================================
 * H1.4 — operation-type detection (pure over the query string).
 * ============================================================ */

it('op type: shorthand and named queries → query', function () {
    assert_eq('query', wpultra_headless_operation_type('{ posts { nodes { title } } }'));
    assert_eq('query', wpultra_headless_operation_type('query GetPosts($n: Int) { posts(first: $n) { nodes { title } } }'));
    assert_eq('query', wpultra_headless_operation_type("\n  query {\n    generalSettings { title }\n  }"));
});

it('op type: mutations detected, incl. named + after fragments', function () {
    assert_eq('mutation', wpultra_headless_operation_type('mutation { createPost(input: {title: "x"}) { post { id } } }'));
    assert_eq('mutation', wpultra_headless_operation_type('mutation MakePost($t: String!) { createPost(input: {title: $t}) { post { id } } }'));
    assert_eq('mutation', wpultra_headless_operation_type("fragment F on Post { id }\nmutation { createPost(input: {}) { post { ...F } } }"));
});

it('op type: comments and string literals cannot fool the detector', function () {
    assert_eq('query', wpultra_headless_operation_type("# mutation is mentioned here\n{ posts { nodes { id } } }"));
    assert_eq('query', wpultra_headless_operation_type('{ posts(where: {search: "mutation"}) { nodes { id } } }'));
    assert_eq('mutation', wpultra_headless_operation_type("# just a comment\nmutation { deletePost(input: {id: \"1\"}) { deletedId } }"));
});

it('op type: subscription detected; empty/garbage → empty string', function () {
    assert_eq('subscription', wpultra_headless_operation_type('subscription { postUpdated { id } }'));
    assert_eq('', wpultra_headless_operation_type(''));
    assert_eq('', wpultra_headless_operation_type('not graphql at all'));
});

it('op type: mixed document with any mutation counts as mutation', function () {
    assert_eq('mutation', wpultra_headless_operation_type("query A { posts { nodes { id } } }\nmutation B { createPost(input: {}) { post { id } } }"));
});

/* ============================================================
 * H1.5 — GraphQL name derivation (pure over the slug).
 * ============================================================ */

it('graphql names: slug → camelCase single + plural', function () {
    assert_eq(['single' => 'wpultraBooking', 'plural' => 'wpultraBookings'], wpultra_headless_graphql_names('wpultra_booking'));
    assert_eq(['single' => 'movieReview', 'plural' => 'movieReviews'], wpultra_headless_graphql_names('movie-review'));
    assert_eq(['single' => 'event', 'plural' => 'events'], wpultra_headless_graphql_names('event'));
});

it('graphql names: s/x/ch endings pluralize with es; explicit names win', function () {
    assert_eq(['single' => 'business', 'plural' => 'businesses'], wpultra_headless_graphql_names('business'));
    assert_eq(['single' => 'box', 'plural' => 'boxes'], wpultra_headless_graphql_names('box'));
    assert_eq(['single' => 'Person', 'plural' => 'People'], wpultra_headless_graphql_names('person', 'Person', 'People'));
});

/* ============================================================
 * H1.5 — expose config merge/remove (pure over the option value).
 * ============================================================ */

it('expose merge: adds post types with derived names, idempotent', function () {
    $cfg = wpultra_headless_expose_merge([], 'post_types', [['slug' => 'wpultra_booking']]);
    assert_eq(['wpultra_booking' => ['single' => 'wpultraBooking', 'plural' => 'wpultraBookings']], $cfg['post_types']);
    $again = wpultra_headless_expose_merge($cfg, 'post_types', [['slug' => 'wpultra_booking']]);
    assert_eq($cfg, $again);
});

it('expose merge: explicit names respected, taxonomies separate bucket', function () {
    $cfg = wpultra_headless_expose_merge([], 'taxonomies', [['slug' => 'genre', 'single' => 'Genre', 'plural' => 'Genres']]);
    assert_eq(['genre' => ['single' => 'Genre', 'plural' => 'Genres']], $cfg['taxonomies']);
    assert_true(!isset($cfg['post_types']['genre']), 'not in post_types');
});

it('expose remove: deletes the slug, leaves others', function () {
    $cfg = wpultra_headless_expose_merge([], 'post_types', [['slug' => 'a'], ['slug' => 'b']]);
    $cfg = wpultra_headless_expose_remove($cfg, 'post_types', ['a']);
    assert_eq(['b'], array_keys($cfg['post_types']));
});

/* ============================================================
 * H1.5 — register-args filter (pure over args + config).
 * ============================================================ */

it('expose args: configured slug gets show_in_graphql + names', function () {
    $cfg = ['post_types' => ['wpultra_booking' => ['single' => 'wpultraBooking', 'plural' => 'wpultraBookings']]];
    $args = wpultra_headless_expose_args(['public' => true], 'wpultra_booking', 'post_types', $cfg);
    assert_eq(true, $args['show_in_graphql']);
    assert_eq('wpultraBooking', $args['graphql_single_name']);
    assert_eq('wpultraBookings', $args['graphql_plural_name']);
    assert_eq(true, $args['public'], 'original args survive');
});

it('expose args: unconfigured slug untouched; existing exposure not clobbered', function () {
    $cfg = ['post_types' => ['a' => ['single' => 'a', 'plural' => 'as']]];
    assert_eq(['public' => true], wpultra_headless_expose_args(['public' => true], 'other', 'post_types', $cfg));
    $pre = ['show_in_graphql' => true, 'graphql_single_name' => 'customName'];
    $out = wpultra_headless_expose_args($pre, 'a', 'post_types', $cfg);
    assert_eq('customName', $out['graphql_single_name'], 'plugin-set graphql name wins');
});

/* ============================================================
 * H1.5 — theme-token shaping (pure over wp_get_global_settings shape).
 * ============================================================ */

it('theme tokens: palette + font sizes flatten to token lists', function () {
    $settings = [
        'color' => ['palette' => ['theme' => [
            ['slug' => 'primary', 'name' => 'Primary', 'color' => '#0af'],
            ['slug' => 'base', 'name' => 'Base', 'color' => '#fff'],
        ]]],
        'typography' => ['fontSizes' => ['theme' => [
            ['slug' => 'large', 'name' => 'Large', 'size' => '2rem'],
        ]]],
    ];
    $t = wpultra_headless_shape_tokens($settings);
    assert_eq([
        ['id' => 'primary', 'label' => 'Primary', 'value' => '#0af'],
        ['id' => 'base', 'label' => 'Base', 'value' => '#fff'],
    ], $t['colors']);
    assert_eq([['id' => 'large', 'label' => 'Large', 'value' => '2rem']], $t['fontSizes']);
});

it('theme tokens: empty/garbage settings → empty token lists', function () {
    $t = wpultra_headless_shape_tokens([]);
    assert_eq([], $t['colors']);
    assert_eq([], $t['fontSizes']);
});

/* ============================================================
 * H1.6 — REST bundle config shape (pure over the option value).
 * ============================================================ */

it('rest config: defaults off with all routes on', function () {
    foreach ([null, false, '', []] as $raw) {
        $c = wpultra_headless_rest_shape_config($raw);
        assert_eq(false, $c['enabled']);
        assert_eq(['menus' => true, 'settings' => true, 'tokens' => true, 'fields' => true], $c['routes']);
    }
});

it('rest config: stored enabled + partial routes survive, junk keys dropped', function () {
    $c = wpultra_headless_rest_shape_config(['enabled' => true, 'routes' => ['fields' => false, 'bogus' => true]]);
    assert_eq(true, $c['enabled']);
    assert_eq(['menus' => true, 'settings' => true, 'tokens' => true, 'fields' => false], $c['routes']);
});

/* ============================================================
 * H1.6 — menu item shaping (pure over nav-menu-item rows).
 * ============================================================ */

it('menu items: flattened to stable shape with parent/order ints', function () {
    $rows = [[
        'ID' => 11, 'menu_item_parent' => '0', 'menu_order' => 1, 'title' => 'Home',
        'url' => 'https://site.test/', 'target' => '', 'classes' => ['', 'nav-home'],
    ], [
        'ID' => 12, 'menu_item_parent' => '11', 'menu_order' => 2, 'title' => 'About',
        'url' => 'https://site.test/about/', 'target' => '_blank', 'classes' => [],
    ]];
    $items = wpultra_headless_shape_menu_items($rows);
    assert_eq([
        ['id' => 11, 'parent' => 0, 'order' => 1, 'label' => 'Home', 'url' => 'https://site.test/', 'target' => '', 'classes' => ['nav-home']],
        ['id' => 12, 'parent' => 11, 'order' => 2, 'label' => 'About', 'url' => 'https://site.test/about/', 'target' => '_blank', 'classes' => []],
    ], $items);
});

/* ============================================================
 * H1.6 — public meta filter (pure over a get_post_meta map).
 * ============================================================ */

it('public meta: underscore keys dropped, single-element arrays flattened', function () {
    $meta = [
        '_edit_lock'  => ['123:1'],
        'price'       => ['49'],
        'features'    => ['a', 'b'],
        '_thumbnail_id' => ['9'],
    ];
    assert_eq(['price' => '49', 'features' => ['a', 'b']], wpultra_headless_public_meta($meta));
});

it('public meta: serialized values unserialized safely', function () {
    $meta = ['specs' => [serialize(['cpu' => 'M3', 'ram' => 16])]];
    assert_eq(['specs' => ['cpu' => 'M3', 'ram' => 16]], wpultra_headless_public_meta($meta));
});

/* ============================================================
 * H2.1 — scaffold manifest (pure over framework + ctx).
 * ============================================================ */

function hl_ctx(): array {
    return [
        'endpoint'   => 'http://wp-connector.local/graphql',
        'site_title' => 'wp-connector',
        'site_url'   => 'http://wp-connector.local',
        'name'       => 'my-frontend',
    ];
}

it('scaffold: template fill replaces every {{TOKEN}}', function () {
    $out = wpultra_headless_scaffold_fill('a {{ENDPOINT}} b {{SITE_TITLE}} c {{NAME}}', hl_ctx());
    assert_eq('a http://wp-connector.local/graphql b wp-connector c my-frontend', $out);
});

it('scaffold: unknown framework → error string', function () {
    assert_true(is_string(wpultra_headless_scaffold_manifest('svelte', hl_ctx())), 'svelte rejected');
});

it('scaffold: next manifest has the core files, unique relative paths', function () {
    $files = wpultra_headless_scaffold_manifest('next', hl_ctx());
    assert_true(is_array($files), 'manifest is array');
    $paths = array_column($files, 'path');
    assert_eq(count($paths), count(array_unique($paths)), 'unique');
    foreach ($paths as $p) { assert_true($p[0] !== '/' && !str_contains($p, '..'), "relative safe: $p"); }
    foreach (['package.json', 'next.config.mjs', 'tsconfig.json', '.env.local.example', 'lib/wp.ts', 'lib/queries.ts', 'app/layout.tsx', 'app/page.tsx', 'app/posts/[slug]/page.tsx', 'app/[slug]/page.tsx', 'app/api/revalidate/route.ts', 'app/sitemap.ts', 'README.md'] as $need) {
        assert_true(in_array($need, $paths, true), "has $need");
    }
});

it('scaffold: next files carry the endpoint + ISR + draft-mode markers', function () {
    $files = wpultra_headless_scaffold_manifest('next', hl_ctx());
    $by = array_column($files, 'content', 'path');
    assert_contains('http://wp-connector.local/graphql', $by['.env.local.example']);
    assert_contains('"next"', $by['package.json']);
    assert_contains('revalidate', $by['lib/wp.ts']);
    assert_contains('generateStaticParams', $by['app/posts/[slug]/page.tsx']);
    assert_contains('draftMode', $by['app/api/revalidate/route.ts'] . $by['lib/wp.ts'] . implode('', $by));
});

it('scaffold: vite manifest has SPA core files with endpoint env', function () {
    $files = wpultra_headless_scaffold_manifest('vite', hl_ctx());
    assert_true(is_array($files), 'manifest is array');
    $by = array_column($files, 'content', 'path');
    foreach (['package.json', 'index.html', 'vite.config.ts', '.env.example', 'src/vite-env.d.ts', 'src/main.tsx', 'src/App.tsx', 'src/lib/wp.ts', 'src/lib/queries.ts', 'src/pages/Home.tsx', 'src/pages/Post.tsx', 'README.md'] as $need) {
        assert_true(isset($by[$need]), "has $need");
    }
    assert_contains('VITE_WORDPRESS_GRAPHQL_ENDPOINT', $by['.env.example']);
    assert_contains('http://wp-connector.local/graphql', $by['.env.example']);
    assert_contains('"vite"', $by['package.json']);
    assert_contains('react-router-dom', $by['package.json']);
});

it('scaffold: no template token survives in any emitted file', function () {
    foreach (['next', 'vite'] as $fw) {
        foreach (wpultra_headless_scaffold_manifest($fw, hl_ctx()) as $f) {
            assert_true(!preg_match('/\{\{[A-Z_]+\}\}/', $f['content']), "no tokens left in $fw:{$f['path']}");
        }
    }
});

/* ============================================================
 * H2.2 — preview config + link builder (pure).
 * ============================================================ */

it('preview shape: defaults disabled with /api/preview route', function () {
    foreach ([null, false, [], ''] as $raw) {
        $c = wpultra_headless_preview_shape($raw);
        assert_eq(false, $c['enabled']);
        assert_eq('/api/preview', $c['route']);
        assert_eq('', $c['frontend_url']);
        assert_eq('', $c['secret']);
    }
});

it('preview shape: stored values survive, frontend_url trailing slash trimmed', function () {
    $c = wpultra_headless_preview_shape(['enabled' => true, 'frontend_url' => 'http://localhost:3000/', 'secret' => 's3', 'route' => '/preview']);
    assert_eq(true, $c['enabled']);
    assert_eq('http://localhost:3000', $c['frontend_url']);
    assert_eq('s3', $c['secret']);
    assert_eq('/preview', $c['route']);
});

it('preview validate: bad frontend_url or route rejected with error string', function () {
    assert_true(is_string(wpultra_headless_preview_validate(['frontend_url' => 'not a url'])), 'bad url');
    assert_true(is_string(wpultra_headless_preview_validate(['frontend_url' => 'ftp://x.com'])), 'bad scheme');
    assert_true(is_string(wpultra_headless_preview_validate(['frontend_url' => 'http://localhost:3000', 'route' => 'api/preview'])), 'route needs leading slash');
});

it('preview link: builds the tokened URL with encoded args', function () {
    $cfg = ['enabled' => true, 'frontend_url' => 'http://localhost:3000', 'route' => '/api/preview', 'secret' => 's3&x'];
    $url = wpultra_headless_preview_link($cfg, ['id' => 12, 'slug' => 'hello world', 'type' => 'post', 'status' => 'draft']);
    assert_eq('http://localhost:3000/api/preview?secret=s3%26x&id=12&slug=hello+world&type=post&status=draft', $url);
});

it('preview link: disabled or unconfigured → empty string', function () {
    assert_eq('', wpultra_headless_preview_link(wpultra_headless_preview_shape([]), ['id' => 1, 'slug' => 'a', 'type' => 'post', 'status' => 'draft']));
    assert_eq('', wpultra_headless_preview_link(['enabled' => true, 'frontend_url' => '', 'route' => '/api/preview', 'secret' => 's'], ['id' => 1, 'slug' => 'a', 'type' => 'post', 'status' => 'draft']));
});

it('preview frontend manifest: next files carry draftMode + DATABASE_ID preview query', function () {
    $files = wpultra_headless_preview_manifest('next', ['frontend_url' => 'http://localhost:3000', 'route' => '/api/preview']);
    $by = array_column($files, 'content', 'path');
    assert_true(isset($by['app/api/preview/route.ts']), 'preview route');
    assert_true(isset($by['app/api/exit-preview/route.ts']), 'exit route');
    assert_true(isset($by['app/preview/[id]/page.tsx']), 'preview page');
    assert_contains('draftMode', $by['app/api/preview/route.ts']);
    assert_contains('WORDPRESS_PREVIEW_SECRET', $by['app/api/preview/route.ts']);
    assert_contains('DATABASE_ID', $by['app/preview/[id]/page.tsx']);
    assert_contains('asPreview', $by['app/preview/[id]/page.tsx']);
    assert_contains('WORDPRESS_AUTH', $by['app/preview/[id]/page.tsx']);
});

it('preview frontend manifest: vite gets the guarded-route recipe', function () {
    $files = wpultra_headless_preview_manifest('vite', ['frontend_url' => 'http://localhost:5173', 'route' => '/preview']);
    $by = array_column($files, 'content', 'path');
    assert_true(isset($by['src/pages/Preview.tsx']), 'preview page');
    assert_contains('asPreview', $by['src/pages/Preview.tsx']);
});

/* ============================================================
 * H2.3 — auth helpers (pure).
 * ============================================================ */

it('basic header: user:password base64d, app-password display spaces stripped', function () {
    assert_eq('Basic ' . base64_encode('admin:abcd1234efgh5678'), wpultra_headless_basic_header('admin', 'abcd 1234 efgh 5678'));
    assert_eq('Basic ' . base64_encode('bob:secret'), wpultra_headless_basic_header('bob', 'secret'));
});

it('auth modes: jwt ready only with plugin + secret; app-passwords when available', function () {
    $d_jwt = hl_detected(['wp-graphql' => '2.0.0', 'wpgraphql-jwt' => '0.7.0']);
    $m = wpultra_headless_auth_modes($d_jwt, true, true);
    $by = array_column($m, 'ready', 'mode');
    assert_eq(true, $by['jwt']);
    assert_eq(true, $by['application-passwords']);

    $m2 = wpultra_headless_auth_modes($d_jwt, false, true);
    $by2 = array_column($m2, 'ready', 'mode');
    assert_eq(false, $by2['jwt'], 'no secret → jwt not ready');

    $m3 = wpultra_headless_auth_modes(hl_detected(['wp-graphql' => '2.0.0']), true, false);
    $by3 = array_column($m3, 'ready', 'mode');
    assert_eq(false, $by3['jwt'], 'no plugin → jwt not ready');
    assert_eq(false, $by3['application-passwords']);
});

it('auth modes: every entry carries a how-to string', function () {
    foreach (wpultra_headless_auth_modes(hl_detected(), false, true) as $m) {
        assert_true(is_string($m['how']) && $m['how'] !== '', "how for {$m['mode']}");
    }
});

/* ============================================================
 * H2.4 — revalidate bridge (pure; trigger defs validated against
 * the real triggers engine).
 * ============================================================ */

require __DIR__ . '/../wp-ultra-mcp/includes/triggers/engine.php';

it('reval shape: defaults disabled, no endpoint, empty trigger ids', function () {
    foreach ([null, [], false] as $raw) {
        $c = wpultra_headless_reval_shape($raw);
        assert_eq(false, $c['enabled']);
        assert_eq('', $c['endpoint']);
        assert_eq('', $c['secret']);
        assert_eq([], $c['trigger_ids']);
    }
});

it('reval validate: endpoint must be a full http(s) URL', function () {
    assert_true(is_string(wpultra_headless_reval_validate(['endpoint' => 'localhost:3000/api/revalidate'])), 'needs scheme');
    assert_true(is_string(wpultra_headless_reval_validate(['endpoint' => ''])), 'empty rejected');
    $ok = wpultra_headless_reval_validate(['endpoint' => 'http://localhost:3000/api/revalidate']);
    assert_eq('http://localhost:3000/api/revalidate', $ok['endpoint']);
});

it('reval trigger defs: one webhook def per event, template carries secret + path', function () {
    $defs = wpultra_headless_reval_trigger_defs('http://localhost:3000/api/revalidate', 'sec1', ['post_published', 'post_updated']);
    assert_eq(2, count($defs));
    foreach ($defs as $d) {
        assert_eq('webhook', $d['action_type']);
        assert_eq('http://localhost:3000/api/revalidate', $d['url']);
        assert_eq('sec1', $d['template']['secret']);
        assert_contains('{data.permalink}', $d['template']['path']);
        assert_contains('headless-revalidate', $d['label']);
        assert_eq(true, wpultra_triggers_validate($d), 'accepted by the triggers engine');
    }
    assert_eq(['post_published', 'post_updated'], array_column($defs, 'event'));
});

it('reval allowed host: extracted from the configured endpoint only', function () {
    assert_eq('localhost', wpultra_headless_reval_allowed_host('http://localhost:3000/api/revalidate'));
    assert_eq('front.example.com', wpultra_headless_reval_allowed_host('https://front.example.com/hook'));
    assert_eq('', wpultra_headless_reval_allowed_host(''));
    assert_eq('', wpultra_headless_reval_allowed_host('not a url'));
});

it('reval allowed port: extracted when non-standard, 0 otherwise', function () {
    assert_eq(3000, wpultra_headless_reval_allowed_port('http://localhost:3000/api/revalidate'));
    assert_eq(0, wpultra_headless_reval_allowed_port('https://front.example.com/hook'));
    assert_eq(0, wpultra_headless_reval_allowed_port(''));
});

it('reval trigger defs: unknown events are dropped', function () {
    $defs = wpultra_headless_reval_trigger_defs('http://x.test/hook', 's', ['post_published', 'bogus_event']);
    assert_eq(['post_published'], array_column($defs, 'event'));
});

/* ============================================================
 * H3.1 — build-site: content model → route plan, tokens → CSS,
 * per-CPT pages (pure).
 * ============================================================ */

it('build model: only GraphQL-exposed custom types survive; built-ins and unexposed dropped', function () {
    $rows = [
        ['slug' => 'post', 'builtin' => true, 'single' => 'post', 'plural' => 'posts'],
        ['slug' => 'page', 'builtin' => true, 'single' => 'page', 'plural' => 'pages'],
        ['slug' => 'wpultra_listing', 'builtin' => false, 'single' => 'wpultraListing', 'plural' => 'wpultraListings'],
        ['slug' => 'wpultra_job', 'builtin' => false, 'single' => '', 'plural' => ''],
    ];
    $model = wpultra_headless_build_model($rows);
    assert_eq(1, count($model));
    assert_eq('wpultra_listing', $model[0]['slug']);
    assert_eq('wpultraListing', $model[0]['single']);
    assert_eq('wpultraListings', $model[0]['plural']);
    assert_eq('listing', $model[0]['route'], 'route from slug without wpultra_ prefix');
});

it('build model: route strips prefixes and uses kebab-case', function () {
    $rows = [['slug' => 'movie_review', 'builtin' => false, 'single' => 'movieReview', 'plural' => 'movieReviews']];
    assert_eq('movie-review', wpultra_headless_build_model($rows)[0]['route']);
});

it('tokens css: colors + font sizes become :root variables', function () {
    $css = wpultra_headless_tokens_css([
        'colors'    => [['id' => 'primary', 'label' => 'Primary', 'value' => '#0af']],
        'fontSizes' => [['id' => 'large', 'label' => 'Large', 'value' => '2rem']],
    ]);
    assert_contains(':root', $css);
    assert_contains('--wp-color-primary: #0af;', $css);
    assert_contains('--wp-font-size-large: 2rem;', $css);
});

it('cpt files: archive + single pages wired to the CPT graphql names', function () {
    $files = wpultra_headless_build_cpt_files(['slug' => 'wpultra_listing', 'single' => 'wpultraListing', 'plural' => 'wpultraListings', 'route' => 'listing']);
    $by = array_column($files, 'content', 'path');
    assert_true(isset($by['app/listing/page.tsx']), 'archive page');
    assert_true(isset($by['app/listing/[slug]/page.tsx']), 'single page');
    assert_contains('wpultraListings', $by['app/listing/page.tsx']);
    assert_contains('generateStaticParams', $by['app/listing/[slug]/page.tsx']);
    assert_contains('idType: SLUG', $by['app/listing/[slug]/page.tsx']);
});

it('build manifest: search + blog + tokens css + per-CPT files, unique paths', function () {
    $model = [['slug' => 'wpultra_listing', 'single' => 'wpultraListing', 'plural' => 'wpultraListings', 'route' => 'listing']];
    $files = wpultra_headless_build_manifest($model, ['colors' => [], 'fontSizes' => []]);
    $paths = array_column($files, 'path');
    assert_eq(count($paths), count(array_unique($paths)), 'unique');
    foreach (['app/search/page.tsx', 'app/blog/page.tsx', 'app/wp-tokens.css', 'app/listing/page.tsx', 'app/listing/[slug]/page.tsx'] as $need) {
        assert_true(in_array($need, $paths, true), "has $need");
    }
    $by = array_column($files, 'content', 'path');
    assert_contains('search', $by['app/search/page.tsx']);
});

/* ============================================================
 * H3.2 — WooGraphQL storefront manifest (pure).
 * ============================================================ */

it('woo manifest: storefront files present with unique safe paths', function () {
    $files = wpultra_headless_woo_manifest(hl_ctx());
    $paths = array_column($files, 'path');
    assert_eq(count($paths), count(array_unique($paths)), 'unique');
    foreach (['lib/woo.ts', 'app/shop/page.tsx', 'app/shop/[slug]/page.tsx', 'app/cart/page.tsx', 'components/AddToCart.tsx'] as $need) {
        assert_true(in_array($need, $paths, true), "has $need");
    }
});

it('woo manifest: session header handling + cart/checkout mutations wired', function () {
    $by = array_column(wpultra_headless_woo_manifest(hl_ctx()), 'content', 'path');
    assert_contains('woocommerce-session', $by['lib/woo.ts']);
    assert_contains('addToCart', $by['lib/woo.ts']);
    assert_contains('checkout', $by['lib/woo.ts']);
    assert_contains("'use client'", $by['components/AddToCart.tsx']);
    assert_contains('products', $by['app/shop/page.tsx']);
    assert_contains('generateStaticParams', $by['app/shop/[slug]/page.tsx']);
    assert_contains("'use client'", $by['app/cart/page.tsx']);
});

it('woo manifest: endpoint token filled, none left over', function () {
    foreach (wpultra_headless_woo_manifest(hl_ctx()) as $f) {
        assert_true(!preg_match('/\{\{[A-Z_]+\}\}/', $f['content']), "no tokens in {$f['path']}");
    }
    $by = array_column(wpultra_headless_woo_manifest(hl_ctx()), 'content', 'path');
    assert_contains('http://wp-connector.local/graphql', $by['lib/woo.ts']);
});

/* ============================================================
 * H3.3 — headless SEO: host rewrite, wpSeo shape, frontend manifest (pure).
 * ============================================================ */

it('rewrite host: WP origin swapped for the frontend, path + query kept', function () {
    assert_eq('http://localhost:3000/hello/', wpultra_headless_rewrite_host('http://wp-connector.local/hello/', 'http://wp-connector.local', 'http://localhost:3000'));
    assert_eq('https://front.example.com/p/?x=1', wpultra_headless_rewrite_host('http://wp.example.com/p/?x=1', 'http://wp.example.com', 'https://front.example.com'));
    // other hosts + empty frontend untouched
    assert_eq('http://elsewhere.com/a', wpultra_headless_rewrite_host('http://elsewhere.com/a', 'http://wp.example.com', 'https://front.example.com'));
    assert_eq('http://wp.example.com/a', wpultra_headless_rewrite_host('http://wp.example.com/a', 'http://wp.example.com', ''));
});

it('wpSeo shape: driver meta camelCased, canonical falls back to the (rewritten) permalink', function () {
    $meta = [
        'title' => 'SEO Title', 'description' => 'Desc', 'canonical' => '',
        'og_title' => 'OG', 'og_description' => '', 'og_image' => 'http://wp.test/img.png',
        'twitter_title' => '', 'twitter_description' => '',
        'robots_noindex' => true, 'robots_nofollow' => false, 'mode' => 'native',
    ];
    $s = wpultra_headless_seo_shape($meta, 'http://wp.test/hello/', 'http://wp.test', 'http://localhost:3000');
    assert_eq('SEO Title', $s['title']);
    assert_eq('http://localhost:3000/hello/', $s['canonical'], 'permalink fallback, host rewritten');
    assert_eq('OG', $s['ogTitle']);
    assert_eq('http://wp.test/img.png', $s['ogImage'], 'media stays on the WP host');
    assert_eq(true, $s['noindex']);
    assert_eq(false, $s['nofollow']);
    assert_eq('native', $s['mode']);
});

it('wpSeo shape: explicit canonical on the WP host is rewritten too', function () {
    $meta = ['canonical' => 'http://wp.test/custom-canonical/', 'robots_noindex' => false, 'robots_nofollow' => false, 'mode' => 'yoast'];
    $s = wpultra_headless_seo_shape($meta, 'http://wp.test/hello/', 'http://wp.test', 'https://front.x');
    assert_eq('https://front.x/custom-canonical/', $s['canonical']);
});

it('seo frontend manifest: lib/seo.ts mapper + robots.ts + wpSeo-aware post page', function () {
    $files = wpultra_headless_seo_manifest();
    $by = array_column($files, 'content', 'path');
    assert_true(isset($by['lib/seo.ts']), 'lib/seo.ts');
    assert_true(isset($by['app/robots.ts']), 'robots');
    assert_true(isset($by['app/posts/[slug]/page.tsx']), 'post page updated');
    assert_contains('wpSeo', $by['lib/seo.ts']);
    assert_contains('wpSeoToMetadata', $by['lib/seo.ts']);
    assert_contains('wpSeo', $by['app/posts/[slug]/page.tsx']);
    assert_contains('sitemap', $by['app/robots.ts']);
});

/* ============================================================
 * H3.4 — deploy config generator (pure).
 * ============================================================ */

it('deploy files: netlify.toml with the next plugin; vercel gets vercel.json; unknown → error', function () {
    $n = wpultra_headless_deploy_files('netlify');
    $bn = array_column($n, 'content', 'path');
    assert_true(isset($bn['netlify.toml']), 'netlify.toml');
    assert_contains('@netlify/plugin-nextjs', $bn['netlify.toml']);
    $v = wpultra_headless_deploy_files('vercel');
    $bv = array_column($v, 'content', 'path');
    assert_true(isset($bv['vercel.json']), 'vercel.json');
    assert_true(is_string(wpultra_headless_deploy_files('cloudflare')), 'unknown provider → error string');
});

it('deploy env: endpoint + secrets + placeholders with notes', function () {
    $env = wpultra_headless_deploy_env([
        'endpoint' => 'http://wp.test/graphql',
        'preview_secret' => 'psec', 'revalidate_secret' => 'rsec',
    ]);
    $by = array_column($env, 'value', 'key');
    assert_eq('http://wp.test/graphql', $by['NEXT_PUBLIC_WORDPRESS_GRAPHQL_ENDPOINT']);
    assert_eq('psec', $by['WORDPRESS_PREVIEW_SECRET']);
    assert_eq('rsec', $by['REVALIDATE_SECRET']);
    assert_true(isset($by['NEXT_PUBLIC_SITE_URL']), 'site url placeholder');
    assert_true(isset($by['WORDPRESS_AUTH']), 'auth placeholder');
    foreach ($env as $e) { assert_true($e['note'] !== '', "note for {$e['key']}"); }
});

it('deploy build-hook trigger def validates against the triggers engine', function () {
    $def = wpultra_headless_deploy_trigger_def('https://api.vercel.com/v1/integrations/deploy/prj_x/hook_y');
    assert_eq('post_published', $def['event']);
    assert_eq('webhook', $def['action_type']);
    assert_contains('headless-deploy', $def['label']);
    assert_eq(true, wpultra_triggers_validate($def));
});

/* ============================================================
 * H3.5 — persisted queries (pure helpers).
 * ============================================================ */

it('pq alias: explicit alias sanitized; falls back to slugified name', function () {
    assert_eq('recent-posts', wpultra_headless_pq_alias('Recent Posts!', ''));
    assert_eq('home-data', wpultra_headless_pq_alias('', 'Home Data'));
    assert_eq('my_alias-9', wpultra_headless_pq_alias('My_Alias 9', 'ignored'));
    assert_eq('', wpultra_headless_pq_alias('', ''));
});

it('pq validate: good input normalized; bad grant / empty query rejected', function () {
    $ok = wpultra_headless_pq_validate(['query' => '{ posts { nodes { id } } }', 'name' => 'Posts', 'grant' => 'allow', 'max_age' => 120]);
    assert_true(is_array($ok), 'valid input accepted');
    assert_eq('allow', $ok['grant']);
    assert_eq(120, $ok['max_age']);
    assert_eq('posts', $ok['alias']);
    assert_true(is_string(wpultra_headless_pq_validate(['query' => ''])), 'empty query rejected');
    assert_true(is_string(wpultra_headless_pq_validate(['query' => '{ a }', 'grant' => 'maybe'])), 'bad grant rejected');
    assert_true(is_string(wpultra_headless_pq_validate(['query' => '{ a }', 'name' => '', 'alias' => ''])), 'needs a name or alias');
});

it('pq validate: defaults — grant default, max_age 0, mutation flagged in note', function () {
    $ok = wpultra_headless_pq_validate(['query' => 'mutation { x }', 'name' => 'Mut']);
    assert_true(is_array($ok));
    assert_eq('default', $ok['grant']);
    assert_eq(0, $ok['max_age']);
    assert_eq('mutation', $ok['operation']);
});

run_tests();
