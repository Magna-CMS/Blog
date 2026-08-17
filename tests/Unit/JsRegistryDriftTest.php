<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests\Unit;

use MagnaCms\Blog\Editor\BlockSchema;
use PHPUnit\Framework\TestCase;

/**
 * Guards the PHP↔JS allowlists that are, by necessity, expressed on both sides:
 * the server-side security schema (BlockSchema) and the client-side editor
 * registry (the JS tool modules). The JS modules carry rich presentation data
 * the security schema deliberately omits — labels, SVG paths, per-template flags
 * — so PHP cannot be the generation source without bloating a security-critical
 * class with UI metadata, and a build-time codegen step would couple the PHP and
 * JS toolchains for a handful of arrays. A drift TEST is therefore the correct
 * architecture for a Laravel/Magna plugin: one authoritative security source
 * (BlockSchema), predictable static JS consumption, and a failing test the moment
 * the two lists diverge.
 *
 * These four lists are the concrete "mirrors X in Y.js" pairs. BlockSchema::TYPES
 * is PHP-internal (shared by sanitizer + renderer, already guarded by
 * BlockSchemaTest); the JS tool registry is intentionally a superset consumer of
 * it (it also holds inline formats and server-injected tools), so it is not a
 * mirror and is not asserted here.
 */
final class JsRegistryDriftTest extends TestCase
{
    private const JS_DIR = __DIR__.'/../../resources/js/editor';

    public function test_paragraph_templates_match_between_php_and_js(): void
    {
        $jsKeys = $this->objectListKeys(
            self::JS_DIR.'/tools/paragraph.js',
            'PARAGRAPH_TEMPLATES',
        );

        $this->assertEqualSets(BlockSchema::PARAGRAPH_TEMPLATES, $jsKeys, 'paragraph.js');
    }

    public function test_faq_templates_match_between_php_and_js(): void
    {
        $jsKeys = $this->objectListKeys(
            self::JS_DIR.'/tools/faq.js',
            'FAQ_TEMPLATES',
        );

        $this->assertEqualSets(BlockSchema::FAQ_TEMPLATES, $jsKeys, 'faq.js');
    }

    public function test_social_networks_match_between_php_and_js(): void
    {
        $jsKeys = $this->objectMapKeys(
            self::JS_DIR.'/tools/social-icons.js',
            'SOCIAL_NETWORKS',
        );

        $this->assertEqualSets(BlockSchema::SOCIAL_NETWORKS, $jsKeys, 'social-icons.js');
    }

    public function test_code_languages_match_between_php_and_js(): void
    {
        // The editor offers languages through the `code` inspector's <select> in
        // index.js; its option keys (minus the empty "Plain" default) are the
        // client-side counterpart to BlockSchema::CODE_LANGUAGES.
        $jsKeys = $this->codeInspectorLanguages(self::JS_DIR.'/index.js');

        $this->assertEqualSets(BlockSchema::CODE_LANGUAGES, $jsKeys, 'index.js code inspector');
    }

    /**
     * Keys of an exported `const NAME = [ { key: '…' }, … ]` array-of-objects.
     *
     * @return list<string>
     */
    private function objectListKeys(string $file, string $constName): array
    {
        $body = $this->constBody($file, $constName, '[', ']');

        preg_match_all("/key:\s*'([^']+)'/", $body, $matches);

        return $matches[1];
    }

    /**
     * Top-level keys of an exported `const NAME = { key: { … }, … }` object map.
     *
     * @return list<string>
     */
    private function objectMapKeys(string $file, string $constName): array
    {
        $body = $this->constBody($file, $constName, '{', '}');

        // Each network is a top-level `name: { label: … }` entry.
        preg_match_all('/(\w+):\s*\{\s*label:/', $body, $matches);

        return $matches[1];
    }

    /**
     * Option keys of the `code` block inspector's language <select> in index.js,
     * excluding the empty ("Plain") default.
     *
     * @return list<string>
     */
    private function codeInspectorLanguages(string $file): array
    {
        $source = $this->read($file);

        // Isolate the code inspector's language select: `key: 'language' … options: { … }`.
        if (preg_match("/key:\s*'language'.*?options:\s*\{(.*?)\}/s", $source, $m) !== 1) {
            $this->fail('Could not locate the code language inspector in '.basename($file));
        }

        preg_match_all("/(?:'')|(\w+):/", $m[1], $matches);

        return array_values(array_filter($matches[1]));
    }

    /** Slice the literal body between the first delimiter pair after `const NAME =`. */
    private function constBody(string $file, string $constName, string $open, string $close): string
    {
        $source = $this->read($file);

        $start = strpos($source, $constName);
        if ($start === false) {
            $this->fail("Could not find {$constName} in ".basename($file));
        }

        $open = strpos($source, $open, $start);
        $end = strpos($source, $close.';', $open);
        if ($open === false || $end === false) {
            $this->fail("Could not delimit {$constName} in ".basename($file));
        }

        return substr($source, $open, $end - $open);
    }

    private function read(string $file): string
    {
        $this->assertFileExists($file);

        return (string) file_get_contents($file);
    }

    /**
     * @param  list<string>  $php
     * @param  list<string>  $js
     */
    private function assertEqualSets(array $php, array $js, string $jsLabel): void
    {
        sort($php);
        sort($js);

        $this->assertSame(
            $php,
            $js,
            "BlockSchema and {$jsLabel} have drifted. Missing in JS: ["
            .implode(', ', array_diff($php, $js)).']; extra in JS: ['
            .implode(', ', array_diff($js, $php)).'].',
        );
    }
}
