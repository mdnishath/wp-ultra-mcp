<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — frontend scaffold (Roadmap-3, H2.1).
 *
 * The MCP filesystem is jailed to the WP install, so the scaffold RETURNS a
 * file manifest [{path, content}] that the AI (with filesystem access to the
 * frontend repo) writes to disk. Templates are nowdocs with {{TOKEN}} markers;
 * everything here is pure so the manifest is unit-testable.
 */

/** Replace {{TOKEN}} markers from ctx. Pure. */
function wpultra_headless_scaffold_fill(string $tpl, array $ctx): string {
    $map = [
        '{{ENDPOINT}}'   => (string) ($ctx['endpoint'] ?? ''),
        '{{SITE_TITLE}}' => (string) ($ctx['site_title'] ?? ''),
        '{{SITE_URL}}'   => (string) ($ctx['site_url'] ?? ''),
        '{{SITE_HOST}}'  => (string) ($ctx['site_host'] ?? (parse_url((string) ($ctx['site_url'] ?? ''), PHP_URL_HOST) ?: 'localhost')),
        '{{NAME}}'       => (string) ($ctx['name'] ?? 'wp-headless-frontend'),
    ];
    return strtr($tpl, $map);
}

/**
 * Build the file manifest for a framework. Pure.
 * @return array<int,array{path:string,content:string}>|string  manifest, or error string
 */
function wpultra_headless_scaffold_manifest(string $framework, array $ctx) {
    if (!isset($ctx['site_host'])) {
        $ctx['site_host'] = parse_url((string) ($ctx['site_url'] ?? ''), PHP_URL_HOST) ?: 'localhost';
    }
    $templates = $framework === 'next' ? wpultra_headless_scaffold_next()
        : ($framework === 'vite' ? wpultra_headless_scaffold_vite() : null);
    if ($templates === null) {
        return "Unknown framework '$framework' — supported: next (SEO/SSG/ISR, recommended for content sites), vite (React SPA for app-like frontends).";
    }
    $files = [];
    foreach ($templates as $path => $content) {
        $files[] = ['path' => $path, 'content' => wpultra_headless_scaffold_fill($content, $ctx)];
    }
    return $files;
}

/** Next.js (App Router + TS + SSG/ISR + draft mode + metadata + sitemap) templates. */
function wpultra_headless_scaffold_next(): array {
    $files = [];

    $files['package.json'] = <<<'EOT'
{
  "name": "{{NAME}}",
  "private": true,
  "scripts": {
    "dev": "next dev",
    "build": "next build",
    "start": "next start"
  },
  "dependencies": {
    "next": "^15.3.0",
    "react": "^19.0.0",
    "react-dom": "^19.0.0"
  },
  "devDependencies": {
    "@types/node": "^22.0.0",
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "typescript": "^5.6.0"
  }
}
EOT;

    $files['next.config.mjs'] = <<<'EOT'
/** @type {import('next').NextConfig} */
const nextConfig = {
  images: {
    remotePatterns: [
      { protocol: 'http', hostname: '{{SITE_HOST}}' },
      { protocol: 'https', hostname: '{{SITE_HOST}}' },
    ],
  },
};

export default nextConfig;
EOT;

    $files['tsconfig.json'] = <<<'EOT'
{
  "compilerOptions": {
    "target": "ES2022",
    "lib": ["dom", "dom.iterable", "esnext"],
    "allowJs": true,
    "skipLibCheck": true,
    "strict": true,
    "noEmit": true,
    "esModuleInterop": true,
    "module": "esnext",
    "moduleResolution": "bundler",
    "resolveJsonModule": true,
    "isolatedModules": true,
    "jsx": "preserve",
    "incremental": true,
    "plugins": [{ "name": "next" }],
    "paths": { "@/*": ["./*"] }
  },
  "include": ["next-env.d.ts", "**/*.ts", "**/*.tsx", ".next/types/**/*.ts"],
  "exclude": ["node_modules"]
}
EOT;

    $files['.env.local.example'] = <<<'EOT'
# WordPress GraphQL endpoint (public queries)
NEXT_PUBLIC_WORDPRESS_GRAPHQL_ENDPOINT={{ENDPOINT}}

# Shared secret for the /api/revalidate endpoint (WP-Ultra headless-revalidate posts here)
REVALIDATE_SECRET=change-me

# Shared secret for draft preview (WP-Ultra headless-preview wires the WP Preview button here)
WORDPRESS_PREVIEW_SECRET=change-me
EOT;

    $files['lib/wp.ts'] = <<<'EOT'
const ENDPOINT = process.env.NEXT_PUBLIC_WORDPRESS_GRAPHQL_ENDPOINT ?? '{{ENDPOINT}}';

type FetchOptions = {
  variables?: Record<string, unknown>;
  /** ISR window in seconds (default 60). Use 0 for always-fresh (draft preview). */
  revalidate?: number | false;
  /** Cache tags so /api/revalidate can invalidate precisely. */
  tags?: string[];
  /** Bearer token or Basic auth for authenticated (draft) queries. */
  authorization?: string;
};

export async function fetchGraphQL<T = unknown>(query: string, options: FetchOptions = {}): Promise<T> {
  const { variables, revalidate = 60, tags, authorization } = options;
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      ...(authorization ? { Authorization: authorization } : {}),
    },
    body: JSON.stringify({ query, variables }),
    next: { revalidate, ...(tags ? { tags } : {}) },
  });
  if (!res.ok) {
    throw new Error(`WordPress GraphQL responded ${res.status}`);
  }
  const json = await res.json();
  if (json.errors?.length) {
    throw new Error(`GraphQL error: ${json.errors[0].message}`);
  }
  return json.data as T;
}
EOT;

    $files['lib/queries.ts'] = <<<'EOT'
