# Changelog

All notable changes to **Juliet Just Mask** will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.0 - 2026-08-27

### Added
- **Stealth Routing Engine**: Early sub-millisecond reverse proxy router hooking into `parse_request` with zero query lag.
- **Mask Registry UI**: Modern card and list view dashboard with instant AJAX status toggles, real-time live search, and filtering.
- **Asset Dependency Patcher**: Server-side rewriter for relative and root-relative URLs, stylesheets, scripts, `srcset`, and CSS `url()` assets.
- **SPA & `<base>` Tag Injector**: Automatic `<base href>` injection for Single Page Applications (React, Vue, Vite, Next.js, Angular, Svelte) to isolate dynamic chunk loading and API fetch calls.
- **SSRF Protection & Loop Guard**: Strict protocol validation, DNS loop checking, private IP validation, and `X-Juliet-Proxy` header detection.
- **Live Permalink Conflict Detection**: Real-time validation warning against existing WordPress Pages, Posts, and Romeo Redirect Manager rules.
- **JSON Backup & Safe Import**: Export and conflict-aware import tools with duplicate detection, merge, and overwrite modes.
- **Responsive Mobile Layout**: Fully optimized layout for desktop, tablet, and mobile screens.
