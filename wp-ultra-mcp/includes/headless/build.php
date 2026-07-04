<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — full-site build (Roadmap-3, H3.1).
 *
 * Extends headless-scaffold: reads the LIVE content model (GraphQL-exposed
 * custom post types), and emits per-CPT archive + single routes, a paginated
 * blog index, a search page, and the theme design tokens as CSS variables.
 * Everything here is pure; the ability feeds it live WP data.
 */

/**
 * Route plan from prepared post-type rows. Pure. Only non-builtin types with
 * GraphQL names participate (built-ins are covered by the base scaffold; a
 * type without graphql names is invisible to the frontend anyway).
 * @param array<int,array{slug:string,builtin:bool,single:string,plural:string}> $rows
 * @return array<int,array{slug:string,single:string,plural:string,route:string}>
 */
function wpultra_headless_build_model(array $rows): array {
    $model = [];
    foreach ($rows as $r) {
        if (!empty($r['builtin'])) { continue; }
        $single = (string) ($r['single'] ?? '');
        $plural = (string) ($r['plural'] ?? '');
        if ($single === '' || $plural === '') { continue; }
        $route = (string) ($r['slug'] ?? '');
        $route = (string) preg_replace('/^(wpultra_|wp_)/', '', $route);
        $route = strtolower(str_replace('_', '-', trim($route)));
        $model[] = ['slug' => (string) $r['slug'], 'single' => $single, 'plural' => $plural, 'route' => $route];
    }
    return $model;
}

/** Theme tokens → CSS custom properties. Pure over the shape_tokens() output. */
function wpultra_headless_tokens_css(array $tokens): string {
    $lines = [":root {"];
    foreach ((array) ($tokens['colors'] ?? []) as $t) {
        if (($t['id'] ?? '') !== '' && ($t['value'] ?? '') !== '') {
            $lines[] = "  --wp-color-{$t['id']}: {$t['value']};";
        }
    }
    foreach ((array) ($tokens['fontSizes'] ?? []) as $t) {
        if (($t['id'] ?? '') !== '' && ($t['value'] ?? '') !== '') {
            $lines[] = "  --wp-font-size-{$t['id']}: {$t['value']};";
        }
    }
    $lines[] = "}";
    return implode("\n", $lines) . "\n";
}

/**
 * Archive + single pages for one CPT. Pure. Uses the same fetchGraphQL/@lib
 * conventions as the base scaffold; queries are built from the CPT's GraphQL
 * single/plural names via a contentNodes fallback-free direct field.
 * @param array{slug:string,single:string,plural:string,route:string} $type
 * @return array<int,array{path:string,content:string}>
 */
function wpultra_headless_build_cpt_files(array $type): array {
    $single = $type['single'];
    $plural = $type['plural'];
    $route  = $type['route'];
    $label  = ucfirst($route);

    $archive = <<<EOT
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';

const QUERY = /* GraphQL */ `
  query {$label}Archive(\$first: Int = 50) {
    {$plural}(first: \$first) {
      nodes { id slug title uri }
    }
  }
`;

type Data = { {$plural}: { nodes: { id: string; slug: string; title: string }[] } };

export const metadata = { title: '{$label}' };

export default async function {$label}ArchivePage() {
  const data = await fetchGraphQL<Data>(QUERY, { tags: ['{$route}'] });
  return (
    <>
      <h1>{$label}</h1>
      {data.{$plural}.nodes.map((node) => (
        <article key={node.id} style={{ marginBottom: '1rem' }}>
          <h2>
            <Link href={`/{$route}/\${node.slug}`} style={{ color: 'inherit' }}>{node.title}</Link>
          </h2>
        </article>
      ))}
    </>
  );
}
EOT;

    $single_page = <<<EOT
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchGraphQL } from '@/lib/wp';

const QUERY_SLUGS = /* GraphQL */ `
  query {$label}Slugs(\$first: Int = 100) {
    {$plural}(first: \$first) { nodes { slug } }
  }
`;

const QUERY_ONE = /* GraphQL */ `
  query {$label}BySlug(\$slug: ID!) {
    {$single}(id: \$slug, idType: SLUG) {
      id title
      ... on NodeWithContentEditor { content }
    }
  }
`;

type SlugsData = { {$plural}: { nodes: { slug: string }[] } };
type OneData = { {$single}: { title: string; content?: string } | null };

