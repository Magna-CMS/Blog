<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Editor;

use MagnaCms\Blog\Support\FontRegistry;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises an Editor.js block document server-side before persistence.
 *
 * Security model: an allowlist. Only known block types survive; within each
 * block, text fields are cleaned to a small set of inline formatting tags,
 * URL fields are restricted to safe schemes, and code is kept verbatim as a
 * string (the delivery consumer is responsible for escaping it on output).
 * Anything not explicitly handled is dropped. This is the primary defence
 * against stored XSS injected through crafted block JSON.
 */
final class EditorJsSanitizer
{
    /** Hard cap on blocks per document (and per column) to bound storage/DoS. */
    private const MAX_BLOCKS = 1000;

    /** Bounds on nested-list depth/breadth so a crafted list cannot blow the stack. */
    private const MAX_LIST_DEPTH = 6;

    private const MAX_LIST_ITEMS = 1000;

    /** Bounds on table size so a single table block cannot exhaust memory. */
    private const MAX_TABLE_ROWS = 200;

    private const MAX_TABLE_COLS = 50;

    /**
     * Hosts whose iframes the embed block may reference. Any embed URL outside
     * this allowlist is dropped — the primary defence against arbitrary iframe
     * (clickjacking / XSS) injection through the embed block.
     *
     * @var list<string>
     */
    private const EMBED_HOSTS = [
        'youtube.com', 'youtube-nocookie.com', 'youtu.be',
        'vimeo.com', 'player.vimeo.com',
        'instagram.com', 'twitter.com', 'x.com', 'platform.twitter.com',
        'facebook.com', 'codepen.io',
        'open.spotify.com', 'soundcloud.com', 'w.soundcloud.com',
        'tiktok.com', 'twitch.tv', 'player.twitch.tv',
        'pinterest.com', 'coub.com',
    ];

    private readonly HtmlSanitizer $html;

    /** Broader (but still script-free) profile for the raw HTML block. */
    private readonly HtmlSanitizer $rawHtml;

    /** @var list<string> Font keys accepted on styled blocks (buttons). */
    private readonly array $allowedFonts;

