<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Settings\BlogSettings;

/**
 * Resolves the fallback category for posts left uncategorised — WordPress-style.
 * A post with no category is filed under the admin-chosen default category if one
 * is set and still exists, otherwise under a self-healing "Uncategorised"
 * category that is created on demand and protected from deletion.
 */
final class DefaultCategory
{
    public const SLUG = 'uncategorised';

    /** Memoised for the request so a bulk save resolves it once. */
    private static ?int $uncategorisedId = null;

    /** The category id a post should fall back to when none is chosen. */
    public static function fallbackId(): int
    {
        $configured = BlogSettings::get()->default_category_id;

        if ($configured !== null && Category::query()->whereKey($configured)->exists()) {
            return $configured;
        }

        return self::uncategorisedId();
    }

    /** Id of the "Uncategorised" category, created if it does not yet exist. */
    public static function uncategorisedId(): int
    {
        if (self::$uncategorisedId !== null && Category::query()->whereKey(self::$uncategorisedId)->exists()) {
            return self::$uncategorisedId;
        }

        $category = Category::query()->firstOrCreate(
            ['slug' => self::SLUG],
            ['name' => 'Uncategorised'],
        );

        return self::$uncategorisedId = (int) $category->getKey();
    }

    public static function isDefault(Category $category): bool
    {
        return $category->slug === self::SLUG;
    }
}
