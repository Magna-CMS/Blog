# Changelog

All notable changes to Magna Blog are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
