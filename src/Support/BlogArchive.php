<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Support\Facades\DB;
use Magna\Users\User;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Series;
use MagnaCms\Blog\Models\Tag;

/**
 * Exports the blog's content to a portable JSON bundle and imports it back,
 * idempotently. Entities are keyed by slug (categories, tags, posts) and authors
 * by email, so a bundle round-trips within one install and can seed another
 * without carrying database ids. Media is referenced by path, not copied; the
 * hashed post password, view counts and derived columns are intentionally never
 * exported.
 */
class BlogArchive
{
    /** Bundle schema version; bump when the shape changes incompatibly. */
    public const SCHEMA = 1;

    public function __construct(private readonly EditorJsSanitizer $sanitizer) {}

    /**
     * Build the export bundle from every non-trashed post plus all taxonomies.
     *
     * @return array<string, mixed>
     */
    public function export(): array
    {
        $categories = Category::query()
            ->with('parent:id,slug')
            ->orderBy('id')
            ->get()
            ->map(fn (Category $c): array => [
                'name' => $c->name,
                'slug' => $c->slug,
                'description' => $c->description,
                'parent_slug' => $c->parent?->slug,
            ])
            ->all();

        $tags = Tag::query()
            ->orderBy('name')
            ->get(['name', 'slug'])
            ->map(fn (Tag $t): array => ['name' => $t->name, 'slug' => $t->slug])
            ->all();

        $series = Series::query()
            ->orderBy('title')
            ->get(['title', 'slug', 'description'])
            ->map(fn (Series $s): array => ['title' => $s->title, 'slug' => $s->slug, 'description' => $s->description])
            ->all();

        $posts = Post::query()
            ->with(['category:id,slug', 'series:id,slug', 'author:id,email', 'tags:id,slug', 'meta', 'coAuthors:id,email'])
            ->orderBy('id')
            ->get()
            ->map(fn (Post $p): array => [
                'title' => $p->title,
                'slug' => $p->slug,
                'locale' => $p->locale,
                'translation_group' => $p->translation_group,
                'excerpt' => $p->excerpt,
                'content' => is_array($p->content) ? $p->content : ['blocks' => []],
                'featured_image' => $p->featured_image,
                'category_slug' => $p->category?->slug,
                'series_slug' => $p->series?->slug,
                'series_position' => $p->series_position,
                'author_email' => $p->author?->email,
                'co_author_emails' => $p->coAuthors->pluck('email')->filter()->values()->all(),
                'status' => $p->status->value,
                'visibility' => $p->visibility->value,
                'is_featured' => $p->is_featured,
                'allow_comments' => $p->allow_comments,
                'published_at' => $p->published_at?->toIso8601String(),
                'tags' => $p->tags->pluck('slug')->all(),
                'meta' => $p->meta
                    ->map(fn ($m): array => ['key' => $m->key, 'type' => $m->type->value, 'value' => $m->value])
                    ->all(),
            ])
            ->all();

        return [
            'schema' => self::SCHEMA,
            'generator' => 'magna-cms/blog',
            'exported_at' => now()->toIso8601String(),
            'categories' => $categories,
            'tags' => $tags,
            'series' => $series,
            'posts' => $posts,
        ];
    }

    /**
     * Import a bundle, upserting by slug so re-running never duplicates content.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{categories: int, tags: int, posts: int}
     */
    public function import(array $bundle): array
    {
        $schema = $bundle['schema'] ?? null;
        if ($schema !== self::SCHEMA) {
            throw new \InvalidArgumentException(
                "Unsupported bundle schema [{$this->describe($schema)}]; expected ".self::SCHEMA.'.'
            );
        }

        return DB::transaction(function () use ($bundle): array {
            $categories = $this->importCategories(is_array($bundle['categories'] ?? null) ? $bundle['categories'] : []);
            $tags = $this->importTags(is_array($bundle['tags'] ?? null) ? $bundle['tags'] : []);
            $this->importSeries(is_array($bundle['series'] ?? null) ? $bundle['series'] : []);
            $posts = $this->importPosts(is_array($bundle['posts'] ?? null) ? $bundle['posts'] : []);

            return ['categories' => $categories, 'tags' => $tags, 'posts' => $posts];
        });
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function importCategories(array $rows): int
    {
        $count = 0;

        // First pass: upsert every category without touching parents.
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['slug'] ?? null) || $row['slug'] === '') {
                continue;
            }

