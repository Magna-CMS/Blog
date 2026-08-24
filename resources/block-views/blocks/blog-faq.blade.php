{{--
    Block: blog-faq — the blog's FAQ, on a built page.

    The HTML is emitted RAW, and that is correct here and only here: it was
    produced by BlockRenderer from a document FaqBlockResolver had already
    put through EditorJsSanitizer. Escaping it would print the accordion's
    own markup on the page. If the resolver is ever bypassed, `_resolved` is
    absent and this renders nothing rather than falling back to raw block
    data — a missing resolve step must fail closed.

    The stylesheet is linked rather than inlined: it is one shared file (the
    template rules name the editor and the page in the same selector), so a
    <link> lets the browser cache it across pages instead of pushing ~56KB
    into every cached page body. Once per render — a page with five FAQs
    needs one copy.
--}}
@php
    $html = $block['_resolved']['html'] ?? '';
@endphp
@if($html !== '')
    @once
        <link rel="stylesheet" href="{{ asset('css/magna-cms/blog/blog-editor.css') }}">
    @endonce

    {!! $html !!}
@endif
