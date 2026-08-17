<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Editor;

use Illuminate\Contracts\Config\Repository;

/**
 * Server-side source of truth for which Editor.js tools are enabled. Reads the
 * plugin config (which Blog settings may override) so the enabled set lives in
 * one place and is passed to the browser at render time.
 */
final class EditorToolRegistry
{
    public function __construct(private readonly Repository $config) {}

    /**
     * Enabled tool names, in toolbar order.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $tools = $this->config->get('blog.editor.tools', []);

        if (! is_array($tools)) {
            return [];
        }

        return array_values(array_filter($tools, 'is_string'));
    }
}
