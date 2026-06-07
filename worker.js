/**
 * Cloudflare Workers reverse proxy for oPan
 *
 * Proxies all requests to the Vercel deployment.
 * Useful for bypassing DNS/firewall restrictions.
 *
 * Setup:
 *   1. Create a Worker in Cloudflare dashboard
 *   2. Paste this code
 *   3. Add a custom domain route (e.g., pan-proxy.yourdomain.com)
 *   4. Update ALLOWED_ORIGIN if needed
 */

const BACKEND = 'https://pan.yuer6327.top';
const ALLOWED_ORIGIN = '*'; // Set to your domain for production

export default {
  async fetch(request, env, ctx) {
    const url = new URL(request.url);

    // Build the backend URL
    const targetUrl = BACKEND + url.pathname + url.search;

    // Clone the request with the new URL
    const headers = new Headers(request.headers);

    // Remove headers that shouldn't be forwarded
    headers.delete('host');

    // Add forwarded headers so the backend knows the real client
    headers.set('X-Forwarded-For', request.headers.get('CF-Connecting-IP') || '');
    headers.set('X-Real-IP', request.headers.get('CF-Connecting-IP') || '');

    try {
      // Forward the request to the backend
      // `body` is streamed, not buffered — works for large file uploads
      const backendResponse = await fetch(targetUrl, {
        method: request.method,
        headers: headers,
        body: request.method !== 'GET' && request.method !== 'HEAD' ? request.body : undefined,
        redirect: 'follow',
      });

      // Build the response to the client
      const responseHeaders = new Headers(backendResponse.headers);

      // Add CORS headers
      responseHeaders.set('Access-Control-Allow-Origin', ALLOWED_ORIGIN);
      responseHeaders.set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
      responseHeaders.set('Access-Control-Allow-Headers', 'Content-Type, Authorization, x-apikey');
      responseHeaders.set('Access-Control-Max-Age', '86400');

      // Remove headers that might cause issues
      responseHeaders.delete('x-frame-options');
      responseHeaders.delete('content-security-policy');

      // Handle CORS preflight
      if (request.method === 'OPTIONS') {
        return new Response(null, {
          status: 204,
          headers: responseHeaders,
        });
      }

      return new Response(backendResponse.body, {
        status: backendResponse.status,
        statusText: backendResponse.statusText,
        headers: responseHeaders,
      });

    } catch (err) {
      return new Response(JSON.stringify({
        ok: false,
        error: `Proxy error: ${err.message}`,
        code: 'PROXY_ERROR',
      }), {
        status: 502,
        headers: {
          'Content-Type': 'application/json',
          'Access-Control-Allow-Origin': ALLOWED_ORIGIN,
        },
      });
    }
  },
};
