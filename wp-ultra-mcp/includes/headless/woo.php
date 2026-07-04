<?php
declare(strict_types=1);
if (!defined('ABSPATH')) { exit(); }

/**
 * Headless domain — WooGraphQL storefront scaffold (Roadmap-3, H3.2).
 *
 * Extends the Next.js starter with a shop: SSG product grid + single product
 * (server components), and a client-side cart/checkout built on WooGraphQL's
 * session flow — the `woocommerce-session` response header is captured in
 * localStorage and replayed on every cart mutation. Templates are pure
 * ({{ENDPOINT}} filled via the scaffold token filler).
 */

/**
 * The storefront file manifest. Pure over ctx (same tokens as headless-scaffold).
 * @return array<int,array{path:string,content:string}>
 */
function wpultra_headless_woo_manifest(array $ctx): array {
    $templates = [];

    $templates['lib/woo.ts'] = <<<'EOT'
'use client';

/**
 * Browser-side WooGraphQL client. Cart state lives in a WooCommerce session:
 * every response may carry a `woocommerce-session` header — persist it and
 * send it back as `woocommerce-session: Session <token>` so the cart follows
 * the visitor. (The WP side must CORS-allow this origin; WP-Ultra
 * headless-setup handles that, and WooGraphQL exposes the session header.)
 */
const ENDPOINT = process.env.NEXT_PUBLIC_WORDPRESS_GRAPHQL_ENDPOINT ?? '{{ENDPOINT}}';
const SESSION_KEY = 'woo-session';

export async function wooFetch<T = unknown>(query: string, variables?: Record<string, unknown>): Promise<T> {
  const headers: Record<string, string> = { 'Content-Type': 'application/json' };
  const session = typeof window !== 'undefined' ? window.localStorage.getItem(SESSION_KEY) : null;
  if (session) headers['woocommerce-session'] = `Session ${session}`;
  const res = await fetch(ENDPOINT, {
    method: 'POST',
    headers,
    body: JSON.stringify({ query, variables }),
  });
  const newSession = res.headers.get('woocommerce-session');
  if (newSession && typeof window !== 'undefined') window.localStorage.setItem(SESSION_KEY, newSession);
  const json = await res.json();
  if (json.errors?.length) throw new Error(json.errors[0].message);
  return json.data as T;
}

export const MUTATION_ADD_TO_CART = /* GraphQL */ `
  mutation AddToCart($productId: Int!, $quantity: Int = 1) {
    addToCart(input: { productId: $productId, quantity: $quantity }) {
      cart { contents { itemCount } total }
    }
  }
`;

export const QUERY_CART = /* GraphQL */ `
  query Cart {
    cart {
      contents {
        itemCount
        nodes {
          key quantity total
          product { node { id name slug } }
        }
      }
      subtotal total
    }
  }
`;

export const MUTATION_REMOVE_ITEM = /* GraphQL */ `
  mutation RemoveItem($keys: [ID]) {
    removeItemsFromCart(input: { keys: $keys }) {
      cart { contents { itemCount } total }
    }
  }
`;

export const MUTATION_CHECKOUT = /* GraphQL */ `
  mutation Checkout($billing: CustomerAddressInput!, $payment: String!) {
    checkout(input: { billing: $billing, paymentMethod: $payment }) {
      order { databaseId orderNumber status total }
      result redirect
    }
  }
`;
EOT;

    $templates['app/shop/page.tsx'] = <<<'EOT'
import Link from 'next/link';
import { fetchGraphQL } from '@/lib/wp';

const QUERY_PRODUCTS = /* GraphQL */ `
  query Products($first: Int = 24) {
    products(first: $first, where: { status: "publish", visibility: VISIBLE }) {
      nodes {
        id databaseId slug name
        image { sourceUrl altText }
        ... on SimpleProduct { price }
        ... on VariableProduct { price }
      }
    }
  }
`;

type ProductsData = {
  products: { nodes: { id: string; slug: string; name: string; price?: string | null; image: { sourceUrl: string; altText: string } | null }[] };
};

export const metadata = { title: 'Shop' };

export default async function ShopPage() {
  const data = await fetchGraphQL<ProductsData>(QUERY_PRODUCTS, { tags: ['products'] });
  return (
    <>
      <h1>Shop</h1>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(200px, 1fr))', gap: '1.5rem' }}>
        {data.products.nodes.map((p) => (
          <Link key={p.id} href={`/shop/${p.slug}`} style={{ textDecoration: 'none', color: 'inherit' }}>
            {p.image && <img src={p.image.sourceUrl} alt={p.image.altText} style={{ width: '100%', aspectRatio: '1', objectFit: 'cover' }} />}
            <h3 style={{ margin: '0.5rem 0 0.25rem' }}>{p.name}</h3>
            {p.price && <p style={{ margin: 0, color: '#666' }}>{p.price}</p>}
          </Link>
        ))}
      </div>
      <p style={{ marginTop: '2rem' }}><Link href="/cart">View cart →</Link></p>
    </>
  );
}
EOT;

    $templates['app/shop/[slug]/page.tsx'] = <<<'EOT'
