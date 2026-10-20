# Changelog

All notable changes to Magna Blog are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-10-21

Requires Magna 1.6.0 or later.

### Fixed

- **The post editor works on installed sites.** The editor's compiled bundle (`dist/blog-editor.js`)
  was gitignored, so every release — which Composer installs straight from the git tag — shipped
  without it. On an installed site the editor showed a title and no body, `filament:assets` aborted on
  the missing file, and no post could be written. The bundle is now committed, a test fails if it
  ever stops being tracked, and the plugin's pre-commit hook refuses an editor-source change that
  arrives without the rebuilt bundle.

## [1.1.0] - 2026-10-20

Requires Magna 1.6.0 or later.

### Added

- **Public post pages.** With Magna Pages installed, every published, public post is served at its
  permalink inside the site's layout (header, footer, theme), with its title and description in the
  page's meta. Drafts, scheduled, private and password-protected posts, unknown slugs and any
  non-canonical path for a multi-segment pattern get the site's 404. Without Pages the blog stays
  headless.
- Stable class hooks on the post page (`post-title`, `post-meta`, `featured`, `post-body`,
  `post-nav`) for themes to style.

### Changed

- The permalink pattern now drives the post page path, every post link (Loop source, related posts,
  previous/next), the sitemap and the builder's slug prefix. Each token must be a whole segment and
  `{slug}` is required; otherwise `/blog/{slug}` is used and a warning is logged. Changing the pattern
  does not redirect old URLs.
- Related posts in the delivery payload gain `url` and `image` beside the existing `slug` and
  `featured_image`.

### Fixed

- The table-of-contents block keeps its title and numbering settings.
- Related posts render as links.

### Security

- Slugs from JSON bundles and WordPress imports are normalised to `[a-z0-9-]`.
- Cover and link-card images can no longer inject CSS through a crafted URL.
- Links, buttons and embeds are re-validated at render time; `/\host` URLs are refused as
  protocol-relative; the raw HTML block drops `id`, `srcset` and `data:` images.

## [1.0.0] - 2026-08-17

Initial public release.

### Added

- Headless blog management for Magna CMS.
- Rich **Editor.js** block editor with 30+ content blocks, sanitised server-side and stored as
  structured JSON.
- **Categories** (nested), **tags** (flat) and **series** with ordered navigation.
- Posts with excerpts, featured images, primary author and co-authors, per-post locale and translation
  groups.
- Editorial workflow: draft, pending review, published, scheduled and archived states, plus soft-delete
  Trash.
- Scheduled publishing.
- Revisions with configurable pruning and restore.
- Media-library integration for featured images and editor uploads.
- Read-only **delivery API** for published, public posts, with filtering, search, pagination, related
  and adjacent posts, taxonomies, comments and reactions.
- Comments with honeypot and optional Akismet spam protection, held for moderation or auto-approved.
- Anonymous reactions with a non-reversible per-visitor fingerprint.
- CSV export (formula-injection safe) and WordPress (WXR) import.
- GDPR personal-data export and erase.
- Webhook events for post published, updated and deleted.
- Optional integration with the Magna SEO plugin for per-post metadata (search title, description,
  canonical URL, robots, Open Graph and Twitter), plus sitemap and scan coverage — enabled automatically
  when the SEO plugin is installed, and fully decoupled otherwise.
- Admin internationalisation via a `blog::` translation namespace.
- Security hardening: allowlist content sanitiser with an escaping renderer, SSRF-protected editor
  fetches (special-use IP-range blocking and connection pinning), CSV formula-injection protection,
  network-disabled XML import, and server-side per-record authorisation.

[1.0.0]: https://github.com/Magna-CMS/Blog/releases/tag/v1.0.0
