<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Magna\Content\Models\Revision;
use Magna\Users\User;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Enums\PostVisibility;

/**
 * @property int $id
 * @property string $title
 * @property string $slug
 * @property string $locale
 * @property string|null $translation_group
 * @property string|null $excerpt
 * @property array<string, mixed>|null $content
 * @property string|null $search_text
 * @property string|null $featured_image
 * @property int|null $category_id
 * @property int|null $series_id
 * @property int|null $series_position
 * @property string|null $author_id
 * @property PostStatus $status
 * @property string|null $review_note
 * @property PostVisibility $visibility
 * @property bool $is_featured
 * @property int $views
 * @property bool $allow_comments
 * @property string|null $password
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Category|null $category
 * @property-read User|null $author
 */
class Post extends Model
{
    use SoftDeletes;

    /**
     * Stable identifier used as the polymorphic entry_type when writing to the
     * core magna_revisions table. Kept as a constant so a future table rename
     * cannot silently orphan existing revisions.
     */
    public const ENTRY_TYPE = 'blog_post';

    protected $table = 'blog_posts';

    /**
     * Transient (never persisted) marker set by the builder before an autosave
     * write, so PostObserver can skip the historical revision + the
     * `blog.post.updated` webhook for background saves. Declared as a real
     * property, so it is assigned directly and never routed through Eloquent's
     * __set / attribute bag.
     */
    public bool $autosaving = false;

