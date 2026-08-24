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
    public function __construct(
        private readonly EditorJsSanitizer $sanitizer,
        private readonly BlockRenderer $renderer,
    ) {}

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
        $clean = $this->sanitizer->sanitize(['blocks' => [[
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

        return ['html' => $this->renderer->render($clean)];
    }
}
