<?php

declare(strict_types=1);

use MagnaCms\Blog\Support\HtmlToEditorJs;

function convert(string $html): array
{
    return (new HtmlToEditorJs)->convert($html)['blocks'];
}

it('returns no blocks for empty input', function (): void {
    expect(convert(''))->toBe([])
        ->and(convert('   '))->toBe([]);
});

it('maps headings to header blocks with their level', function (): void {
    $blocks = convert('<h2>Title</h2><h4>Sub</h4>');

    expect($blocks[0])->toMatchArray(['type' => 'header', 'data' => ['text' => 'Title', 'level' => 2]])
        ->and($blocks[1]['data']['level'])->toBe(4);
});

it('maps paragraphs and keeps inline formatting', function (): void {
    $blocks = convert('<p>Hello <strong>world</strong></p>');

    expect($blocks[0]['type'])->toBe('paragraph')
        ->and($blocks[0]['data']['text'])->toContain('<strong>world</strong>');
});

it('drops empty paragraphs', function (): void {
    expect(convert('<p></p><p>   </p>'))->toBe([]);
});

it('maps ordered and unordered lists', function (): void {
    $ul = convert('<ul><li>a</li><li>b</li></ul>')[0];
    $ol = convert('<ol><li>one</li></ol>')[0];

    expect($ul)->toMatchArray(['type' => 'list', 'data' => ['style' => 'unordered', 'items' => ['a', 'b']]])
        ->and($ol['data']['style'])->toBe('ordered');
});

it('maps blockquote, pre and hr', function (): void {
    expect(convert('<blockquote>quote</blockquote>')[0]['type'])->toBe('quote')
        ->and(convert('<pre>code here</pre>')[0])->toMatchArray(['type' => 'code', 'data' => ['code' => 'code here', 'language' => '']])
        ->and(convert('<hr>')[0]['type'])->toBe('delimiter');
});

it('maps images, including a paragraph that wraps only an image', function (): void {
    $img = convert('<img src="https://x.test/a.png" alt="Alt text">')[0];
    $wrapped = convert('<p><img src="https://x.test/b.png" alt="B"></p>')[0];

    expect($img)->toMatchArray([
        'type' => 'image',
        'data' => ['url' => 'https://x.test/a.png', 'alt' => 'Alt text', 'caption' => 'Alt text'],
    ])->and($wrapped['type'])->toBe('image');
});

it('maps a figure with a caption to an image block', function (): void {
    $block = convert('<figure><img src="https://x.test/c.png" alt="C"><figcaption>A caption</figcaption></figure>')[0];

    expect($block['type'])->toBe('image')
        ->and($block['data']['url'])->toBe('https://x.test/c.png')
        ->and($block['data']['caption'])->toBe('A caption');
});

it('maps tables and detects headings', function (): void {
    $block = convert('<table><tr><th>H1</th><th>H2</th></tr><tr><td>a</td><td>b</td></tr></table>')[0];

    expect($block['type'])->toBe('table')
        ->and($block['data']['withHeadings'])->toBeTrue()
        ->and($block['data']['content'])->toBe([['H1', 'H2'], ['a', 'b']]);
});

it('strips Gutenberg block comments but keeps their markup', function (): void {
    $blocks = convert('<!-- wp:paragraph --><p>Gutenberg body</p><!-- /wp:paragraph -->');

    expect($blocks)->toHaveCount(1)
        ->and($blocks[0]['type'])->toBe('paragraph')
        ->and($blocks[0]['data']['text'])->toBe('Gutenberg body');
});

it('falls back to a raw block for unrecognised structural markup', function (): void {
    $block = convert('<div class="wp-custom"><span>x</span><p>nested</p></div>')[0];

    expect($block['type'])->toBe('raw')
        ->and($block['data']['html'])->toContain('wp-custom');
});
