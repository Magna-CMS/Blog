<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;

/**
 * Generates URL-safe, table-unique slugs. Reused by posts, categories, and tags
 * so slug rules live in exactly one place.
 */
final class SlugGenerator
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Build a unique slug for the given table. When $source already looks like a
     * deliberate slug it is respected; otherwise it is slugified. Uniqueness is
     * enforced by appending -2, -3, … while ignoring the given primary key
     * (so updating a record keeps its own slug).
     */
    public function generate(string $source, string $table, int|string|null $ignoreId = null, string $column = 'slug', string $keyName = 'id'): string
    {
        $base = Str::slug($source);

        if ($base === '') {
            $base = 'n-'.Str::lower(Str::random(8));
        }

        $slug = $base;
        $suffix = 2;

        while ($this->exists($slug, $table, $column, $ignoreId, $keyName)) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    private function exists(string $slug, string $table, string $column, int|string|null $ignoreId, string $keyName): bool
    {
        $query = $this->db->table($table)->where($column, $slug);

        if ($ignoreId !== null) {
            $query->where($keyName, '!=', $ignoreId);
        }

        return $query->exists();
    }
}
