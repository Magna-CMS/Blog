<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Filament\Support\Assets\Js;

/**
 * A Filament JS asset whose cache-busting version is derived from the file's
 * content hash instead of the (static, "dev-main") package version. This makes
 * every rebuild produce a new `?v=` query, so browsers never serve a stale
 * editor bundle after `npm run build` + `php artisan filament:assets`.
 */
class VersionedJs extends Js
{
    public function getVersion(): string
    {
        $path = $this->getPath();

        if (is_string($path) && is_file($path)) {
            $hash = @md5_file($path);
            if ($hash !== false) {
                return substr($hash, 0, 8);
            }
        }

        return parent::getVersion();
    }
}
