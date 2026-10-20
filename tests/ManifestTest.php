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
        // The post page builds a FrontendPage with a data provider, which
        // core only accepts from 1.6.0 — an older core would fatal on boot.
        $this->assertTrue($manifest->isCompatibleWith('1.6.0'));
        $this->assertFalse($manifest->isCompatibleWith('1.5.1'));
        $this->assertFalse($manifest->isCompatibleWith('2.0.0'));
    }
}
