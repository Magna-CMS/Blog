<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Listeners;

use Illuminate\Contracts\Events\Dispatcher;
use Magna\Frontend\Events\PublicContentChanged;
use MagnaCms\Blog\Blocks\DataSources\PostsSource;
use MagnaCms\Blog\Models\Post;

/**
 * The one place that knows the blog's public cache tags, and announces
 * when content behind them changed.
 *
 *   blog:post:{id}   a post page
 *   blog:posts       every post listing — and every post page, which
 *                    carries it too because its prev/next links and its
 *                    category/tag labels come from other posts
 *
 * The blog observers, and the settings page, call this on the writes that
 * change what a visitor sees. Whichever plugin caches public responses
 * (Magna Pages) listens for PublicContentChanged; the blog never learns
 * which one, or whether any does.
 */
final class PurgePublicBlogCache
{
    private const SOURCE = 'magna-cms/blog';

    public function __construct(private readonly Dispatcher $events) {}

    public static function postTag(int|string $postId): string
    {
        return 'blog:post:'.$postId;
    }

    /** A post's own page, and every listing it may appear in. */
    public function post(Post $post): void
    {
        $this->events->dispatch(PublicContentChanged::tagged(
            [self::postTag($post->id), PostsSource::CACHE_TAG],
            self::SOURCE,
        ));
    }

    /** Only a post's own page: its comments changed, nothing listed it differently. */
    public function postPage(int|string $postId): void
    {
        $this->events->dispatch(PublicContentChanged::tagged([self::postTag($postId)], self::SOURCE));
    }

    /** Every listing and every post page (a category or tag every post may show). */
    public function allPosts(): void
    {
        $this->events->dispatch(PublicContentChanged::tagged([PostsSource::CACHE_TAG], self::SOURCE));
    }

    /** Everything any cache holds: a setting can change any page the blog renders. */
    public function everything(): void
    {
        $this->events->dispatch(PublicContentChanged::all(self::SOURCE));
    }
}
