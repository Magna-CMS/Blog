<?php

declare(strict_types=1);

// The admin editor cannot function without its compiled bundle, and that bundle
// is a build artifact rather than a committed file. These checks make a
// forgotten `npm run build` fail the suite loudly — with an actionable message —
// instead of shipping an install whose editor silently never loads.

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
