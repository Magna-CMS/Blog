<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named, ordered collection of posts (a multi-part series). Posts point back
 * via `series_id` + `series_position`; the delivery layer uses this to render
 * "Part N of M" navigation.
 *
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string|null $description
 */
class Series extends Model
{
    protected $table = 'blog_series';

    protected $fillable = [
        'title',
        'slug',
        'description',
    ];

    /** Posts in this series, ordered by their position then publish date. */
    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'series_id')
            ->orderByRaw('series_position is null')
            ->orderBy('series_position')
            ->orderBy('published_at');
    }
}