export const QUERY_SETTINGS = /* GraphQL */ `
  query Settings {
    generalSettings { title description url }
  }
`;

export const QUERY_PRIMARY_MENU = /* GraphQL */ `
  query PrimaryMenu {
    menus(first: 1) {
      nodes {
        name
        menuItems(first: 50) {
          nodes { id parentId label uri target }
        }
      }
    }
  }
`;

export const QUERY_RECENT_POSTS = /* GraphQL */ `
  query RecentPosts($first: Int = 10) {
    posts(first: $first, where: { status: PUBLISH }) {
      nodes { id slug title excerpt date featuredImage { node { sourceUrl altText } } }
    }
  }
`;

export const QUERY_ALL_POST_SLUGS = /* GraphQL */ `
  query AllPostSlugs($first: Int = 100) {
    posts(first: $first, where: { status: PUBLISH }) {
      nodes { slug modifiedGmt }
    }
  }
`;

export const QUERY_POST_BY_SLUG = /* GraphQL */ `
  query PostBySlug($slug: ID!, $asPreview: Boolean = false) {
    post(id: $slug, idType: SLUG, asPreview: $asPreview) {
      id databaseId slug title content excerpt date modifiedGmt
      featuredImage { node { sourceUrl altText } }
      categories { nodes { name uri } }
    }
  }
`;

export const QUERY_ALL_PAGE_URIS = /* GraphQL */ `
  query AllPageUris($first: Int = 100) {
    pages(first: $first, where: { status: PUBLISH }) {
      nodes { uri modifiedGmt }
    }
  }
`;

export const QUERY_PAGE_BY_URI = /* GraphQL */ `
  query PageByUri($uri: ID!, $asPreview: Boolean = false) {
    page(id: $uri, idType: URI, asPreview: $asPreview) {
      id databaseId uri title content modifiedGmt
    }
  }
`;
EOT;

    $files['app/layout.tsx'] = <<<'EOT'
import type { Metadata } from 'next';
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';
import { QUERY_SETTINGS, QUERY_PRIMARY_MENU } from '@/lib/queries';

type Settings = { generalSettings: { title: string; description: string } };
type MenuData = {
  menus: { nodes: { menuItems: { nodes: { id: string; label: string; uri: string | null }[] } }[] };
};

