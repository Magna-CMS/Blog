<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Cache as CacheFacade;
use MagnaCms\Blog\Models\Post;

/**
 * Buffers post view counts in the cache and flushes them to the database
 * periodically, so a popular post is never a hot row updated on every read.
 *
 * - Each unique viewer (per post, per calendar day, per IP) is counted once; the
 *   dedupe key naturally bounds how many increments a viral post can generate.
 * - Pending counts accumulate under per-post cache keys; the ids with pending
 *   counts are tracked in a small set so the flush needs no cache-key enumeration
 *   (which most cache drivers cannot do portably).
 * - The dirty-set read/modify/write and the flush are guarded by a short cache
 *   lock so concurrent requests do not lose ids.
 */
class ViewCounter
{
    private const PENDING_PREFIX = 'blog:views:pending:';

    private const DIRTY_SET = 'blog:views:dirty';

    private const SEEN_PREFIX = 'blog:views:seen:';

    private const LOCK = 'blog:views:lock';

    public function __construct(private readonly Cache $cache) {}

    /**
     * Record one view of a post from a given client, deduplicated per IP per day.
     * Returns true when the view was counted, false when it was a repeat.
     */
    public function record(Post $post, ?string $ip): bool
    {
        $id = (int) $post->getKey();
        $seenKey = self::SEEN_PREFIX.$id.':'.now()->format('Ymd').':'.sha1((string) $ip);

        // add() is atomic: only the first caller per IP/day gets true.
        if (! $this->cache->add($seenKey, true, now()->addDay())) {
            return false;
        }

        $pendingKey = self::PENDING_PREFIX.$id;
        $this->cache->add($pendingKey, 0);
        $this->cache->increment($pendingKey);

        CacheFacade::lock(self::LOCK, 5)->block(3, function () use ($id): void {
            /** @var array<int, true> $dirty */
            $dirty = $this->cache->get(self::DIRTY_SET, []);
            $dirty[$id] = true;
            $this->cache->forever(self::DIRTY_SET, $dirty);
        });

        return true;
    }

    /**
     * Apply every pending buffer to the database and clear it. Returns the number
     * of posts whose count was advanced.
     */
    public function flush(): int
    {
        return (int) CacheFacade::lock(self::LOCK, 10)->block(5, function (): int {
            /** @var array<int, true> $dirty */
            $dirty = $this->cache->get(self::DIRTY_SET, []);
            $this->cache->forget(self::DIRTY_SET);

            $flushed = 0;

            foreach (array_keys($dirty) as $id) {
                $pendingKey = self::PENDING_PREFIX.$id;
                $count = (int) $this->cache->get($pendingKey, 0);
                $this->cache->forget($pendingKey);

                if ($count > 0) {
                    Post::query()->whereKey($id)->increment('views', $count);
                    $flushed++;
                }
            }

            return $flushed;
        });
    }
}
