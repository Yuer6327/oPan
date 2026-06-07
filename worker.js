/**
 * Cloudflare Workers reverse proxy for oPan
 *
 * Routes:
 *   /api/*   → Vercel backend (pan.yuer6327.top)
 *   /koofr/* → Koofr content API (app.koofr.net) — fast CF network, auth handled server-side
 *   /*        → Vercel frontend (index.html etc.)
 *
 * Setup:
 *   1. Create Worker in Cloudflare Dashboard → Workers & Pages
 *   2. Set environment variables in Worker Settings:
 *      - KOOFR_EMAIL: your Koofr email
 *      - KOOFR_APP_PASSWORD: your Koofr app password
 *      - KOOFR_MOUNT_ID: your Koofr mount ID
 *   3. Bind a custom domain route (e.g., pan.yourdomain.com)
 */

const VERCEL_BACKEND = 'https://pan.yuer6327.top';
const KOOFR_BASE = 'https://app.koofr.net';

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);
    const path = url.pathname;

    // ── CORS preflight ───────────────────────────────────────────────
    if (request.method === 'OPTIONS') {
      return new Response(null, {
        status: 204,
        headers: corsHeaders(),
      });
    }

    // ── Route: /koofr/* → Koofr content API (with auth) ─────────────
    if (path.startsWith('/koofr/')) {
      return handleKoofr(request, url, env);
    }

    // ── Route: everything else → Vercel backend ──────────────────────
    return handleVercel(request, url);
  },
};

// ── Koofr proxy ─────────────────────────────────────────────────────────
async function handleKoofr(request, url, env) {
  const email = env.KOOFR_EMAIL;
  const password = env.KOOFR_APP_PASSWORD;
  const mountId = env.KOOFR_MOUNT_ID;

  if (!email || !password || !mountId) {
    return jsonResponse({ ok: false, error: 'Koofr not configured on Worker' }, 500);
  }

  // Build Koofr URL: /koofr/get?path=... → /content/api/v2/mounts/{id}/files/get?path=...
  // /koofr/put?path=...&filename=... → /content/api/v2/mounts/{id}/files/put?path=...&filename=...
  const action = url.pathname.replace('/koofr/', '');
  const search = url.search; // includes ?
  const koofrUrl = `${KOOFR_BASE}/content/api/v2/mounts/${mountId}/files/${action}${search}`;

  const auth = 'Basic ' + btoa(`${email}:${password}`);

  const headers = new Headers();
  headers.set('Authorization', auth);

  // Forward Content-Type for uploads
  if (request.headers.get('Content-Type')) {
    headers.set('Content-Type', request.headers.get('Content-Type'));
  }

  try {
    const resp = await fetch(koofrUrl, {
      method: request.method,
      headers: headers,
      body: request.method !== 'GET' && request.method !== 'HEAD' ? request.body : undefined,
    });

    const respHeaders = new Headers(resp.headers);
    respHeaders.set('Access-Control-Allow-Origin', '*');
    respHeaders.set('Access-Control-Allow-Methods', 'GET, POST, PUT, OPTIONS');
    respHeaders.set('Access-Control-Allow-Headers', 'Content-Type');

    return new Response(resp.body, {
      status: resp.status,
      headers: respHeaders,
    });
  } catch (err) {
    return jsonResponse({ ok: false, error: `Koofr proxy error: ${err.message}` }, 502);
  }
}

// ── Vercel proxy ─────────────────────────────────────────────────────────
async function handleVercel(request, url) {
  const targetUrl = VERCEL_BACKEND + url.pathname + url.search;

  const headers = new Headers(request.headers);
  headers.delete('host');
  headers.set('X-Forwarded-For', request.headers.get('CF-Connecting-IP') || '');
  headers.set('X-Real-IP', request.headers.get('CF-Connecting-IP') || '');

  try {
    const resp = await fetch(targetUrl, {
      method: request.method,
      headers: headers,
      body: request.method !== 'GET' && request.method !== 'HEAD' ? request.body : undefined,
      redirect: 'follow',
    });

    const respHeaders = new Headers(resp.headers);
    respHeaders.set('Access-Control-Allow-Origin', '*');
    respHeaders.set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
    respHeaders.set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Admin-Key');
    respHeaders.set('Access-Control-Max-Age', '86400');
    respHeaders.delete('x-frame-options');
    respHeaders.delete('content-security-policy');

    return new Response(resp.body, {
      status: resp.status,
      statusText: resp.statusText,
      headers: respHeaders,
    });
  } catch (err) {
    return jsonResponse({ ok: false, error: `Vercel proxy error: ${err.message}` }, 502);
  }
}

// ── Helpers ──────────────────────────────────────────────────────────────
function corsHeaders() {
  return {
    'Access-Control-Allow-Origin': '*',
    'Access-Control-Allow-Methods': 'GET, POST, PUT, DELETE, OPTIONS',
    'Access-Control-Allow-Headers': 'Content-Type, Authorization, X-Admin-Key',
    'Access-Control-Max-Age': '86400',
  };
}

function jsonResponse(data, status = 200) {
  return new Response(JSON.stringify(data), {
    status,
    headers: { 'Content-Type': 'application/json', 'Access-Control-Allow-Origin': '*' },
  });
}
