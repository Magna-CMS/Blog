<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Blocks\DataSources;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Magna\Blocks\DataSources\DataSourcePage;
use Magna\Blocks\DataSources\DeclaresCacheability;
use Magna\Blocks\DataSources\PaginatedDataSource;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Support\PostPermalink;

/**
 * Blog posts, as a feed the Pages Loop block can iterate.
 *
 * This is the whole seam between the blog and visual page building: the blog
 * knows nothing about pages, and the builder knows nothing about posts — the
 * Loop block's source picker is populated from the DataSourceRegistry, so
 * registering this makes "latest posts" placeable on any page with no change
 * to core or to the Pages plugin.
 *
 * Two instances rather than two classes, and a `featured` flag rather than a
 * config key: LoopBlockResolver passes only `limit` to fetch(), so a variant
 * that the editor can choose has to be its own registered HANDLE. Filtering
 * by category or series would need a new field on the Loop block itself.
 *
 * Paginated (one page plus the total) and cache-aware since 1.2.0: a listing
 * page answers to the `blog:posts` purge tag, and expires on its own when a
 * post dated in the future goes live. A `locale` config key narrows the feed
 * to the posts that locale serves; without one, every live post is listed,
 * as before.
 */
final class PostsSource implements DeclaresCacheability, PaginatedDataSource
{
    /** Mirrors LoopBlockResolver's clamp; a source must not trust its caller. */
    public const MAX_ITEMS = 50;

    private const DEFAULT_ITEMS = 6;

    /** The purge tag every public post listing answers to. */
    public const CACHE_TAG = 'blog:posts';

    public function __construct(private readonly bool $featuredOnly = false) {}

    public function handle(): string
    {
        return $this->featuredOnly ? 'blog.featured' : 'blog.posts';
    }

    public function label(): string
    {
        return $this->featuredOnly ? 'Blog — featured posts' : 'Blog — latest posts';
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, string>>
     */
    public function fetch(array $config): array
    {
        $limit = is_numeric($config['limit'] ?? null)
            ? max(1, min(self::MAX_ITEMS, (int) $config['limit']))
            : self::DEFAULT_ITEMS;

        $permalink = PostPermalink::fromSettings();

        return $this->items($this->query($config, $permalink)->limit($limit), $permalink);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function fetchPage(array $config, int $page, int $perPage): DataSourcePage
    {
        $perPage = max(1, min(self::MAX_ITEMS, $perPage));
        $permalink = PostPermalink::fromSettings();
        $query = $this->query($config, $permalink);

        $total = (clone $query)->toBase()->getCountForPagination();

        return new DataSourcePage(
            $this->items($query->offset((max(1, $page) - 1) * $perPage)->limit($perPage), $permalink),
            $total,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public function cacheTags(array $config): array
    {
        return [self::CACHE_TAG];
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function nextChangeAt(array $config): ?DateTimeInterface
    {
        return Post::nextGoLiveAt(self::locale($config), $this->featuredOnly);
    }

    /**
     * SECURITY: this runs on the public render path, for an anonymous
     * visitor, with a config that came out of an editable document. The
     * document is editor input, never an authorization — so the filters
     * below are unconditional and there is no parameter that can relax
     * them.
     *
     *   live()               published, and the publish date has arrived
     *   public()             excludes both Private and Password posts
     *   whereNull(password)  belt-and-braces: a stray password on a post
     *                        still marked Public must not leak its title
     *
     * SoftDeletes excludes trashed posts by default; nothing here
     * reintroduces them. A locale only ever narrows the set.
     *
     * @param  array<string, mixed>  $config
     * @return Builder<Post>
     */
    private function query(array $config, PostPermalink $permalink): Builder
    {
        $locale = self::locale($config);

        $query = Post::query()
            ->live()
            ->public()
            ->whereNull('password')
            ->when($this->featuredOnly, fn (Builder $builder) => $builder->featured())
            ->when($locale !== null, fn (Builder $builder) => $builder->servedIn((string) $locale))
            ->select(['id', 'title', 'slug', 'locale', 'excerpt', 'generated_excerpt', 'featured_image', 'category_id', 'published_at', 'created_at'])
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        // Only when the pattern actually reads it — a loop is N rows on
        // every uncached render, and an unused eager load is a second query
        // for nothing.
        if ($permalink->usesCategory()) {
            $query->with('category:id,slug');
        }

        return $query;
    }

    /**
     * @param  Builder<Post>  $query
     * @return list<array<string, string>>
     */
    private function items(Builder $query, PostPermalink $permalink): array
    {
        $items = [];

        foreach ($query->get() as $post) {
            $title = (string) $post->title;
            if ($title === '' || (string) $post->slug === '') {
                continue;
            }

            // Keys are omitted rather than set to null: the contract is a
            // map of STRINGS, and the loop view treats an absent key and an
            // empty one the same way.
            $item = ['title' => $title, 'url' => $permalink->url($post)];

            $summary = $post->summary();
            if ($summary !== null) {
                $item['description'] = $summary;
            }

            $image = (string) ($post->featured_image ?? '');
            if ($image !== '') {
                $item['image'] = Storage::disk('public')->url($image);
            }

            if ($post->published_at !== null) {
                $item['date'] = $post->published_at->format('Y-m-d');
            }

            $items[] = $item;
        }

        return $items;
    }

    /** @param  array<string, mixed>  $config */
    private static function locale(array $config): ?string
    {
        $locale = $config['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? $locale : null;
    }
}