export async function generateMetadata(): Promise<Metadata> {
  const data = await fetchGraphQL<Settings>(QUERY_SETTINGS, { tags: ['settings'] });
  return {
    title: { default: data.generalSettings.title, template: `%s | ${data.generalSettings.title}` },
    description: data.generalSettings.description,
  };
}

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const [settings, menu] = await Promise.all([
    fetchGraphQL<Settings>(QUERY_SETTINGS, { tags: ['settings'] }),
    fetchGraphQL<MenuData>(QUERY_PRIMARY_MENU, { tags: ['menus'] }),
  ]);
  const items = menu.menus.nodes[0]?.menuItems.nodes ?? [];
  return (
    <html lang="en">
      <body style={{ margin: 0, fontFamily: 'system-ui, sans-serif' }}>
        <header style={{ display: 'flex', gap: '1.5rem', alignItems: 'center', padding: '1rem 2rem', borderBottom: '1px solid #eee' }}>
          <Link href="/" style={{ fontWeight: 700, textDecoration: 'none', color: 'inherit' }}>
            {settings.generalSettings.title}
          </Link>
          <nav style={{ display: 'flex', gap: '1rem' }}>
            {items.map((item) => (
              <Link key={item.id} href={item.uri ?? '/'} style={{ textDecoration: 'none', color: 'inherit' }}>
                {item.label}
              </Link>
            ))}
          </nav>
        </header>
        <main style={{ maxWidth: 760, margin: '0 auto', padding: '2rem' }}>{children}</main>
      </body>
    </html>
  );
}
EOT;

    $files['app/page.tsx'] = <<<'EOT'
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';
import { QUERY_RECENT_POSTS } from '@/lib/queries';

type PostsData = {
  posts: { nodes: { id: string; slug: string; title: string; excerpt: string; date: string }[] };
};

export default async function HomePage() {
  const data = await fetchGraphQL<PostsData>(QUERY_RECENT_POSTS, { variables: { first: 10 }, tags: ['posts'] });
  return (
    <>
      <h1>Latest posts</h1>
      {data.posts.nodes.map((post) => (
        <article key={post.id} style={{ marginBottom: '2rem' }}>
          <h2 style={{ marginBottom: '0.25rem' }}>
            <Link href={`/posts/${post.slug}`} style={{ color: 'inherit' }}>{post.title}</Link>
          </h2>
          <time dateTime={post.date} style={{ color: '#666', fontSize: '0.875rem' }}>
            {new Date(post.date).toLocaleDateString()}
          </time>
          <div dangerouslySetInnerHTML={{ __html: post.excerpt }} />
        </article>
      ))}
    </>
  );
}
EOT;

    $files['app/posts/[slug]/page.tsx'] = <<<'EOT'
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { draftMode } from 'next/headers';
import { fetchGraphQL } from '@/lib/wp';
import { QUERY_ALL_POST_SLUGS, QUERY_POST_BY_SLUG } from '@/lib/queries';

type SlugsData = { posts: { nodes: { slug: string }[] } };
type PostData = {
  post: { title: string; content: string; excerpt: string; date: string } | null;
};

