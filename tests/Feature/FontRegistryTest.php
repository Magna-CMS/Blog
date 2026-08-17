<?php

declare(strict_types=1);

use Magna\Testing\PluginTestCase;
use MagnaCms\Blog\Editor\EditorJsSanitizer;
use MagnaCms\Blog\Settings\BlogSettings;
use MagnaCms\Blog\Support\BlockRenderer;
use MagnaCms\Blog\Support\FontRegistry;

uses(PluginTestCase::class);

beforeEach(function (): void {
    $this->enablePlugin('magna-cms/blog');

    $settings = BlogSettings::get();
    $settings->custom_fonts = [[
        'key' => 'u-brand',
        'label' => 'Brand Sans',
        'path' => 'blog/fonts/brand.woff2',
        'url' => '/storage/blog/fonts/brand.woff2',
        'format' => 'woff2',
    ]];
    $settings->save();
});

it('exposes an uploaded font through the registry', function (): void {
    expect(FontRegistry::keys())->toContain('u-brand')
        ->and(FontRegistry::stack('u-brand'))->toBe("'MagnaFont-u-brand', sans-serif");

    $forJs = collect(FontRegistry::forJs())->firstWhere('key', 'u-brand');
    expect($forJs['label'])->toBe('Brand Sans')
        ->and($forJs['url'])->toBe('/storage/blog/fonts/brand.woff2');

    expect(FontRegistry::customFontFaceCss())
        ->toContain("font-family:'MagnaFont-u-brand'")
        ->toContain("url('/storage/blog/fonts/brand.woff2') format('woff2')");
});

it('lets the sanitiser keep an uploaded font key and drops unknown ones', function (): void {
    $out = (new EditorJsSanitizer)->sanitize(['blocks' => [
        ['type' => 'buttons', 'data' => ['buttons' => [
            ['label' => 'A', 'url' => 'https://a.test', 'font' => 'u-brand'],
            ['label' => 'B', 'url' => 'https://b.test', 'font' => 'u-nope'],
        ]]],
    ]]);

    $buttons = $out['blocks'][0]['data']['buttons'];
    expect($buttons[0]['font'])->toBe('u-brand')
        ->and($buttons[1]['font'])->toBe('');
});

it('renders an uploaded font to its @font-face family', function (): void {
    $html = (new BlockRenderer)->render(['blocks' => [
        ['type' => 'buttons', 'data' => ['buttons' => [
            ['label' => 'A', 'url' => 'https://a.test', 'font' => 'u-brand'],
        ]]],
    ]]);

    expect($html)->toContain('MagnaFont-u-brand');
});
