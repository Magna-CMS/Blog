{{-- One comment's byline and text; the <li> around it belongs to the caller. --}}
<div class="post-comment__meta">
    <span class="post-comment__author">{{ $comment['author'] }}</span>
    @if ($comment['date'])
        <time class="post-comment__date" datetime="{{ $comment['date']->toAtomString() }}">{{ $comment['date']->format('j M Y') }}</time>
    @endif
</div>
<div class="post-comment__text">
    @foreach ($comment['paragraphs'] as $lines)
        <p>
            @foreach ($lines as $line)
                {{ $line }}@if (! $loop->last)<br>@endif
            @endforeach
        </p>
    @endforeach
</div>
