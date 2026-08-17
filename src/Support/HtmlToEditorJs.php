<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Best-effort converter from post HTML (WordPress classic or Gutenberg output)
 * into an Editor.js block document. Top-level HTML elements are mapped to the
 * nearest native block; anything unrecognised falls back to a raw HTML block so
 * no content is silently lost. The result is expected to be passed through
 * EditorJsSanitizer before persistence — this class shapes structure, the
 * sanitiser enforces safety.
 */
class HtmlToEditorJs
{
    private const HEADINGS = ['h1' => 1, 'h2' => 2, 'h3' => 3, 'h4' => 4, 'h5' => 5, 'h6' => 6];

    /**
     * @return array{blocks: list<array<string, mixed>>}
     */
    public function convert(string $html): array
    {
        $html = trim($html);
        if ($html === '') {
            return ['blocks' => []];
        }

        $dom = new DOMDocument;

        // Gutenberg wraps blocks in HTML comments (<!-- wp:paragraph -->); loading
        // as UTF-8 HTML keeps the wrapped markup and lets us ignore the comments.
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $dom->getElementById('__root__');
        if ($root === null) {
            return ['blocks' => []];
        }

        $blocks = [];
        foreach ($root->childNodes as $node) {
            $block = $this->nodeToBlock($node);
            if ($block !== null) {
                $blocks[] = $block;
            }
        }

        return ['blocks' => $blocks];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function nodeToBlock(DOMNode $node): ?array
    {
        // Loose top-level text becomes a paragraph; whitespace-only is dropped.
        if ($node instanceof DOMText) {
            $text = trim($node->wholeText);

            return $text === '' ? null : $this->block('paragraph', ['text' => $text]);
        }

        if (! $node instanceof DOMElement) {
            return null;
        }

        $tag = strtolower($node->nodeName);

        if (isset(self::HEADINGS[$tag])) {
            return $this->block('header', [
                'text' => $this->inner($node),
                'level' => self::HEADINGS[$tag],
            ]);
        }

        return match ($tag) {
            'p' => $this->paragraphOrImage($node),
            'ul', 'ol' => $this->block('list', [
                'style' => $tag === 'ol' ? 'ordered' : 'unordered',
                'items' => $this->listItems($node),
            ]),
            'blockquote' => $this->block('quote', [
                'text' => $this->inner($node),
                'caption' => '',
                'alignment' => 'left',
            ]),
            'pre' => $this->block('code', ['code' => $node->textContent, 'language' => '']),
            'hr' => $this->block('delimiter', ['style' => 'dots']),
            'img' => $this->imageBlock($node),
            'figure' => $this->figureBlock($node),
            'table' => $this->block('table', [
                'withHeadings' => $this->tableHasHeadings($node),
                'content' => $this->tableRows($node),
            ]),
            // Empty structural wrappers carry nothing; skip them.
            'div', 'section', 'article' => $this->wrapperBlock($node),
            default => $this->rawBlock($node),
        };
    }

    /**
     * A paragraph that contains only an image becomes an image block; otherwise a
     * paragraph carrying the inline HTML. Empty paragraphs are dropped.
     *
     * @return array<string, mixed>|null
     */
    private function paragraphOrImage(DOMElement $node): ?array
    {
        $img = $this->soleImage($node);
        if ($img !== null) {
            return $this->imageBlock($img);
        }

        $text = $this->inner($node);

        return trim(strip_tags($text)) === '' ? null : $this->block('paragraph', ['text' => $text]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function figureBlock(DOMElement $node): ?array
    {
        $img = $node->getElementsByTagName('img')->item(0);
        if ($img instanceof DOMElement) {
            $caption = $node->getElementsByTagName('figcaption')->item(0);

            return $this->imageBlock($img, $caption instanceof DOMElement ? trim($caption->textContent) : '');
        }

        return $this->rawBlock($node);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function imageBlock(DOMElement $img, string $caption = ''): ?array
    {
        $src = trim($img->getAttribute('src'));
        if ($src === '') {
            return null;
        }

        return $this->block('image', [
            'url' => $src,
            'alt' => trim($img->getAttribute('alt')),
            'caption' => $caption !== '' ? $caption : trim($img->getAttribute('alt')),
        ]);
    }

    /**
     * Unwrap a structural container: if it holds block-level children, convert
     * them; otherwise treat its inline content as a paragraph. Falls back to raw
     * when it is neither.
     *
     * @return array<string, mixed>|null
     */
    private function wrapperBlock(DOMElement $node): ?array
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && $this->isBlockLevel(strtolower($child->nodeName))) {
                return $this->rawBlock($node);
            }
        }

        $text = $this->inner($node);

        return trim(strip_tags($text)) === '' ? null : $this->block('paragraph', ['text' => $text]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawBlock(DOMElement $node): ?array
    {
        $html = $this->outer($node);

        return trim(strip_tags($html)) === '' ? null : $this->block('raw', ['html' => $html]);
    }

    /**
     * @return list<string>
     */
    private function listItems(DOMElement $node): array
    {
        $items = [];
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->nodeName) === 'li') {
                $items[] = $this->inner($child);
            }
        }

        return $items;
    }

    /**
     * @return list<list<string>>
     */
    private function tableRows(DOMElement $node): array
    {
        $rows = [];
        foreach ($node->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = $this->inner($cell);
                }
            }
            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        return $rows;
    }

    private function tableHasHeadings(DOMElement $node): bool
    {
        return $node->getElementsByTagName('th')->length > 0;
    }

    private function soleImage(DOMElement $node): ?DOMElement
    {
        $img = null;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                if (trim($child->wholeText) !== '') {
                    return null;
                }

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }
            if (strtolower($child->nodeName) !== 'img' || $img !== null) {
                return null;
            }
            $img = $child;
        }

        return $img;
    }

    private function isBlockLevel(string $tag): bool
    {
        return isset(self::HEADINGS[$tag])
            || in_array($tag, ['p', 'ul', 'ol', 'blockquote', 'pre', 'hr', 'figure', 'table', 'div', 'section', 'article'], true);
    }

    /** Inner HTML of an element (children serialised, element tag excluded). */
    private function inner(DOMElement $node): string
    {
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $this->serialise($child);
        }

        return trim($html);
    }

    /** Outer HTML of an element (the element tag included). */
    private function outer(DOMElement $node): string
    {
        return trim($this->serialise($node));
    }

    private function serialise(DOMNode $node): string
    {
        $doc = $node->ownerDocument;

        return $doc !== null ? (string) $doc->saveHTML($node) : '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function block(string $type, array $data): array
    {
        return ['type' => $type, 'data' => $data];
    }
}
