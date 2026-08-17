<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests\Unit;

use MagnaCms\Blog\Support\BlockRenderer;
use PHPUnit\Framework\TestCase;

final class BlockRendererTest extends TestCase
{
    private BlockRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->renderer = new BlockRenderer;
    }

    public function test_it_renders_common_blocks(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'Title', 'level' => 2]],
            ['type' => 'paragraph', 'data' => ['text' => 'Hi <b>bold</b>']],
            ['type' => 'callout', 'data' => ['type' => 'warning', 'title' => 'Note', 'text' => 'body']],
            ['type' => 'buttons', 'data' => ['buttons' => [['label' => 'Go', 'url' => 'https://x.test', 'style' => 'primary']]]],
            ['type' => 'columns', 'data' => ['cols' => [['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'col text']]]]]]],
        ]]);

        $this->assertStringContainsString('<h2 id="title">Title</h2>', $html);
        $this->assertStringContainsString('<p>Hi <b>bold</b></p>', $html);
        $this->assertStringContainsString('callout-warning', $html);
        $this->assertStringContainsString('href="https://x.test"', $html);
        $this->assertStringContainsString('col text', $html);
    }

    public function test_it_escapes_url_attributes(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'image', 'data' => ['url' => 'https://x.test/a.jpg"><script>alert(1)</script>', 'alt' => 'x']],
        ]]);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_it_applies_the_style_tune_as_inline_css(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'styled'], 'tunes' => ['style' => [
                'align' => 'center', 'color' => '#2563eb', 'fontSize' => 'lg', 'font' => 'lora',
            ]]],
            // No style tune: rendered untouched, no wrapper div.
            ['type' => 'paragraph', 'data' => ['text' => 'plain']],
        ]]);

        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('color:#2563eb', $html);
        $this->assertStringContainsString('font-size:1.25rem', $html);
        $this->assertStringContainsString('Lora', $html);
        $this->assertStringContainsString('<p>styled</p>', $html);
        $this->assertStringContainsString('<p>plain</p>', $html);
    }

    public function test_the_background_style_tune_paints_a_real_background(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'boxed'], 'tunes' => ['style' => ['bg' => '#22c55e']]],
        ]]);

        // Must paint an actual background (not only the --p-bg custom property),
        // with padding + radius so the text is not flush against the edge.
        $this->assertStringContainsString('background-color:#22c55e', $html);
        $this->assertStringContainsString('--p-bg:#22c55e', $html);
        $this->assertStringContainsString('padding:0.75rem 1rem', $html);
        $this->assertStringContainsString('border-radius:8px', $html);
    }

    public function test_it_renders_buttons_with_type_colour_and_layout(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'buttons', 'data' => ['align' => 'center', 'gap' => 12, 'buttons' => [
                ['label' => 'Filled', 'url' => 'https://x.test', 'type' => 'filled', 'color' => '#ff0000', 'radius' => 20],
                ['label' => 'Outline', 'url' => 'https://y.test', 'type' => 'outline', 'color' => '#00ff00', 'textColor' => '#0000ff'],
                ['label' => 'New', 'url' => 'https://z.test', 'type' => 'link', 'newTab' => true, 'nofollow' => true],
            ]]],
        ]]);

        // Block alignment + gap on the container.
        $this->assertStringContainsString('justify-content:center', $html);
        $this->assertStringContainsString('gap:12px', $html);
        // Filled: background hex + radius.
        $this->assertStringContainsString('background:#ff0000', $html);
        $this->assertStringContainsString('border-radius:20px', $html);
        // Outline: explicit text colour used.
        $this->assertStringContainsString('color:#0000ff', $html);
        // New-tab + nofollow attributes.
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
    }

    public function test_it_maps_a_button_font_key_to_its_css_stack(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'buttons', 'data' => ['buttons' => [
                ['label' => 'A', 'url' => 'https://a.test', 'font' => 'poppins'],
            ]]],
        ]]);

        // Key resolves to the registry stack (never the raw key).
        $this->assertStringContainsString('Poppins', $html);
        $this->assertStringNotContainsString('font-family:poppins', $html);
    }

    public function test_it_renders_image_layout_and_link(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'image', 'data' => [
                'url' => 'https://cdn.test/a.png', 'alt' => 'A', 'align' => 'center',
                'width' => 'medium', 'rounded' => true, 'linkUrl' => 'https://ok.test',
            ]],
        ]]);

        $this->assertStringContainsString('width:50%', $html);
        $this->assertStringContainsString('border-radius:0.6rem', $html);
        $this->assertStringContainsString('text-align:center', $html);
        $this->assertStringContainsString('<a href="https://ok.test">', $html);
    }

    public function test_it_renders_gallery_grid(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'gallery', 'data' => [
                'images' => [['url' => 'https://cdn.test/a.jpg'], ['url' => 'https://cdn.test/b.jpg']],
                'columns' => 4, 'gap' => 12, 'crop' => '16-9', 'rounded' => true,
            ]],
        ]]);

        $this->assertStringContainsString('grid-template-columns:repeat(4,1fr)', $html);
        $this->assertStringContainsString('gap:12px', $html);
        $this->assertStringContainsString('aspect-ratio:16/9', $html);
        $this->assertStringContainsString('border-radius:.5rem', $html);
    }

    public function test_it_renders_cover_height_overlay_and_align(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'cover', 'data' => [
                'url' => 'https://cdn.test/bg.jpg', 'title' => 'Hi', 'text' => 'body',
                'height' => 'large', 'overlay' => 60, 'align' => 'left',
            ]],
        ]]);

        $this->assertStringContainsString('min-height:440px', $html);
        $this->assertStringContainsString('text-align:left', $html);
        $this->assertStringContainsString('rgba(0,0,0,0.6)', $html);
        $this->assertStringContainsString('align-items:flex-start', $html);
    }

    public function test_it_renders_video_playback_flags(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'video', 'data' => ['url' => 'https://cdn.test/v.mp4', 'controls' => false, 'autoplay' => true, 'loop' => true]],
        ]]);

        $this->assertStringNotContainsString(' controls', $html);
        $this->assertStringContainsString(' autoplay', $html);
        $this->assertStringContainsString(' loop', $html);
        // Autoplay forces muted + playsinline on video.
        $this->assertStringContainsString(' muted', $html);
        $this->assertStringContainsString(' playsinline', $html);
    }

    public function test_it_renders_cta_align_background_and_button(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'cta', 'data' => [
                'title' => 'Join', 'text' => 'now', 'buttonLabel' => 'Go', 'buttonUrl' => 'https://x.test',
                'align' => 'left', 'background' => '#f1f5f9', 'buttonType' => 'outline', 'buttonColor' => '#16a34a',
            ]],
        ]]);

        $this->assertStringContainsString('text-align:left', $html);
        $this->assertStringContainsString('background:#f1f5f9', $html);
        // Outline button: transparent bg, coloured border + text.
        $this->assertStringContainsString('border:1.5px solid #16a34a', $html);
        $this->assertStringContainsString('background:transparent;color:#16a34a', $html);
    }

    public function test_it_renders_callout_icon_by_type(): void
    {
        $withIcon = $this->renderer->render(['blocks' => [
            ['type' => 'callout', 'data' => ['type' => 'danger', 'title' => 'Stop', 'text' => 'x', 'icon' => true]],
        ]]);
        $this->assertStringContainsString('callout-danger', $withIcon);
        $this->assertStringContainsString('callout-icon', $withIcon);
        $this->assertStringContainsString('✕', $withIcon);

        $noIcon = $this->renderer->render(['blocks' => [
            ['type' => 'callout', 'data' => ['type' => 'info', 'text' => 'x', 'icon' => false]],
        ]]);
        $this->assertStringNotContainsString('callout-icon', $noIcon);
    }

    public function test_it_renders_faq_open_first_and_schema(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'faq', 'data' => [
                'openFirst' => true, 'schema' => true,
                'items' => [
                    ['question' => 'Q1', 'answer' => 'A1'],
                    ['question' => 'Q2', 'answer' => 'A2'],
                ],
            ]],
        ]]);

        // First item open, second closed.
        $this->assertStringContainsString('<details open><summary>Q1', $html);
        $this->assertStringContainsString('<details><summary>Q2', $html);
        // JSON-LD schema emitted.
        $this->assertStringContainsString('application/ld+json', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
    }

    public function test_faq_schema_cannot_break_out_of_the_script_tag(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'faq', 'data' => ['schema' => true, 'items' => [
                ['question' => 'Q</script><script>alert(1)</script>', 'answer' => 'A'],
            ]]],
        ]]);

        $this->assertStringNotContainsString('</script><script>alert', $html);
    }

    public function test_faq_template_is_emitted_and_defaults_and_falls_back(): void
    {
        $valid = $this->renderer->render(['blocks' => [
            ['type' => 'faq', 'data' => ['template' => 'terminal', 'items' => [['question' => 'Q', 'answer' => 'A']]]],
        ]]);
        $this->assertStringContainsString('<div class="faq" data-template="terminal">', $valid);

        // Missing template defaults to 'card'.
        $default = $this->renderer->render(['blocks' => [
            ['type' => 'faq', 'data' => ['items' => [['question' => 'Q', 'answer' => 'A']]]],
        ]]);
        $this->assertStringContainsString('data-template="card"', $default);

        // Unknown template falls back to 'card'.
        $bogus = $this->renderer->render(['blocks' => [
            ['type' => 'faq', 'data' => ['template' => 'evil"><x', 'items' => [['question' => 'Q', 'answer' => 'A']]]],
        ]]);
        $this->assertStringContainsString('data-template="card"', $bogus);
        $this->assertStringNotContainsString('evil', $bogus);
    }

    public function test_it_renders_toc_title_and_numbering(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'toc', 'data' => [
                'title' => 'On this page', 'ordered' => true,
                'items' => [['text' => 'Intro'], ['text' => 'Setup']],
            ]],
        ]]);

        $this->assertStringContainsString('<strong>On this page</strong>', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertStringContainsString('<a href="#intro">Intro</a>', $html);
    }

    public function test_it_renders_related_posts_layout_and_images(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'relatedPosts', 'data' => [
                'layout' => 'list', 'showImage' => true,
                'posts' => [
                    ['title' => 'First', 'url' => 'https://x.test/1', 'image' => 'https://cdn.test/1.jpg'],
                    ['title' => 'Second'],
                ],
            ]],
        ]]);

        $this->assertStringContainsString('related-list', $html);
        $this->assertStringContainsString('<a href="https://x.test/1">First</a>', $html);
        $this->assertStringContainsString('<img src="https://cdn.test/1.jpg"', $html);
    }

    public function test_it_renders_delimiter_styles(): void
    {
        $dots = $this->renderer->render(['blocks' => [['type' => 'delimiter', 'data' => ['style' => 'dots']]]]);
        $this->assertStringContainsString('<div class="delimiter">• • •</div>', $dots);

        $line = $this->renderer->render(['blocks' => [['type' => 'delimiter', 'data' => ['style' => 'line']]]]);
        $this->assertStringContainsString('<hr class="delimiter-line">', $line);

        // Unknown style falls back to dots.
        $bad = $this->renderer->render(['blocks' => [['type' => 'delimiter', 'data' => ['style' => 'zigzag']]]]);
        $this->assertStringContainsString('• • •', $bad);
    }

    public function test_it_renders_spacer_height_and_divider(): void
    {
        $plain = $this->renderer->render(['blocks' => [['type' => 'spacer', 'data' => ['height' => 48]]]]);
        $this->assertStringContainsString('height:48px', $plain);
        $this->assertStringNotContainsString('<hr', $plain);

        $divider = $this->renderer->render(['blocks' => [['type' => 'spacer', 'data' => ['height' => 40, 'divider' => true]]]]);
        $this->assertStringContainsString('height:40px', $divider);
        $this->assertStringContainsString('<hr', $divider);
    }

    public function test_it_applies_table_presentation_flags_via_style_tune(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'table', 'data' => ['withHeadings' => true, 'content' => [['A', 'B'], ['1', '2']]],
                'tunes' => ['style' => ['striped' => true, 'bordered' => true]]],
        ]]);

        $this->assertStringContainsString('class="is-striped is-bordered"', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_it_renders_columns_with_count_var_and_valign(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'columns', 'data' => ['cols' => [
                ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'left']]]],
                ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'right']]]],
            ]], 'tunes' => ['style' => ['valign' => 'center']]],
        ]]);

        $this->assertStringContainsString('--cols:2', $html);
        $this->assertStringContainsString('class="is-vcenter"', $html);
        $this->assertStringContainsString('left', $html);
    }

    public function test_it_gives_headings_anchor_ids_and_links_the_toc(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'header', 'data' => ['text' => 'Getting Started!', 'level' => 2]],
            ['type' => 'toc', 'data' => ['items' => [['text' => 'Getting Started!']]]],
        ]]);

        $this->assertStringContainsString('<h2 id="getting-started">Getting Started!</h2>', $html);
        $this->assertStringContainsString('<a href="#getting-started">Getting Started!</a>', $html);
    }

    public function test_it_tags_a_code_block_with_its_language(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'code', 'data' => ['code' => 'echo 1;', 'language' => 'php']],
        ]]);
        $this->assertStringContainsString('<code class="language-php">echo 1;</code>', $html);

        $plain = $this->renderer->render(['blocks' => [['type' => 'code', 'data' => ['code' => 'x', 'language' => 'zzz']]]]);
        $this->assertStringNotContainsString('language-', $plain);
    }

    public function test_it_applies_a_columns_ratio_via_style_tune(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'columns', 'data' => ['cols' => [
                ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'a']]]],
                ['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'b']]]],
            ]], 'tunes' => ['style' => ['ratio' => '2-1']]],
        ]]);

        $this->assertStringContainsString('class="ratio-2-1"', $html);
    }

    public function test_it_renders_paragraph_templates(): void
    {
        $callout = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Note', 'template' => 'callout']]]]);
        $this->assertStringContainsString('<div class="magna-blog-p" data-template="callout"><div class="magna-blog-p__text">Note</div></div>', $callout);

        // Labelled template emits the stored label (or its default).
        $ribbon = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'ribbon', 'label' => 'New']]]]);
        $this->assertStringContainsString('data-template="ribbon"', $ribbon);
        $this->assertStringContainsString('<span class="magna-blog-p__label">New</span>', $ribbon);

        $stat = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'stat']]]]);
        $this->assertStringContainsString('<span class="magna-blog-p__label">94%</span>', $stat);

        $withImg = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hi', 'template' => 'image-left', 'image' => 'https://cdn.test/i.jpg', 'imageAlt' => 'x']]]]);
        $this->assertStringContainsString('data-template="image-left"', $withImg);
        $this->assertStringContainsString('<div class="magna-blog-p__media"><img src="https://cdn.test/i.jpg" alt="x"></div>', $withImg);

        // Image template with no image degrades to a plain paragraph.
        $noImg = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'Hi', 'template' => 'image-left']]]]);
        $this->assertStringContainsString('<p>Hi</p>', $noImg);
        $this->assertStringNotContainsString('magna-blog-p__media', $noImg);
    }

    public function test_takeaway_and_insight_labels_are_editable(): void
    {
        // No stored label falls back to the template default.
        $default = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'takeaway']]]]);
        $this->assertStringContainsString('data-template="takeaway"', $default);
        $this->assertStringContainsString('<span class="magna-blog-p__label">Key takeaway</span>', $default);

        // A user-supplied label wins over the default (nothing is hardcoded).
        $custom = $this->renderer->render(['blocks' => [['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'insight', 'label' => 'My finding']]]]);
        $this->assertStringContainsString('<span class="magna-blog-p__label">My finding</span>', $custom);
    }

    public function test_paragraph_accent_colour_is_applied_as_a_css_var(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'callout'], 'tunes' => ['style' => ['accent' => '#ff0088']]],
        ]]);

        $this->assertStringContainsString('--p-accent:#ff0088', $html);
    }

    public function test_paragraph_accent_and_editable_label_and_alt(): void
    {
        // takeaway is a labelled template: a custom label overrides the default.
        $takeaway = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'takeaway', 'label' => 'Remember']],
        ]]);
        $this->assertStringContainsString('<span class="magna-blog-p__label">Remember</span>', $takeaway);

        // The Style tune's accent colour is emitted as the --p-accent custom prop
        // (hex only) so templates can recolour their decorative parts.
        $accent = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'callout'],
                'tunes' => ['style' => ['accent' => '#ff0000']]],
        ]]);
        $this->assertStringContainsString('--p-accent:#ff0000', $accent);

        // Text colour is emitted both as `color` and as --p-text, so templates
        // whose inner elements carry a default colour can defer to the user's.
        $textColour = $this->renderer->render(['blocks' => [
            ['type' => 'faq', 'data' => ['template' => 'card', 'items' => [['question' => 'Q', 'answer' => 'A']]],
                'tunes' => ['style' => ['color' => '#00ff00']]],
        ]]);
        $this->assertStringContainsString('color:#00ff00', $textColour);
        $this->assertStringContainsString('--p-text:#00ff00', $textColour);

        // A non-hex accent is dropped (never reaches inline CSS).
        $bad = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'x', 'template' => 'callout'],
                'tunes' => ['style' => ['accent' => 'red;background:url(x)']]],
        ]]);
        $this->assertStringNotContainsString('--p-accent', $bad);

        // Image alt is stored + emitted (editable in the inspector).
        $img = $this->renderer->render(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Hi', 'template' => 'image-left',
                'image' => 'https://cdn.test/i.jpg', 'imageAlt' => 'A red bike']],
        ]]);
        $this->assertStringContainsString('alt="A red bike"', $img);
    }

    public function test_it_renders_details_block(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'details', 'data' => ['summary' => 'More info', 'text' => 'Hidden <b>body</b>', 'open' => true]],
        ]]);
        $this->assertStringContainsString('<details class="magna-details" open><summary>More info</summary>', $html);
        $this->assertStringContainsString('<div class="magna-details__body">Hidden <b>body</b></div>', $html);

        // Empty details renders nothing.
        $this->assertSame('', $this->renderer->render(['blocks' => [['type' => 'details', 'data' => []]]]));
    }

    public function test_it_renders_social_icons(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'socialIcons', 'data' => ['style' => 'brand', 'shape' => 'circle', 'size' => 'lg', 'align' => 'center',
                'items' => [
                    ['network' => 'github', 'url' => 'https://github.com/x'],
                    ['network' => 'evil', 'url' => 'https://example.test'],
                ]]],
        ]]);
        $this->assertStringContainsString('data-shape="circle" data-style="brand" data-size="lg" data-align="center"', $html);
        $this->assertStringContainsString('href="https://github.com/x"', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
        $this->assertStringContainsString('<svg', $html);
        // Unknown network falls back to the generic website icon (still renders).
        $this->assertStringContainsString('href="https://example.test"', $html);

        // No valid links → nothing.
        $this->assertSame('', $this->renderer->render(['blocks' => [['type' => 'socialIcons', 'data' => ['items' => []]]]]));
    }

    public function test_it_renders_media_text(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'mediaText', 'data' => ['image' => 'https://cdn.test/i.jpg', 'imageAlt' => 'pic', 'text' => 'Hello',
                'mediaSide' => 'right', 'ratio' => '2-1', 'vAlign' => 'top', 'stackMobile' => true]],
        ]]);
        $this->assertStringContainsString('class="magna-mediatext" data-side="right" data-ratio="2-1" data-valign="top" data-stack="1"', $html);
        $this->assertStringContainsString('<img src="https://cdn.test/i.jpg" alt="pic">', $html);

        // No image degrades to a plain text column.
        $plain = $this->renderer->render(['blocks' => [['type' => 'mediaText', 'data' => ['text' => 'Just text']]]]);
        $this->assertStringContainsString('<div class="magna-mediatext-plain">Just text</div>', $plain);
        $this->assertStringNotContainsString('magna-mediatext__media', $plain);
    }

    public function test_it_renders_group_with_nested_blocks_and_layout(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'group', 'data' => [
                'layout' => 'grid', 'columns' => 3, 'gap' => 20, 'padding' => 24, 'radius' => 8,
                'background' => '#eeeeee', 'align' => 'center',
                'blocks' => [
                    ['type' => 'paragraph', 'data' => ['text' => 'inside']],
                    ['type' => 'header', 'data' => ['text' => 'Head', 'level' => 3]],
                ],
            ]],
        ]]);

        $this->assertStringContainsString('class="magna-group" data-layout="grid" data-align="center"', $html);
        $this->assertStringContainsString('--g-cols:3', $html);
        $this->assertStringContainsString('--g-bg:#eeeeee', $html);
        $this->assertStringContainsString('<p>inside</p>', $html);
        $this->assertStringContainsString('Head</h3>', $html);

        // Empty group renders nothing.
        $this->assertSame('', $this->renderer->render(['blocks' => [['type' => 'group', 'data' => ['blocks' => []]]]]));
    }

    public function test_it_renders_footnotes_with_anchors(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'footnotes', 'data' => ['title' => 'References', 'items' => [
                ['text' => 'First <b>note</b>'],
                ['text' => '   '],
                ['text' => 'Second note'],
            ]]],
        ]]);

        $this->assertStringContainsString('<section class="footnotes">', $html);
        $this->assertStringContainsString('<h2 class="footnotes__title">References</h2>', $html);
        $this->assertStringContainsString('<li id="fn-1" class="footnote">', $html);
        $this->assertStringContainsString('href="#fnref-1"', $html);
        // Blank item skipped, so the second real note is fn-2.
        $this->assertStringContainsString('<li id="fn-2" class="footnote">', $html);
        $this->assertStringNotContainsString('fn-3', $html);

        // All-empty footnotes render nothing.
        $this->assertSame('', $this->renderer->render(['blocks' => [['type' => 'footnotes', 'data' => ['items' => [['text' => ' ']]]]]]));
    }

    public function test_it_renders_rss_items_from_the_stored_snapshot(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'rss', 'data' => [
                'feedTitle' => 'Example Blog', 'count' => 2, 'showDate' => true,
                'items' => [
                    ['title' => 'First post', 'link' => 'https://example.test/1', 'date' => 'Mon, 01 Jan 2026'],
                    ['title' => 'Second post', 'link' => 'https://example.test/2', 'date' => 'Tue, 02 Jan 2026'],
                    ['title' => 'Third post', 'link' => 'https://example.test/3', 'date' => 'Wed, 03 Jan 2026'],
                ],
            ]],
        ]]);

        $this->assertStringContainsString('<strong class="rss__feed">Example Blog</strong>', $html);
        $this->assertStringContainsString('href="https://example.test/1" rel="noopener nofollow"', $html);
        $this->assertStringContainsString('Mon, 01 Jan 2026', $html);
        // count=2 caps the list — the third item is not rendered.
        $this->assertStringNotContainsString('example.test/3', $html);

        // Dates hidden when showDate is false.
        $noDate = $this->renderer->render(['blocks' => [
            ['type' => 'rss', 'data' => ['showDate' => false, 'items' => [['title' => 'x', 'link' => 'https://e.test/x', 'date' => 'someday']]]],
        ]]);
        $this->assertStringNotContainsString('someday', $noDate);

        // No items → nothing.
        $this->assertSame('', $this->renderer->render(['blocks' => [['type' => 'rss', 'data' => ['items' => []]]]]));
    }

    public function test_it_renders_a_link_card_when_metadata_is_present(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'link', 'data' => [
                'link' => 'https://example.test/post',
                'text' => 'ignored when a card renders',
                'newTab' => true,
                'nofollow' => true,
                'meta' => [
                    'title' => 'Great Post',
                    'description' => 'A short summary.',
                    'image' => ['url' => 'https://cdn.test/og.png'],
                ],
            ]],
        ]]);

        $this->assertStringContainsString('class="link-card"', $html);
        $this->assertStringContainsString('href="https://example.test/post"', $html);
        $this->assertStringContainsString('rel="noopener nofollow"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('Great Post', $html);
        $this->assertStringContainsString('example.test', $html);
        $this->assertStringContainsString("url('https://cdn.test/og.png')", $html);
    }

    public function test_it_renders_a_plain_anchor_with_link_text_when_no_metadata(): void
    {
        $html = $this->renderer->render(['blocks' => [
            ['type' => 'link', 'data' => [
                'link' => 'https://example.test/x',
                'text' => 'Read more',
                'meta' => ['title' => '', 'description' => '', 'image' => ['url' => '']],
            ]],
        ]]);

        $this->assertStringContainsString('class="link-plain"', $html);
        $this->assertStringContainsString('>Read more</a>', $html);
        // No new-tab opt-in → no target attribute; noopener still present.
        $this->assertStringNotContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener"', $html);
    }

    public function test_a_link_block_without_a_url_renders_nothing(): void
    {
        $this->assertSame('', $this->renderer->render(['blocks' => [
            ['type' => 'link', 'data' => ['link' => '', 'text' => 'nope']],
        ]]));
    }

    public function test_unknown_blocks_render_nothing(): void
    {
        $this->assertSame('', $this->renderer->render(['blocks' => [
            ['type' => 'evil', 'data' => ['x' => 1]],
        ]]));
    }
}