import type { Metadata } from 'next';
import { notFound } from 'next/navigation';
import { fetchGraphQL } from '@/lib/wp';
import AddToCart from '@/components/AddToCart';

const QUERY_SLUGS = /* GraphQL */ `
  query ProductSlugs($first: Int = 100) {
    products(first: $first, where: { status: "publish" }) { nodes { slug } }
  }
`;

const QUERY_PRODUCT = /* GraphQL */ `
  query ProductBySlug($slug: ID!) {
    product(id: $slug, idType: SLUG) {
      id databaseId name description shortDescription
      image { sourceUrl altText }
      ... on SimpleProduct { price stockStatus }
      ... on VariableProduct { price stockStatus }
    }
  }
`;

type ProductData = {
  product: {
    databaseId: number; name: string; description: string | null; shortDescription: string | null;
    price?: string | null; stockStatus?: string | null;
    image: { sourceUrl: string; altText: string } | null;
  } | null;
};

export async function generateStaticParams() {
  const data = await fetchGraphQL<{ products: { nodes: { slug: string }[] } }>(QUERY_SLUGS, { tags: ['products'] });
  return data.products.nodes.map((p) => ({ slug: p.slug }));
}

export async function generateMetadata({ params }: { params: Promise<{ slug: string }> }): Promise<Metadata> {
  const { slug } = await params;
  const data = await fetchGraphQL<ProductData>(QUERY_PRODUCT, { variables: { slug }, tags: ['products'] });
  return data.product ? { title: data.product.name } : {};
}

export default async function ProductPage({ params }: { params: Promise<{ slug: string }> }) {
  const { slug } = await params;
  const data = await fetchGraphQL<ProductData>(QUERY_PRODUCT, { variables: { slug }, tags: ['products'] });
  if (!data.product) notFound();
  const p = data.product;
  return (
    <article style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '2rem' }}>
      <div>{p.image && <img src={p.image.sourceUrl} alt={p.image.altText} style={{ width: '100%' }} />}</div>
      <div>
        <h1>{p.name}</h1>
        {p.price && <p style={{ fontSize: '1.5rem' }}>{p.price}</p>}
        {p.stockStatus && <p style={{ color: '#666' }}>{p.stockStatus}</p>}
        <div dangerouslySetInnerHTML={{ __html: p.shortDescription ?? '' }} />
        <AddToCart productId={p.databaseId} />
        <div dangerouslySetInnerHTML={{ __html: p.description ?? '' }} />
      </div>
    </article>
  );
}
EOT;

    $templates['components/AddToCart.tsx'] = <<<'EOT'
'use client';

import { useState } from 'react';
import Link from 'next/link';
import { wooFetch, MUTATION_ADD_TO_CART } from '@/lib/woo';

