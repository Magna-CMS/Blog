{{--
    The comment form — the main one ($parentId null) or a reply under a
    comment. Safe on a cached page: the CSRF field is filled with the
    visitor's own token by <x-magna::form-token/>, and _ts is a signed render
    stamp that stays valid however old the cached copy is. Name and email are
    asked of guests only; a signed-in commenter is their account.
--}}
@php
    $formId = 'post-comment-form-'.($parentId ?? 'new');
    $old = ($comments['old']['parent_id'] ?? '') === (string) ($parentId ?? '') ? $comments['old'] : ['author_name' => '', 'author_email' => '', 'body' => ''];
    $showErrors = ($comments['old']['parent_id'] ?? '') === (string) ($parentId ?? '') && $comments['errors'] !== [];
@endphp
<form class="post-comments__form" method="post" action="{{ $comments['action'] }}" id="{{ $formId }}">
    <x-magna::form-token/>
    <input type="hidden" name="_ts" value="{{ $comments['timestamp'] }}">
    <input type="hidden" name="parent_id" value="{{ $parentId ?? '' }}">

    @if ($showErrors)
        <ul class="post-comments__errors" role="alert">
            @foreach ($comments['errors'] as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @unless ($comments['signedIn'])
        <p class="post-comments__field">
            <label for="{{ $formId }}-name">{{ __('Name') }}</label>
            <input id="{{ $formId }}-name" type="text" name="author_name" maxlength="120" required autocomplete="name" value="{{ $old['author_name'] }}">
        </p>
        <p class="post-comments__field">
            <label for="{{ $formId }}-email">{{ __('Email (never shown)') }}</label>
            <input id="{{ $formId }}-email" type="email" name="author_email" maxlength="190" required autocomplete="email" value="{{ $old['author_email'] }}">
        </p>
    @endunless

    <p class="post-comments__field">
        <label for="{{ $formId }}-body">{{ $parentId === null ? __('Comment') : __('Reply') }}</label>
        <textarea id="{{ $formId }}-body" name="body" rows="5" maxlength="5000" required>{{ $old['body'] }}</textarea>
    </p>

    {{-- Honeypot: labelled for screen readers, out of sight and out of the tab order. --}}
    <p class="post-comments__field" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;overflow:hidden">
        <label for="{{ $formId }}-hp">{{ __('Leave this field empty') }}</label>
        <input id="{{ $formId }}-hp" type="text" name="{{ $comments['honeypot'] }}" value="" tabindex="-1" autocomplete="off">
    </p>

    @if ($comments['captchaView'])
        @include($comments['captchaView'], ['surface' => $comments['captchaSurface']])
    @endif

    <button type="submit" class="post-comments__submit">{{ $parentId === null ? __('Post comment') : __('Post reply') }}</button>
</form>
