<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Blocks\DataSources;

use Illuminate\Support\Facades\Storage;
use Magna\Blocks\DataSources\DataSource;
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
 */
final class PostsSource implements DataSource
{
    /** Mirrors LoopBlockResolver's clamp; a source must not trust its caller. */
    private const MAX_ITEMS = 50;

    private const DEFAULT_ITEMS = 6;

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

        /*
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
         * reintroduces them.
         */
        $query = Post::query()
            ->live()
            ->public()
            ->whereNull('password')
            ->when($this->featuredOnly, fn ($builder) => $builder->featured())
            ->select(['id', 'title', 'slug', 'excerpt', 'featured_image', 'category_id', 'published_at', 'created_at'])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($limit);

        // Only when the pattern actually reads it — a loop is N rows on
        // every uncached render, and an unused eager load is a second query
        // for nothing.
        if ($permalink->usesCategory()) {
            $query->with('category:id,slug');
        }

        $items = [];

        foreach ($query->get() as $post) {
            $title = (string) $post->title;
            if ($title === '' || (string) $post->slug === '') {
                continue;
            }

            // Keys are omitted rather than set to null: the contract is a
            // map of STRINGS, and the loop view treats an absent key and an
            // empty one the same way.
            $item = ['title' => $title, 'url' => $permalink->for($post)];

            $excerpt = (string) ($post->excerpt ?? '');
            if ($excerpt !== '') {
                $item['description'] = $excerpt;
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
}