export async function generateStaticParams() {
  const data = await fetchGraphQL<SlugsData>(QUERY_ALL_POST_SLUGS, { tags: ['posts'] });
  return data.posts.nodes.map((p) => ({ slug: p.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await fetchGraphQL<PostData>(QUERY_POST_BY_SLUG, { variables: { slug }, tags: ['posts'] });
  if (!data.post) return {};
  return { title: data.post.title, description: data.post.excerpt.replace(/<[^>]+>/g, '').slice(0, 160) };
}

export default async function PostPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const { isEnabled: preview } = await draftMode();
  const data = await fetchGraphQL<PostData>(QUERY_POST_BY_SLUG, {
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
EOT;

    $files['app/[slug]/page.tsx'] = <<<'EOT'
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchGraphQL } from '@/lib/wp';
import { QUERY_ALL_PAGE_URIS, QUERY_PAGE_BY_URI } from '@/lib/queries';

type UrisData = { pages: { nodes: { uri: string }[] } };
type PageData = { page: { title: string; content: string } | null };

export async function generateStaticParams() {
  const data = await fetchGraphQL<UrisData>(QUERY_ALL_PAGE_URIS, { tags: ['pages'] });
  return data.pages.nodes
    .map((p) => p.uri.replaceAll('/', ''))
    .filter((slug) => slug !== '')
    .map((slug) => ({ slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await fetchGraphQL<PageData>(QUERY_PAGE_BY_URI, { variables: { uri: `/${slug}/` }, tags: ['pages'] });
  return data.page ? { title: data.page.title } : {};
}

export default async function WPPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const data = await fetchGraphQL<PageData>(QUERY_PAGE_BY_URI, { variables: { uri: `/${slug}/` }, tags: ['pages'] });
  if (!data.page) notFound();
  return (
    <article>
      <h1>{data.page.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: data.page.content }} />
    </article>
  );
}
EOT;

    $files['app/api/revalidate/route.ts'] = <<<'EOT'
import { NextRequest, NextResponse } from 'next/server';
import { revalidatePath, revalidateTag } from 'next/cache';

/**
 * On-demand ISR endpoint. WP-Ultra's headless-revalidate trigger POSTs here
 * on publish/update: { secret, tags?: string[], paths?: string[] }.
 * With no tags/paths it refreshes the common content tags.
 */
export async function POST(request: NextRequest) {
  const body = await request.json().catch(() => ({}));
  const secret = body.secret ?? request.nextUrl.searchParams.get('secret');
  if (!process.env.REVALIDATE_SECRET || secret !== process.env.REVALIDATE_SECRET) {
    return NextResponse.json({ revalidated: false, error: 'Invalid secret' }, { status: 401 });
  }
  const tags: string[] = Array.isArray(body.tags) && body.tags.length ? body.tags : ['posts', 'pages', 'menus', 'settings'];
  const paths: string[] = Array.isArray(body.paths) ? body.paths : [];
  tags.forEach((tag) => revalidateTag(tag));
  paths.forEach((path) => revalidatePath(path));
  return NextResponse.json({ revalidated: true, tags, paths, now: Date.now() });
}
EOT;

    $files['app/sitemap.ts'] = <<<'EOT'
import type { MetadataRoute } from 'next';
import { fetchGraphQL } from '@/lib/wp';
import { QUERY_ALL_POST_SLUGS, QUERY_ALL_PAGE_URIS } from '@/lib/queries';

const SITE = process.env.NEXT_PUBLIC_SITE_URL ?? 'http://localhost:3000';

type SlugsData = { posts: { nodes: { slug: string; modifiedGmt: string }[] } };
type UrisData = { pages: { nodes: { uri: string; modifiedGmt: string }[] } };

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [posts, pages] = await Promise.all([
    fetchGraphQL<SlugsData>(QUERY_ALL_POST_SLUGS, { tags: ['posts'] }),
    fetchGraphQL<UrisData>(QUERY_ALL_PAGE_URIS, { tags: ['pages'] }),
  ]);
  return [
    { url: SITE, lastModified: new Date() },
    ...posts.posts.nodes.map((p) => ({ url: `${SITE}/posts/${p.slug}`, lastModified: new Date(p.modifiedGmt + 'Z') })),
    ...pages.pages.nodes.map((p) => ({ url: `${SITE}${p.uri}`, lastModified: new Date(p.modifiedGmt + 'Z') })),
  ];
}
EOT;

    $files['README.md'] = <<<'EOT'
# {{NAME}}

Headless Next.js frontend for **{{SITE_TITLE}}** ({{SITE_URL}}), scaffolded by WP-Ultra-MCP.
Content is fetched from WPGraphQL at `{{ENDPOINT}}` with SSG + ISR (60s default window).

## Setup

```bash
cp .env.local.example .env.local   # then edit the secrets
npm install
npm run dev                        # http://localhost:3000
```

## Production

```bash
npm run build && npm run start
```

## How it stays fresh

- Every fetch is tagged (`posts`, `pages`, `menus`, `settings`) with a 60s ISR window.
- `POST /api/revalidate` with `{ "secret": "<REVALIDATE_SECRET>", "tags": ["posts"] }`
  refreshes instantly — WP-Ultra's `headless-revalidate` ability wires WordPress
  publish/update events to this endpoint.

## Draft preview

`WORDPRESS_PREVIEW_SECRET` + WP-Ultra's `headless-preview` ability point the WP
editor's Preview button at this frontend with Next.js draft mode.
EOT;

    return $files;
}

/** Vite (React SPA + TS + graphql-request + react-router) templates. */
function wpultra_headless_scaffold_vite(): array {
    $files = [];

    $files['package.json'] = <<<'EOT'
{
  "name": "{{NAME}}",
  "private": true,
  "type": "module",
  "scripts": {
    "dev": "vite",
    "build": "tsc -b && vite build",
    "preview": "vite preview"
  },
  "dependencies": {
    "graphql": "^16.9.0",
    "graphql-request": "^7.1.0",
    "react": "^19.0.0",
    "react-dom": "^19.0.0",
    "react-router-dom": "^7.1.0"
  },
  "devDependencies": {
    "@types/react": "^19.0.0",
    "@types/react-dom": "^19.0.0",
    "@vitejs/plugin-react": "^4.3.0",
    "typescript": "^5.6.0",
    "vite": "^6.0.0"
  }
}
EOT;

    $files['index.html'] = <<<'EOT'
<!doctype html>
<html lang="en">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{SITE_TITLE}}</title>
  </head>
  <body>
    <div id="root"></div>
    <script type="module" src="/src/main.tsx"></script>
  </body>
</html>
EOT;

    $files['vite.config.ts'] = <<<'EOT'
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
});
EOT;

    $files['tsconfig.json'] = <<<'EOT'
{
  "compilerOptions": {
    "target": "ES2022",
    "useDefineForClassFields": true,
    "lib": ["ES2022", "DOM", "DOM.Iterable"],
    "module": "ESNext",
    "skipLibCheck": true,
    "moduleResolution": "bundler",
    "resolveJsonModule": true,
    "isolatedModules": true,
    "noEmit": true,
    "jsx": "react-jsx",
    "strict": true
  },
  "include": ["src"]
}
EOT;

    $files['.env.example'] = <<<'EOT'
# WordPress GraphQL endpoint (public queries)
VITE_WORDPRESS_GRAPHQL_ENDPOINT={{ENDPOINT}}
EOT;

    $files['src/vite-env.d.ts'] = <<<'EOT'
/// <reference types="vite/client" />
EOT;

    $files['src/lib/wp.ts'] = <<<'EOT'
import { GraphQLClient } from 'graphql-request';

const endpoint = import.meta.env.VITE_WORDPRESS_GRAPHQL_ENDPOINT ?? '{{ENDPOINT}}';

export const wp = new GraphQLClient(endpoint);
EOT;

    $files['src/lib/queries.ts'] = <<<'EOT'
import { gql } from 'graphql-request';

export const QUERY_SETTINGS = gql`
  query Settings {
    generalSettings { title description }
  }
`;

export const QUERY_PRIMARY_MENU = gql`
  query PrimaryMenu {
    menus(first: 1) {
      nodes {
        menuItems(first: 50) {
          nodes { id label uri }
        }
      }
    }
  }
`;

export const QUERY_RECENT_POSTS = gql`
  query RecentPosts($first: Int = 10) {
    posts(first: $first, where: { status: PUBLISH }) {
      nodes { id slug title excerpt date }
    }
  }
`;

export const QUERY_POST_BY_SLUG = gql`
  query PostBySlug($slug: ID!) {
    post(id: $slug, idType: SLUG) {
      id title content date
    }
  }
`;

export const QUERY_PAGE_BY_URI = gql`
  query PageByUri($uri: ID!) {
    page(id: $uri, idType: URI) {
      id title content
    }
  }
`;
EOT;

    $files['src/main.tsx'] = <<<'EOT'
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Routes, Route } from 'react-router-dom';
import App from './App';
import Home from './pages/Home';
import Post from './pages/Post';
import Page from './pages/Page';

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter>
      <Routes>
        <Route element={<App />}>
          <Route index element={<Home />} />
          <Route path="posts/:slug" element={<Post />} />
          <Route path=":slug" element={<Page />} />
        </Route>
      </Routes>
    </BrowserRouter>
  </StrictMode>
);
EOT;

    $files['src/App.tsx'] = <<<'EOT'
