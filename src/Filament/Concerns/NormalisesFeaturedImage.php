<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Concerns;

use Magna\Media\Concerns\IngestsMedia;

/**
 * Bridges Filament's FileUpload (which works with an array of paths) and the
 * blog_posts.featured_image column (a single media-library path string), and
 * registers freshly-uploaded files into the central media library on save.
 */
trait NormalisesFeaturedImage
{
    use IngestsMedia;

    /**
     * FileUpload → DB: collapse the array to a single path and ingest it into
     * the media library so it appears in /media with uploader attribution.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normaliseFeaturedImageForSave(array $data): array
    {
        $value = $data['featured_image'] ?? null;

        if (is_array($value)) {
            $value = (string) (array_values(array_filter($value))[0] ?? '');
        }

        $value = is_string($value) ? $value : '';

        $data['featured_image'] = $value === ''
            ? null
            : ($this->ingestToMedia($value) ?? $value);

        return $data;
    }

    /**
     * DB → FileUpload: wrap the stored string path in the array shape the
     * FileUpload component expects when hydrating the form.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normaliseFeaturedImageForForm(array $data): array
    {
        $value = $data['featured_image'] ?? null;

        if (is_string($value) && $value !== '') {
            $data['featured_image'] = [$value];
        }

        return $data;
    }
}
