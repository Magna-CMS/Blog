<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Tests;

use Magna\Plugins\Manifest;
use PHPUnit\Framework\TestCase;

/**
 * Validates this plugin's magna.json against the Magna Plugin SDK. Runs
 * standalone (composer install && vendor/bin/phpunit) — no CMS required.
 */
final class ManifestTest extends TestCase
{
    public function test_manifest_is_valid(): void
    {
        $manifest = Manifest::loadFromFile(__DIR__.'/../magna.json');

        $this->assertNotSame('', $manifest->name);
        $this->assertNotSame('', $manifest->entryClass);
        // Pagination, locale URLs, page caching, redirects and the comment
        // form build on core's 1.6.2 frontend seams (RenderContext,
        // CacheCollector, LocaleUrls, the form-token endpoint) — an older
        // core would fatal on boot or render.
        $this->assertSame('1.2.0', $manifest->version);
        $this->assertTrue($manifest->isCompatibleWith('1.6.2'));
        $this->assertTrue($manifest->isCompatibleWith('1.9.0'));
        $this->assertFalse($manifest->isCompatibleWith('1.6.1'));
        $this->assertFalse($manifest->isCompatibleWith('2.0.0'));
    }
}
