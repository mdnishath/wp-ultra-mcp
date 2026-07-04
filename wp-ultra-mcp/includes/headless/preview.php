<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — draft preview bridge (Roadmap-3, H2.2).
 *
 * The #1 headless pain: the WP editor's Preview button points at the WP theme,
 * not the frontend. When enabled, the preview_post_link filter rewrites it to
 * {frontend_url}{route}?secret&id&slug&type&status — the frontend route
 * validates the secret, enables draft mode, and fetches the draft by
 * DATABASE_ID + asPreview (works for never-published drafts with no slug).
 */

/**
 * Shape the stored preview option. Pure.
 * @param mixed $raw
 * @return array{enabled:bool,frontend_url:string,route:string,secret:string}
 */
function wpultra_headless_preview_shape($raw): array {
    $out = ['enabled' => false, 'frontend_url' => '', 'route' => '/api/preview', 'secret' => ''];
    if (is_array($raw)) {
        $out['enabled']      = !empty($raw['enabled']);
        $out['frontend_url'] = untrailingslashit((string) ($raw['frontend_url'] ?? ''));
        $out['secret']       = (string) ($raw['secret'] ?? '');
        $route = (string) ($raw['route'] ?? '');
        if ($route !== '') { $out['route'] = $route; }
    }
    return $out;
}

/**
 * Validate enable-input. Pure. Returns normalized [frontend_url, route] or an
 * error string.
 * @return array{frontend_url:string,route:string}|string
 */
