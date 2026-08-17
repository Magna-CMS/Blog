<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Filament\Fields;

use Closure;
use Filament\Forms\Components\Field;
use MagnaCms\Blog\Editor\EditorToolRegistry;

/**
 * Filament form field hosting an Editor.js instance. The block document is
 * stored as structured JSON (array) in the bound model attribute. The list of
 * enabled tools comes from the EditorToolRegistry unless overridden per field.
 */
class EditorJsField extends Field
{
    protected string $view = 'blog::filament.forms.editor-js';

    /** @var array<int, string>|Closure|null */
    protected array|Closure|null $enabledTools = null;

    protected string|Closure|null $editorPlaceholder = null;

    /**
     * @param  array<int, string>|Closure  $tools
     */
    public function enabledTools(array|Closure $tools): static
    {
        $this->enabledTools = $tools;

        return $this;
    }

    public function editorPlaceholder(string|Closure|null $placeholder): static
    {
        $this->editorPlaceholder = $placeholder;

        return $this;
    }

    /**
     * @return list<string>
     */
    public function getEnabledTools(): array
    {
        $tools = $this->evaluate($this->enabledTools);

        if (is_array($tools)) {
            return array_values(array_filter($tools, 'is_string'));
        }

        return app(EditorToolRegistry::class)->enabled();
    }

    public function getEditorPlaceholder(): ?string
    {
        return $this->evaluate($this->editorPlaceholder);
    }
}
