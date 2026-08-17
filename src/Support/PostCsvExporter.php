<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Support\Collection;
use MagnaCms\Blog\Models\Post;

/**
 * Serialises posts to a CSV string for the admin bulk-export action. Kept as a
 * pure service (no HTTP concerns) so the column mapping is unit-testable; the
 * resource action wraps the output in a streamed download.
 */
class PostCsvExporter
{
    private const HEADER = [
        'id', 'title', 'slug', 'status', 'visibility', 'category', 'author', 'published_at', 'views', 'featured',
    ];

    /**
     * @param  Collection<int, Post>  $posts
     */
    public function toCsv(Collection $posts): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // escape: '' disables PHP's legacy backslash escaping (its default is
        // changing in 8.4+ and would otherwise corrupt values); RFC 4180 quoting
        // still applies. enclosure stays the default double quote.
        fputcsv($handle, self::HEADER, escape: '');

        foreach ($posts as $post) {
            fputcsv($handle, [
                $post->id,
                $this->csvSafe($post->title),
                $this->csvSafe($post->slug),
                $post->status->value,
                $post->visibility->value,
                $this->csvSafe((string) data_get($post, 'category.name', '')),
                $this->csvSafe((string) data_get($post, 'author.name', '')),
                $post->published_at?->toIso8601String() ?? '',
                $post->views,
                $post->is_featured ? '1' : '0',
            ], escape: '');
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * Neutralise CSV formula injection (CWE-1236). A cell a spreadsheet would
     * evaluate as a formula — one that opens with =, +, -, @ or a control char —
     * is prefixed with a single quote so Excel / Sheets treat it as literal text.
     * Author-controlled fields (title, slug, category, author name) run through
     * here before they reach fputcsv.
     */
    private function csvSafe(string $value): string
    {
        if ($value !== '' && preg_match('/^[=+\-@\t\r]/', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }
}
