=== Juliet Just Masks ===
Contributors: harsh98trivedi
Tags: url-masking, mask-manager, reverse-proxy, url-masker, stealth-routing
Donate link: https://buymeacoffee.com/harshtrivedi
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

High-performance URL masking and stealth routing mask manager. Reverse proxy remote apps and landing pages on your own domain without iframes.

== Description ==

**Juliet Just Masks** is a high-performance URL masking and reverse proxy **mask manager** for WordPress. While Romeo Redirect Manager handles open redirects, Juliet provides seamless **stealth routing** and **URL masking**.

As a lightweight, native **URL masker**, Juliet lets WordPress act as a stealth reverse proxy. It intercepts specific incoming paths, keeps the visitor's address bar unchanged, and fetches/renders dynamic HTML, React/Vue SPAs, or external landing pages behind the scenes.

= Why use Juliet Mask Manager? =

* **Native URL Masking** — Serve remote applications, microservices, and landing pages on your own custom domain without iframes or server configuration.
* **Smart Mask Manager** — Visual dashboard to create, manage, sort, search, and toggle active/inactive URL masks in real time.
* **Fast Path Reverse Proxy** — Zero-latency early route matching hooks before WP Query to proxy requests at top speed.
* **SPA & `<base>` Tag Injector** — Keeps relative script chunks, assets, and API requests properly routed through the proxy.
* **Asset & Link Patcher** — Rewrites root-relative URLs, internal CSS `url()` assets, and external navigation links into your local mask namespace.
* **SSRF Protection & Security** — Enterprise-grade IP and protocol validation protects your server from unsafe outbound requests.
* **Companion to Romeo Redirect Manager** — The complete routing suite: Romeo for redirection, Juliet for masking.

= Features =

* **Stealth Routing Engine** — Bypasses WP_Query to serve remote HTML without triggering a 404, supporting unlimited sub-paths.
* **Mask Manager Dashboard** — Intuitive card and list views with live search, custom dropdown filtering, and instant AJAX toggles.
* **URL Masker & Link Masking** — Automatically rewrites remote URLs and same-origin links into your local slug namespace.
* **Asset Dependency Patcher** — Rewrites root-relative asset paths (`/css/style.css`, `srcset`, inline `url()`, data-src) on the fly.
* **Proxy Header Passthrough** — Forwards visitor IP (`X-Forwarded-For`), User-Agent, language, and protocol for accurate analytics.
* **SSRF Protection** — Validates protocols, credentials, and DNS against private IP ranges.
* **DOM `<base>` Injector** — Smart `<base href>` injection tailored for complex JavaScript/AJAX-heavy SPAs.
* **Asset Caching & MIME Typing** — Built-in static asset caching headers and strict MIME type detection for scripts and styles.
* **Graceful Failsafe** — Theme-native 404 fallback if a remote server is unreachable.
* **Method Pass-Through** — Supports GET, POST, PUT, PATCH, DELETE, and CORS OPTIONS preflight requests.

= Example =

1. Go to **Juliet Just Masks** in wp-admin.
2. Click **Create New Mask**.
3. Enter the Local Path: `marketing-hub`.
4. Enter the Remote Target URL: `https://external-landing-page.com/promo-1`.
5. Save.

Visiting `yoursite.com/marketing-hub` now renders the remote landing page while the address bar still shows `yoursite.com/marketing-hub`. Deeper paths work too: `yoursite.com/marketing-hub/pricing` proxies the remote app's `/pricing` route.

= Known limitations =

* **CORS** — if the remote server sends strict CORS headers for its assets, browsers may block cross-origin subresources. The remote must allow your domain (or serve assets with permissive CORS).
* **Remote sessions** — `Set-Cookie` responses are not forwarded (cookies would be scoped incorrectly). Authenticated remote applications need additional cookie handling; forwarding the visitor's cookies upstream is available via the `juliet_forward_cookies` filter but leaks this site's auth cookies, so only enable it for trusted targets.
* **DNS rebinding** — SSRF validation resolves DNS separately from the fetch. A hostile target could rotate DNS between check and fetch. Only mask targets you control or trust.
* **Lazy-loaded assets** — attributes such as `data-src` set by JavaScript after load cannot be patched server-side; enable `<base>` injection for those apps.

== Screenshots ==

1. Main Dashboard (Card View) — Overview of all active and inactive URL masks with instant toggles, quick copy actions, real-time search, and status filtering.
2. Create & Edit Mask Interface — Clean configuration form with custom local path prefixing, target URL routing, and dynamic <base> tag injection.
3. Interactive <base> Tag & SPA Guide Modal — Educational popup guide explaining Single Page Applications (React, Vue, Vite, Next.js, Angular, Svelte) and chunk proxy isolation.
4. One-Click Backup & JSON Import — Safe migration modal with automated duplicate slug conflict detection and smart merge options.
5. Fully Responsive Mobile Interface — Optimized layout and touch-friendly controls across smartphones, tablets, and desktop devices.
6. Live Permalink Conflict Detection — Real-time warning alert preventing collisions with existing WordPress Pages, Posts, and Romeo 301 redirects before saving.

== Installation ==

1. Upload the `juliet-just-masks` folder to `/wp-content/plugins/`, or install via the Plugins screen.
2. Activate **Juliet Just Masks** through the **Plugins** menu (the registry table is created automatically).
3. Navigate to **Juliet Just Masks** in the admin menu and create your first mask.

If routes return 404s after manual database edits, visit **Settings → Permalinks** once to flush rewrite rules.

== Frequently Asked Questions ==

= Does this change my .htaccess or Nginx config? =

No. Juliet uses WordPress rewrite rules and `template_redirect`, so it works on managed hosting where server configs are off-limits.

= What happens when I save or delete a mask? =

Rewrite rules are flushed automatically, so new routes go live instantly.

= Can a mask hide an existing page? =

Masks register at top priority. If you pick a path already used by a page, the mask wins and the admin will warn you about the conflict on save.

= How do I disable caching? =

`add_filter( 'juliet_cache_ttl', '__return_zero' );`

== Filters (developer reference) ==

* `juliet_target_url` — filter the final outbound URL (args: `$url`, `$mask`, `$subpath`).
* `juliet_timeout` — remote fetch timeout in seconds (default 15).
* `juliet_cache_ttl` — response cache TTL in seconds (default 300, 0 disables).
* `juliet_max_cache_bytes` — max cacheable body size (default 2 MB).
* `juliet_proxy_headers` — outbound proxy headers (args: `$headers`, `$mask`, `$url`).
* `juliet_forward_cookies` — forward the visitor's Cookie header upstream (default false).
* `juliet_allow_private_targets` — allow private/internal network targets (default false).
* `juliet_apply_link_masking` — rewrite same-origin remote links into the slug namespace (default true).
* `juliet_reserved_slugs` — slugs that may never be registered as masks.
* `juliet_request_args` — full `wp_remote_request()` argument override.
* `juliet_honor_loop_guard` — block requests that already carry the proxy signature (default true).

== Changelog ==

= 1.0.0 =
* Initial release: Stealth Routing Engine, Mask Registry UI, Asset Dependency Patcher, link masking, header passthrough, SSRF protection, base-tag injection, response caching and native 404 fallback.
