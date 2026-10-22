<?php

declare(strict_types=1);

namespace MagnaCms\Blog\SiteKit;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Magna\Contracts\SiteKitSection;
use Magna\Users\User;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\Tag;

/**
 * Blog posts travel in a site kit under 'blog:posts' — so a theme's demo
 * content can include real posts, and a site promoted between environments
 * brings its writing with it.
 *
 * A row is the BlogArchive post shape, keyed by slug, plus what a kit needs
 * that an archive does not:
 *
 *   "category": {"slug": "craft", "name": "Craft"}   created when missing
 *   "tags": ["design", {"slug": "type", "name": "Typography"}]
 *   "featured_image": "data:image/jpeg;base64,…"     stored on the public disk
 *   "published_days_ago": 3                          a demo that stays recent
 *
 * A kit is a file somebody can edit, so it earns no more trust than the post
 * editor: content goes through EditorJsSanitizer, an inline image must decode
 * as the image type it claims and stay under a size cap, a malformed row is
 * skipped, and an author is only ever the user applying the kit.
 */
class PostsSection implements SiteKitSection
{
    /** An inline featured image larger than this is skipped, not stored. */
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    private const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function __construct(private readonly EditorJsSanitizer $sanitizer) {}

    public function key(): string
    {
        return 'blog:posts';
    }

