<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Magna\Media\Exceptions\MimeTypeNotAllowedException;
use Magna\Media\MediaIngestor;
use MagnaCms\Blog\Support\SafeHttpFetcher;

/**
 * Backend endpoints the Editor.js tools call from the admin editor: the Link
 * tool's URL-preview fetch and the Attaches tool's file upload. Both require an
 * authenticated user with post create/edit permission.
 */
class EditorToolController
{
    public function __construct(private readonly SafeHttpFetcher $fetcher) {}

    /**
     * GET link preview. Fetches Open Graph metadata for a URL, guarded against
     * SSRF (public http/https hosts only; the connection is pinned to the
     * validated IPs and every redirect is re-validated, closing DNS rebinding).
     */
    public function linkPreview(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);

        $body = $this->fetcher->fetch(
            (string) $request->query('url', ''),
            timeoutSeconds: 5,
            maxBytes: 200_000,
            userAgent: 'MagnaBlog/1.0 LinkPreview',
        );

        if ($body === null) {
            return response()->json(['success' => 0]);
        }

        return response()->json([
            'success' => 1,
            'meta' => $this->parseMeta($body),
        ]);
    }

    /**
     * POST attaches upload. Stores the file in the core media library (which
     * content-sniffs the MIME, enforces the allowlist, and re-encodes images).
     */
    public function attaches(Request $request, MediaIngestor $ingestor): JsonResponse
    {
        $this->authorizeEditor($request);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
        ]);

        $file = $request->file('file');
        if ($file === null) {
            return response()->json(['success' => 0]);
        }

        try {
            $media = $ingestor->ingest($file->getRealPath(), $file->getClientOriginalName(), 'public');
        } catch (MimeTypeNotAllowedException) {
            return response()->json(['success' => 0, 'message' => 'That file type is not allowed.'], 422);
        }

        return response()->json([
            'success' => 1,
            'file' => [
                'url' => Storage::disk('public')->url($media->path),
                'name' => $file->getClientOriginalName(),
                'size' => $media->size,
                'extension' => $file->getClientOriginalExtension(),
                'title' => $file->getClientOriginalName(),
            ],
        ]);
    }

    /**
     * GET RSS/Atom feed items for the RSS block's live preview. SSRF-guarded via
     * SafeHttpFetcher (public http/https only, connection pinned to validated IPs,
     * every redirect re-validated), parsed without network entity resolution
     * (XXE-safe), and cached for 15 minutes so the editor can re-fetch cheaply.
     */
    public function rss(Request $request): JsonResponse
    {
        $this->authorizeEditor($request);

        $url = (string) $request->query('url', '');

        // The fetcher validates + pins the URL itself (null on any non-public
        // host or redirect), so the result — including a miss — is cached for a
        // short window to keep the editor's live preview cheap.
        $result = Cache::remember('blog:rss:'.md5($url), now()->addMinutes(15), function () use ($url) {
            $body = $this->fetcher->fetch($url, timeoutSeconds: 6, maxBytes: 500_000, userAgent: 'MagnaBlog/1.0 RSS');

            return $body === null ? null : $this->parseFeed($body);
        });

        if ($result === null) {
            return response()->json(['success' => 0]);
        }

        return response()->json(['success' => 1] + $result);
    }

    /**
     * Parse an RSS 2.0 or Atom document into a title + up to 20 items. Parsed
     * with LIBXML_NONET so external entities are never fetched (XXE/SSRF-safe).
     *
     * @return array{feed: array{title: string}, items: list<array{title: string, link: string, date: string}>}|null
     */
    private function parseFeed(string $body): ?array
    {
        $xml = @simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        if ($xml === false) {
            return null;
        }

        $items = [];
        $feedTitle = '';

        if (isset($xml->channel)) {
            // RSS 2.0
            $feedTitle = trim((string) $xml->channel->title);
            foreach ($xml->channel->item as $item) {
                $items[] = [
                    'title' => trim((string) $item->title),
                    'link' => trim((string) $item->link),
                    'date' => trim((string) $item->pubDate),
                ];
                if (count($items) >= 20) {
                    break;
                }
            }
        } elseif (isset($xml->entry)) {
            // Atom
            $feedTitle = trim((string) $xml->title);
            foreach ($xml->entry as $entry) {
                $link = '';
                foreach ($entry->link as $l) {
                    $href = (string) $l['href'];
                    $rel = (string) $l['rel'];
                    if ($href !== '' && ($rel === '' || $rel === 'alternate')) {
                        $link = $href;
                        break;
                    }
                }
                $items[] = [
                    'title' => trim((string) $entry->title),
                    'link' => trim($link),
                    'date' => trim((string) ($entry->updated ?? $entry->published)),
                ];
                if (count($items) >= 20) {
                    break;
                }
            }
        } else {
            return null;
        }

        return ['feed' => ['title' => $feedTitle], 'items' => $items];
    }

    private function authorizeEditor(Request $request): void
    {
        $user = $request->user();

        abort_unless(
            $user !== null && ($user->can('blog.posts.create') || $user->can('blog.posts.edit')),
            403,
        );
    }

    /**
     * @return array{title: string, description: string, image: array{url: string}}
     */
    private function parseMeta(string $html): array
    {
        $meta = fn (string $property): string => preg_match(
            '/<meta[^>]+(?:property|name)=["\']'.preg_quote($property, '/').'["\'][^>]+content=["\']([^"\']*)["\']/i',
            $html,
            $matches,
        ) === 1 ? html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5) : '';

        $title = $meta('og:title');
        if ($title === '' && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m) === 1) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5);
        }

        return [
            'title' => $title,
            'description' => $meta('og:description'),
            'image' => ['url' => $meta('og:image')],
        ];
    }
}