import { useEffect, useState } from 'react';
import { Link, Outlet } from 'react-router-dom';
import { wp } from './lib/wp';
import { QUERY_SETTINGS, QUERY_PRIMARY_MENU } from './lib/queries';

type Settings = { generalSettings: { title: string } };
type MenuData = { menus: { nodes: { menuItems: { nodes: { id: string; label: string; uri: string | null }[] } }[] } };

export default function App() {
  const [title, setTitle] = useState('');
  const [items, setItems] = useState<{ id: string; label: string; uri: string | null }[]>([]);

  useEffect(() => {
    wp.request<Settings>(QUERY_SETTINGS).then((d) => setTitle(d.generalSettings.title));
    wp.request<MenuData>(QUERY_PRIMARY_MENU).then((d) => setItems(d.menus.nodes[0]?.menuItems.nodes ?? []));
  }, []);

  return (
    <div style={{ maxWidth: 760, margin: '0 auto', padding: '2rem', fontFamily: 'system-ui, sans-serif' }}>
      <header style={{ display: 'flex', gap: '1.5rem', marginBottom: '2rem' }}>
        <Link to="/" style={{ fontWeight: 700 }}>{title}</Link>
        <nav style={{ display: 'flex', gap: '1rem' }}>
          {items.map((item) => (
            <Link key={item.id} to={item.uri ?? '/'}>{item.label}</Link>
          ))}
        </nav>
      </header>
      <Outlet />
    </div>
  );
}
EOT;

    $files['src/pages/Home.tsx'] = <<<'EOT'
