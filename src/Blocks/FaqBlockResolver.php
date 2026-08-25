<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Blocks;

use Magna\Blocks\Resolution\ResolvesBlockData;
use MagnaCms\Blog\Editor\BlockSchema;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Support\BlockRenderer;

/**
 * Renders the blog's FAQ — twenty visual templates and FAQPage JSON-LD — as
 * a block a page can hold.
 *
 * Reuses BlockRenderer rather than reimplementing the markup, so the page
 * and the post editor cannot drift into two different FAQs. The stylesheet
 * is shared by construction: every template rule names both surfaces in one
 * selector (`.magna-blog-faq[data-template="neon"] …, .faq[data-template="neon"]
 * details`), which is also why a frontend-only copy of that CSS would be a
 * fork rather than an extraction.
 *
 * SECURITY — the reason this class exists at all rather than the view
 * calling BlockRenderer directly:
 *
 * BlockRenderer emits text fields AS-IS. It is documented as taking an
 * already-sanitised document, and in the blog that holds, because a post
 * body passes EditorJsSanitizer on save. Page-builder data does NOT: it is
 * written through JSON-Patch, which never goes near that sanitiser. Handing
 * raw block data to BlockRenderer would therefore be stored XSS, reachable
 * by anyone who can edit a page.
 *
 * So the document is sanitised HERE, on the way in, every time — the same
 * allowlist the post editor uses, applied at the point the guarantee would
 * otherwise be missing.
 */
final class FaqBlockResolver implements ResolvesBlockData
{
    private ?EditorJsSanitizer $sanitizer = null;

    private ?BlockRenderer $renderer = null;

    /*
     * Collaborators resolved on FIRST USE, not injected.
     *
     * Every registered resolver is built when the plugin boots, and
     * EditorJsSanitizer's constructor builds two HtmlSanitizers — one of
     * them calling allowSafeElements(), which materialises the whole W3C
     * attribute table. Constructor-injecting it meant paying for that on
     * every boot of an app with the blog enabled, whether or not any page
     * held an FAQ; across a full test suite it exhausted a gigabyte.
     *
     * Resolved through app() rather than by holding the container: a
     * resolver that carries the container carries the entire application
     * graph with it, and anything that exports one — a failure diff, a dump
     * — walks that graph until it runs out of memory. This class stays a
     * small object that knows how to find two collaborators.
     *
     * The plugin binds EditorJsSanitizer as a singleton, so a page with
     * five FAQ blocks still builds one.
     */
    private function sanitizer(): EditorJsSanitizer
    {
        return $this->sanitizer ??= app(EditorJsSanitizer::class);
    }

    private function renderer(): BlockRenderer
    {
        return $this->renderer ??= app(BlockRenderer::class);
    }

    public function handle(): string
    {
        return 'blog-faq';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function resolve(array $data): array
    {
        $items = [];
        foreach (is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
            if (is_array($item)) {
                $items[] = [
                    'question' => (string) ($item['question'] ?? ''),
                    'answer' => (string) ($item['answer'] ?? ''),
                ];
            }
        }

        if ($items === []) {
            return ['html' => ''];
        }

        $template = is_string($data['template'] ?? null) ? $data['template'] : 'card';

        // Sanitise, THEN render — the order the blog itself uses, moved to
        // the point where page-builder data enters.
        $clean = $this->sanitizer()->sanitize(['blocks' => [[
            'type' => 'faq',
            'data' => [
                'items' => $items,
                'template' => in_array($template, BlockSchema::FAQ_TEMPLATES, true) ? $template : 'card',
                'openFirst' => ($data['open_first'] ?? 'no') === 'yes',
                // JSON-LD is worth having by default: an FAQ on a page is
                // exactly the thing search results render as an accordion.
                'schema' => ($data['schema'] ?? 'yes') === 'yes',
            ],
        ]]]);

        /*
         * Nothing SURVIVED, not nothing was given.
         *
         * The sanitiser drops an item with neither question nor answer, so a
         * freshly seeded FAQ — one blank row — cleans down to no items and
         * BlockRenderer emits `<div class="faq">` with no children. That div
         * has no height, which in the builder means a block nobody can
         * click: the canvas marks it, and the marker is invisible.
         *
         * Returning nothing instead hands it to the empty-block placeholder,
         * which is drawn precisely so a block with nothing to show is still
         * selectable.
         */
        $survived = $clean['blocks'][0]['data']['items'] ?? [];
        if (! is_array($survived) || $survived === []) {
            return ['html' => ''];
        }

        return ['html' => $this->renderer()->render($clean)];
    }
}
