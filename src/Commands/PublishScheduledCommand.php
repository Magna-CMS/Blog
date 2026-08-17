<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Models\Post;

/**
 * Promotes scheduled posts to published once their publish date has passed.
 * Registered on the Laravel scheduler (every minute) by BlogPlugin.
 */
class PublishScheduledCommand extends Command
{
    protected $signature = 'blog:publish-scheduled';

    protected $description = 'Publish scheduled blog posts whose publish date has passed.';

    public function handle(): int
    {
        // Select and update inside one transaction with a row lock so two runners
        // (e.g. overlapping cron on separate hosts, where withoutOverlapping only
        // guards a single host) can never both promote the same post: the second
        // waits on the lock, then finds nothing still Scheduled. Idempotent.
        $count = 0;

        DB::transaction(function () use (&$count): void {
            $due = Post::query()
                ->where('status', PostStatus::Scheduled->value)
                ->whereNotNull('published_at')
                ->where('published_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($due as $post) {
                $post->update(['status' => PostStatus::Published->value]);
                $count++;
            }
        });

        $this->info($count === 0
            ? 'No scheduled posts are due.'
            : "Published {$count} scheduled post(s).");

        return self::SUCCESS;
    }
}
