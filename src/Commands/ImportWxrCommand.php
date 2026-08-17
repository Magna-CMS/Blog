<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Commands;

use Illuminate\Console\Command;
use MagnaCms\Blog\Support\BlogArchive;
use MagnaCms\Blog\Support\WxrImporter;

/**
 * Imports a WordPress WXR (eXtended RSS) export file. The XML is mapped to the
 * portable bundle shape and handed to BlogArchive, so the import is idempotent
 * (matched by slug), transactional and sanitised. The HTML → Editor.js mapping
 * is best-effort; content with no clean block equivalent lands in raw blocks.
 */
class ImportWxrCommand extends Command
{
    protected $signature = 'blog:import-wxr {file : Path to the WordPress WXR (.xml) export}';

    protected $description = 'Import posts, categories and tags from a WordPress WXR export.';

    public function handle(WxrImporter $importer, BlogArchive $archive): int
    {
        $file = (string) $this->argument('file');

        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $xml = file_get_contents($file);
        if ($xml === false) {
            $this->error("Cannot read: {$file}");

            return self::FAILURE;
        }

        try {
            $bundle = $importer->toBundle($xml);
            $stats = $archive->import($bundle);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported {$stats['posts']} post(s), {$stats['categories']} category(ies), {$stats['tags']} tag(s) from WXR.");

        return self::SUCCESS;
    }
}
