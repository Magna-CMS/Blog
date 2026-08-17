<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Enums;

use Illuminate\Support\Carbon;

/**
 * The declared type of a post-meta value. Governs how the JSON-stored value is
 * coerced back to a PHP value on read and how it is validated on write, so a
 * frontend receives a real int/bool/date rather than a stringly-typed blob.
 */
enum MetaType: string
{
    case String = 'string';
    case Integer = 'integer';
    case Boolean = 'boolean';
    case Json = 'json';
    case Date = 'date';

    public function label(): string
    {
        return (string) __('blog::enums.meta_type.'.$this->value);
    }

    /**
     * Coerce a raw (JSON-decoded) stored value into its typed PHP representation.
     */
    public function coerce(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($this) {
            self::String => is_scalar($value) ? (string) $value : '',
            self::Integer => is_numeric($value) ? (int) $value : 0,
            self::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
            self::Json => $value,
            self::Date => $this->coerceDate($value),
        };
    }

    private function coerceDate(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Options map for Filament selects: value => label.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