function wpultra_headless_preview_validate(array $input) {
    $url = (string) ($input['frontend_url'] ?? '');
    $p = parse_url($url);
    if (!in_array(strtolower((string) ($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host'])) {
        return "Invalid frontend_url '$url' — expected the frontend origin, e.g. http://localhost:3000.";
    }
    $route = (string) ($input['route'] ?? '/api/preview');
    if ($route === '' || $route[0] !== '/') {
        return "Invalid route '$route' — must start with '/', e.g. /api/preview.";
    }
    return ['frontend_url' => untrailingslashit($url), 'route' => $route];
}

/**
 * Build the frontend preview URL for a post. Pure. Empty string when the
 * bridge is disabled or incomplete (caller falls back to the WP link).
 * @param array{enabled:bool,frontend_url:string,route:string,secret:string} $cfg
 * @param array{id:int|string,slug:string,type:string,status:string} $post
 */
function wpultra_headless_preview_link(array $cfg, array $post): string {
    if (empty($cfg['enabled']) || (string) ($cfg['frontend_url'] ?? '') === '' || (string) ($cfg['secret'] ?? '') === '') {
        return '';
    }
    $qs = http_build_query([
        'secret' => (string) $cfg['secret'],
        'id'     => (string) ($post['id'] ?? ''),
        'slug'   => (string) ($post['slug'] ?? ''),
        'type'   => (string) ($post['type'] ?? ''),
        'status' => (string) ($post['status'] ?? ''),
    ]);
    return $cfg['frontend_url'] . $cfg['route'] . '?' . $qs;
}

/** The live preview config. */
function wpultra_headless_preview_config(): array {
    return wpultra_headless_preview_shape(function_exists('get_option') ? get_option('wpultra_headless_preview', []) : []);
}

/**
 * Frontend-side files for the preview flow. Pure over framework + cfg.
 * Next.js: draft-mode enable/exit routes + a /preview/[id] page fetching by
 * DATABASE_ID with asPreview (authenticated via WORDPRESS_AUTH). Vite: a
 * guarded /preview route recipe.
 * @return array<int,array{path:string,content:string}>
 */
function wpultra_headless_preview_manifest(string $framework, array $cfg): array {
    if ($framework === 'vite') {
        $preview_tsx = <<<'EOT'
import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { GraphQLClient, gql } from 'graphql-request';

/**
 * Guarded draft-preview route (SPA recipe). WordPress' Preview button opens
 * /preview?secret=…&id=…; the secret must match VITE_WORDPRESS_PREVIEW_SECRET.
 * Draft content needs an authenticated request — set VITE_WORDPRESS_AUTH to
 * "Basic <base64 user:application-password>" in .env.local (dev only: this
 * ships to the browser bundle, so use a low-privilege preview-only user).
 */
const QUERY_PREVIEW = gql`
  query PreviewById($id: ID!) {
    contentNode(id: $id, idType: DATABASE_ID, asPreview: true) {
      ... on NodeWithTitle { title }
      ... on NodeWithContentEditor { content }
    }
  }
`;

type Node = { title?: string; content?: string } | null;

export default function Preview() {
  const [params] = useSearchParams();
  const [node, setNode] = useState<Node>(null);
  const [error, setError] = useState('');
  const secret = params.get('secret') ?? '';
  const id = params.get('id') ?? '';

  useEffect(() => {
    if (secret !== import.meta.env.VITE_WORDPRESS_PREVIEW_SECRET) {
      setError('Invalid preview secret.');
      return;
    }
    const client = new GraphQLClient(import.meta.env.VITE_WORDPRESS_GRAPHQL_ENDPOINT, {
      headers: { Authorization: import.meta.env.VITE_WORDPRESS_AUTH ?? '' },
    });
    client
      .request<{ contentNode: Node }>(QUERY_PREVIEW, { id })
      .then((d) => setNode(d.contentNode))
      .catch((e) => setError(String(e)));
  }, [secret, id]);

  if (error) return <p>{error}</p>;
  if (!node) return <p>Loading draft…</p>;
  return (
    <article>
      <p style={{ background: '#fef3c7', padding: '0.5rem 1rem' }}>Draft preview</p>
      <h1>{node.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: node.content ?? '' }} />
    </article>
  );
}
EOT;
        return [
            ['path' => 'src/pages/Preview.tsx', 'content' => $preview_tsx],
        ];
    }

    $route_ts = <<<'EOT'
import { draftMode } from 'next/headers';
import { redirect } from 'next/navigation';
import { NextRequest } from 'next/server';

/**
 * WordPress' Preview button (wired by WP-Ultra headless-preview) opens
 * /api/preview?secret=…&id=… — validate the shared secret, enable Next.js
 * draft mode, and hand off to the always-fresh /preview/[id] page.
 */
export async function GET(request: NextRequest) {
  const params = request.nextUrl.searchParams;
  if (!process.env.WORDPRESS_PREVIEW_SECRET || params.get('secret') !== process.env.WORDPRESS_PREVIEW_SECRET) {
    return new Response('Invalid preview secret', { status: 401 });
  }
  const id = params.get('id');
  if (!id) {
    return new Response('Missing post id', { status: 400 });
  }
  (await draftMode()).enable();
  redirect(`/preview/${id}`);
}
EOT;

    $exit_ts = <<<'EOT'
import { draftMode } from 'next/headers';
import { redirect } from 'next/navigation';

export async function GET() {
  (await draftMode()).disable();
  redirect('/');
}
EOT;

    $page_tsx = <<<'EOT'
import { notFound } from 'next/navigation';
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';

/**
 * Draft preview by DATABASE_ID (never-published drafts have no slug, so
 * slug routes can't render them). Draft content requires authentication:
 * set WORDPRESS_AUTH="Basic <base64 user:application-password>" in .env.local
 * — it is only used server-side.
 */
const QUERY_PREVIEW = /* GraphQL */ `
  query PreviewById($id: ID!) {
    contentNode(id: $id, idType: DATABASE_ID, asPreview: true) {
      ... on NodeWithTitle { title }
      ... on NodeWithContentEditor { content }
    }
  }
`;

type PreviewData = { contentNode: { title?: string; content?: string } | null };

export const dynamic = 'force-dynamic';

export default async function PreviewPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = await params;
  const data = await fetchGraphQL<PreviewData>(QUERY_PREVIEW, {
    variables: { id },
    revalidate: 0,
    authorization: process.env.WORDPRESS_AUTH,
  });
  if (!data.contentNode) notFound();
  return (
    <article>
      <p style={{ background: '#fef3c7', padding: '0.5rem 1rem' }}>
        Draft preview — <Link href="/api/exit-preview">exit</Link>
      </p>
      <h1>{data.contentNode.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: data.contentNode.content ?? '' }} />
    </article>
  );
}
EOT;

    return [
        ['path' => 'app/api/preview/route.ts', 'content' => $route_ts],
        ['path' => 'app/api/exit-preview/route.ts', 'content' => $exit_ts],
        ['path' => 'app/preview/[id]/page.tsx', 'content' => $page_tsx],
    ];
}

/**
 * Runtime boot: rewrite the editor's Preview link to the frontend. Called from
 * wpultra_headless_boot(); no-op while disabled.
 */
function wpultra_headless_preview_boot(): void {
    add_filter('preview_post_link', function ($link, $post = null) {
        $cfg = wpultra_headless_preview_config();
        if (!$cfg['enabled'] || !is_object($post)) { return $link; }
        $rewritten = wpultra_headless_preview_link($cfg, [
            'id'     => (int) $post->ID,
            'slug'   => (string) $post->post_name,
            'type'   => (string) $post->post_type,
            'status' => (string) $post->post_status,
        ]);
        return $rewritten !== '' ? $rewritten : $link;
    }, 20, 2);
}
