<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Magna\Frontend\UrlMove;
use MagnaCms\Blog\Models\Category;
use MagnaCms\Blog\Models\Post;

/**
 * Which public post URLs an edit moved, as old path → new path.
 *
 * A post's URL is built from its slug, date, category, locale and the
 * permalink pattern; changing any of them strands every link and search
 * result pointing at the old one. This works out the moves so the blog can
 * announce them (Magna\Frontend\Events\PublicUrlsMoved) to whichever plugin
 * serves redirects — the blog never knows which.
 *
 * Only a post that was live and public before the edit AND is still live
 * and public after it moves: a draft had no URL anyone could have shared,
 * and a post that left public view must stop answering, not redirect.
 *
 * Pattern edits and category renames touch every post they cover, so those
 * walk the posts in chunks of CHUNK — about three queries per live post in
 * total, synchronously, which a blog of a few thousand posts absorbs.
 */
final class PostUrlHistory
{
    private const CHUNK = 200;

    /** Every column a URL is built from, plus what isLive() reads. */
    private const COLUMNS = ['id', 'slug', 'locale', 'status', 'visibility', 'password', 'published_at', 'created_at', 'category_id', 'deleted_at'];

    /**
     * The move a post's pending or just-saved edit makes, read from its
     * original attributes (Eloquent keeps them until after `updated`).
     *
     * @return list<UrlMove>
     */
    public function movesForPost(Post $post): array
    {
        if (! $post->exists) {
            return [];
        }

        $before = $this->snapshot($post->getRawOriginal());
        $after = $this->snapshot($post->getAttributes());

        if (! self::isLive($before) || ! self::isLive($after)) {
            return [];
        }

        $permalink = PostPermalink::fromSettings();
        if ($permalink->usesCategory()) {
            $this->attachCategory($before);
            $this->attachCategory($after);
        }

        return self::move($permalink->url($before), $permalink->url($after));
    }

    /**
     * Every live post's move from one permalink pattern to another. Read the
     * old pattern BEFORE the new one is saved.
     *
     * @return list<UrlMove>
     */
    public function movesForPattern(string $oldPattern, string $newPattern): array
    {
        $from = PostPermalink::forPattern($oldPattern);
        $to = PostPermalink::forPattern($newPattern);

        if ($from->pattern() === $to->pattern()) {
            return [];
        }

        $query = $this->livePosts();
        if ($from->usesCategory() || $to->usesCategory()) {
            $query->with('category:id,slug');
        }

        $moves = [];
        $query->chunkById(self::CHUNK, function (Collection $posts) use ($from, $to, &$moves): void {
            foreach ($posts as $post) {
                array_push($moves, ...self::move($from->url($post), $to->url($post)));
            }
        });

        return $moves;
    }

    /**
     * The moves of every live post filed under $categoryId when that
     * category's URL segment goes from $oldSlug to $newSlug — a rename, or
     * a re-file under another category when this one is deleted. Nothing
     * moves when the pattern does not read the category.
     *
     * @return list<UrlMove>
     */
    public function movesForCategorySlug(int $categoryId, string $oldSlug, string $newSlug): array
    {
        $permalink = PostPermalink::fromSettings();
        if (! $permalink->usesCategory() || $oldSlug === $newSlug) {
            return [];
        }

        $old = (new Category)->forceFill(['id' => $categoryId, 'slug' => $oldSlug]);
        $new = (new Category)->forceFill(['id' => $categoryId, 'slug' => $newSlug]);

        $moves = [];
        $this->livePosts()
            ->where('category_id', $categoryId)
            ->chunkById(self::CHUNK, function (Collection $posts) use ($permalink, $old, $new, &$moves): void {
                foreach ($posts as $post) {
                    $from = $permalink->url($post->setRelation('category', $old));
                    $to = $permalink->url($post->setRelation('category', $new));
                    array_push($moves, ...self::move($from, $to));
                }
            });

        return $moves;
    }

    /** @return Builder<Post> */
    private function livePosts(): Builder
    {
        return Post::query()->live()->public()->whereNull('password')->select(self::COLUMNS);
    }

    /**
     * A detached copy of a post holding $attributes exactly as stored, so
     * the casts read them the way they read the row.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function snapshot(array $attributes): Post
    {
        return (new Post)->setRawAttributes($attributes, true);
    }

    private function attachCategory(Post $post): void
    {
        $category = $post->category_id === null
            ? null
            : Category::query()->whereKey($post->category_id)->first(['id', 'slug']);

        $post->setRelation('category', $category);
    }

    private static function isLive(Post $post): bool
    {
        return $post->isPubliclyVisible() && $post->password === null && $post->deleted_at === null;
    }

    /** @return list<UrlMove> */
    private static function move(string $from, string $to): array
    {
        $move = new UrlMove($from, $to);

        return $move->isNoop() ? [] : [$move];
    }
}
