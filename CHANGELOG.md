# Changelog

All notable changes to Magna Blog are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] - 2026-10-22

### Added

- **Posts in site kits (`blog:posts`).** A theme's demo content — or a site kit promoted
  between environments — can now carry posts with their category, tags and featured image.
  Rows are keyed by slug; content is sanitized like the editor's; an inline featured image
  must decode as the image type it claims and stay under 5 MB (remote URLs, SVG and path
  traversal are dropped); categories and tags are created when missing and never renamed;
  the author is only ever the user applying the kit. `published_days_ago` keeps demo posts
  recent.

## [1.2.1] - 2026-10-21

### Fixed

- **The post editor's Publish date was blank, and saving moved the date.** The builder seeded the field
  with the record's serialised UTC timestamp, which a date-time input cannot show; saving then wrote that
  UTC time back as the site's local time, shifting the publish date by the site's UTC offset on every save
  (and with it any date-based permalink). The field now holds the date on the site's own clock.
- **Delivery API prev/next stay in the post's language.** A French post's `adjacent` links are French
  posts, as on the post page.

### Added

- `url` on every entry of a post's `translations` in the delivery API — where that translation is served,
  language prefix included.
- `GET /api/v1/blog/posts/{slug}/comments/stamp`: a signed `_ts` for API comment forms, the same bot check
  the post page's form carries.

### Deprecated

- The unsigned `form_started_at` comment field. Still honoured; removal planned for 2.0. Send `_ts` instead.

## [1.2.0] - 2026-10-21

Requires Magna 1.6.2 or later (its render context, page-cache, locale and form-token seams).

### Added

- **Paginated post listings.** The Blog posts block has a *Pagination* setting: newer / older pages of
  "How many" posts (or the *Posts per page* setting), page 1 at the bare path and `?page=N` after it, a
  404 past the last page, and core's `magna-pagination` navigation. The `blog.posts` and
  `blog.featured` data sources serve pages to the Pages Loop block too.
- **Language-prefixed post URLs.** A post in one of the site's non-default languages is served, and
  linked everywhere, under that language's prefix (`/fr/blog/bonjour`). Prev/next links stay within the
  language, and a post's translations are offered as hreflang alternates.
- **Cached post pages.** Guest post pages and post listings are stored by the Magna Pages cache and purged
  when a post (autosaves of published posts included), a visible comment, a category, a tag or a Blog
  setting changes. A post scheduled for later appears on time with no save, and a comment form closes on
  time.
- **Redirects for moved posts.** Changing a published post's slug, date, category or language, renaming
  a category, deleting one (its posts move to Uncategorised), or editing the permalink pattern redirects
  each old URL to the new one, through Magna Pages' redirects.
- **Comments on post pages.** Each post shows its approved comments, oldest first, with one level of
  replies, and a comment form while comments are open, held for moderation or auto-approved per
  settings. The form works on cached pages (core's form token), carries a signed render stamp and a
  honeypot, is rate limited, and can show a captcha when Magna Defence enables the new *Blog comment form*
  surface (off by default). "Require login to comment" means signed in to the Magna panel; a signed-in
  commenter's name and email come from their account.
- **Excerpts for every post.** A post without an excerpt is summarised by the first paragraph of its body
  (up to 160 characters, cut between words), used for listings, the page's meta description and SEO. The
  post builder gains an Excerpt field (up to 300 characters), required on save when *Allow empty excerpt*
  is off. Existing posts are backfilled on update.
- **Delivery API:** posts carry `summary` and `url`; adjacent posts carry `url`. Comment submissions may
  send the `_ts` render stamp, which is then verified. No field was renamed or removed.

### Changed

- The API and the post page's form share one comment submission path. A reply to a reply is filed under
  its top-level comment (one level deep was already all the API ever listed).

## [1.1.1] - 2026-10-20

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
