<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Commands;

use Illuminate\Console\Command;
use MagnaCms\Blog\Support\ViewCounter;

/**
 * Flushes cache-buffered post view counts to the database. Registered on the
 * scheduler (every five minutes) by BlogPlugin, so reads never write to the
 * posts row directly.
 */
class FlushViewsCommand extends Command
{
    protected $signature = 'blog:flush-views';

    protected $description = 'Persist cache-buffered blog post view counts to the database.';

    public function handle(ViewCounter $views): int
    {
        $flushed = $views->flush();

        $this->info("Flushed views for {$flushed} post(s).");

        return self::SUCCESS;
    }
}