    /**
     * Model-level defaults so a new post always carries status/visibility even
     * before it is persisted — important for revision snapshots taken on create.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
        'visibility' => 'public',
        'allow_comments' => true,
    ];

    protected $fillable = [
        'title',
        'slug',
        'locale',
        'translation_group',
        'excerpt',
        'content',
        'featured_image',
        'category_id',
        'series_id',
        'series_position',
        'author_id',
        'status',
        'review_note',
        'visibility',
        'is_featured',
        'allow_comments',
        'password',
        'published_at',
    ];

    /**
     * The hashed password is never exposed through array/JSON serialization,
     * so it cannot leak into the delivery API or admin payloads.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => PostStatus::class,
            'visibility' => PostVisibility::class,
            'is_featured' => 'boolean',
            'views' => 'integer',
            'series_position' => 'integer',
            'allow_comments' => 'boolean',
            'password' => 'hashed',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    /** @return BelongsTo<Series, $this> */
    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class, 'series_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blog_post_tag', 'post_id', 'tag_id');
    }

    /**
     * Additional contributors beyond the primary `author`.
     *
     * @return BelongsToMany<User, $this>
     */
    public function coAuthors(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'blog_post_author', 'post_id', 'user_id');
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    /** @return HasMany<PostMeta, $this> */
    public function meta(): HasMany
    {
        return $this->hasMany(PostMeta::class, 'post_id');
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class, 'post_id');
    }

    /**
     * The typed value of a single meta key, or $default when the key is unset.
     * Uses the loaded `meta` relation when present to avoid a per-call query.
     */
    public function metaValue(string $key, mixed $default = null): mixed
    {
        $row = $this->relationLoaded('meta')
            ? $this->meta->firstWhere('key', $key)
            : $this->meta()->where('key', $key)->first();

        return $row?->typedValue() ?? $default;
    }

    /**
     * Revision history for this post, newest first. Reads the core, polymorphic
     * magna_revisions table filtered to this post's entry type.
     *
     * @return HasMany<Revision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(Revision::class, 'entry_id')
            ->where('entry_type', self::ENTRY_TYPE)
            ->latest();
    }

    /**
     * Whether this post is safe to serve to the public right now: published,
     * public visibility, and its publish date reached. Mirrors the live()+public()
     * query scopes as an in-memory check so authorization (e.g. preview access)
     * shares the exact same "is this already public" rule.
     */
    public function isPubliclyVisible(): bool
    {
        return $this->status === PostStatus::Published
            && $this->visibility === PostVisibility::Public
            && ($this->published_at === null || $this->published_at->lessThanOrEqualTo(now()));
    }

    /**
     * Published posts whose publish date has arrived.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', PostStatus::Published->value)
            ->where(function (Builder $q): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            });
    }

    /**
     * Posts safe to serve without authentication (public visibility only).
     *
     * @param  Builder<Post>  $query
     */
    public function scopePublic(Builder $query): void
    {
        $query->where('visibility', PostVisibility::Public->value);
    }

    /**
     * Featured / sticky posts only.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeFeatured(Builder $query): void
    {
        $query->where('is_featured', true);
    }

    /**
     * Free-text search over the denormalised search column. Portable LIKE with a
     * leading wildcard (not index-backed); swap for FULLTEXT/Scout behind this
     * scope later without touching callers. Each whitespace-separated term must
     * match (AND), so multi-word queries narrow rather than widen.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeSearch(Builder $query, string $term): void
    {
        $terms = array_slice(array_filter(preg_split('/\s+/', trim($term)) ?: []), 0, 10);

        foreach ($terms as $word) {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $word);
            $query->where('search_text', 'like', '%'.$escaped.'%');
        }
    }

    /**
     * Restrict to a category by slug.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeInCategory(Builder $query, string $slug): void
    {
        $query->whereHas('category', fn (Builder $q) => $q->where('slug', $slug));
    }

    /**
     * Restrict to posts carrying a tag by slug.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeWithTag(Builder $query, string $slug): void
    {
        $query->whereHas('tags', fn (Builder $q) => $q->where('blog_tags.slug', $slug));
    }

    /**
     * Restrict to a single locale.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeLocale(Builder $query, string $locale): void
    {
        $query->where('locale', $locale);
    }

    /**
     * Restrict to a series by slug, ordered by position within that series.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeInSeries(Builder $query, string $slug): void
    {
        $query->whereHas('series', fn (Builder $q) => $q->where('slug', $slug))
            ->reorder()
            ->orderByRaw('series_position is null')
            ->orderBy('series_position')
            ->orderByDesc('published_at');
    }

    /**
     * Restrict to an author by their (string) user id.
     *
     * @param  Builder<Post>  $query
     */
    public function scopeByAuthor(Builder $query, string $authorId): void
    {
        $query->where('author_id', $authorId);
    }

    /**
     * Archive filter on the publish date. Month is only applied alongside a year.
     *
     * @param  Builder<Post>  $query
     */
    public function scopePublishedIn(Builder $query, int $year, ?int $month = null): void
    {
        $query->whereYear('published_at', $year);

        if ($month !== null) {
            $query->whereMonth('published_at', $month);
        }
    }

    /**
     * The immediately newer / older live public post, by publish date then id for
     * a stable total order even when two posts share a timestamp. Direction is
     * 'next' (newer) or 'prev' (older).
     */
    public function adjacent(string $direction): ?self
    {
        $newer = $direction === 'next';
        $anchor = $this->published_at ?? $this->created_at ?? now();

        return static::query()
            ->live()
            ->public()
            ->whereKeyNot($this->getKey())
            ->where(function (Builder $q) use ($anchor, $newer): void {
                $operator = $newer ? '>' : '<';
                $q->where('published_at', $operator, $anchor)
                    ->orWhere(function (Builder $tie) use ($anchor, $newer): void {
                        $tie->where('published_at', $anchor)
                            ->where('id', $newer ? '>' : '<', $this->getKey());
                    });
            })
            ->orderBy('published_at', $newer ? 'asc' : 'desc')
            ->orderBy('id', $newer ? 'asc' : 'desc')
            ->first(['id', 'title', 'slug']);
    }

    /** Concatenated searchable text (title + excerpt + flattened blocks). */
    public function searchableText(): string
    {
        return trim(implode(' ', array_filter([
            $this->title,
            $this->excerpt ?? '',
            $this->plainText(),
        ])));
    }

    /** Estimated reading time in minutes, derived from the Editor.js text. */
    public function readingTimeMinutes(): int
    {
        $words = str_word_count($this->plainText());

        return (int) max(1, (int) round($words / 200));
    }

    /** Flatten the Editor.js block document to plain text. */
    public function plainText(): string
    {
        $blocks = is_array($this->content) ? ($this->content['blocks'] ?? []) : [];
        $parts = [];

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $data = $block['data'] ?? [];
            if (! is_array($data)) {
                continue;
            }

            foreach (['text', 'title', 'message', 'caption', 'code'] as $key) {
                if (isset($data[$key]) && is_string($data[$key])) {
                    $parts[] = strip_tags($data[$key]);
                }
            }
        }

        return trim(implode(' ', $parts));
    }
}
