<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Enums\MetaType;

/**
 * Extension seam for declared post-meta fields. Other plugins (e.g. a future
 * magna-seo) register their meta keys here — with a label, type and a public
 * flag — so the admin can surface them and the delivery API knows which keys are
 * safe to expose. Ad-hoc, unregistered meta is still allowed (WordPress-style);
 * registration only adds first-class handling and public exposure.
 *
 * Registered as a singleton so definitions accumulate for the process lifetime.
 */
class MetaRegistry
{
    /**
     * @var array<string, array{key: string, label: string, type: MetaType, public: bool}>
     */
    private array $definitions = [];

    /**
     * Declare a meta field. Re-declaring the same key overwrites the prior
     * definition, so the last registrant wins deterministically.
     */
    public function define(string $key, string $label, MetaType $type = MetaType::String, bool $public = false): void
    {
        $this->definitions[$key] = [
            'key' => $key,
            'label' => $label,
            'type' => $type,
            'public' => $public,
        ];
    }

    /**
     * @return array<string, array{key: string, label: string, type: MetaType, public: bool}>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    /**
     * @return array{key: string, label: string, type: MetaType, public: bool}|null
     */
    public function get(string $key): ?array
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * Keys marked public at registration. The delivery layer unions this with the
     * admin-managed BlogSettings::$public_meta allowlist.
     *
     * @return list<string>
     */
    public function publicKeys(): array
    {
        return array_keys(array_filter($this->definitions, fn (array $def): bool => $def['public']));
    }
}