import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { wp } from '../lib/wp';
import { QUERY_RECENT_POSTS } from '../lib/queries';

type PostsData = { posts: { nodes: { id: string; slug: string; title: string; excerpt: string }[] } };

export default function Home() {
  const [posts, setPosts] = useState<PostsData['posts']['nodes']>([]);
  useEffect(() => {
    wp.request<PostsData>(QUERY_RECENT_POSTS, { first: 10 }).then((d) => setPosts(d.posts.nodes));
  }, []);
  return (
    <>
      <h1>Latest posts</h1>
      {posts.map((post) => (
        <article key={post.id} style={{ marginBottom: '1.5rem' }}>
          <h2><Link to={`/posts/${post.slug}`}>{post.title}</Link></h2>
          <div dangerouslySetInnerHTML={{ __html: post.excerpt }} />
        </article>
      ))}
    </>
  );
}
EOT;

    $files['src/pages/Post.tsx'] = <<<'EOT'
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { wp } from '../lib/wp';
import { QUERY_POST_BY_SLUG } from '../lib/queries';

type PostData = { post: { title: string; content: string } | null };

export default function Post() {
  const { slug } = useParams();
  const [post, setPost] = useState<PostData['post']>(null);
  useEffect(() => {
    if (slug) wp.request<PostData>(QUERY_POST_BY_SLUG, { slug }).then((d) => setPost(d.post));
  }, [slug]);
  if (!post) return <p>Loading…</p>;
  return (
    <article>
      <h1>{post.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: post.content }} />
    </article>
  );
}
EOT;

    $files['src/pages/Page.tsx'] = <<<'EOT'
import { useEffect, useState } from 'react';
import { useParams } from 'react-router-dom';
import { wp } from '../lib/wp';
import { QUERY_PAGE_BY_URI } from '../lib/queries';

type PageData = { page: { title: string; content: string } | null };

export default function Page() {
  const { slug } = useParams();
  const [page, setPage] = useState<PageData['page']>(null);
  useEffect(() => {
    if (slug) wp.request<PageData>(QUERY_PAGE_BY_URI, { uri: `/${slug}/` }).then((d) => setPage(d.page));
  }, [slug]);
  if (!page) return <p>Loading…</p>;
  return (
    <article>
      <h1>{page.title}</h1>
      <div dangerouslySetInnerHTML={{ __html: page.content }} />
    </article>
  );
}
EOT;

    $files['README.md'] = <<<'EOT'
# {{NAME}}

Vite + React SPA frontend for **{{SITE_TITLE}}** ({{SITE_URL}}), scaffolded by WP-Ultra-MCP.
Content is fetched client-side from WPGraphQL at `{{ENDPOINT}}` — pick this template for
app-like frontends (dashboards, portals) where SEO is not the driver; use the Next.js
template for content/marketing sites.

## Setup

```bash
cp .env.example .env    # adjust if the endpoint differs
npm install
npm run dev             # http://localhost:5173
```

Make sure this origin is CORS-allowed on the WordPress side
(WP-Ultra `headless-setup` with origins:["http://localhost:5173"]).

## Production

```bash
npm run build           # emits dist/
```
EOT;

    return $files;
}
