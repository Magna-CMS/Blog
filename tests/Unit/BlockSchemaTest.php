<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests\Unit;

use MagnaCms\Blog\Editor\BlockSchema;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Support\BlockRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Guards the shared block allowlists (BlockSchema) against drift between the two
 * server-side authorities that consume them: the sanitizer (what persists) and
 * the renderer (what is emitted). If these two ever disagree, content is either
 * lost or rendered from data the sanitizer never validated — these tests fail
 * loudly the moment that happens.
 */
final class BlockSchemaTest extends TestCase
{
    private EditorJsSanitizer $sanitizer;

    private BlockRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sanitizer = new EditorJsSanitizer;
        $this->renderer = new BlockRenderer;
    }

    public function test_an_unknown_block_type_never_survives_sanitization(): void
    {
        $out = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'evilblock', 'data' => ['x' => 1]],
            ['type' => 'paragraph', 'data' => ['text' => 'ok']],
        ]]);

        $this->assertCount(1, $out['blocks']);
        foreach ($out['blocks'] as $block) {
            $this->assertContains($block['type'], BlockSchema::TYPES);
        }
    }

    public function test_sanitizer_and_renderer_share_the_paragraph_template_list(): void
    {
        // A known template survives sanitization and reaches the rendered output.
        $known = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Hi', 'template' => 'lead']],
        ]]);
        $this->assertSame('lead', $known['blocks'][0]['data']['template']);
        $this->assertStringContainsString('data-template="lead"', $this->renderer->render($known));

        // An unknown template coerces to "standard" in the sanitizer, and the
        // renderer emits a plain paragraph (no data-template) for it — proving
        // both sides read the same allowlist.
        $unknown = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'paragraph', 'data' => ['text' => 'Hi', 'template' => 'no-such-template']],
        ]]);
        $this->assertSame('standard', $unknown['blocks'][0]['data']['template']);
        $this->assertStringNotContainsString('data-template', $this->renderer->render($unknown));
    }

    public function test_sanitizer_and_renderer_share_the_code_language_list(): void
    {
        $known = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'code', 'data' => ['code' => 'echo 1;', 'language' => 'php']],
        ]]);
        $this->assertSame('php', $known['blocks'][0]['data']['language']);
        $this->assertStringContainsString('language-php', $this->renderer->render($known));

        $unknown = $this->sanitizer->sanitize(['blocks' => [
            ['type' => 'code', 'data' => ['code' => 'x', 'language' => 'brainfuck']],
        ]]);
        $this->assertSame('', $unknown['blocks'][0]['data']['language']);
    }

    public function test_every_renderer_social_icon_is_an_allowlisted_network(): void
    {
        // The renderer paints one SVG per network; each must be a network the
        // sanitizer also accepts, or an icon could reference a dropped value.
        $icons = (new ReflectionClass(BlockRenderer::class))->getConstant('SOCIAL_ICONS');
        $this->assertIsArray($icons);

        foreach (array_keys($icons) as $network) {
            $this->assertContains($network, BlockSchema::SOCIAL_NETWORKS);
        }
    }
}
