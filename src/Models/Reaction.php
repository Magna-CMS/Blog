<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single anonymous reaction on a post. Uniqueness on (post_id, type,
 * fingerprint) makes reacting idempotent per visitor; the fingerprint is a
 * hashed IP + user agent, never a raw identifier.
 *
 * @property int $id
 * @property int $post_id
 * @property string $type
 * @property string $fingerprint
 */
class Reaction extends Model
{
    protected $table = 'blog_reactions';

    protected $fillable = [
        'type',
        'fingerprint',
    ];

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
