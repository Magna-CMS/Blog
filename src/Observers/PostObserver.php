<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Observers;

use Illuminate\Support\Facades\DB;
use Magna\Content\Models\Revision;
use MagnaCms\Blog\Enums\PostStatus;
use MagnaCms\Blog\Models\Post;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\DefaultCategory;
use MagnaCms\Blog\Webhooks\BlogWebhookDispatcher;

/**
 * Writes an append-only revision to the core magna_revisions table whenever a
 * post is created or updated (pruned to the configured cap), and fires the
 * plugin's webhook events. Revision writing is gated by the "enable_revisions"
 * setting.
 */
class PostObserver
{
    public function __construct(private readonly BlogWebhookDispatcher $webhooks) {}

    /**
     * Keep the denormalised search column in sync on every write. Runs before the
     * row is persisted so a single source of truth (the post's own text) feeds
     * both storage and search — no second code path can drift out of sync.
     */
    public function saving(Post $post): void
    {
        $post->search_text = $post->searchableText();

        // WordPress-style: a post with no category is filed under the default
        // ("Uncategorised") category rather than left orphaned.
        if ($post->category_id === null) {
            $post->category_id = DefaultCategory::fallbackId();
        }
    }

    public function created(Post $post): void
    {
        $this->recordRevision($post);

        if ($post->status === PostStatus::Published) {
            $this->webhooks->fire('blog.post.published', $this->payload($post));
        }
    }

    public function updated(Post $post): void
    {
        // Background autosaves persist the draft row but must not consume the
        // finite revision history nor spam webhook subscribers. A publish-tier
        // transition can never happen on an autosave (the status is clamped to
        // draft/pending), so there is nothing meaningful to announce here.
        if ($post->autosaving) {
            return;
        }

        $this->recordRevision($post);

        $this->webhooks->fire('blog.post.updated', $this->payload($post));

        if ($post->status === PostStatus::Published && $post->getOriginal('status') !== PostStatus::Published) {
            $this->webhooks->fire('blog.post.published', $this->payload($post));
        }
    }

    public function deleted(Post $post): void
    {
        $this->webhooks->fire('blog.post.deleted', [
            'post_id' => $post->getKey(),
            'slug' => $post->slug,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Post $post): array
    {
        return [
            'post_id' => $post->getKey(),
            'slug' => $post->slug,
            'status' => $post->status->value,
            'title' => $post->title,
        ];
    }

    private function recordRevision(Post $post): void
    {
        $settings = BlogSettings::get();

        if (! $settings->enable_revisions) {
            return;
        }

        // Normalise enums/dates to the exact scalar shape they take once stored as
        // JSON, so the dedup comparison below is apples-to-apples with the latest
        // revision's (JSON-cast) payload — otherwise a PostStatus enum would never
        // equal the stored 'draft' string and no save would ever dedup.
        $snapshot = json_decode((string) json_encode($this->snapshot($post)), true);

        // Skip a revision that is identical to the latest one, so a no-op save (or
        // the first explicit save right after an autosaved draft) never adds a
        // duplicate history entry.
        if ($this->matchesLatestRevision($post, $snapshot)) {
            return;
        }

        DB::transaction(function () use ($post, $settings, $snapshot): void {
            Revision::create([
                'entry_type' => Post::ENTRY_TYPE,
                'entry_id' => (string) $post->getKey(),
                'payload' => $snapshot,
                'author_id' => $post->author_id,
            ]);

            $this->prune($post, max(1, $settings->max_revisions));
        });
    }

    /**
     * True when the most recent stored revision already holds this exact snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    private function matchesLatestRevision(Post $post, array $snapshot): bool
    {
        $latest = Revision::query()
            ->where('entry_type', Post::ENTRY_TYPE)
            ->where('entry_id', (string) $post->getKey())
            ->latest('id')
            ->first();

        // Revision casts `payload` to array, so this compares decoded snapshots.
        return $latest !== null && $latest->payload === $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Post $post): array
    {
        return $post->only([
            'title',
            'slug',
            'excerpt',
            'content',
            'featured_image',
            'category_id',
            'author_id',
            'status',
            'visibility',
            'published_at',
        ]);
    }

    private function prune(Post $post, int $keep): void
    {
        $ids = Revision::query()
            ->where('entry_type', Post::ENTRY_TYPE)
            ->where('entry_id', (string) $post->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->pluck('id');

        $overflow = $ids->slice($keep)->values();

        if ($overflow->isNotEmpty()) {
            // A query-builder delete bypasses the model's append-only guard,
            // which is intended for individual records, not maintenance pruning.
            Revision::query()->whereIn('id', $overflow->all())->delete();
        }
    }
}