export default function AddToCart({ productId }: { productId: number }) {
  const [state, setState] = useState<'idle' | 'busy' | 'added' | 'error'>('idle');
  const [message, setMessage] = useState('');

  async function add() {
    setState('busy');
    try {
      await wooFetch(MUTATION_ADD_TO_CART, { productId, quantity: 1 });
      setState('added');
    } catch (e) {
      setMessage(String(e));
      setState('error');
    }
  }

  return (
    <div style={{ margin: '1rem 0' }}>
      <button onClick={add} disabled={state === 'busy'} style={{ padding: '0.75rem 1.5rem', fontSize: '1rem', cursor: 'pointer' }}>
        {state === 'busy' ? 'Adding…' : 'Add to cart'}
      </button>
      {state === 'added' && (
        <p>Added. <Link href="/cart">Go to cart →</Link></p>
      )}
      {state === 'error' && <p style={{ color: '#cf2e2e' }}>{message}</p>}
    </div>
  );
}
EOT;

    $templates['app/cart/page.tsx'] = <<<'EOT'
'use client';

import { useEffect, useState } from 'react';
import Link from 'next/link';
import { wooFetch, QUERY_CART, MUTATION_REMOVE_ITEM, MUTATION_CHECKOUT } from '@/lib/woo';

type Cart = {
  contents: { itemCount: number; nodes: { key: string; quantity: number; total: string; product: { node: { id: string; name: string; slug: string } } }[] };
  subtotal: string;
  total: string;
} | null;

export default function CartPage() {
  const [cart, setCart] = useState<Cart>(null);
  const [busy, setBusy] = useState(false);
  const [order, setOrder] = useState<{ orderNumber: string; total: string } | null>(null);
  const [error, setError] = useState('');

  async function load() {
    const data = await wooFetch<{ cart: Cart }>(QUERY_CART);
    setCart(data.cart);
  }
  useEffect(() => { load().catch((e) => setError(String(e))); }, []);

  async function remove(key: string) {
    setBusy(true);
    try { await wooFetch(MUTATION_REMOVE_ITEM, { keys: [key] }); await load(); } catch (e) { setError(String(e)); }
    setBusy(false);
  }

  async function checkout(form: FormData) {
    setBusy(true);
    setError('');
    try {
      const billing = {
        firstName: form.get('firstName'), lastName: form.get('lastName'),
        email: form.get('email'), country: 'US',
      };
      const data = await wooFetch<{ checkout: { order: { orderNumber: string; total: string } } }>(MUTATION_CHECKOUT, { billing, payment: 'cod' });
      setOrder(data.checkout.order);
    } catch (e) { setError(String(e)); }
    setBusy(false);
  }

  if (order) {
    return (
      <>
        <h1>Thank you!</h1>
        <p>Order #{order.orderNumber} placed — total {order.total}.</p>
        <p><Link href="/shop">Continue shopping →</Link></p>
      </>
    );
  }

  return (
    <>
      <h1>Cart</h1>
      {error && <p style={{ color: '#cf2e2e' }}>{error}</p>}
      {!cart ? <p>Loading…</p> : cart.contents.itemCount === 0 ? (
        <p>Cart is empty. <Link href="/shop">Shop →</Link></p>
      ) : (
        <>
          {cart.contents.nodes.map((item) => (
            <div key={item.key} style={{ display: 'flex', justifyContent: 'space-between', padding: '0.5rem 0', borderBottom: '1px solid #eee' }}>
              <span>{item.product.node.name} × {item.quantity}</span>
              <span>
                {item.total}{' '}
                <button onClick={() => remove(item.key)} disabled={busy} style={{ marginLeft: '1rem' }}>remove</button>
              </span>
            </div>
          ))}
          <p style={{ fontWeight: 700 }}>Total: {cart.total}</p>
          <h2>Checkout</h2>
          <form action={checkout} style={{ display: 'grid', gap: '0.5rem', maxWidth: 360 }}>
            <input name="firstName" placeholder="First name" required style={{ padding: '0.5rem' }} />
            <input name="lastName" placeholder="Last name" required style={{ padding: '0.5rem' }} />
            <input name="email" type="email" placeholder="Email" required style={{ padding: '0.5rem' }} />
            <button type="submit" disabled={busy} style={{ padding: '0.75rem' }}>
              {busy ? 'Placing order…' : 'Place order (cash on delivery)'}
            </button>
          </form>
        </>
      )}
    </>
  );
}
EOT;

    $files = [];
    foreach ($templates as $path => $content) {
        $files[] = ['path' => $path, 'content' => wpultra_headless_scaffold_fill($content, $ctx)];
    }
    return $files;
}
