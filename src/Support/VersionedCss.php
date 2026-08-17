<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use Filament\Support\Assets\Css;

/**
 * A Filament CSS asset versioned by content hash (see VersionedJs) so a rebuilt
 * or edited stylesheet busts the browser cache without a manual hard refresh.
 */
class VersionedCss extends Css
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
