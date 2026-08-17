<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Magna\Users\User;
use MagnaCms\Blog\Enums\CommentStatus;

/**
 * A blog comment. Comments are submitted through the delivery API and moderated
 * in the admin panel; only approved comments are ever served publicly.
 *
 * @property int $id
 * @property int $post_id
 * @property int|null $parent_id
 * @property string|null $author_id
 * @property string|null $author_name
 * @property string|null $author_email
 * @property string $body
 * @property CommentStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Post|null $post
 * @property-read User|null $author
 */
class Comment extends Model
{
    protected $table = 'blog_comments';

    protected $attributes = [
        'status' => 'pending',
    ];

    protected $fillable = [
        'post_id',
        'parent_id',
        'author_id',
        'author_name',
        'author_email',
        'body',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => CommentStatus::class,
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * @param  Builder<Comment>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', CommentStatus::Approved->value);
    }
}
