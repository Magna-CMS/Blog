{{--
    A published post, in the site layout's main slot (Magna Pages wraps it in
    .magna-frontend-page--content). Markup only: PostPageProvider resolves
    everything shown here, so this view never queries.

    The class hooks are a contract with themes, which style this page with
    CSS because they cannot override the view: <article> directly in the
    wrapper; .post-title, .post-meta (.who, .dot), .featured and .post-body
    as in the admin preview; .post-nav directly inside the article; the
    comments section (partials/comments) as a SIBLING after </article>, so
    a theme's `article > nav` rule never reaches it.

    $bodyHtml is BlockRenderer output: content sanitised by EditorJsSanitizer
    on save, every attribute escaped on render.
--}}
@if ($stylesheet)
    <link rel="stylesheet" href="{{ $stylesheet }}">
@endif
<article class="magna-blog-post">
    <header>
        <h1 class="post-title">{{ $post->title }}</h1>
        <div class="post-meta">
            @if ($authorName)
                <span class="who">{{ $authorName }}</span>
                <span class="dot" aria-hidden="true">·</span>
            @endif
            @if ($publishedAt)
                <time datetime="{{ $publishedAt->toAtomString() }}">{{ $publishedAt->format('j M Y') }}</time>
                <span class="dot" aria-hidden="true">·</span>
            @endif
            <span class="reading-time">{{ $readingTime }} min read</span>
        </div>
    </header>

    @if ($featuredImage)
        <img class="featured" src="{{ $featuredImage }}" alt="">
    @endif

    <div class="post-body">{!! $bodyHtml !!}</div>

    @if ($tags !== [])
        <ul class="post-tags">
            @foreach ($tags as $tag)
                <li>{{ $tag }}</li>
            @endforeach
        </ul>
    @endif

    @if ($previous || $next)
        <nav class="post-nav" aria-label="More posts">
            @if ($previous)
                <a class="post-nav__prev" href="{{ $previous['url'] }}" rel="prev">← {{ $previous['title'] }}</a>
            @endif
            @if ($next)
                <a class="post-nav__next" href="{{ $next['url'] }}" rel="next">{{ $next['title'] }} →</a>
            @endif
        </nav>
    @endif
</article>
@if (($comments['visible'] ?? false) === true)
    @include('blog::frontend.partials.comments', ['comments' => $comments])
@endif
