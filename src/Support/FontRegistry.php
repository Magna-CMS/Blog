<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Settings\BlogSettings;
use Throwable;

/**
 * Single source of truth for the global font list, shared by the editor (JS),
 * the sanitiser (allowlist), the renderer (key → CSS stack) and the preview
 * (@font-face / Google links).
 *
 * A font is referenced everywhere by a short KEY (e.g. "poppins"), never by a
 * raw font-family string, so a stored value can only ever be one of these known
 * keys — closing any CSS-injection surface. Delivery is hybrid: a couple of
 * self-hosted faces (Inter/system), the popular free set via Google Fonts, and
 * user-uploaded faces (served as @font-face) added through Blog Settings.
 */
final class FontRegistry
{
    /**
     * Curated built-in fonts. `google` is the Google Fonts css2 `family=` value
     * (null for self-hosted/system faces).
     *
     * @return array<string, array{label: string, stack: string, google: ?string, source: string}>
     */
    public static function builtins(): array
    {
        return [
            'inter' => ['label' => 'Inter',           'stack' => "'Inter', ui-sans-serif, system-ui, sans-serif",     'google' => null, 'source' => 'self'],
            'system' => ['label' => 'System UI',       'stack' => 'ui-sans-serif, system-ui, -apple-system, sans-serif', 'google' => null, 'source' => 'system'],
            'poppins' => ['label' => 'Poppins',         'stack' => "'Poppins', sans-serif",       'google' => 'Poppins:wght@400;500;600;700',      'source' => 'google'],
            'roboto' => ['label' => 'Roboto',          'stack' => "'Roboto', sans-serif",        'google' => 'Roboto:wght@400;500;700',           'source' => 'google'],
            'montserrat' => ['label' => 'Montserrat',      'stack' => "'Montserrat', sans-serif",    'google' => 'Montserrat:wght@400;500;600;700',   'source' => 'google'],
            'opensans' => ['label' => 'Open Sans',       'stack' => "'Open Sans', sans-serif",     'google' => 'Open+Sans:wght@400;600;700',        'source' => 'google'],
            'lato' => ['label' => 'Lato',            'stack' => "'Lato', sans-serif",          'google' => 'Lato:wght@400;700',                 'source' => 'google'],
            'nunito' => ['label' => 'Nunito',          'stack' => "'Nunito', sans-serif",        'google' => 'Nunito:wght@400;600;700',           'source' => 'google'],
            'worksans' => ['label' => 'Work Sans',       'stack' => "'Work Sans', sans-serif",     'google' => 'Work+Sans:wght@400;500;600',        'source' => 'google'],
            'merriweather' => ['label' => 'Merriweather',  'stack' => "'Merriweather', serif",       'google' => 'Merriweather:wght@400;700',         'source' => 'google'],
            'lora' => ['label' => 'Lora',            'stack' => "'Lora', serif",               'google' => 'Lora:wght@400;500;600',             'source' => 'google'],
            'playfair' => ['label' => 'Playfair Display', 'stack' => "'Playfair Display', serif",  'google' => 'Playfair+Display:wght@400;600;700', 'source' => 'google'],
            'ptserif' => ['label' => 'PT Serif',        'stack' => "'PT Serif', serif",           'google' => 'PT+Serif:wght@400;700',             'source' => 'google'],
            'oswald' => ['label' => 'Oswald',          'stack' => "'Oswald', sans-serif",        'google' => 'Oswald:wght@400;500;600;700',       'source' => 'google'],
            'robotomono' => ['label' => 'Roboto Mono',     'stack' => "'Roboto Mono', monospace",    'google' => 'Roboto+Mono:wght@400;500',          'source' => 'google'],
        ];
    }

    /**
     * User-uploaded fonts from Blog Settings, keyed like the builtins.
     *
     * @return array<string, array{label: string, stack: string, google: ?string, source: string, url: string, format: string}>
     */
    public static function custom(): array
    {
        $out = [];

        try {
            $fonts = BlogSettings::get()->custom_fonts;
        } catch (Throwable) {
            return [];
        }

        foreach ($fonts as $font) {
            $key = is_string($font['key'] ?? null) ? $font['key'] : '';
            $label = is_string($font['label'] ?? null) ? $font['label'] : '';
            $url = is_string($font['url'] ?? null) ? $font['url'] : '';
            if ($key === '' || $label === '' || $url === '') {
                continue;
            }
            $family = 'MagnaFont-'.$key;
            $out[$key] = [
                'label' => $label,
                'stack' => "'".$family."', sans-serif",
                'google' => null,
                'source' => 'custom',
                'url' => $url,
                'format' => is_string($font['format'] ?? null) ? $font['format'] : 'woff2',
            ];
        }

        return $out;
    }

    /**
     * All fonts (builtins + custom).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::builtins() + self::custom();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    public static function stack(string $key): ?string
    {
        $font = self::all()[$key] ?? null;

        return is_array($font) && is_string($font['stack'] ?? null) ? $font['stack'] : null;
    }

    /**
     * Compact list for the editor's font picker: [{key,label,stack,google,url,format}].
     *
     * @return list<array<string, mixed>>
     */
    public static function forJs(): array
    {
        $out = [['key' => '', 'label' => 'Default', 'stack' => '', 'google' => null]];
        foreach (self::all() as $key => $font) {
            $out[] = [
                'key' => $key,
                'label' => $font['label'],
                'stack' => $font['stack'],
                'google' => $font['google'] ?? null,
                'url' => $font['url'] ?? null,
                'format' => $font['format'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * The Google Fonts css2 stylesheet URL covering every google-hosted font, or
     * null if none are in use. Loaded in the admin editor + preview so any chosen
     * font renders.
     */
    public static function googleStylesheetUrl(): ?string
    {
        $families = [];
        foreach (self::all() as $font) {
            if (is_string($font['google'] ?? null) && $font['google'] !== '') {
                $families[] = 'family='.$font['google'];
            }
        }

        return $families === []
            ? null
            : 'https://fonts.googleapis.com/css2?'.implode('&', $families).'&display=swap';
    }

    /** @font-face CSS for every uploaded custom font (empty string if none). */
    public static function customFontFaceCss(): string
    {
        $css = '';
        foreach (self::custom() as $key => $font) {
            $family = 'MagnaFont-'.$key;
            $css .= "@font-face{font-family:'".$family."';font-display:swap;src:url('".$font['url']."') format('".$font['format']."');}";
        }

        return $css;
    }
}
