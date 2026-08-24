{{--
    Block: blog-posts — the blog's own posts, on a built page.

    Items arrive under the conventional data-source keys
    (title/url/description/date/image) and everything is escaped: a source
    returns presentation STRINGS, and even one that misbehaved could not
    inject markup through this view.
--}}
@php
    $items = $block['_resolved']['items'] ?? [];
    $heading = trim((string) ($block['data']['heading'] ?? ''));
@endphp

<div class="magna-block magna-block--blog-posts magna-blog-posts">
    @if($heading !== '')
        <h2 class="magna-blog-posts__heading">{{ $heading }}</h2>
    @endif

    @if($items === [])
        {{-- No posts yet renders a gap, not a broken box. --}}
    @else
        <ul class="magna-blog-posts__items">
            @foreach($items as $item)
                <li class="magna-blog-posts__item">
                    @if(!empty($item['image']))
                        <img class="magna-blog-posts__image"
                             src="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['image']) }}"
                             alt="" loading="lazy">
                    @endif

                    <div class="magna-blog-posts__body">
                        @if(!empty($item['url']))
                            <a class="magna-blog-posts__title"
                               href="{{ \Magna\Blocks\Support\SafeUrl::sanitize($item['url']) }}">
                                {{ $item['title'] ?? 'Untitled' }}
                            </a>
                        @else
                            <span class="magna-blog-posts__title">{{ $item['title'] ?? 'Untitled' }}</span>
                        @endif

                        @if(!empty($item['description']))
                            <p class="magna-blog-posts__description">{{ $item['description'] }}</p>
                        @endif

                        @if(!empty($item['date']))
                            <time class="magna-blog-posts__date">{{ $item['date'] }}</time>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
