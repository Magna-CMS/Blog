{{--
    The post page's comments section. A FROZEN markup contract themes style
    with CSS (pinned by PostCommentsMarkupTest) — change a class and every
    theme that styled it breaks:

      section.post-comments#comments            sibling after </article>
        h2.post-comments__title
        p.post-comments__notice(--pending|--approved)[role=status]
        ol.post-comments__list > li.post-comment#comment-{id}
          .post-comment__meta > .post-comment__author, time.post-comment__date
          .post-comment__text
          details.post-comment__reply > form.post-comments__form
          ol.post-comment__replies > li.post-comment.post-comment--reply
        p.post-comments__closed | p.post-comments__login | form.post-comments__form

    $comments comes from PostCommentThread; everything a commenter wrote is
    escaped here, and arrives as plain text in the first place.
--}}
<section class="post-comments" id="comments" aria-labelledby="post-comments-title">
    <h2 class="post-comments__title" id="post-comments-title">
        {{ $comments['count'] > 0 ? trans_choice(':count comment|:count comments', $comments['count']) : __('Comments') }}
    </h2>

    @if ($comments['notice'] === 'pending')
        <p class="post-comments__notice post-comments__notice--pending" role="status">{{ __('Thanks! Your comment is awaiting moderation.') }}</p>
    @elseif ($comments['notice'] === 'approved')
        <p class="post-comments__notice post-comments__notice--approved" role="status">{{ __('Thanks! Your comment has been published.') }}</p>
    @endif

    @if ($comments['items'] !== [])
        <ol class="post-comments__list">
            @foreach ($comments['items'] as $comment)
                <li class="post-comment" id="comment-{{ $comment['id'] }}">
                    @include('blog::frontend.partials.comment', ['comment' => $comment])

                    @if ($comments['open'] && ! $comments['loginRequired'])
                        <details class="post-comment__reply">
                            <summary>{{ __('Reply') }}</summary>
                            @include('blog::frontend.partials.comment-form', ['comments' => $comments, 'parentId' => $comment['id']])
                        </details>
                    @endif

                    @if ($comment['replies'] !== [])
                        <ol class="post-comment__replies">
                            @foreach ($comment['replies'] as $reply)
                                <li class="post-comment post-comment--reply" id="comment-{{ $reply['id'] }}">
                                    @include('blog::frontend.partials.comment', ['comment' => $reply])
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </li>
            @endforeach
        </ol>
    @endif

    @if (! $comments['open'])
        <p class="post-comments__closed">{{ __('Comments are closed.') }}</p>
    @elseif ($comments['loginRequired'])
        <p class="post-comments__login">
            <a href="{{ $comments['signInUrl'] }}" rel="nofollow">{{ __('Sign in') }}</a> {{ __('to leave a comment.') }}
        </p>
    @else
        @include('blog::frontend.partials.comment-form', ['comments' => $comments, 'parentId' => null])
    @endif
</section>
