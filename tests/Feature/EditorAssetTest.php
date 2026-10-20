<?php

declare(strict_types=1);

// The admin editor cannot function without its compiled bundle. It used to be a
// gitignored build artifact: these checks passed in every developer's checkout,
// while Composer — which installs a tag straight from git — delivered a package
// with no bundle at all, and every installed blog's editor showed a title and
// no body. The bundle is now committed; the last test keeps it that way.

$bundle = __DIR__.'/../../dist/blog-editor.js';

it('has a compiled editor bundle on disk', function () use ($bundle): void {
    expect(is_file($bundle))->toBeTrue(
        'Missing dist/blog-editor.js. Run `npm ci && npm run build` in the plugin before running the suite.',
    );
});

it('ships a non-trivial, well-formed editor bundle', function () use ($bundle): void {
    if (! is_file($bundle)) {
        $this->markTestSkipped('Bundle not built; covered by the existence test.');
    }

    $contents = (string) file_get_contents($bundle);

    expect(strlen($contents))->toBeGreaterThan(50_000)
        // The bundle publishes the editor's public extension registry; its
        // presence proves this is the real Editor.js build, not a stub.
        ->and($contents)->toContain('magnaBlog')
        // esbuild emits an IIFE for `format: 'iife'`.
        ->and($contents)->toContain('use strict');
});

it('commits the bundle, so a release installed from git carries it', function (): void {
    $root = dirname(__DIR__, 2);
    $tracked = [];
    $code = 0;
    exec('git -C '.escapeshellarg($root).' ls-files -- dist/blog-editor.js 2>&1', $tracked, $code);

    if ($code !== 0) {
        $this->markTestSkipped('Not a git checkout.');
    }

    expect($tracked)->toBe(['dist/blog-editor.js'],
        'dist/blog-editor.js is not tracked by git, so a Composer install would ship without the editor.',
    );
});
