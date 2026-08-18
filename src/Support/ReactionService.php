<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Database\UniqueConstraintViolationException;
use MagnaCms\Blog\Models\Post;

/**
 * Applies and tallies anonymous post reactions. Visitor identity is a
 * non-reversible fingerprint (hashed IP + user agent + app key), so reacting is
 * idempotent per visitor without storing any personal data.
 */
class ReactionService
{
    /**
     * Allowlisted reaction types from config. An empty list disables reactions.
     *
     * @return list<string>
     */
    public function types(): array
    {
        $types = config('blog.reactions.types', []);

        return is_array($types) ? array_values(array_filter($types, 'is_string')) : [];
    }

    public function isEnabled(): bool
    {
        return $this->types() !== [];
    }

    public function allows(string $type): bool
    {
        return in_array($type, $this->types(), true);
    }

    /**
     * Non-reversible per-visitor fingerprint; never stores the raw IP. Keyed
     * with the app key via HMAC so the visitor identity cannot be brute-forced
     * from a leaked reactions table — this is signing per-visitor data, not an
     * install identity (which is InstallFingerprint's job).
     */
    public function fingerprint(?string $ip, ?string $userAgent): string
    {
        return hash_hmac('sha256', ($ip ?? '').'|'.($userAgent ?? ''), (string) config('app.key'));
    }

    /**
     * Toggle a reaction for a visitor: add it if absent, remove it if present.
     *
     * @return array{type: string, count: int, reacted: bool}
     */
    public function toggle(Post $post, string $type, string $fingerprint): array
    {
        $existing = $post->reactions()
            ->where('type', $type)
            ->where('fingerprint', $fingerprint)
            ->first();

        if ($existing !== null) {
            $existing->delete();
            $reacted = false;
        } else {
            try {
                $post->reactions()->create(['type' => $type, 'fingerprint' => $fingerprint]);
            } catch (UniqueConstraintViolationException) {
                // A concurrent request inserted the same (post, type, fingerprint)
                // between the read above and this insert. The unique index is the
                // real consistency boundary; the row now exists either way, so the
                // visitor is treated as having reacted rather than served a 500.
            }
            $reacted = true;
        }

        return [
            'type' => $type,
            'count' => $post->reactions()->where('type', $type)->count(),
            'reacted' => $reacted,
        ];
    }

    /**
     * Counts per allowlisted type, zero-filled, in the configured order. Uses the
     * loaded `reactions` relation when present to avoid a query.
     *
     * @return array<string, int>
     */
    public function counts(Post $post): array
    {
        $counts = array_fill_keys($this->types(), 0);
        if ($counts === []) {
            return [];
        }

        if ($post->relationLoaded('reactions')) {
            foreach ($post->reactions as $reaction) {
                if (isset($counts[$reaction->type])) {
                    $counts[$reaction->type]++;
                }
            }

            return $counts;
        }

        $grouped = $post->reactions()
            ->selectRaw('type, count(*) as aggregate')
            ->groupBy('type')
            ->pluck('aggregate', 'type');

        foreach ($grouped as $type => $aggregate) {
            if (isset($counts[$type])) {
                $counts[$type] = (int) $aggregate;
            }
        }

        return $counts;
    }
}
