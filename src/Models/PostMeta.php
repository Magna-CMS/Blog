<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MagnaCms\Blog\Enums\MetaType;

/**
 * A single typed custom field on a post. The stored `value` is JSON-encoded so
 * any scalar or structured value round-trips; `typedValue()` coerces it back
 * under its declared type for delivery and admin display.
 *
 * @property int $id
 * @property int $post_id
 * @property string $key
 * @property mixed $value
 * @property MetaType $type
 */
class PostMeta extends Model
{
    protected $table = 'blog_post_meta';

    protected $fillable = [
        'key',
        'value',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'json',
            'type' => MetaType::class,
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** The stored value coerced to its declared PHP type. */
    public function typedValue(): mixed
    {
        return $this->type->coerce($this->value);
    }
}
