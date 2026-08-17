<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Enums;

enum PostVisibility: string
{
    case Public = 'public';
    case Private = 'private';
    case Password = 'password';

    public function label(): string
    {
        return (string) __('blog::enums.post_visibility.'.$this->value);
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
