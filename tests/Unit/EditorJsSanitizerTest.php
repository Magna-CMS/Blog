<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests\Unit;

use MagnaCms\Blog\Editor\EditorJsSanitizer;
use PHPUnit\Framework\TestCase;

final class EditorJsSanitizerTest extends TestCase
{
    private EditorJsSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new EditorJsSanitizer;
    }

    public function test_it_strips_scripts_and_event_handlers_from_paragraph_text(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Hi <b>x</b><script>alert(1)</script><img src=x onerror=alert(2)>']],
        ]]);

        $text = $out['blocks'][0]['data']['text'];
        $this->assertStringContainsString('<b>x</b>', $text);
        $this->assertStringNotContainsString('<script>', $text);
        $this->assertStringNotContainsString('onerror', $text);
    }

    public function test_it_keeps_new_inline_formats_but_strips_unsafe_span_attrs(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'a<s>b</s> H<sub>2</sub>O x<sup>2</sup> <span class="mgb-c-red">red</span>'
                .'<span style="color:red" onclick="x()">bad</span>']],
        ]]);

        $text = $out['blocks'][0]['data']['text'];
        $this->assertStringContainsString('<s>b</s>', $text);
        $this->assertStringContainsString('<sub>2</sub>', $text);
        $this->assertStringContainsString('<sup>2</sup>', $text);
        $this->assertStringContainsString('<span class="mgb-c-red">red</span>', $text);
        // The class-only span survives; style + event handler are stripped.
        $this->assertStringNotContainsString('onclick', $text);
        $this->assertStringNotContainsString('style=', $text);
    }

    public function test_it_drops_javascript_links_but_keeps_safe_https_links(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => '<a href="javascript:alert(1)">x</a> <a href="https://ok.test">ok</a>']],
        ]]);

        $text = $out['blocks'][0]['data']['text'];
        $this->assertStringNotContainsString('javascript:', $text);
        $this->assertStringContainsString('https://ok.test', $text);
    }

    public function test_it_keeps_relative_and_anchor_links_but_still_drops_javascript(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => '<a href="#fn-1">ref</a> <a href="/about">internal</a> <a href="javascript:alert(1)">x</a>']],
        ]]);

        $text = $out['blocks'][0]['data']['text'];
        // Footnote anchor + internal path survive; javascript: is dropped.
        $this->assertStringContainsString('href="#fn-1"', $text);
        $this->assertStringContainsString('href="/about"', $text);
        $this->assertStringNotContainsString('javascript:', $text);
    }

    public function test_it_drops_unknown_block_types(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'keep']],
            ['type' => 'evil', 'data' => ['text' => 'drop']],
        ]]);

        $this->assertCount(1, $out['blocks']);
        $this->assertSame('paragraph', $out['blocks'][0]['type']);
    }

    public function test_it_clamps_header_levels_to_the_2_to_4_range(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'h', 'level' => 9]],
            ['type' => 'header', 'data' => ['text' => 'h', 'level' => 1]],
        ]]);

        $this->assertSame(4, $out['blocks'][0]['data']['level']);
        $this->assertSame(2, $out['blocks'][1]['data']['level']);
    }

    public function test_it_rejects_non_http_image_urls_and_strips_alt_tags(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'image', 'data' => ['url' => 'javascript:alert(1)']],
            ['type' => 'image', 'data' => ['url' => 'https://cdn.test/a.png', 'alt' => '<b>x</b>']],
        ]]);

        $this->assertCount(1, $out['blocks']);
        $this->assertSame('https://cdn.test/a.png', $out['blocks'][0]['data']['url']);
        $this->assertSame('x', $out['blocks'][0]['data']['alt']);
    }

    public function test_it_validates_image_layout_settings(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'image', 'data' => [
                'url' => 'https://cdn.test/a.png', 'align' => 'center', 'width' => 'medium',
                'rounded' => true, 'linkUrl' => 'https://ok.test',
            ]],
            ['type' => 'image', 'data' => [
                'url' => 'https://cdn.test/b.png', 'align' => 'diagonal', 'width' => 'huge',
                'linkUrl' => 'javascript:alert(1)',
            ]],
        ]]);

        $a = $out['blocks'][0]['data'];
        $this->assertSame('center', $a['align']);
        $this->assertSame('medium', $a['width']);
        $this->assertTrue($a['rounded']);
        $this->assertSame('https://ok.test', $a['linkUrl']);

        // Invalid align/width fall back; javascript link is emptied.
        $b = $out['blocks'][1]['data'];
        $this->assertSame('', $b['align']);
        $this->assertSame('full', $b['width']);
        $this->assertSame('', $b['linkUrl']);
    }

    public function test_it_keeps_a_link_block_with_a_safe_url_and_drops_a_javascript_one(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'link', 'data' => ['link' => 'javascript:alert(1)', 'meta' => []]],
            ['type' => 'link', 'data' => [
                'link' => 'https://ok.test',
                'text' => 'Read <b>more</b>',
                'newTab' => true,
                'nofollow' => 1,
                'meta' => ['title' => '<b>x</b>Site'],
            ]],
        ]]);

        $this->assertCount(1, $out['blocks']);
        $this->assertSame('https://ok.test', $out['blocks'][0]['data']['link']);
        $this->assertSame('Read more', $out['blocks'][0]['data']['text']);
        $this->assertTrue($out['blocks'][0]['data']['newTab']);
        $this->assertTrue($out['blocks'][0]['data']['nofollow']);
        $this->assertSame('xSite', $out['blocks'][0]['data']['meta']['title']);
    }

    public function test_it_drops_an_attaches_block_with_an_unsafe_file_url(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'attaches', 'data' => ['file' => ['url' => 'javascript:alert(1)']]],
            ['type' => 'attaches', 'data' => ['file' => ['url' => 'https://cdn.test/a.pdf', 'name' => 'a.pdf', 'size' => 1234]]],
        ]]);

        $this->assertCount(1, $out['blocks']);
        $this->assertSame('https://cdn.test/a.pdf', $out['blocks'][0]['data']['file']['url']);
        $this->assertSame(1234, $out['blocks'][0]['data']['file']['size']);
    }

    public function test_it_sanitises_the_new_static_blocks(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'callout', 'data' => ['type' => 'evil', 'title' => '<b>t</b>', 'text' => 'hi<script>x</script>']],
            ['type' => 'buttons', 'data' => ['align' => 'sideways', 'gap' => 999, 'buttons' => [
                ['label' => 'Go', 'url' => 'javascript:alert(1)', 'type' => 'hacker', 'color' => 'red; position:fixed'],
                ['label' => 'Docs', 'url' => 'https://ok.test', 'type' => 'outline', 'color' => '#ff0000', 'textColor' => 'evil', 'radius' => 999],
            ]]],
            ['type' => 'spacer', 'data' => ['height' => 'huge']],
            ['type' => 'preformatted', 'data' => ['text' => "  spaced\n  lines<script>x</script>"]],
            ['type' => 'more', 'data' => []],
            ['type' => 'pageBreak', 'data' => []],
        ]]);

        $blocks = $out['blocks'];

        // Callout: invalid type -> info; title tag-stripped; script gone.
        $this->assertSame('info', $blocks[0]['data']['type']);
        $this->assertSame('t', $blocks[0]['data']['title']);
        $this->assertStringNotContainsString('<script>', $blocks[0]['data']['text']);

        // Buttons block-level: invalid align -> left; gap clamped to 64.
        $this->assertSame('left', $blocks[1]['data']['align']);
        $this->assertSame(64, $blocks[1]['data']['gap']);
        // Button 0: javascript URL emptied; invalid type -> filled; CSS-injection
        // colour rejected -> default hex.
        $this->assertSame('', $blocks[1]['data']['buttons'][0]['url']);
        $this->assertSame('filled', $blocks[1]['data']['buttons'][0]['type']);
        $this->assertSame('#6366f1', $blocks[1]['data']['buttons'][0]['color']);
        // Button 1: safe URL kept; valid hex colour kept; bad textColor -> '';
        // radius clamped to 50.
        $this->assertSame('https://ok.test', $blocks[1]['data']['buttons'][1]['url']);
        $this->assertSame('outline', $blocks[1]['data']['buttons'][1]['type']);
        $this->assertSame('#ff0000', $blocks[1]['data']['buttons'][1]['color']);
        $this->assertSame('', $blocks[1]['data']['buttons'][1]['textColor']);
        $this->assertSame(50, $blocks[1]['data']['buttons'][1]['radius']);

        // Spacer: invalid height -> default 32px.
        $this->assertSame(32, $blocks[2]['data']['height']);

        // Preformatted: whitespace preserved, tags stripped.
        $this->assertStringContainsString("  spaced\n  lines", $blocks[3]['data']['text']);
        $this->assertStringNotContainsString('<script>', $blocks[3]['data']['text']);

        // Marker blocks kept with empty data.
        $this->assertSame('more', $blocks[4]['type']);
        $this->assertSame('pageBreak', $blocks[5]['type']);
    }

    public function test_it_sanitises_media_blocks(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'gallery', 'data' => ['images' => [
                ['url' => '/storage/blog/a.jpg', 'alt' => '<b>A</b>'],
                ['url' => 'javascript:alert(1)'],
                ['url' => 'https://cdn.test/b.jpg'],
            ]]],
            ['type' => 'video', 'data' => ['url' => 'https://cdn.test/v.mp4', 'caption' => 'Clip']],
            ['type' => 'audio', 'data' => ['url' => 'javascript:alert(1)']],
        ]]);

        $blocks = $out['blocks'];

        // Gallery: root-relative kept, alt tag-stripped, javascript dropped, https kept.
        $this->assertCount(2, $blocks[0]['data']['images']);
        $this->assertSame('/storage/blog/a.jpg', $blocks[0]['data']['images'][0]['url']);
        $this->assertSame('A', $blocks[0]['data']['images'][0]['alt']);
        $this->assertSame('https://cdn.test/b.jpg', $blocks[0]['data']['images'][1]['url']);

        // Video kept; audio with javascript URL dropped entirely.
        $this->assertSame('video', $blocks[1]['type']);
        $this->assertCount(2, $blocks);
    }

    public function test_it_validates_gallery_grid_settings(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'gallery', 'data' => [
                'images' => [['url' => 'https://cdn.test/a.jpg']],
                'columns' => 4, 'gap' => 12, 'crop' => '16-9', 'rounded' => true,
            ]],
            ['type' => 'gallery', 'data' => [
                'images' => [['url' => 'https://cdn.test/b.jpg']],
                'columns' => 99, 'gap' => 999, 'crop' => 'circle',
            ]],
        ]]);

        $a = $out['blocks'][0]['data'];
        $this->assertSame(4, $a['columns']);
        $this->assertSame(12, $a['gap']);
        $this->assertSame('16-9', $a['crop']);
        $this->assertTrue($a['rounded']);

        // Out-of-range clamped, unknown crop dropped.
        $b = $out['blocks'][1]['data'];
        $this->assertSame(6, $b['columns']);
        $this->assertSame(40, $b['gap']);
        $this->assertSame('', $b['crop']);
    }

    public function test_it_allowlists_embed_provider_hosts(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'embed', 'data' => ['service' => 'youtube', 'embed' => 'https://www.youtube.com/embed/abc', 'source' => 'https://youtu.be/abc']],
            ['type' => 'embed', 'data' => ['service' => 'evil', 'embed' => 'https://evil.example/iframe']],
            ['type' => 'embed', 'data' => ['service' => 'spotify', 'embed' => 'https://open.spotify.com/embed/track/xyz']],
        ]]);

        $blocks = $out['blocks'];

        // Only the two allowlisted-host embeds survive; the evil host is dropped.
        $this->assertCount(2, $blocks);
        $this->assertSame('https://www.youtube.com/embed/abc', $blocks[0]['data']['embed']);
        $this->assertSame('https://open.spotify.com/embed/track/xyz', $blocks[1]['data']['embed']);
    }

    public function test_it_recursively_sanitises_columns_and_blocks_nested_columns(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'columns', 'data' => ['cols' => [
                ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'hi<script>x</script>']]]],
                ['blocks' => [['type' => 'columns', 'data' => ['cols' => []]]]],
            ]]],
        ]]);

        $cols = $out['blocks'][0]['data']['cols'];

        $this->assertCount(2, $cols);
        // Nested paragraph sanitised.
        $this->assertStringNotContainsString('<script>', $cols[0]['blocks'][0]['data']['text']);
        // Columns nested inside columns are dropped (no unbounded recursion).
        $this->assertSame([], $cols[1]['blocks']);
    }

    public function test_it_keeps_allowlisted_style_tune_tokens_and_drops_the_rest(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            // Valid values survive (colour is a hex literal now).
            ['type' => 'paragraph', 'data' => ['text' => 'a'], 'tunes' => ['style' => [
                'align' => 'center', 'color' => '#2563eb', 'fontSize' => 'lg', 'font' => 'poppins',
            ]]],
            // Bad values (and a raw-CSS injection attempt) are dropped entirely.
            ['type' => 'paragraph', 'data' => ['text' => 'b'], 'tunes' => ['style' => [
                'align' => 'justify', 'color' => 'red;position:fixed', 'fontSize' => '99px',
            ]]],
            // Unknown tune keys are ignored.
            ['type' => 'paragraph', 'data' => ['text' => 'c'], 'tunes' => ['evil' => ['x' => 1]]],
        ]]);

        $blocks = $out['blocks'];

        $this->assertSame(['align' => 'center', 'color' => '#2563eb', 'fontSize' => 'lg', 'font' => 'poppins'], $blocks[0]['tunes']['style']);
        $this->assertArrayNotHasKey('tunes', $blocks[1]);
        $this->assertArrayNotHasKey('tunes', $blocks[2]);
    }

    public function test_it_allowlists_button_fonts(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'buttons', 'data' => ['buttons' => [
                ['label' => 'A', 'url' => 'https://a.test', 'font' => 'poppins'],
                ['label' => 'B', 'url' => 'https://b.test', 'font' => "notafont'; position:fixed"],
            ]]],
        ]]);

        $buttons = $out['blocks'][0]['data']['buttons'];
        $this->assertSame('poppins', $buttons[0]['font']);
        $this->assertSame('', $buttons[1]['font']);
    }

    public function test_it_maps_legacy_spacer_height_names_and_clamps(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'spacer', 'data' => ['height' => 'large']],
            ['type' => 'spacer', 'data' => ['height' => 999, 'divider' => true]],
        ]]);

        $this->assertSame(64, $out['blocks'][0]['data']['height']);
        $this->assertSame(160, $out['blocks'][1]['data']['height']);
        $this->assertTrue($out['blocks'][1]['data']['divider']);
    }

    public function test_it_validates_paragraph_template_and_image(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'a', 'template' => 'callout', 'image' => 'https://cdn.test/i.jpg', 'imageAlt' => '<b>x</b>']],
            ['type' => 'paragraph', 'data' => ['text' => 'b', 'template' => 'bogus', 'image' => 'javascript:alert(1)']],
        ]]);

        $a = $out['blocks'][0]['data'];
        $this->assertSame('callout', $a['template']);
        $this->assertSame('https://cdn.test/i.jpg', $a['image']);
        $this->assertSame('x', $a['imageAlt']);

        $b = $out['blocks'][1]['data'];
        $this->assertSame('standard', $b['template']);
        $this->assertSame('', $b['image']);
    }

    public function test_it_validates_faq_template(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'faq', 'data' => ['template' => 'terminal', 'items' => [['question' => 'Q', 'answer' => 'A']]]],
            ['type' => 'faq', 'data' => ['template' => 'bogus', 'items' => [['question' => 'Q', 'answer' => 'A']]]],
            ['type' => 'faq', 'data' => ['items' => [['question' => 'Q', 'answer' => 'A']]]],
        ]]);

        $this->assertSame('terminal', $out['blocks'][0]['data']['template']);
        $this->assertSame('card', $out['blocks'][1]['data']['template']);
        $this->assertSame('card', $out['blocks'][2]['data']['template']);
    }

    public function test_it_sanitises_details_social_and_media_text(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'details', 'data' => ['summary' => '<b>Sum</b>', 'text' => 'Body<script>x</script>', 'open' => 1]],
            ['type' => 'socialIcons', 'data' => ['style' => 'bad', 'shape' => 'circle', 'items' => [
                ['network' => 'x', 'url' => 'https://x.com/a'],
                ['network' => 'evil', 'url' => 'javascript:alert(1)'],
                ['network' => 'email', 'url' => 'me@test.dev'],
            ]]],
            ['type' => 'mediaText', 'data' => ['image' => 'https://cdn.test/i.jpg', 'text' => 'hi',
                'mediaSide' => 'nope', 'ratio' => 'bad', 'vAlign' => 'bottom']],
        ]]);

        $details = $out['blocks'][0]['data'];
        $this->assertSame('Sum', $details['summary']);          // tags stripped from summary
        $this->assertStringNotContainsString('<script', $details['text']);
        $this->assertTrue($details['open']);

        $social = $out['blocks'][1]['data'];
        $this->assertSame('brand', $social['style']);           // invalid style → default
        $this->assertCount(2, $social['items']);                // javascript: url dropped
        $this->assertSame('x', $social['items'][0]['network']);
        $this->assertSame('email', $social['items'][1]['network']);
        $this->assertSame('mailto:me@test.dev', $social['items'][1]['url']);

        $mt = $out['blocks'][2]['data'];
        $this->assertSame('left', $mt['mediaSide']);            // invalid → default
        $this->assertSame('1-1', $mt['ratio']);                 // invalid → default
        $this->assertSame('bottom', $mt['vAlign']);
        $this->assertSame('https://cdn.test/i.jpg', $mt['image']);
    }

    public function test_it_sanitises_rss_snapshot(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'rss', 'data' => [
                'url' => 'https://example.test/feed.xml', 'feedTitle' => '<b>Feed</b>', 'count' => 99, 'showDate' => false,
                'items' => [
                    ['title' => 'Good', 'link' => 'https://example.test/1', 'date' => 'today'],
                    ['title' => 'Bad link', 'link' => 'javascript:alert(1)'],
                    ['title' => '', 'link' => ''],
                ],
            ]],
            // No url and no items → dropped.
            ['type' => 'rss', 'data' => ['items' => []]],
        ]]);

        $this->assertCount(1, $out['blocks']);
        $rss = $out['blocks'][0]['data'];
        $this->assertSame('https://example.test/feed.xml', $rss['url']);
        $this->assertSame('Feed', $rss['feedTitle']);           // tags stripped
        $this->assertSame(20, $rss['count']);                   // clamped
        $this->assertFalse($rss['showDate']);
        // Item with a javascript link keeps title but empties link; empty item dropped.
        $this->assertCount(2, $rss['items']);
        $this->assertSame('', $rss['items'][1]['link']);
    }

    public function test_it_sanitises_group_and_bounds_nesting(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'group', 'data' => [
                'layout' => 'bad', 'columns' => 9, 'gap' => 999, 'padding' => -5, 'radius' => 4,
                'background' => 'red; position:fixed', 'align' => 'center',
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'ok<script>x</script>']],
                    // A group nested inside a group must be dropped (depth bound).
                    ['type' => 'group', 'data' => ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'deep']]]]],
                ],
            ]],
            // A row group with no 'columns' key must not error and defaults to 2.
            ['type' => 'group', 'data' => ['layout' => 'row', 'blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x']]]]],
        ]]);

        $this->assertSame(2, $out['blocks'][1]['data']['columns']);

        // Footnotes: script stripped, blank note dropped, empty block removed.
        $fn = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'footnotes', 'data' => ['title' => '<b>Refs</b>', 'items' => [
                ['text' => 'keep<script>x</script>'],
                ['text' => '   '],
            ]]],
            ['type' => 'footnotes', 'data' => ['items' => [['text' => ' ']]]],
        ]]);
        $this->assertCount(1, $fn['blocks']);
        $this->assertSame('Refs', $fn['blocks'][0]['data']['title']);
        $this->assertCount(1, $fn['blocks'][0]['data']['items']);
        $this->assertStringNotContainsString('<script>', $fn['blocks'][0]['data']['items'][0]['text']);

        $group = $out['blocks'][0]['data'];
        $this->assertSame('stack', $group['layout']);           // invalid → default
        $this->assertSame(2, $group['columns']);                // out of range → default
        $this->assertSame(48, $group['gap']);                   // clamped
        $this->assertSame(0, $group['padding']);                // clamped
        $this->assertSame('', $group['background']);            // CSS-injection colour rejected
        $this->assertSame('center', $group['align']);
        // Only the paragraph survives; the nested group is dropped.
        $this->assertCount(1, $group['blocks']);
        $this->assertSame('paragraph', $group['blocks'][0]['type']);
        $this->assertStringNotContainsString('<script>', $group['blocks'][0]['data']['text']);
    }

    public function test_it_always_returns_a_well_formed_document_for_garbage_input(): void
    {
        $fromNull = $this->sanitizer->sanitize(null);
        $this->assertArrayHasKey('time', $fromNull);
        $this->assertArrayHasKey('version', $fromNull);
        $this->assertSame([], $fromNull['blocks']);

        $this->assertSame([], $this->sanitizer->sanitize('not an array')['blocks']);
    }

    public function test_it_bounds_deeply_nested_list_items(): void
    {
        // Build a list nested far deeper than the allowed depth.
        $node = ['content' => 'leaf', 'items' => []];
        for ($i = 0; $i < 40; $i++) {
            $node = ['content' => 'n'.$i, 'items' => [$node]];
        }

        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'list', 'data' => ['style' => 'unordered', 'items' => [$node]]],
        ]]);

        // Walk the surviving nesting; it must stop at the depth cap, not recurse 40 deep.
        $depth = 0;
        $items = $out['blocks'][0]['data']['items'];
        while ($items !== []) {
            $depth++;
            $items = $items[0]['items'] ?? [];
        }

        $this->assertLessThanOrEqual(8, $depth);
    }

    public function test_it_bounds_oversized_tables(): void
    {
        $row = array_fill(0, 500, 'x');
        $rows = array_fill(0, 1000, $row);

        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'table', 'data' => ['withHeadings' => false, 'content' => $rows]],
        ]]);

        $content = $out['blocks'][0]['data']['content'];
        $this->assertLessThanOrEqual(200, count($content));
        $this->assertLessThanOrEqual(50, count($content[0]));
    }
}
