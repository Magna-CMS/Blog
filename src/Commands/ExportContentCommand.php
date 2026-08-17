<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Commands;

use Illuminate\Console\Command;
use MagnaCms\Blog\Support\BlogArchive;

/**
 * Writes the blog's content to a portable JSON bundle. Defaults to
 * storage/app/blog-export.json; pass --out to choose another path.
 */
class ExportContentCommand extends Command
{
    protected $signature = 'blog:export {--out= : Destination file path (defaults to storage/app/blog-export.json)}';

    protected $description = 'Export all blog content (posts, taxonomies, meta) to a JSON bundle.';

    public function handle(BlogArchive $archive): int
    {
        $path = $this->stringOption('out') ?: storage_path('app/blog-export.json');

        $json = json_encode($archive->export(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $this->error('Failed to encode the export bundle.');

            return self::FAILURE;
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            $this->error("Cannot create directory [{$directory}].");

            return self::FAILURE;
        }

        if (file_put_contents($path, $json) === false) {
            $this->error("Cannot write to [{$path}].");

            return self::FAILURE;
        }

        $this->info("Exported blog content to {$path}.");

        return self::SUCCESS;
    }

    private function stringOption(string $name): string
    {
        $value = $this->option($name);

        return is_string($value) ? trim($value) : '';
    }
}
