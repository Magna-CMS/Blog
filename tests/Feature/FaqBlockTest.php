<?php

declare(strict_types=1);

/**
 * The blog's FAQ, as a page-builder block.
 *
 * Twenty visual templates and FAQPage JSON-LD, which the core `faq` block
 * has neither of. Rendered by the blog's own BlockRenderer so the page and
 * the post editor cannot drift into two different FAQs.
 */

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Magna\Blocks\BlockNode;
use Magna\Blocks\BlockRegistry;
use Magna\Blocks\Resolution\BlockDataResolver;
use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Editor\BlockSchema;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');
});

function resolveFaq(array $data): string
{
    $payload = app(BlockDataResolver::class)->viewPayload(BlockNode::fromArray([
        'id' => 'blk-faq',
        'block' => 'blog-faq',
        'settings' => [],
        'data' => $data,
    ]));

    return $payload['_resolved']['html'] ?? '';
}

/** @return array<int, array<string, string>> */
function faqItems(): array
{
    return [['question' => 'Do you ship?', 'answer' => 'Yes, worldwide.']];
}

it('offers every template the editor has, without a second list to keep in step', function (): void {
    $definition = app(BlockRegistry::class)->get('blog-faq');

    expect($definition)->not->toBeNull()
        ->and($definition->label)->toBe('Styled FAQ')
        ->and($definition->category)->toBe('blog');

    $options = $definition->field('template')?->options ?? [];

    expect(array_keys($options))->toBe(BlockSchema::FAQ_TEMPLATES)
        ->and($options)->toHaveCount(20);
});

it('has a view the resolution chain can find', function (): void {
    expect(View::exists('magna::blocks.blog-faq'))->toBeTrue();
});

it('renders the accordion with the template it was given', function (): void {
    $html = resolveFaq(['items' => faqItems(), 'template' => 'neon']);

    expect($html)->toContain('data-template="neon"')
        ->and($html)->toContain('<details')
        ->and($html)->toContain('Do you ship?')
        ->and($html)->toContain('Yes, worldwide.');
});

it('refuses a template it does not publish', function (): void {
    $html = resolveFaq(['items' => faqItems(), 'template' => '" onload="evil()']);

    expect($html)->toContain('data-template="card"')
        ->and($html)->not->toContain('onload');
});

/*
 * The reason the resolver exists.
 *
 * BlockRenderer emits text fields as-is — it is documented as taking an
 * already-sanitised document, which holds for a post body because that
 * passes EditorJsSanitizer on save. Page-builder data is written through
 * JSON-Patch and never goes near that sanitiser, so without the resolver
 * cleaning it here this block would be stored XSS reachable by anyone who
 * can edit a page.
 */
it('sanitises an answer written through the page builder', function (): void {
    $html = resolveFaq([
        'items' => [[
            'question' => 'Is it safe?',
            'answer' => 'Yes <script>alert(1)</script><img src=x onerror=alert(2)> really.',
        ]],
        // Structured data OFF, so the only <script> that could appear is an
        // injected one. With it on, the block's own legitimate
        // application/ld+json tag makes this assertion meaningless.
        'schema' => 'no',
    ]);

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('alert(1)')
        // The legitimate text around it survives — this is a sanitiser, not
        // a blunt strip.
        ->and($html)->toContain('Yes')
        ->and($html)->toContain('really.');
});

it('keeps the inline formatting an answer is allowed to have', function (): void {
    $html = resolveFaq([
        'items' => [['question' => 'Q', 'answer' => 'Plans start at <b>ten</b> a month.']],
    ]);

    expect($html)->toContain('<b>ten</b>');
});

it('emits FAQPage structured data, and leaves it out when asked', function (): void {
    $with = resolveFaq(['items' => faqItems(), 'schema' => 'yes']);
    expect($with)->toContain('application/ld+json')
        ->and($with)->toContain('FAQPage');

    $without = resolveFaq(['items' => faqItems(), 'schema' => 'no']);
    expect($without)->not->toContain('FAQPage');
});

it('opens the first answer only when told to', function (): void {
    expect(resolveFaq(['items' => faqItems(), 'open_first' => 'yes']))->toContain('<details open>');
    expect(resolveFaq(['items' => faqItems()]))->not->toContain('<details open>');
});

it('renders nothing at all until it has a question', function (): void {
    expect(resolveFaq(['items' => [], 'template' => 'neon']))->toBe('');
});

it('fails closed when the resolve step did not run', function (): void {
    // A view that fell back to raw block data would undo the sanitising
    // above, so an absent `_resolved` must render nothing instead.
    $html = Blade::render(
        (string) file_get_contents(__DIR__.'/../../resources/block-views/blocks/blog-faq.blade.php'),
        ['block' => ['data' => ['items' => [['question' => 'Q', 'answer' => '<script>x</script>']]]]],
    );

    expect(trim($html))->toBe('');
});

it('links its stylesheet once, however many FAQs a page holds', function (): void {
    // The template rules name the editor and the page in one selector, so
    // there is a single stylesheet; a page with several FAQs must not link
    // it several times.
    $html = Blade::render(
        '@foreach([1,2,3] as $i)'
        .(string) file_get_contents(__DIR__.'/../../resources/block-views/blocks/blog-faq.blade.php')
        .'@endforeach',
        ['block' => ['data' => [], '_resolved' => ['html' => '<div class="faq"></div>']]],
    );

    expect(substr_count($html, 'blog-editor.css'))->toBe(1);
});
