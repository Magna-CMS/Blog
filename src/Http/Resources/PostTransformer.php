<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Resources;

use Illuminate\Support\Facades\Storage;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Models\PostMeta;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\CommentPolicy;
use MagnaCms\Blog\Support\MetaRegistry;
use MagnaCms\Blog\Support\ReactionService;

/**
 * Shapes a Post into the frontend-agnostic JSON returned by the delivery API.
 * No internal columns (password hash, soft-delete state) are ever exposed.
 */
final class PostTransformer
{
    public function __construct(
        private readonly CommentPolicy $comments,
        private readonly DynamicBlockResolver $dynamic,
        private readonly MetaRegistry $metaRegistry,
        private readonly ReactionService $reactions,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(Post $post): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'slug' => $post->slug,
            'locale' => $post->locale,
            'excerpt' => $post->excerpt,
            'featured_image' => $this->imageUrl($post->featured_image),
            'author' => $post->author?->name,
            'category' => $post->category ? ['name' => $post->category->name, 'slug' => $post->category->slug] : null,
            'published_at' => $post->published_at?->toIso8601String(),
            'reading_time' => $post->readingTimeMinutes(),
            'featured' => $post->is_featured,
            'views' => $post->views,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function full(Post $post): array
    {
        return array_merge($this->summary($post), [
            'content' => $this->dynamic->resolve(
                is_array($post->content) ? $post->content : ['blocks' => []],
                $post,
            ),
            'tags' => $post->tags->map(fn ($tag): array => ['name' => $tag->name, 'slug' => $tag->slug])->all(),
            'co_authors' => $post->coAuthors->map(fn ($user): string => (string) $user->name)->all(),
            'comments' => [
                'enabled' => $this->comments->open($post),
                'require_login' => $this->comments->requiresLogin(),
            ],
            'adjacent' => [
                'prev' => $this->adjacent($post, 'prev'),
                'next' => $this->adjacent($post, 'next'),
            ],
            'meta' => $this->publicMeta($post),
            'reactions' => $this->reactions->counts($post),
            'series' => $this->seriesNav($post),
            'locale' => $post->locale,
            'translations' => $this->translations($post),
            'updated_at' => $post->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * The post's meta, filtered to keys explicitly marked public — the union of
     * the admin-managed BlogSettings allowlist and registry-declared public keys.
     * Custom fields are private by default so internal flags never leak.
     *
     * @return array<string, mixed>
     */
    private function publicMeta(Post $post): array
    {
        $allowed = array_flip(array_merge(
            BlogSettings::get()->public_meta,
            $this->metaRegistry->publicKeys(),
        ));

        if ($allowed === []) {
            return [];
        }

        return $post->meta
            ->filter(fn (PostMeta $meta): bool => isset($allowed[$meta->key]))
            ->mapWithKeys(fn (PostMeta $meta): array => [$meta->key => $meta->typedValue()])
            ->all();
    }

    /**
     * @return array{title: string, slug: string}|null
     */
    private function adjacent(Post $post, string $direction): ?array
    {
        $neighbour = $post->adjacent($direction);

        return $neighbour === null
            ? null
            : ['title' => $neighbour->title, 'slug' => $neighbour->slug];
    }

    /**
     * "Part N of M" series navigation for a post, or null when it is not in a
     * series. prev/next are the adjacent live-public posts by series position.
     *
     * @return array<string, mixed>|null
     */
    private function seriesNav(Post $post): ?array
    {
        if ($post->series_id === null || $post->series === null) {
            return null;
        }

        $siblings = Post::query()
            ->live()
            ->public()
            ->where('series_id', $post->series_id)
            ->orderByRaw('series_position is null')
            ->orderBy('series_position')
            ->orderBy('published_at')
            ->get(['id', 'title', 'slug']);

        $index = $siblings->search(fn (Post $sibling): bool => $sibling->getKey() === $post->getKey());
        $link = fn (?Post $p): ?array => $p === null ? null : ['title' => $p->title, 'slug' => $p->slug];

        return [
            'title' => $post->series->title,
            'slug' => $post->series->slug,
            'position' => $post->series_position,
            'total' => $siblings->count(),
            'prev' => is_int($index) && $index > 0 ? $link($siblings[$index - 1]) : null,
            'next' => is_int($index) && $index < $siblings->count() - 1 ? $link($siblings[$index + 1]) : null,
        ];
    }

    /**
     * Sibling translations of a post: other live-public posts sharing its
     * translation_group, as {locale, slug}. Empty when the post has no group.
     *
     * @return list<array{locale: string, slug: string}>
     */
    private function translations(Post $post): array
    {
        if ($post->translation_group === null || $post->translation_group === '') {
            return [];
        }

        $siblings = Post::query()
            ->live()
            ->public()
            ->where('translation_group', $post->translation_group)
            ->whereKeyNot($post->getKey())
            ->orderBy('locale')
            ->get(['locale', 'slug']);

        $out = [];
        foreach ($siblings as $sibling) {
            $out[] = ['locale' => $sibling->locale, 'slug' => $sibling->slug];
        }

        return $out;
    }

    private function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
