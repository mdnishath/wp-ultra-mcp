<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — SEO over GraphQL (Roadmap-3, H3.3).
 *
 * Registers a `wpSeo` field on every ContentNode that resolves through the
 * plugin's own SEO driver — so the SAME field works whether the site runs
 * Yoast, Rank Math, or the native WP-Ultra meta (no WPGraphQL-SEO addon
 * needed). Canonicals on the WP host are rewritten to the frontend origin
 * (the headless-preview frontend_url). The frontend manifest maps wpSeo into
 * Next.js' metadata API and adds a headless-aware robots.ts.
 */

/** Swap the WP origin for the frontend origin, keeping path + query. Pure. */
function wpultra_headless_rewrite_host(string $url, string $wp_home, string $frontend): string {
    if ($url === '' || $frontend === '' || $wp_home === '') { return $url; }
    $wp_home  = untrailingslashit($wp_home);
    $frontend = untrailingslashit($frontend);
    if (stripos($url, $wp_home) === 0) {
        return $frontend . substr($url, strlen($wp_home));
    }
    return $url;
}

/**
 * Driver meta → the GraphQL wpSeo shape. Pure. Canonical falls back to the
 * permalink; WP-host canonicals move to the frontend origin (media URLs like
 * ogImage stay on WP, where the files actually live).
 * @param array<string,mixed> $meta  wpultra_seo_get_meta() output
 */
function wpultra_headless_seo_shape(array $meta, string $permalink, string $wp_home, string $frontend): array {
    $canonical = (string) ($meta['canonical'] ?? '');
    if ($canonical === '') { $canonical = $permalink; }
    return [
        'title'              => (string) ($meta['title'] ?? ''),
        'description'        => (string) ($meta['description'] ?? ''),
        'canonical'          => wpultra_headless_rewrite_host($canonical, $wp_home, $frontend),
        'ogTitle'            => (string) ($meta['og_title'] ?? ''),
        'ogDescription'      => (string) ($meta['og_description'] ?? ''),
        'ogImage'            => (string) ($meta['og_image'] ?? ''),
        'twitterTitle'       => (string) ($meta['twitter_title'] ?? ''),
        'twitterDescription' => (string) ($meta['twitter_description'] ?? ''),
        'noindex'            => !empty($meta['robots_noindex']),
        'nofollow'           => !empty($meta['robots_nofollow']),
        'mode'               => (string) ($meta['mode'] ?? ''),
    ];
}

/**
 * Frontend files: the wpSeo fragment + Next metadata mapper, a headless
 * robots.ts, and the posts single page upgraded to use wpSeo. Pure.
 * @return array<int,array{path:string,content:string}>
 */