    /** @return list<array<string, mixed>> */
    public function export(): array
    {
        return Post::query()
            ->with(['category:id,slug,name', 'tags:id,slug,name'])
            ->orderBy('slug')
            ->get()
            ->map(fn (Post $p): array => array_filter([
                'slug' => $p->slug,
                'title' => $p->title,
                'locale' => $p->locale,
                'excerpt' => $p->excerpt,
                'content' => is_array($p->content) ? $p->content : ['blocks' => []],
                // By path: the bytes stay on this environment's disk.
                'featured_image' => $p->featured_image,
                'category' => $p->category !== null ? ['slug' => $p->category->slug, 'name' => $p->category->name] : null,
                'tags' => $p->tags->map(fn (Tag $t): array => ['slug' => $t->slug, 'name' => $t->name])->values()->all(),
                'status' => $p->status->value,
                'visibility' => $p->visibility->value,
                'is_featured' => $p->is_featured,
                'allow_comments' => $p->allow_comments,
                'published_at' => $p->published_at?->toIso8601String(),
            ], fn ($v): bool => $v !== null))
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{added: list<string>, changed: list<string>, localOnly: list<string>}
     */
    public function diff(array $rows): array
    {
        $incoming = [];
        foreach ($rows as $row) {
            $slug = $this->slug($row['slug'] ?? null);
            if ($slug !== '') {
                $incoming[$slug] = $row;
            }
        }

        // Trashed posts count as present: their slug is still taken.
        $local = Post::withTrashed()->get(['slug', 'title', 'excerpt'])->keyBy('slug');

        $added = $changed = [];
        foreach ($incoming as $slug => $row) {
            $post = $local->get($slug);

            if ($post === null) {
                $added[] = $slug;
            } elseif ($post->title !== ($row['title'] ?? $post->title) || $post->excerpt !== ($row['excerpt'] ?? $post->excerpt)) {
                $changed[] = $slug;
            }
        }

        return [
            'added' => $added,
            'changed' => $changed,
            'localOnly' => array_values(array_diff($local->keys()->all(), array_keys($incoming))),
        ];
    }

    /** @param  list<array<string, mixed>>  $rows */
    public function apply(array $rows, ?string $actorId = null): int
    {
        $author = $actorId !== null && User::query()->whereKey($actorId)->exists() ? $actorId : null;
        $written = 0;

        foreach ($rows as $row) {
            $slug = $this->slug($row['slug'] ?? null);
            $title = is_string($row['title'] ?? null) ? trim($row['title']) : '';

            if ($slug === '' || $title === '') {
                continue;
            }

            $post = Post::withTrashed()->firstOrNew(['slug' => $slug]);

            $post->fill([
                'title' => Str::limit($title, 250, ''),
                'locale' => is_string($row['locale'] ?? null) && $row['locale'] !== '' ? $row['locale'] : (string) config('blog.locales.default', 'en'),
                'excerpt' => is_string($row['excerpt'] ?? null) ? Str::limit($row['excerpt'], 1000, '') : null,
                'content' => $this->sanitizer->sanitize($row['content'] ?? ['blocks' => []]),
                'category_id' => $this->categoryId($row['category'] ?? null),
                'status' => in_array($row['status'] ?? null, ['draft', 'published'], true) ? $row['status'] : 'published',
                'visibility' => 'public',
                'is_featured' => (bool) ($row['is_featured'] ?? false),
                'allow_comments' => (bool) ($row['allow_comments'] ?? true),
                'published_at' => $this->publishedAt($row),
            ]);

            if (! $post->exists && $author !== null) {
                $post->author_id = $author;
            }

            $image = $this->featuredImage($row['featured_image'] ?? null, $slug);
            if ($image !== null) {
                $post->featured_image = $image;
            }

            $post->save();

            if ($post->trashed()) {
                $post->restore();
            }

            $post->tags()->sync($this->tagIds(is_array($row['tags'] ?? null) ? $row['tags'] : []));
            $written++;
        }

        return $written;
    }

    /** @param  array<string, mixed>  $row */
    private function publishedAt(array $row): Carbon
    {
        if (is_numeric($row['published_days_ago'] ?? null)) {
            return now()->subDays(max(0, min(3650, (int) $row['published_days_ago'])))->startOfHour();
        }

        try {
            return is_string($row['published_at'] ?? null) ? Carbon::parse($row['published_at']) : now();
        } catch (\Throwable) {
            return now();
        }
    }

    private function categoryId(mixed $category): ?int
    {
        $slug = $this->slug(is_array($category) ? ($category['slug'] ?? null) : $category);

        if ($slug === '') {
            return null;
        }

        $name = is_array($category) && is_string($category['name'] ?? null) && trim($category['name']) !== ''
            ? Str::limit(trim($category['name']), 120, '')
            : Str::headline($slug);

        // Created when missing, never renamed: an existing category is the site's.
        return (int) Category::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->getKey();
    }

    /**
     * @param  array<int, mixed>  $tags
     * @return list<int>
     */
    private function tagIds(array $tags): array
    {
        $ids = [];

        foreach (array_slice($tags, 0, 30) as $tag) {
            $slug = $this->slug(is_array($tag) ? ($tag['slug'] ?? $tag['name'] ?? null) : $tag);
            if ($slug === '') {
                continue;
            }

            $name = is_array($tag) && is_string($tag['name'] ?? null) && trim($tag['name']) !== ''
                ? Str::limit(trim($tag['name']), 80, '')
                : (is_string($tag) ? Str::limit(trim($tag), 80, '') : $slug);

            $ids[] = (int) Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->getKey();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Store an inline (data URI) featured image and return its public-disk
     * path; a plain path is kept as given (an export round-tripping within
     * one environment). Anything else — a remote URL, a bad or oversized
     * image, a type it does not claim — is dropped: the post still imports,
     * without a picture.
     */
    private function featuredImage(mixed $value, string $slug): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        if (! str_starts_with($value, 'data:')) {
            return preg_match('#^[A-Za-z0-9._/-]{1,255}$#', $value) === 1 && ! str_contains($value, '..') ? $value : null;
        }

        if (preg_match('#^data:(image/(?:jpeg|png|webp|gif));base64,([A-Za-z0-9+/=\s]+)$#', $value, $m) !== 1) {
            return null;
        }

        if (strlen($m[2]) > (int) ceil(self::MAX_IMAGE_BYTES * 4 / 3) + 4) {
            return null;
        }

        $bytes = base64_decode($m[2], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);

        if ($bytes === false || strlen($bytes) > self::MAX_IMAGE_BYTES || $info === false || ($info['mime'] ?? null) !== $m[1]) {
            return null;
        }

        $path = 'blog-featured/'.$slug.'-'.substr(hash('sha256', $bytes), 0, 10).'.'.self::IMAGE_TYPES[$m[1]];
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    private function slug(mixed $value): string
    {
        return is_string($value) ? Str::limit(Str::slug($value), 190, '') : '';
    }
}