export async function generateStaticParams() {
  const data = await fetchGraphQL<SlugsData>(QUERY_SLUGS, { tags: ['{$route}'] });
  return data.{$plural}.nodes.map((n) => ({ slug: n.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await fetchGraphQL<OneData>(QUERY_ONE, { variables: { slug }, tags: ['{$route}'] });
  return data.{$single} ? { title: data.{$single}.title } : {};
}

export default async function {$label}SinglePage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const data = await fetchGraphQL<OneData>(QUERY_ONE, { variables: { slug }, tags: ['{$route}'] });
  if (!data.{$single}) notFound();
  return (
    <article>
      <h1>{data.{$single}.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: data.{$single}.content ?? '' }} />
    </article>
  );
}
EOT;

    return [
        ['path' => "app/{$route}/page.tsx", 'content' => $archive],
        ['path' => "app/{$route}/[slug]/page.tsx", 'content' => $single_page],
    ];
}

/**
 * The full build manifest: search + paginated blog index + tokens CSS +
 * per-CPT routes. Pure. (Base starter files come from headless-scaffold.)
 * @param array<int,array{slug:string,single:string,plural:string,route:string}> $model
 * @return array<int,array{path:string,content:string}>
 */
function wpultra_headless_build_manifest(array $model, array $tokens): array {
    $files = [];

    $files[] = ['path' => 'app/wp-tokens.css', 'content' => wpultra_headless_tokens_css($tokens)];

    $files[] = ['path' => 'app/search/page.tsx', 'content' => <<<'EOT'
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';

const QUERY_SEARCH = /* GraphQL */ `
  query Search($term: String!, $first: Int = 20) {
    contentNodes(first: $first, where: { search: $term, contentTypes: [POST, PAGE] }) {
      nodes {
        id uri
        ... on NodeWithTitle { title }
      }
    }
  }
`;

type SearchData = { contentNodes: { nodes: { id: string; uri: string | null; title?: string }[] } };

export const metadata = { title: 'Search' };

export default async function SearchPage({ searchParams }: { searchParams: Promise<{ q?: string }> }) {
  const { q = '' } = await searchParams;
  const results = q
    ? (await fetchGraphQL<SearchData>(QUERY_SEARCH, { variables: { term: q }, revalidate: 0 })).contentNodes.nodes
    : [];
  return (
    <>
      <h1>Search</h1>
      <form action="/search" method="get" style={{ marginBottom: '2rem' }}>
        <input name="q" defaultValue={q} placeholder="Search…" style={{ padding: '0.5rem', width: '60%' }} />
        <button type="submit" style={{ padding: '0.5rem 1rem', marginLeft: '0.5rem' }}>Go</button>
      </form>
      {q && results.length === 0 && <p>No results for “{q}”.</p>}
      {results.map((node) => (
        <p key={node.id}>
          <Link href={node.uri ?? '/'}>{node.title ?? node.uri}</Link>
        </p>
      ))}
    </>
  );
}
EOT];

    $files[] = ['path' => 'app/blog/page.tsx', 'content' => <<<'EOT'
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';

const QUERY_BLOG = /* GraphQL */ `
  query BlogIndex($first: Int = 10, $after: String) {
    posts(first: $first, after: $after, where: { status: PUBLISH }) {
      pageInfo { hasNextPage endCursor }
      nodes { id slug title excerpt date }
    }
  }
`;

type BlogData = {
  posts: {
    pageInfo: { hasNextPage: boolean; endCursor: string | null };
    nodes: { id: string; slug: string; title: string; excerpt: string; date: string }[];
  };
};

export const metadata = { title: 'Blog' };

export default async function BlogPage({ searchParams }: { searchParams: Promise<{ after?: string }> }) {
  const { after } = await searchParams;
  const data = await fetchGraphQL<BlogData>(QUERY_BLOG, {
    variables: { first: 10, after: after ?? null },
    revalidate: after ? 0 : 60,
    tags: ['posts'],
  });
  return (
    <>
      <h1>Blog</h1>
      {data.posts.nodes.map((post) => (
        <article key={post.id} style={{ marginBottom: '2rem' }}>
          <h2>
            <Link href={`/posts/${post.slug}`} style={{ color: 'inherit' }}>{post.title}</Link>
          </h2>
          <time dateTime={post.date} style={{ color: '#666', fontSize: '0.875rem' }}>
            {new Date(post.date).toLocaleDateString()}
          </time>
          <div dangerouslySetInnerHTML={{ __html: post.excerpt }} />
        </article>
      ))}
      {data.posts.pageInfo.hasNextPage && (
        <p>
          <Link href={`/blog?after=${data.posts.pageInfo.endCursor}`}>Older posts →</Link>
        </p>
      )}
    </>
  );
}
EOT];

    foreach ($model as $type) {
        foreach (wpultra_headless_build_cpt_files($type) as $f) { $files[] = $f; }
    }
    return $files;
}
