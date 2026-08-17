<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $image
 * @property int|null $parent_id
 * @property string|null $description
 * @property-read Category|null $parent
 */
class Category extends Model
{
    protected $table = 'blog_categories';

    protected $fillable = [
        'name',
        'slug',
        'image',
        'parent_id',
        'description',
    ];

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'category_id');
    }

    /**
     * Ancestor trail from the root down to (but not including) this category.
     * Guards against a cyclic parent_id chain so a corrupt tree cannot hang
     * the request.
     *
     * @return list<self>
     */
    public function ancestors(): array
    {
        $trail = [];
        $seen = [];
        $node = $this->parent;

        while ($node !== null && ! isset($seen[$node->id])) {
            $seen[$node->id] = true;
            array_unshift($trail, $node);
            $node = $node->parent;
        }

        return $trail;
    }

    /**
     * IDs of every descendant (children, grandchildren, …), resolved breadth-first
     * and cycle-safe. Used to keep a category's parent picker from offering its own
     * subtree, which would otherwise let an editor create a loop in the hierarchy.
     *
     * @return list<int>
     */
    public function descendantIds(): array
    {
        $ids = [];
        $frontier = [$this->getKey()];
        $seen = [$this->getKey() => true];

        while ($frontier !== []) {
            /** @var list<int> $children */
            $children = self::query()->whereIn('parent_id', $frontier)->pluck('id')->all();

            $frontier = [];
            foreach ($children as $id) {
                if (! isset($seen[$id])) {
                    $seen[$id] = true;
                    $ids[] = $id;
                    $frontier[] = $id;
                }
            }
        }

        return $ids;
    }
}