    /**
     * @param  list<string>|null  $allowedFonts  Defaults to the font registry's keys.
     */
    public function __construct(?array $allowedFonts = null)
    {
        $this->allowedFonts = $allowedFonts ?? FontRegistry::keys();
        $this->rawHtml = new HtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['https', 'http', 'mailto'])
                ->allowRelativeLinks(false),
        );

        $config = (new HtmlSanitizerConfig)
            ->allowElement('b')
            ->allowElement('strong')
            ->allowElement('i')
            ->allowElement('em')
            ->allowElement('u')
            ->allowElement('mark')
            ->allowElement('code')
            ->allowElement('s')
            ->allowElement('strike')
            ->allowElement('sub')
            ->allowElement('sup')
            // Palette text colour: a class-only span. The class VALUE is not
            // restricted by the sanitiser, but a class carries no script and
            // only the fixed mgb-c-* classes have matching CSS, so an unknown
            // class is inert. Kept narrow (no style/id/other attributes).
            ->allowElement('span', ['class'])
            ->allowElement('br')
            ->allowElement('a', ['href'])
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            // Allow relative links so in-page anchors (#fn-1 footnote refs) and
            // internal paths work. The scheme allowlist above still blocks
            // javascript:/data: URLs, and rel is forced below — relative links
            // carry no extra XSS surface.
            ->allowRelativeLinks(true)
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow');

        $this->html = new HtmlSanitizer($config);
    }

    /**
     * @param  mixed  $document  The raw Editor.js document (expected: array with a "blocks" list).
     * @return array{time: int, blocks: list<array<string, mixed>>, version: string}
     */
    public function sanitize(mixed $document): array
    {
        $rawBlocks = is_array($document) && is_array($document['blocks'] ?? null) ? $document['blocks'] : [];

        return [
            'time' => is_array($document) && isset($document['time']) && is_int($document['time'])
                ? $document['time']
                : (int) (microtime(true) * 1000),
            'blocks' => $this->sanitizeBlocks($rawBlocks, 0),
            'version' => is_array($document) && isset($document['version']) && is_string($document['version'])
                ? $document['version']
                : '2.30.0',
        ];
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @return list<array<string, mixed>>
     */
    private function sanitizeBlocks(array $blocks, int $depth): array
    {
        $clean = [];
        foreach (array_slice($blocks, 0, self::MAX_BLOCKS) as $block) {
            $sanitized = is_array($block) ? $this->sanitizeBlock($block, $depth) : null;
            if ($sanitized !== null) {
                $clean[] = $sanitized;
            }
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>|null
     */
    private function sanitizeBlock(array $block, int $depth = 0): ?array
    {
        $type = is_string($block['type'] ?? null) ? $block['type'] : null;
        $data = is_array($block['data'] ?? null) ? $block['data'] : [];

        $clean = match ($type) {
            'paragraph' => [
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'template' => in_array($data['template'] ?? '', BlockSchema::PARAGRAPH_TEMPLATES, true) ? $data['template'] : 'standard',
                'image' => $this->cleanUrl($data['image'] ?? ''),
                'imageAlt' => $this->cleanText($data['imageAlt'] ?? ''),
                'label' => $this->cleanText($data['label'] ?? ''),
            ],
            'header' => [
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'level' => $this->clampLevel($data['level'] ?? 2),
            ],
            'list' => [
                'style' => in_array($data['style'] ?? '', ['ordered', 'unordered'], true) ? $data['style'] : 'unordered',
                'items' => $this->cleanListItems($data['items'] ?? []),
            ],
            'checklist' => ['items' => $this->cleanChecklistItems($data['items'] ?? [])],
            'quote' => [
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'caption' => $this->cleanHtml($data['caption'] ?? ''),
                'alignment' => in_array($data['alignment'] ?? '', ['left', 'center'], true) ? $data['alignment'] : 'left',
            ],
            'table' => [
                'withHeadings' => (bool) ($data['withHeadings'] ?? false),
                'content' => $this->cleanTable($data['content'] ?? []),
            ],
            'code' => [
                'code' => is_string($data['code'] ?? null) ? $data['code'] : '',
                'language' => in_array($data['language'] ?? '', BlockSchema::CODE_LANGUAGES, true) ? $data['language'] : '',
            ],
            'raw' => ['html' => $this->rawHtml->sanitize(is_string($data['html'] ?? null) ? $data['html'] : '')],
            'delimiter' => ['style' => in_array($data['style'] ?? '', ['dots', 'line', 'dashed', 'asterisks'], true) ? $data['style'] : 'dots'],
            'image' => [
                'url' => $this->cleanUrl($data['url'] ?? ''),
                'caption' => $this->cleanText($data['caption'] ?? ''),
                'alt' => $this->cleanText($data['alt'] ?? ''),
                'align' => in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : '',
                'width' => in_array($data['width'] ?? '', ['small', 'medium', 'large', 'full'], true) ? $data['width'] : 'full',
                'rounded' => (bool) ($data['rounded'] ?? false),
                'linkUrl' => $this->cleanUrl($data['linkUrl'] ?? ''),
            ],
            'embed' => $this->cleanEmbed($data),
            'warning' => [
                'title' => $this->cleanHtml($data['title'] ?? ''),
                'message' => $this->cleanHtml($data['message'] ?? ''),
            ],
            'link' => $this->cleanLink($data),
            'attaches' => $this->cleanAttaches($data),
            'preformatted' => ['text' => $this->cleanPlain($data['text'] ?? '')],
            'verse' => ['text' => $this->cleanPlain($data['text'] ?? '')],
            'spacer' => [
                'height' => $this->spacerHeight($data['height'] ?? null),
                'divider' => (bool) ($data['divider'] ?? false),
            ],
            'pullquote' => [
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'citation' => $this->cleanText($data['citation'] ?? ''),
            ],
            'buttons' => [
                'buttons' => $this->cleanButtons($data['buttons'] ?? []),
                'align' => in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left',
                'gap' => max(0, min(64, is_numeric($data['gap'] ?? null) ? (int) $data['gap'] : 8)),
            ],
            'more' => [],
            'pageBreak' => [],
            'callout' => [
                'type' => in_array($data['type'] ?? '', ['info', 'success', 'warning', 'danger'], true) ? $data['type'] : 'info',
                'title' => $this->cleanText($data['title'] ?? ''),
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'icon' => (bool) ($data['icon'] ?? true),
            ],
            'cta' => [
                'title' => $this->cleanText($data['title'] ?? ''),
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'buttonLabel' => $this->cleanText($data['buttonLabel'] ?? ''),
                'buttonUrl' => $this->cleanUrl($data['buttonUrl'] ?? ''),
                'align' => in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'center',
                'background' => $this->cleanHex($data['background'] ?? '', ''),
                'buttonType' => in_array($data['buttonType'] ?? '', ['filled', 'outline'], true) ? $data['buttonType'] : 'filled',
                'buttonColor' => $this->cleanHex($data['buttonColor'] ?? '', '#6366f1'),
            ],
            'faq' => [
                'items' => $this->cleanFaq($data['items'] ?? []),
                'openFirst' => (bool) ($data['openFirst'] ?? false),
                'schema' => (bool) ($data['schema'] ?? true),
                'template' => in_array($data['template'] ?? '', BlockSchema::FAQ_TEMPLATES, true) ? $data['template'] : 'card',
            ],
            'details' => [
                'summary' => $this->cleanText($data['summary'] ?? ''),
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'open' => (bool) ($data['open'] ?? false),
            ],
            'socialIcons' => [
                'items' => $this->cleanSocial($data['items'] ?? []),
                'shape' => in_array($data['shape'] ?? '', ['rounded', 'square', 'circle'], true) ? $data['shape'] : 'rounded',
                'style' => in_array($data['style'] ?? '', ['brand', 'mono', 'outline'], true) ? $data['style'] : 'brand',
                'size' => in_array($data['size'] ?? '', ['sm', 'md', 'lg'], true) ? $data['size'] : 'md',
                'align' => in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'left',
            ],
            'footnotes' => [
                'title' => $this->cleanText($data['title'] ?? ''),
                'items' => $this->cleanFootnotes($data['items'] ?? []),
            ],
            'rss' => [
                'url' => $this->cleanUrl($data['url'] ?? ''),
                'feedTitle' => $this->cleanText($data['feedTitle'] ?? ''),
                'items' => $this->cleanRssItems($data['items'] ?? []),
                'count' => max(1, min(20, is_numeric($data['count'] ?? null) ? (int) $data['count'] : 5)),
                'showDate' => (bool) ($data['showDate'] ?? true),
            ],
            'mediaText' => [
                'image' => $this->cleanUrl($data['image'] ?? ''),
                'imageAlt' => $this->cleanText($data['imageAlt'] ?? ''),
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'mediaSide' => ($data['mediaSide'] ?? '') === 'right' ? 'right' : 'left',
                'ratio' => in_array($data['ratio'] ?? '', ['1-1', '2-1', '1-2', '2-3', '3-2'], true) ? $data['ratio'] : '1-1',
                'vAlign' => in_array($data['vAlign'] ?? '', ['top', 'center', 'bottom'], true) ? $data['vAlign'] : 'center',
                'stackMobile' => (bool) ($data['stackMobile'] ?? true),
            ],
            'gallery' => [
                'images' => $this->cleanGallery($data['images'] ?? []),
                'columns' => max(1, min(6, is_numeric($data['columns'] ?? null) ? (int) $data['columns'] : 3)),
                'gap' => max(0, min(40, is_numeric($data['gap'] ?? null) ? (int) $data['gap'] : 8)),
                'crop' => in_array($data['crop'] ?? '', ['square', '4-3', '16-9'], true) ? $data['crop'] : '',
                'rounded' => (bool) ($data['rounded'] ?? false),
            ],
            'cover' => [
                'url' => $this->cleanUrl($data['url'] ?? ''),
                'title' => $this->cleanText($data['title'] ?? ''),
                'text' => $this->cleanHtml($data['text'] ?? ''),
                'height' => in_array($data['height'] ?? '', ['small', 'medium', 'large'], true) ? $data['height'] : 'medium',
                'overlay' => max(0, min(100, is_numeric($data['overlay'] ?? null) ? (int) $data['overlay'] : 40)),
                'align' => in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : 'center',
            ],
            'video' => [
                'url' => $this->cleanUrl($data['url'] ?? ''),
                'caption' => $this->cleanText($data['caption'] ?? ''),
                'controls' => (bool) ($data['controls'] ?? true),
                'autoplay' => (bool) ($data['autoplay'] ?? false),
                'loop' => (bool) ($data['loop'] ?? false),
                'muted' => (bool) ($data['muted'] ?? false),
            ],
            'audio' => [
                'url' => $this->cleanUrl($data['url'] ?? ''),
                'caption' => $this->cleanText($data['caption'] ?? ''),
                'controls' => (bool) ($data['controls'] ?? true),
                'autoplay' => (bool) ($data['autoplay'] ?? false),
                'loop' => (bool) ($data['loop'] ?? false),
            ],
            'toc' => [
                'title' => $this->cleanText($data['title'] ?? 'Contents'),
                'depth' => in_array((int) ($data['depth'] ?? 3), [2, 3, 4], true) ? (int) $data['depth'] : 3,
                'ordered' => (bool) ($data['ordered'] ?? false),
            ],
            'relatedPosts' => [
                'count' => max(1, min(12, is_numeric($data['count'] ?? null) ? (int) $data['count'] : 3)),
                'by' => in_array($data['by'] ?? '', ['category', 'tag'], true) ? $data['by'] : 'category',
                'layout' => in_array($data['layout'] ?? '', ['grid', 'list'], true) ? $data['layout'] : 'grid',
                'showImage' => (bool) ($data['showImage'] ?? true),
            ],
            'postExcerpt' => [],
            'featuredImage' => [],
            'map' => ['query' => $this->cleanText($data['query'] ?? '')],
            // Columns hosts nested child documents; sanitise each recursively.
            // Depth >= 1 drops nested columns so a crafted payload cannot recurse
            // without bound.
            'columns' => $depth < 1 ? ['cols' => $this->cleanColumns($data['cols'] ?? [], $depth)] : null,
            // Group hosts a nested document; only allowed at the top level so a
            // crafted payload cannot recurse without bound.
            'group' => $depth < 1 ? [
                'blocks' => $this->sanitizeBlocks(is_array($data['blocks'] ?? null) ? $data['blocks'] : [], $depth + 1),
                'layout' => in_array($data['layout'] ?? '', ['stack', 'row', 'grid'], true) ? $data['layout'] : 'stack',
                'columns' => in_array((int) ($data['columns'] ?? 2), [2, 3, 4], true) ? (int) ($data['columns'] ?? 2) : 2,
                'gap' => max(0, min(48, is_numeric($data['gap'] ?? null) ? (int) $data['gap'] : 16)),
                'padding' => max(0, min(64, is_numeric($data['padding'] ?? null) ? (int) $data['padding'] : 20)),
                'radius' => max(0, min(40, is_numeric($data['radius'] ?? null) ? (int) $data['radius'] : 12)),
                'background' => $this->cleanHex($data['background'] ?? '', ''),
                'align' => in_array($data['align'] ?? '', ['left', 'center', 'right'], true) ? $data['align'] : '',
            ] : null,
            default => null,
        };

        if ($clean === null) {
            return null;
        }

        // Blocks whose sole content is a URL are dropped when that URL is empty.
        if ($type === 'image' && ($clean['url'] ?? '') === '') {
            return null;
        }
        if ($type === 'link' && ($clean['link'] ?? '') === '') {
            return null;
        }
        if ($type === 'attaches' && (($clean['file']['url'] ?? '') === '')) {
            return null;
        }
        if (($type === 'video' || $type === 'audio') && ($clean['url'] ?? '') === '') {
            return null;
        }
        if ($type === 'embed' && ($clean['embed'] ?? '') === '') {
            return null;
        }
        if ($type === 'map' && ($clean['query'] ?? '') === '') {
            return null;
        }
        if ($type === 'socialIcons' && ($clean['items'] ?? []) === []) {
            return null;
        }
        if ($type === 'details' && ($clean['summary'] ?? '') === '' && ($clean['text'] ?? '') === '') {
            return null;
        }
        if ($type === 'mediaText' && ($clean['image'] ?? '') === '') {
            $mtText = is_string($clean['text'] ?? null) ? $clean['text'] : '';
            if (trim(strip_tags($mtText)) === '') {
                return null;
            }
        }
        if ($type === 'footnotes' && ($clean['items'] ?? []) === []) {
            return null;
        }
        if ($type === 'rss' && ($clean['url'] ?? '') === '' && ($clean['items'] ?? []) === []) {
            return null;
        }

        $result = ['type' => $type, 'data' => $clean];

        $tunes = $this->cleanTunes($block['tunes'] ?? null);
        if ($tunes !== []) {
            $result['tunes'] = $tunes;
        }

        return $result;
    }

    /** Tokens the Style tune accepts, mirrored from the client tune. */
    private const STYLE_ALIGNS = ['left', 'center', 'right'];

    private const STYLE_SIZES = ['sm', 'base', 'lg', 'xl'];

    /**
     * Validate the block-level tunes we support. Only the Style tune is kept:
     * align/fontSize as enums and colour as a hex literal (never raw CSS), so
     * the values can be turned into inline styles at render time without an XSS
     * surface.
     *
     * @return array<string, mixed>
     */
    private function cleanTunes(mixed $tunes): array
    {
        if (! is_array($tunes) || ! is_array($tunes['style'] ?? null)) {
            return [];
        }

        $style = $tunes['style'];
        $clean = [];

        if (in_array($style['align'] ?? '', self::STYLE_ALIGNS, true)) {
            $clean['align'] = $style['align'];
        }
        $color = $this->cleanHex($style['color'] ?? '', '');
        if ($color !== '') {
            $clean['color'] = $color;
        }
        $bg = $this->cleanHex($style['bg'] ?? '', '');
        if ($bg !== '') {
            $clean['bg'] = $bg;
        }
        $accent = $this->cleanHex($style['accent'] ?? '', '');
        if ($accent !== '') {
            $clean['accent'] = $accent;
        }
        if (in_array($style['fontSize'] ?? '', self::STYLE_SIZES, true)) {
            $clean['fontSize'] = $style['fontSize'];
        }
        if (in_array($style['font'] ?? '', $this->allowedFonts, true)) {
            $clean['font'] = $style['font'];
        }
        foreach (['striped', 'compact', 'bordered'] as $flag) {
            if (! empty($style[$flag])) {
                $clean[$flag] = true;
            }
        }
        if (in_array($style['valign'] ?? '', ['top', 'center'], true)) {
            $clean['valign'] = $style['valign'];
        }
        if (in_array($style['ratio'] ?? '', ['2-1', '1-2', '3-1', '1-3'], true)) {
            $clean['ratio'] = $style['ratio'];
        }

        return $clean === [] ? [] : ['style' => $clean];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{link: string, text: string, newTab: bool, nofollow: bool, meta: array{title: string, description: string, image: array{url: string}}}
     */
    private function cleanLink(array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
        $image = is_array($meta['image'] ?? null) ? $meta['image'] : [];

        return [
            'link' => $this->cleanUrl($data['link'] ?? ''),
            'text' => $this->cleanText($data['text'] ?? ''),
            'newTab' => (bool) ($data['newTab'] ?? false),
            'nofollow' => (bool) ($data['nofollow'] ?? false),
            'meta' => [
                'title' => $this->cleanText($meta['title'] ?? ''),
                'description' => $this->cleanText($meta['description'] ?? ''),
                'image' => ['url' => $this->cleanUrl($image['url'] ?? '')],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{file: array{url: string, name: string, size: int, extension: string}, title: string}
     */
    private function cleanAttaches(array $data): array
    {
        $file = is_array($data['file'] ?? null) ? $data['file'] : [];

        return [
            'file' => [
                'url' => $this->cleanUrl($file['url'] ?? ''),
                'name' => $this->cleanText($file['name'] ?? ''),
                'size' => is_numeric($file['size'] ?? null) ? (int) $file['size'] : 0,
                'extension' => $this->cleanText($file['extension'] ?? ''),
            ],
            'title' => $this->cleanText($data['title'] ?? ''),
        ];
    }

    private function cleanHtml(mixed $value): string
    {
        return $this->html->sanitize(is_string($value) ? $value : '');
    }

    /** Plain text: strip every tag. */
    private function cleanText(mixed $value): string
    {
        return trim(strip_tags(is_string($value) ? $value : ''));
    }

    /** Plain text that preserves whitespace and line breaks (verse, preformatted). */
    private function cleanPlain(mixed $value): string
    {
        return strip_tags(is_string($value) ? $value : '');
    }

    /**
     * @return list<array{label: string, url: string, type: string, color: string, textColor: string, size: string, radius: int, font: string, fullWidth: bool, newTab: bool, nofollow: bool}>
     */
    private function cleanButtons(mixed $buttons): array
    {
        if (! is_array($buttons)) {
            return [];
        }

        $clean = [];
        foreach ($buttons as $button) {
            if (! is_array($button)) {
                continue;
            }

            $label = $this->cleanText($button['label'] ?? '');
            $url = $this->cleanUrl($button['url'] ?? '');
            if ($label === '' && $url === '') {
                continue;
            }

            $clean[] = [
                'label' => $label,
                'url' => $url,
                'type' => in_array($button['type'] ?? '', ['filled', 'outline', 'ghost', 'link'], true) ? $button['type'] : 'filled',
                'color' => $this->cleanHex($button['color'] ?? '', '#6366f1'),
                'textColor' => $this->cleanHex($button['textColor'] ?? '', ''),
                'size' => in_array($button['size'] ?? '', ['sm', 'md', 'lg'], true) ? $button['size'] : 'md',
                'radius' => max(0, min(50, is_numeric($button['radius'] ?? null) ? (int) $button['radius'] : 8)),
                'font' => in_array($button['font'] ?? '', $this->allowedFonts, true) ? $button['font'] : '',
                'fullWidth' => (bool) ($button['fullWidth'] ?? false),
                'newTab' => (bool) ($button['newTab'] ?? false),
                'nofollow' => (bool) ($button['nofollow'] ?? false),
            ];
        }

        return $clean;
    }

    /**
     * Validate a colour as a hex literal only (`#rgb`..`#rrggbbaa`); anything
     * else falls back to the given default. Hex-only means the value can be
     * dropped straight into inline CSS without opening a CSS-injection hole.
     */
    private function cleanHex(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $default;
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function cleanFaq(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $question = $this->cleanText($item['question'] ?? '');
            $answer = $this->cleanHtml($item['answer'] ?? '');
            if ($question === '' && $answer === '') {
                continue;
            }

            $clean[] = ['question' => $question, 'answer' => $answer];
        }

        return $clean;
    }

    /**
     * RSS items are a stored snapshot fetched by the editor endpoint. Titles are
     * plain text, links restricted to safe URLs, dates kept as plain strings.
     *
     * @return list<array{title: string, link: string, date: string}>
     */
    private function cleanRssItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $title = $this->cleanText($item['title'] ?? '');
            $link = $this->cleanUrl($item['link'] ?? '');
            if ($title === '' && $link === '') {
                continue;
            }
            $clean[] = [
                'title' => $title,
                'link' => $link,
                'date' => $this->cleanText($item['date'] ?? ''),
            ];
            if (count($clean) >= 20) {
                break;
            }
        }

        return $clean;
    }

    /**
     * @return list<array{text: string}>
     */
    private function cleanFootnotes(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->cleanHtml($item['text'] ?? '');
            if (trim(strip_tags($text)) === '') {
                continue;
            }
            $clean[] = ['text' => $text];
        }

        return $clean;
    }

    /**
     * @return list<array{network: string, url: string}>
     */
    private function cleanSocial(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $network = in_array($item['network'] ?? '', BlockSchema::SOCIAL_NETWORKS, true) ? $item['network'] : 'website';
            $url = $this->cleanUrl($item['url'] ?? '');
            // Allow mailto: for the email network (cleanUrl only keeps http/https).
            if ($network === 'email' && $url === '' && is_string($item['url'] ?? null)) {
                $raw = trim($item['url']);
                if (str_starts_with(strtolower($raw), 'mailto:') && filter_var(substr($raw, 7), FILTER_VALIDATE_EMAIL)) {
                    $url = $raw;
                } elseif (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
                    $url = 'mailto:'.$raw;
                }
            }
            if ($url === '') {
                continue;
            }

            $clean[] = ['network' => $network, 'url' => $url];
        }

        return $clean;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{service: string, source: string, embed: string, width: int|null, height: int|null, caption: string}
     */
    private function cleanEmbed(array $data): array
    {
        $embed = $this->cleanUrl($data['embed'] ?? '');
        if ($embed !== '' && ! $this->hostAllowed($embed)) {
            $embed = '';
        }

        return [
            'service' => $this->cleanText($data['service'] ?? ''),
            'source' => $this->cleanUrl($data['source'] ?? ''),
            'embed' => $embed,
            'width' => is_numeric($data['width'] ?? null) ? (int) $data['width'] : null,
            'height' => is_numeric($data['height'] ?? null) ? (int) $data['height'] : null,
            'caption' => $this->cleanText($data['caption'] ?? ''),
        ];
    }

    /**
     * @return list<array{time: int, blocks: list<array<string, mixed>>, version: string}>
     */
    private function cleanColumns(mixed $cols, int $depth): array
    {
        if (! is_array($cols)) {
            return [];
        }

        $clean = [];
        foreach ($cols as $col) {
            if (! is_array($col)) {
                continue;
            }

            $blocks = is_array($col['blocks'] ?? null) ? $col['blocks'] : (array_is_list($col) ? $col : []);

            $clean[] = [
                'time' => (int) (microtime(true) * 1000),
                'blocks' => $this->sanitizeBlocks($blocks, $depth + 1),
                'version' => '2.30.0',
            ];
        }

        return $clean;
    }

    private function hostAllowed(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach (self::EMBED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{url: string, alt: string, caption: string}>
     */
    private function cleanGallery(mixed $images): array
    {
        if (! is_array($images)) {
            return [];
        }

        $clean = [];
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $url = $this->cleanUrl($image['url'] ?? '');
            if ($url === '') {
                continue;
            }

            $clean[] = [
                'url' => $url,
                'alt' => $this->cleanText($image['alt'] ?? ''),
                'caption' => $this->cleanText($image['caption'] ?? ''),
            ];
        }

        return $clean;
    }

    private function cleanUrl(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';

        if ($url === '') {
            return '';
        }

        // Same-origin root-relative paths (e.g. /storage/…) are safe — but not
        // protocol-relative (//evil.com) URLs, which are rejected below.
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /** Spacer height in px (8–160), mapping the legacy small/medium/large names. */
    private function spacerHeight(mixed $value): int
    {
        $legacy = ['small' => 16, 'medium' => 32, 'large' => 64];
        if (is_string($value) && isset($legacy[$value])) {
            return $legacy[$value];
        }

        return max(8, min(160, is_numeric($value) ? (int) $value : 32));
    }

    private function clampLevel(mixed $level): int
    {
        $level = is_numeric($level) ? (int) $level : 2;

        return max(2, min(4, $level));
    }

    /**
     * Editor.js list items are either strings or nested { content, items } objects.
     * Depth and breadth are bounded so a deeply-nested crafted list cannot exhaust
     * the stack or memory.
     *
     * @return list<array{content: string, items: list<mixed>}>
     */
    private function cleanListItems(mixed $items, int $depth = 0): array
    {
        if (! is_array($items) || $depth > self::MAX_LIST_DEPTH) {
            return [];
        }

        $clean = [];
        foreach (array_slice($items, 0, self::MAX_LIST_ITEMS) as $item) {
            if (is_string($item)) {
                $clean[] = ['content' => $this->cleanHtml($item), 'items' => []];
            } elseif (is_array($item)) {
                $clean[] = [
                    'content' => $this->cleanHtml($item['content'] ?? ''),
                    'items' => $this->cleanListItems($item['items'] ?? [], $depth + 1),
                ];
            }
        }

        return $clean;
    }

    /**
     * @return list<array{text: string, checked: bool}>
     */
    private function cleanChecklistItems(mixed $items): array
    {
        if (! is_array($items)) {
            return [];
        }

        $clean = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $clean[] = [
                    'text' => $this->cleanHtml($item['text'] ?? ''),
                    'checked' => (bool) ($item['checked'] ?? false),
                ];
            }
        }

        return $clean;
    }

    /**
     * @return list<list<string>>
     */
    private function cleanTable(mixed $rows): array
    {
        if (! is_array($rows)) {
            return [];
        }

        $clean = [];
        foreach (array_slice($rows, 0, self::MAX_TABLE_ROWS) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $cells = [];
            foreach (array_slice($row, 0, self::MAX_TABLE_COLS) as $cell) {
                $cells[] = $this->cleanHtml($cell);
            }
            $clean[] = $cells;
        }

        return $clean;
    }
}