            Category::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'name' => (string) ($row['name'] ?? $row['slug']),
                    'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
                ],
            );
            $count++;
        }

        // Second pass: link parents now that every slug exists.
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['slug'] ?? null)) {
                continue;
            }

            $parentSlug = $row['parent_slug'] ?? null;
            if (! is_string($parentSlug) || $parentSlug === '') {
                continue;
            }

            $child = Category::query()->where('slug', $row['slug'])->first();
            $parent = Category::query()->where('slug', $parentSlug)->first();
            if ($child !== null && $parent !== null && $child->getKey() !== $parent->getKey()) {
                $child->update(['parent_id' => $parent->getKey()]);
            }
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function importTags(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['slug'] ?? null) || $row['slug'] === '') {
                continue;
            }

            Tag::query()->updateOrCreate(
                ['slug' => $row['slug']],
                ['name' => (string) ($row['name'] ?? $row['slug'])],
            );
            $count++;
        }

        return $count;
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function importSeries(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['slug'] ?? null) || $row['slug'] === '') {
                continue;
            }

            Series::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'title' => (string) ($row['title'] ?? $row['slug']),
                    'description' => is_string($row['description'] ?? null) ? $row['description'] : null,
                ],
            );
        }
    }

    /**
     * @param  array<int, mixed>  $rows
     */
    private function importPosts(array $rows): int
    {
        $count = 0;

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['slug'] ?? null) || $row['slug'] === '') {
                continue;
            }

            $post = Post::query()->updateOrCreate(
                ['slug' => $row['slug']],
                [
                    'title' => (string) ($row['title'] ?? $row['slug']),
                    'locale' => is_string($row['locale'] ?? null) && $row['locale'] !== '' ? $row['locale'] : (string) config('blog.locales.default', 'en'),
                    'translation_group' => is_string($row['translation_group'] ?? null) && $row['translation_group'] !== '' ? $row['translation_group'] : null,
                    'excerpt' => is_string($row['excerpt'] ?? null) ? $row['excerpt'] : null,
                    'content' => $this->sanitizer->sanitize($row['content'] ?? null),
                    'featured_image' => is_string($row['featured_image'] ?? null) ? $row['featured_image'] : null,
                    'category_id' => $this->categoryId($row['category_slug'] ?? null),
                    'series_id' => $this->seriesId($row['series_slug'] ?? null),
                    'series_position' => is_numeric($row['series_position'] ?? null) ? (int) $row['series_position'] : null,
                    'author_id' => $this->authorId($row['author_email'] ?? null),
                    'status' => (string) ($row['status'] ?? 'draft'),
                    'visibility' => (string) ($row['visibility'] ?? 'public'),
                    'is_featured' => (bool) ($row['is_featured'] ?? false),
                    'allow_comments' => (bool) ($row['allow_comments'] ?? true),
                    'published_at' => is_string($row['published_at'] ?? null) ? $row['published_at'] : null,
                ],
            );

            $this->syncTags($post, is_array($row['tags'] ?? null) ? $row['tags'] : []);
            $this->syncMeta($post, is_array($row['meta'] ?? null) ? $row['meta'] : []);
            $this->syncCoAuthors($post, is_array($row['co_author_emails'] ?? null) ? $row['co_author_emails'] : []);

            $count++;
        }

        return $count;
    }

    private function categoryId(mixed $slug): ?int
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Category::query()->where('slug', $slug)->value('id');
    }

    private function seriesId(mixed $slug): ?int
    {
        if (! is_string($slug) || $slug === '') {
            return null;
        }

        return Series::query()->where('slug', $slug)->value('id');
    }

    private function authorId(mixed $email): ?string
    {
        if (! is_string($email) || $email === '') {
            return null;
        }

        $id = User::query()->where('email', $email)->value('id');

        return $id !== null ? (string) $id : null;
    }

    /**
     * Attach the named tag slugs, creating any that the bundle referenced but did
     * not list in its tags section.
     *
     * @param  array<int, mixed>  $slugs
     */
    private function syncTags(Post $post, array $slugs): void
    {
        $ids = [];

        foreach ($slugs as $slug) {
            if (! is_string($slug) || $slug === '') {
                continue;
            }

            $tag = Tag::query()->firstOrCreate(
                ['slug' => $slug],
                ['name' => $slug],
            );
            $ids[] = $tag->getKey();
        }

        $post->tags()->sync($ids);
    }

    /**
     * Attach co-authors by email, resolving to existing users only (an unknown
     * email is skipped, never invented). Excludes the primary author.
     *
     * @param  array<int, mixed>  $emails
     */
    private function syncCoAuthors(Post $post, array $emails): void
    {
        $ids = [];

        foreach ($emails as $email) {
            if (! is_string($email) || $email === '') {
                continue;
            }

            $id = User::query()->where('email', $email)->value('id');
            if ($id !== null && (string) $id !== (string) $post->author_id) {
                $ids[] = (string) $id;
            }
        }

        $post->coAuthors()->sync($ids);
    }

    /**
     * Replace the post's meta with the bundle's, keyed by meta key.
     *
     * @param  array<int, mixed>  $rows
     */
    private function syncMeta(Post $post, array $rows): void
    {
        $kept = [];

        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['key'] ?? null) || $row['key'] === '') {
                continue;
            }

            $post->meta()->updateOrCreate(
                ['key' => $row['key']],
                [
                    'type' => (string) ($row['type'] ?? 'string'),
                    'value' => $row['value'] ?? null,
                ],
            );
            $kept[] = $row['key'];
        }

        $post->meta()->whereNotIn('key', $kept)->delete();
    }

    private function describe(mixed $schema): string
    {
        return is_scalar($schema) ? (string) $schema : gettype($schema);
    }
}
