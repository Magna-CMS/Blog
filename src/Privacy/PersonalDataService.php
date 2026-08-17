<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Privacy;

use Illuminate\Support\Facades\DB;
use MagnaCms\Blog\Models\Comment;
use MagnaCms\Blog\Models\Post;

/**
 * GDPR export/erase for blog-owned personal data. Kept separate from the plugin
 * entry class so the logic is unit-testable and has a single responsibility.
 */
final class PersonalDataService
{
    /**
     * @return array<string, mixed>
     */
    public function export(int|string|null $userId): array
    {
        return [
            'blog_posts' => Post::withTrashed()
                ->where('author_id', $userId)
                ->get(['id', 'title', 'slug', 'status', 'created_at'])
                ->toArray(),
            'blog_comments' => Comment::query()
                ->where('author_id', $userId)
                ->get(['id', 'post_id', 'body', 'created_at'])
                ->toArray(),
        ];
    }

    public function erase(int|string|null $userId): void
    {
        DB::transaction(function () use ($userId): void {
            // Keep the content, sever the personal link.
            Post::withTrashed()->where('author_id', $userId)->update(['author_id' => null]);

            Comment::query()->where('author_id', $userId)->update([
                'author_id' => null,
                'author_name' => null,
                'author_email' => null,
            ]);
        });
    }
}
