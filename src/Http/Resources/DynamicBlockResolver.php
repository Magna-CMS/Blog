<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Resources;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use MagnaCms\Blog\Models\Post;

/**
 * Fills the "dynamic" blocks (table of contents, related posts, post excerpt,
 * featured image) with data resolved from the post at delivery time. The editor
 * only stores each block's configuration; this keeps the stored document small
 * and always in sync with the post.
 */
final class DynamicBlockResolver
{
    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function resolve(array $document, Post $post): array
    {
        if (! isset($document['blocks']) || ! is_array($document['blocks'])) {
            return $document;
        }

        $headings = $this->headings($document['blocks']);

        foreach ($document['blocks'] as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];

            $document['blocks'][$index]['data'] = match ($type) {
                'toc' => [
                    'depth' => (int) ($data['depth'] ?? 3),
                    'items' => $this->tocItems($headings, (int) ($data['depth'] ?? 3)),
                ],
                'postExcerpt' => ['text' => (string) $post->excerpt],
                'featuredImage' => ['url' => $this->imageUrl($post->featured_image)],
                'relatedPosts' => array_merge($data, ['posts' => $this->related($post, $data)]),
                default => $data,
            };
        }

        return $document;
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<array{text: string, level: int}>
     */
    private function headings(array $blocks): array
    {
        $headings = [];
        foreach ($blocks as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'header') {
                $data = is_array($block['data'] ?? null) ? $block['data'] : [];
                $text = trim(strip_tags((string) ($data['text'] ?? '')));
                if ($text !== '') {
                    $headings[] = ['text' => $text, 'level' => (int) ($data['level'] ?? 2)];
                }
            }
        }

        return $headings;
    }

    /**
     * @param  list<array{text: string, level: int}>  $headings
     * @return list<array{text: string, level: int, anchor: string}>
     */
    private function tocItems(array $headings, int $depth): array
    {
        $items = [];
        foreach ($headings as $heading) {
            if ($heading['level'] <= $depth) {
                $items[] = [
                    'text' => $heading['text'],
                    'level' => $heading['level'],
                    'anchor' => Str::slug($heading['text']),
                ];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    private function related(Post $post, array $data): array
    {
        $count = max(1, min(12, is_numeric($data['count'] ?? null) ? (int) $data['count'] : 3));
        $by = ($data['by'] ?? 'category') === 'tag' ? 'tag' : 'category';

        $query = Post::query()->live()->public()->whereKeyNot($post->getKey());

        if ($by === 'tag') {
            $tagIds = $post->tags()->pluck('blog_tags.id')->all();
            if ($tagIds === []) {
                return [];
            }
            $query->whereHas('tags', fn ($q) => $q->whereIn('blog_tags.id', $tagIds));
        } else {
            if ($post->category_id === null) {
                return [];
            }
            $query->where('category_id', $post->category_id);
        }

        $posts = [];
        foreach ($query->orderByDesc('published_at')->limit($count)->get(['id', 'title', 'slug', 'excerpt', 'featured_image']) as $related) {
            $posts[] = [
                'title' => $related->title,
                'slug' => $related->slug,
                'excerpt' => $related->excerpt,
                'featured_image' => $this->imageUrl($related->featured_image),
            ];
        }

        return $posts;
    }

    private function imageUrl(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        return Storage::disk('public')->url($path);
    }
}
