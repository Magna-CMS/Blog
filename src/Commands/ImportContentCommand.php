<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Commands;

use Illuminate\Console\Command;
use MagnaCms\Blog\Support\BlogArchive;

/**
 * Imports a JSON bundle produced by blog:export. Idempotent: entities are matched
 * by slug, so re-running updates in place rather than duplicating.
 */
class ImportContentCommand extends Command
{
    protected $signature = 'blog:import {file : Path to the JSON bundle}';

    protected $description = 'Import blog content from a JSON bundle (idempotent, matched by slug).';

    public function handle(BlogArchive $archive): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $raw = file_get_contents($file);
        if ($raw === false) {
            $this->error("Cannot read: {$file}");

            return self::FAILURE;
        }

        $bundle = json_decode($raw, true);
        if (! is_array($bundle)) {
            $this->error('The file is not valid JSON.');

            return self::FAILURE;
        }

        try {
            $stats = $archive->import($bundle);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$stats['posts']} post(s), {$stats['categories']} category(ies), {$stats['tags']} tag(s).");

        return self::SUCCESS;
    }
}
