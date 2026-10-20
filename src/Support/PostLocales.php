<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Magna\Frontend\LocaleUrls;

/**
 * Which site locale a post is served in.
 *
 * A post carries its content language in `locale`, picked from every ISO
 * language there is; the site serves only the locales LocalizationSettings
 * makes available. A post whose language is an available, non-fallback
 * locale lives under that locale's prefix (/fr/blog/…). Every other post —
 * the fallback language, or a language the site does not offer — lives at
 * the bare path, because that is the only place a request can reach it.
 *
 * One rule, read by the query scope (Post::scopeServedIn) and by the link
 * builder (PostPermalink::url), so what the blog links to is what it serves.
 */
final class PostLocales
{
    public function __construct(private readonly LocaleUrls $urls) {}

    /** The site locale a post written in $postLocale is served in. */
    public function servedIn(?string $postLocale): string
    {
        return $postLocale !== null && in_array($postLocale, $this->prefixed(), true)
            ? $postLocale
            : $this->urls->fallback();
    }

    public function fallback(): string
    {
        return $this->urls->fallback();
    }

    /**
     * The available locales that live under a prefix.
     *
     * @return list<string>
     */
    public function prefixed(): array
    {
        $fallback = $this->urls->fallback();

        return array_values(array_filter(
            $this->urls->available(),
            static fn (string $locale): bool => $locale !== $fallback,
        ));
    }

    /** The site-relative URL of $path in the locale a $postLocale post is served in. */
    public function urlFor(?string $postLocale, string $path): string
    {
        return $this->urls->urlFor($this->servedIn($postLocale), $path);
    }
}
