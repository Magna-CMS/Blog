<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use SimpleXMLElement;

/**
 * Converts a WordPress eXtended RSS (WXR) export into the JSON bundle shape that
 * BlogArchive::import() consumes, so all persistence — idempotent upserts,
 * transactions, content sanitisation — is reused. This class only handles the
 * lossy XML → bundle mapping: post HTML is converted to Editor.js blocks
 * (best-effort, via HtmlToEditorJs), and anything that has no clean equivalent
 * (post meta, WordPress shortcodes, page/attachment types) is dropped.
 */
class WxrImporter
{
    private const NS_WP = 'http://wordpress.org/export/1.2/';

    private const NS_CONTENT = 'http://purl.org/rss/1.0/modules/content/';

    private const NS_DC = 'http://purl.org/dc/elements/1.1/';

    public function __construct(private readonly HtmlToEditorJs $converter) {}

    /**
     * @return array<string, mixed>
     *
     * @throws \InvalidArgumentException when the XML is not parseable WXR.
     */
    public function toBundle(string $xml): array
    {
        // LIBXML_NONET disables all network access during parsing so a crafted
        // export can never make the importer fetch an external DTD/entity (XXE /
        // SSRF), mirroring the RSS parser. Modern libxml already ignores external
        // entities by default; this makes that guarantee explicit.
        $previous = libxml_use_internal_errors(true);
        $root = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($root === false || ! isset($root->channel)) {
            throw new \InvalidArgumentException('The file is not a valid WXR export.');
        }

        $channel = $root->channel;

        return [
            'schema' => BlogArchive::SCHEMA,
            'generator' => 'magna-cms/blog:wxr',
            'exported_at' => now()->toIso8601String(),
            'categories' => $this->categories($channel),
            'tags' => $this->tags($channel),
            'posts' => $this->posts($channel),
        ];
    }

    /**
     * @return list<array{name: string, slug: string, description: null, parent_slug: string|null}>
     */
    private function categories(SimpleXMLElement $channel): array
    {
        $categories = [];

        foreach ($channel->children(self::NS_WP)->category ?? [] as $category) {
            $slug = $this->text($category->category_nicename);
            if ($slug === '') {
                continue;
            }

            $parent = $this->text($category->category_parent);

            $categories[] = [
                'name' => $this->text($category->cat_name) ?: $slug,
                'slug' => $slug,
                'description' => null,
                'parent_slug' => $parent !== '' ? $parent : null,
            ];
        }

        return $categories;
    }

    /**
     * @return list<array{name: string, slug: string}>
     */
    private function tags(SimpleXMLElement $channel): array
    {
        $tags = [];

        foreach ($channel->children(self::NS_WP)->tag ?? [] as $tag) {
            $slug = $this->text($tag->tag_slug);
            if ($slug === '') {
                continue;
            }

            $tags[] = ['name' => $this->text($tag->tag_name) ?: $slug, 'slug' => $slug];
        }

        return $tags;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function posts(SimpleXMLElement $channel): array
    {
        $authors = $this->authorEmails($channel);
        $posts = [];

        foreach ($channel->item ?? [] as $item) {
            $wp = $item->children(self::NS_WP);

            if ($this->text($wp->post_type) !== 'post') {
                continue; // Skip pages, attachments, nav items, etc.
            }

            $status = $this->text($wp->status);
            if ($status === 'trash') {
                continue;
            }

            $slug = $this->text($wp->post_name);
            $title = $this->text($item->title);
            if ($slug === '') {
                $slug = Str::slug($title !== '' ? $title : 'post-'.$this->text($wp->post_id));
            }

            $html = $this->text($item->children(self::NS_CONTENT)->encoded);
            $creator = $this->text($item->children(self::NS_DC)->creator);

            $posts[] = [
                'title' => $title !== '' ? $title : $slug,
                'slug' => $slug,
                'excerpt' => null,
                'content' => $this->converter->convert($html),
                'featured_image' => null,
                'category_slug' => $this->primaryCategory($item),
                'author_email' => $authors[$creator] ?? null,
                'status' => $this->mapStatus($status),
                'visibility' => $this->mapVisibility($status, $this->text($wp->post_password)),
                'is_featured' => false,
                'allow_comments' => $this->text($wp->comment_status) !== 'closed',
                'published_at' => $this->publishedAt($wp),
                'tags' => $this->itemTags($item),
                'meta' => [],
            ];
        }

        return $posts;
    }

    /**
     * Map WordPress author logins to their email, so posts can be linked to an
     * existing Magna user by email on import.
     *
     * @return array<string, string>
     */
    private function authorEmails(SimpleXMLElement $channel): array
    {
        $map = [];

        foreach ($channel->children(self::NS_WP)->author ?? [] as $author) {
            $login = $this->text($author->author_login);
            $email = $this->text($author->author_email);
            if ($login !== '' && $email !== '') {
                $map[$login] = $email;
            }
        }

        return $map;
    }

    private function primaryCategory(SimpleXMLElement $item): ?string
    {
        foreach ($item->category ?? [] as $category) {
            if ((string) ($category['domain'] ?? '') === 'category') {
                $slug = (string) ($category['nicename'] ?? '');
                if ($slug !== '') {
                    return $slug;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function itemTags(SimpleXMLElement $item): array
    {
        $tags = [];

        foreach ($item->category ?? [] as $category) {
            if ((string) ($category['domain'] ?? '') === 'post_tag') {
                $slug = (string) ($category['nicename'] ?? '');
                if ($slug !== '') {
                    $tags[] = $slug;
                }
            }
        }

        return $tags;
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'publish', 'private' => 'published',
            'future' => 'scheduled',
            'pending' => 'pending_review',
            default => 'draft',
        };
    }

    /** Private and password-protected posts are imported as non-public. */
    private function mapVisibility(string $status, string $password): string
    {
        if ($status === 'private' || $password !== '') {
            return 'private';
        }

        return 'public';
    }

    private function publishedAt(SimpleXMLElement $wp): ?string
    {
        $gmt = $this->text($wp->post_date_gmt);
        if ($gmt !== '' && $gmt !== '0000-00-00 00:00:00') {
            return Carbon::parse($gmt, 'UTC')->toIso8601String();
        }

        $local = $this->text($wp->post_date);

        return ($local !== '' && $local !== '0000-00-00 00:00:00')
            ? Carbon::parse($local)->toIso8601String()
            : null;
    }

    private function text(mixed $node): string
    {
        if ($node instanceof SimpleXMLElement) {
            return trim((string) $node);
        }

        return is_scalar($node) ? trim((string) $node) : '';
    }
}