function wpultra_headless_seo_manifest(): array {
    $files = [];

    $files[] = ['path' => 'lib/seo.ts', 'content' => <<<'EOT'
import type { Metadata } from 'next';

/** Add to any ContentNode query: `wpSeo { ...WPSEO_FIELDS }` (plain field list). */
export const WPSEO_FIELDS = /* GraphQL */ `
  wpSeo {
    title description canonical
    ogTitle ogDescription ogImage
    twitterTitle twitterDescription
    noindex nofollow
  }
`;

export type WPSeo = {
  title: string; description: string; canonical: string;
  ogTitle: string; ogDescription: string; ogImage: string;
  twitterTitle: string; twitterDescription: string;
  noindex: boolean; nofollow: boolean;
};

/** Map the wpSeo field into Next.js' metadata API. Pass fallbacks from the node itself. */
export function wpSeoToMetadata(seo: WPSeo | null | undefined, fallback: { title?: string; description?: string } = {}): Metadata {
  const title = seo?.title || fallback.title || undefined;
  const description = seo?.description || fallback.description || undefined;
  return {
    title,
    description,
    alternates: seo?.canonical ? { canonical: seo.canonical } : undefined,
    openGraph: {
      title: seo?.ogTitle || title,
      description: seo?.ogDescription || description,
      images: seo?.ogImage ? [{ url: seo.ogImage }] : undefined,
    },
    twitter: {
      title: seo?.twitterTitle || seo?.ogTitle || title,
      description: seo?.twitterDescription || seo?.ogDescription || description,
    },
    robots: seo?.noindex || seo?.nofollow
      ? { index: !seo.noindex, follow: !seo.nofollow }
      : undefined,
  };
}
EOT];

    $files[] = ['path' => 'app/robots.ts', 'content' => <<<'EOT'
import type { MetadataRoute } from 'next';

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000';

/**
 * Headless robots strategy: the FRONTEND is the canonical site — point
 * crawlers at its sitemap and keep preview/api paths out of the index.
 * (On the WordPress side, keep the WP sitemap for editors only or noindex
 * the WP host entirely via seo-manage-robots.)
 */
export default function robots(): MetadataRoute.Robots {
  return {
    rules: [{ userAgent: '*', allow: '/', disallow: ['/api/', '/preview/'] }],
    sitemap: `${SITE}/sitemap.xml`,
  };
}
EOT];

    $files[] = ['path' => 'app/posts/[slug]/page.tsx', 'content' => <<<'EOT'
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { draftMode } from 'next/headers';
import { fetchGraphQL } from '@/lib/wp';
import { QUERY_ALL_POST_SLUGS } from '@/lib/queries';
import { WPSEO_FIELDS, wpSeoToMetadata, type WPSeo } from '@/lib/seo';

const QUERY_POST_SEO = /* GraphQL */ `
  query PostBySlugWithSeo($slug: ID!, $asPreview: Boolean = false) {
    post(id: $slug, idType: SLUG, asPreview: $asPreview) {
      id databaseId slug title content excerpt date modifiedGmt
      featuredImage { node { sourceUrl altText } }
      ${WPSEO_FIELDS}
    }
  }
`;

type SlugsData = { posts: { nodes: { slug: string }[] } };
type PostData = {
  post: { title: string; content: string; excerpt: string; date: string; wpSeo: WPSeo | null } | null;
};

export async function generateStaticParams() {
  const data = await fetchGraphQL<SlugsData>(QUERY_ALL_POST_SLUGS, { tags: ['posts'] });
  return data.posts.nodes.map((p) => ({ slug: p.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await fetchGraphQL<PostData>(QUERY_POST_SEO, { variables: { slug }, tags: ['posts'] });
  if (!data.post) return {};
  return wpSeoToMetadata(data.post.wpSeo, {
    title: data.post.title,
    description: data.post.excerpt.replace(/<[^>]+>/g, '').slice(0, 160),
  });
}

export default async function PostPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const { isEnabled: preview } = await draftMode();
  const data = await fetchGraphQL<PostData>(QUERY_POST_SEO, {
    variables: { slug, asPreview: preview },
    revalidate: preview ? 0 : 60,
    tags: ['posts'],
  });
  if (!data.post) notFound();
  return (
    <article>
      <h1>{data.post.title}</h1>
      <time dateTime={data.post.date} style={{ color: '#666' }}>
        {new Date(data.post.date).toLocaleDateString()}
      </time>
      <div dangerouslySetInnerHTML={{ __html: data.post.content }} />
    </article>
  );
}
EOT];

    return $files;
}

/**
 * Runtime boot: register the WPUltraSeo type + the wpSeo field on ContentNode.
 * Called from wpultra_headless_boot(); no-op until WPGraphQL is active.
 */
function wpultra_headless_seo_boot(): void {
    add_action('graphql_register_types', function () {
        if (!function_exists('register_graphql_object_type') || !function_exists('register_graphql_field')) { return; }
        register_graphql_object_type('WPUltraSeo', [
            'description' => 'SEO meta resolved through WP-Ultra\'s driver (Yoast / Rank Math / native — same field either way).',
            'fields' => [
                'title'              => ['type' => 'String'],
                'description'        => ['type' => 'String'],
                'canonical'          => ['type' => 'String'],
                'ogTitle'            => ['type' => 'String'],
                'ogDescription'      => ['type' => 'String'],
                'ogImage'            => ['type' => 'String'],
                'twitterTitle'       => ['type' => 'String'],
                'twitterDescription' => ['type' => 'String'],
                'noindex'            => ['type' => 'Boolean'],
                'nofollow'           => ['type' => 'Boolean'],
                'mode'               => ['type' => 'String'],
            ],
        ]);
        register_graphql_field('ContentNode', 'wpSeo', [
            'type'        => 'WPUltraSeo',
            'description' => 'SEO meta for this node (canonicals rewritten to the headless frontend when configured).',
            'resolve'     => static function ($node) {
                $post_id = 0;
                if (is_object($node)) {
                    $post_id = (int) ($node->databaseId ?? $node->ID ?? 0);
                }
                if ($post_id <= 0 || !function_exists('wpultra_seo_get_meta')) { return null; }
                $frontend = '';
                if (function_exists('wpultra_headless_preview_config')) {
                    $frontend = (string) wpultra_headless_preview_config()['frontend_url'];
                }
                return wpultra_headless_seo_shape(
                    wpultra_seo_get_meta($post_id),
                    (string) get_permalink($post_id),
                    (string) home_url(),
                    $frontend
                );
            },
        ]);
    });
}
