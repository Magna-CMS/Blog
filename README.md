# Magna Blog

[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%5E8.3-777bb4.svg)](composer.json)
[![Magna CMS](https://img.shields.io/badge/Magna%20CMS-%5E1.0-6366f1.svg)](https://github.com/Magna-CMS)

A professional, headless blog and content plugin for **Magna CMS**. It manages blog content through the
Magna admin panel using an **Editor.js** block editor, and exposes a clean, read-only delivery API for
any frontend — a headless site, a static build, or a native app.

Magna Blog does not render a frontend of its own. It owns content authoring, organisation and delivery;
presentation is left to your site.

---

## Overview

- **Block editor** — a rich Editor.js authoring experience with 30+ content blocks, sanitised
  server-side and delivered as structured JSON.
- **Full content model** — posts, nested categories, tags, series, co-authors, per-post language and
  translation groups.
- **Editorial workflow** — draft, pending review, scheduled, published and archived states, with soft-
  delete Trash, revisions and scheduled publishing.
- **Delivery API** — a versioned, cache-friendly JSON API for headless frontends.
- **Engagement** — threaded comments with spam protection, and anonymous reactions.
- **Operations** — CSV export, WordPress (WXR) import, GDPR personal-data export/erase, and webhooks.
- **Optional SEO** — integrates with the Magna SEO plugin for per-post metadata when it is installed.

## Features

- **Posts** — title, slug, excerpt, Editor.js content, featured image, author and co-authors, category,
  tags, series and position, status, visibility (public / private / password-protected), and per-post
  locale with translation groups.
- **Editor.js** — a configurable, extensible block set; content is stored as JSON and sanitised on the
  server through an allowlist model.
- **Taxonomies** — nested **categories** and flat **tags**, each managed as its own admin resource, with
  a self-healing default ("Uncategorised") category.
- **Series** — group related posts into an ordered set with automatic previous/next navigation.
- **Revisions** — every create/update snapshots the post into the core revision store, pruned to a
  configurable maximum; restore any revision from the editor.
- **Scheduling** — scheduled posts publish automatically when their date arrives.
- **Delivery API** — read-only JSON for published, public posts, with filtering, search, pagination,
  related posts, adjacent posts, taxonomies, comments and reactions.
- **Comments** — held for moderation or auto-approved, protected by a honeypot and an optional Akismet
  driver; one level of threading.
- **Reactions** — anonymous, per-visitor reactions with a non-reversible fingerprint (no personal data
  stored).
- **Import / export** — bulk CSV export (formula-injection safe) and WordPress WXR import.
- **Privacy** — GDPR personal-data export and erase for authors and commenters.
- **Webhooks** — emits `blog.post.published`, `blog.post.updated` and `blog.post.deleted` events.
- **Internationalisation** — a `blog::` translation namespace for the admin surface.

## Requirements

- PHP **8.3+**
- **Magna CMS** ^1.0 (provides Filament v4, media library, users, permissions, settings, revisions,
  privacy and webhook subsystems)
- Node.js (only to build the editor bundle — see [Developer installation](#developer-installation))

## Installation

The recommended way to install Magna Blog is through the Magna CMS admin panel.

### 1. Install Magna CMS

Install and configure Magna CMS first.

### 2. Open the Magna Admin Panel

Sign in to the Magna CMS administration panel.

### 3. Open Plugins

Navigate to **Admin → Plugins**.

### 4. Install from Marketplace

Find **Magna Blog** in the Magna CMS Plugin Marketplace and click **Install**.

### 5. Activate

Activate the plugin if required.

### 6. Start blogging

Open the **Blog** section in the Magna Admin Panel and create your first post.

### Developer installation

For local development against a Magna CMS application, the plugin lives under the application's
`plugins-dev/` path repository. After placing the plugin there, build the editor bundle and enable it:

```bash
# From the plugin directory
npm ci
npm run build            # produces dist/blog-editor.js (a required runtime asset)

# From the Magna CMS application
php artisan filament:assets
```

Then enable **Magna Blog** from **Admin → Plugins**. The compiled editor bundle (`dist/blog-editor.js`)
is a build artifact and is not committed; installs and releases must build it.

## Configuration

Backend and content settings are edited from **Admin → Blog → Settings** (general, slugs, permalinks,
editor, content and comments). Infrastructure options — the comment spam driver and reaction types — live
in `config/blog.php` and are environment-driven:

```dotenv
BLOG_COMMENT_SPAM_DRIVER=honeypot   # or "akismet"
AKISMET_KEY=
BLOG_COMMENT_MIN_SECONDS=2
BLOG_DEFAULT_LOCALE=en
```

## Usage

Create and manage posts from **Admin → Blog**. The full-screen builder pairs an Editor.js canvas with a
tabbed sidebar for post settings, block settings and (when the Magna SEO plugin is installed) SEO.

## Editor

Content is authored with **Editor.js** and stored as a structured block document. The tool set is open
for extension without modifying this plugin — register a tool before an editor mounts:

```js
window.magnaBlog.registerTool('myBlock', { class: MyBlockTool, inlineToolbar: true });
```

Server-side security allowlists (block types, templates, code languages, social networks) live once, in
PHP, and are shared by the sanitiser and renderer. See
[docs/adding-a-block.md](docs/adding-a-block.md) for the full block-authoring checklist.

## API

The delivery API is auto-prefixed at `/api/v1/blog/` and protected by the `magna.api` middleware
(delivery-token auth with per-token rate limiting). Only published, public posts are ever returned.

| Method | Path                                  | Returns                                   |
| ------ | ------------------------------------- | ----------------------------------------- |
| GET    | `/api/v1/blog/posts`                  | Paginated, filterable, searchable list    |
| GET    | `/api/v1/blog/posts/{slug}`           | A single published public post            |
| GET    | `/api/v1/blog/categories`             | The category tree with post counts        |
| GET    | `/api/v1/blog/tags`                   | Tags with post counts                     |
| GET    | `/api/v1/blog/posts/{slug}/comments`  | Approved comments (threaded one level)    |
| POST   | `/api/v1/blog/posts/{slug}/comments`  | Submit a comment for moderation           |
| POST   | `/api/v1/blog/posts/{slug}/reactions` | Toggle a reaction                         |

Responses are frontend-agnostic and never expose internal fields (password hashes, soft-delete state).

## Permissions

```
blog.posts.view        blog.posts.create      blog.posts.edit
blog.posts.edit-others blog.posts.delete      blog.posts.publish     blog.posts.restore
blog.categories.manage blog.tags.manage       blog.series.manage
blog.comments.manage   blog.settings.manage
```

Per-record post actions follow a permission-plus-ownership rule: an author acts on their own posts, while
`blog.posts.edit-others` lifts the ownership restriction for editors and administrators.

## Content blocks

The editor ships a broad block set, including paragraph (with visual templates), headings, lists,
checklists, quotes and pullquotes, tables, code, callouts, CTAs, buttons, FAQ, details, galleries, cover,
image, video and audio, embeds, maps, social icons, footnotes, RSS, table of contents, related posts,
group and columns layouts, and dynamic post blocks (excerpt, featured image). Every block is validated
server-side before it can be persisted.

## Development

```bash
npm ci
npm run build            # rebuild the Editor.js bundle after changing resources/js/
php artisan filament:assets
```

## Testing

The plugin ships an extensive Pest suite (feature and unit) that runs inside a Magna CMS application.

```bash
# From the Magna CMS application
php artisan test --testsuite=Plugins

# Static analysis and style
vendor/bin/phpstan analyse -c plugins-dev/magna-cms/blog/phpstan.neon
vendor/bin/pint --test plugins-dev/magna-cms/blog
```

## Security

Content is protected in depth: an allowlist sanitiser cleans every Editor.js block before it is
persisted, and the renderer escapes on output. Outbound editor requests (link preview, RSS) are
SSRF-hardened with special-use IP-range blocking and connection pinning. CSV export is protected against
formula injection, imports parse XML with network access disabled, and per-record authorisation is
enforced server-side on every mutation.

To report a security issue, please contact the Magna CMS maintainers privately rather than opening a
public issue.

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

Magna Blog is open-source software licensed under the [MIT license](LICENSE).
