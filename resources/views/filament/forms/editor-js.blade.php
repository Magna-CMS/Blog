<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $editorId = 'magna-blog-editor-'.$getId();
    @endphp

    <div
        wire:ignore
        x-data="magnaBlogEditor({
            holderId: @js($editorId),
            statePath: @js($getStatePath()),
            tools: @js($getEnabledTools()),
            readOnly: @js($isDisabled()),
            placeholder: @js($getEditorPlaceholder()),
            linkEndpoint: @js(\Illuminate\Support\Facades\Route::has('blog.editor.link-preview') ? route('blog.editor.link-preview') : null),
            attachesEndpoint: @js(\Illuminate\Support\Facades\Route::has('blog.editor.attaches') ? route('blog.editor.attaches') : null),
            rssEndpoint: @js(\Illuminate\Support\Facades\Route::has('blog.editor.rss') ? route('blog.editor.rss') : null),
            csrfToken: @js(csrf_token()),
            fonts: @js(\MagnaCms\Blog\Support\FontRegistry::forJs()),
            googleFontsHref: @js(\MagnaCms\Blog\Support\FontRegistry::googleStylesheetUrl()),
        })"
    >
        <div id="{{ $editorId }}" class="magna-blog-editor"></div>
    </div>
</x-dynamic-component>
