<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Commands;

use Illuminate\Console\Command;
use MagnaCms\Blog\Models\Post;

/**
 * Rebuilds the denormalised `search_text` column for every post. The column is
 * normally maintained by PostObserver::saving(), but bulk writes that bypass
 * model events — imports, revision restores, raw SQL — can leave it stale. This
 * command is the recovery path; it re-saves each post so the same single source
 * of truth (Post::searchableText()) is reapplied.
 */
class ReindexSearchCommand extends Command
{
    protected $signature = 'blog:reindex';

    protected $description = 'Rebuild the search_text column for all blog posts.';

    public function handle(): int
    {
        $count = 0;

        Post::withTrashed()->chunkById(200, function ($posts) use (&$count): void {
            foreach ($posts as $post) {
                $post->search_text = $post->searchableText();
                $post->saveQuietly();
                $count++;
            }
        });

        $this->info("Reindexed {$count} post(s).");

        return self::SUCCESS;
    }
}
