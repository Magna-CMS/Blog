<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Preview — {{ $post->title }}</title>
    {{-- Apply the saved theme before paint to avoid a flash. Light is the default. --}}
    <script>
        (function () {
            try {
                if (localStorage.getItem('magna-preview-theme') === 'dark') {
                    document.documentElement.classList.add('dark');
                }
            } catch (e) {}
        })();
    </script>
    @if ($googleFontsHref = \MagnaCms\Blog\Support\FontRegistry::googleStylesheetUrl())
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="{{ $googleFontsHref }}">
    @endif
    @php $editorCss = public_path('css/magna-cms/blog/blog-editor.css'); @endphp
    @if (is_file($editorCss))
        <link rel="stylesheet" href="{{ asset('css/magna-cms/blog/blog-editor.css') }}?v={{ substr((string) md5_file($editorCss), 0, 8) }}">
    @endif
    <style>
        /* Self-hosted Inter — the same face Magna uses for its logo text. */
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter/inter-var.woff2') format('woff2');
        }
        /* Uploaded custom fonts. */
        {!! \MagnaCms\Blog\Support\FontRegistry::customFontFaceCss() !!}
        :root {
            --brand: #6366f1;
            --brand-2: #8b5cf6;
            --bg: #ffffff;
            --bg-soft: #f8fafc;
            --panel: #ffffff;
            --text: #0f172a;
            --muted: #64748b;
            --border: #e7e9ee;
            --shadow: 0 1px 3px rgba(15,23,42,.06), 0 8px 30px rgba(15,23,42,.05);
        }
        html.dark {
            --bg: #0b1120;
            --bg-soft: #0f172a;
            --panel: #131c2e;
            --text: #e6eaf2;
            --muted: #93a1b8;
            --border: #24304a;
            --shadow: 0 1px 3px rgba(0,0,0,.4), 0 10px 34px rgba(0,0,0,.45);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg-soft); color: var(--text);
            font-family: 'Inter', ui-sans-serif, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;
            -webkit-font-smoothing: antialiased; line-height: 1.7;
            transition: background .25s ease, color .25s ease;
        }

        /* ── Header ─────────────────────────────────────────── */
        .site-header {
            position: sticky; top: 0; z-index: 20;
            display: flex; align-items: center; justify-content: space-between;
            padding: .75rem 1.25rem; background: color-mix(in srgb, var(--bg) 82%, transparent);
            backdrop-filter: saturate(1.4) blur(10px);
            border-bottom: 1px solid var(--border);
        }
        .brand { display: flex; align-items: center; gap: .625rem; text-decoration: none; color: var(--text); }
        .brand__logo { width: 32px; height: 32px; display: block; flex-shrink: 0; }
        .brand__name { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; font-weight: 700;
            letter-spacing: -.025em; font-size: 1.0625rem; line-height: 1.15; }
        .brand__name small { display: block; font-weight: 500; font-size: .68rem; letter-spacing: .02em;
            color: var(--muted); margin-top: 1px; }
        /* Animated logo stroke — the real Magna brand mark. */
        .mgb-line {
            stroke-dasharray: 28 56; stroke-dashoffset: 84;
            animation: mgb-chase 2s cubic-bezier(0.25, 1, 0.5, 1) infinite;
        }
        @keyframes mgb-chase {
            0%   { stroke-dasharray: 15 69; stroke-dashoffset: 84; }
            40%  { stroke-dasharray: 38 46; }
            100% { stroke-dasharray: 15 69; stroke-dashoffset: 0; }
        }

        .theme-toggle {
            width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--border);
            background: var(--panel); color: var(--text); cursor: pointer; display: grid; place-items: center;
            transition: background .2s, border-color .2s, transform .1s;
        }
        .theme-toggle:hover { border-color: var(--brand); }
        .theme-toggle:active { transform: scale(.94); }
        .theme-toggle svg { width: 18px; height: 18px; }
        .theme-toggle .i-moon { display: block; }
        .theme-toggle .i-sun { display: none; }
        html.dark .theme-toggle .i-moon { display: none; }
        html.dark .theme-toggle .i-sun { display: block; }

        /* ── Status ribbon ──────────────────────────────────── */
        .preview-note {
            max-width: 760px; margin: 1.5rem auto 0; padding: 0 1.25rem;
            display: flex; align-items: center; gap: .6rem; font-size: .8rem; color: var(--muted);
        }
        .status-pill {
            display: inline-flex; align-items: center; gap: .35rem; padding: .2rem .6rem;
            border-radius: 999px; font-weight: 700; font-size: .72rem; letter-spacing: .02em;
            background: color-mix(in srgb, var(--brand) 14%, transparent); color: var(--brand);
        }
        .status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: currentColor; }

        /* ── Article ────────────────────────────────────────── */
        article {
            max-width: 760px; margin: 1rem auto 5rem; padding: 2rem 2.25rem 3rem;
            background: var(--panel); border: 1px solid var(--border); border-radius: 16px; box-shadow: var(--shadow);
        }
        h1.post-title { font-size: 2.6rem; font-weight: 800; letter-spacing: -.03em; line-height: 1.12; margin: .2rem 0 1rem; }
        .post-meta { display: flex; align-items: center; gap: .7rem; color: var(--muted);
            font-size: .88rem; margin-bottom: 1.75rem; padding-bottom: 1.5rem; border-bottom: 1px solid var(--border); }
        .avatar { width: 38px; height: 38px; border-radius: 50%; display: grid; place-items: center;
            font-weight: 700; color: #fff; background: linear-gradient(135deg, var(--brand), var(--brand-2)); flex-shrink: 0; }
        .post-meta .who { font-weight: 600; color: var(--text); }
        .post-meta .dot { opacity: .5; }

        article img.featured { width: 100%; border-radius: 12px; margin-bottom: 1.5rem; }
        article > figure img, article img { max-width: 100%; border-radius: 10px; }

        /* Uniform vertical rhythm for EVERY top-level block, whatever its type
           (buttons, columns, callouts, embeds… not only p/ul/ol). */
        .post-body > * { margin: 1.2rem 0; }
        .post-body > *:first-child { margin-top: 0; }
        .post-body > *:last-child { margin-bottom: 0; }
        .post-body > h2, .post-body > h3, .post-body > h4 { margin-top: 2rem; }
        /* Same rhythm for blocks stacked inside a column. */
        .columns { display: grid; grid-template-columns: repeat(var(--cols, 2), 1fr); gap: 1.25rem; align-items: stretch; }
        .is-vtop .columns { align-items: start; }
        .is-vcenter .columns { align-items: center; }
        .ratio-2-1 .columns { grid-template-columns: 2fr 1fr; }
        .ratio-1-2 .columns { grid-template-columns: 1fr 2fr; }
        .ratio-3-1 .columns { grid-template-columns: 3fr 1fr; }
        .ratio-1-3 .columns { grid-template-columns: 1fr 3fr; }
        @media (max-width: 640px) { .columns, [class*="ratio-"] .columns { grid-template-columns: 1fr; } }
        .columns > div > * { margin: .9rem 0; }
        .columns > div > *:first-child { margin-top: 0; }
        .columns > div > *:last-child { margin-bottom: 0; }

        h2, h3, h4 { line-height: 1.3; margin: 2rem 0 .7rem; letter-spacing: -.01em; }
        h2 { font-size: 1.7rem; } h3 { font-size: 1.35rem; } h4 { font-size: 1.12rem; }
        a { color: var(--brand); }
        figcaption { text-align: center; color: var(--muted); font-size: .82rem; margin-top: .4rem; }

        /* Paragraph templates are styled by the linked editor stylesheet (below). */
        .magna-blog-p { margin: 0; }
        .magna-blog-p__text > p { margin: 0; }
        /* …but a templated paragraph is still a top-level block, so it must keep
           the same vertical rhythm as every other block. The reset above (equal
           specificity, declared later) would otherwise cancel `.post-body > *`,
           collapsing the gap between consecutive template paragraphs. */
        .post-body > .magna-blog-p { margin: 1.2rem 0; }
        .post-body > .magna-blog-p:first-child { margin-top: 0; }
        .post-body > .magna-blog-p:last-child { margin-bottom: 0; }

        blockquote { border-left: 4px solid var(--brand); padding: .2rem 0 .2rem 1.1rem; color: var(--muted); font-style: italic; }
        blockquote.pullquote { font-size: 1.4rem; font-weight: 600; font-style: normal; color: var(--text); }
        blockquote cite { display: block; margin-top: .5rem; font-size: .82rem; font-style: normal; }

        pre { background: #0b1120; color: #e2e8f0; padding: 1rem 1.15rem; border-radius: 10px; overflow-x: auto; font-size: .9rem; }
        pre.verse { background: transparent; color: inherit; font-style: italic; font-family: Georgia, serif; }
        hr.marker { border: 0; border-top: 2px dashed var(--border); margin: 2rem 0; }
        hr.delimiter-line { border: 0; border-top: 1px solid var(--border); margin: 1.5rem 0; }
        hr { border: 0; border-top: 1px solid var(--border); }
        .delimiter { text-align: center; color: var(--muted); letter-spacing: .5rem; margin: 1.5rem 0; font-size: 1.1rem; }

        table { border-collapse: collapse; width: 100%; font-size: .92rem; }
        th, td { border: 1px solid var(--border); padding: .55rem .75rem; text-align: left; }
        th { background: var(--bg-soft); font-weight: 700; }
        .is-striped table tbody tr:nth-child(even), .is-striped table tr:nth-child(even) { background: color-mix(in srgb, var(--muted) 8%, transparent); }
        .is-bordered table, .is-bordered th, .is-bordered td { border-color: var(--muted); }
        .is-compact th, .is-compact td { padding: .3rem .5rem; }

        .gallery { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: .5rem; }
        .gallery img { width: 100%; height: 100%; object-fit: cover; border-radius: 8px; }
        /* Overlay is baked into the renderer's background gradient; height/align are inline. */
        .cover { border-radius: 14px; background-size: cover; background-position: center;
            display: flex; flex-direction: column; justify-content: center;
            color: #fff; padding: 2rem; }
        .cover h2 { color: #fff; margin: 0; }
        .embed iframe { width: 100%; aspect-ratio: 16/9; border-radius: 10px; border: 0; }

        .callout { border-radius: 12px; padding: .9rem 1.1rem; border: 1px solid #bfdbfe; background: #eff6ff; color: #1e3a8a;
            display: flex; gap: .7rem; align-items: flex-start; }
        .callout-icon { flex-shrink: 0; width: 1.4rem; height: 1.4rem; border-radius: 50%; display: grid; place-items: center;
            font-size: .85rem; font-weight: 700; background: rgba(0,0,0,.06); line-height: 1; }
        .callout-body { flex: 1; min-width: 0; }
        .callout-success { background: #f0fdf4; border-color: #bbf7d0; color: #14532d; }
        .callout-warning { background: #fffbeb; border-color: #fde68a; color: #78350f; }
        .callout-danger { background: #fef2f2; border-color: #fecaca; color: #7f1d1d; }
        html.dark .callout { background: #10233f; border-color: #1e3a5f; color: #cfe0ff; }
        html.dark .callout-success { background: #0e2a1c; border-color: #1c4532; color: #bbf7d0; }
        html.dark .callout-warning { background: #2b2410; border-color: #4d3f18; color: #fde68a; }
        html.dark .callout-danger { background: #2c1414; border-color: #4d2020; color: #fecaca; }

        .cta { text-align: center; background: var(--bg-soft); border: 1px solid var(--border); border-radius: 14px; padding: 1.75rem; }
        .btn { display: inline-block; background: var(--brand); color: #fff; padding: .55rem 1.1rem; border-radius: 8px;
            text-decoration: none; font-weight: 600; margin: .25rem; }
        .buttons { display: flex; flex-wrap: wrap; gap: .5rem; }
        .faq details { border: 1px solid var(--border); border-radius: 10px; padding: .7rem .9rem; margin: .5rem 0; background: var(--bg-soft); }
        .faq summary { font-weight: 600; cursor: pointer; }
        .toc, .related { background: var(--bg-soft); border: 1px solid var(--border); border-radius: 12px; padding: .9rem 1.1rem; }
        .toc strong, .related strong { display: block; margin-bottom: .6rem; }
        .related-grid .related-items { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: .8rem; }
        .related-list .related-items { display: flex; flex-direction: column; gap: .5rem; }
        .related-item img { width: 100%; aspect-ratio: 16/9; object-fit: cover; border-radius: 8px; margin-bottom: .35rem; display: block; }
        .related-list .related-item { display: flex; align-items: center; gap: .6rem; }
        .related-list .related-item img { width: 64px; aspect-ratio: 1; margin: 0; flex-shrink: 0; }
        .spacer[data-h="small"] { height: 1rem; } .spacer[data-h="medium"] { height: 2rem; } .spacer[data-h="large"] { height: 4rem; }
        video, audio { width: 100%; border-radius: 10px; }
        .excerpt { font-size: 1.15rem; color: var(--muted); }

        @media (max-width: 640px) {
            article { margin: .75rem 1rem 3rem; padding: 1.4rem 1.25rem 2rem; border-radius: 14px; }
            h1.post-title { font-size: 2rem; }
        }
    </style>
</head>
<body>
    <header class="site-header">
        <a class="brand" href="#">
            <svg class="brand__logo" viewBox="0 0 34 34" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                <defs>
                    <linearGradient id="mgb-grad-pv" x1="0" y1="0" x2="34" y2="34">
                        <stop stop-color="#6366f1"/><stop offset="1" stop-color="#8b5cf6"/>
                    </linearGradient>
                    <linearGradient id="mgb-neon-pv" x1="0" y1="0" x2="34" y2="34">
                        <stop offset="0%" stop-color="#00f2fe"/>
                        <stop offset="50%" stop-color="#4facfe"/>
                        <stop offset="100%" stop-color="#f355da"/>
                    </linearGradient>
                </defs>
                <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mgb-grad-pv)" stroke-width="2.4" fill="rgba(99,102,241,.06)"/>
                <path class="mgb-line" d="M17 1 31 9v16l-14 8L3 25V9l14-8Z" stroke="url(#mgb-neon-pv)" stroke-width="2.6" stroke-linecap="round"/>
                <path d="M10 23V11.5l7 6 7-6V23" stroke="url(#mgb-grad-pv)" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
            </svg>
            <span class="brand__name">Magna<small>Page live preview</small></span>
        </a>
        <button type="button" class="theme-toggle" onclick="magnaToggleTheme()" aria-label="Toggle dark mode" title="Toggle theme">
            <svg class="i-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></svg>
            <svg class="i-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg>
        </button>
    </header>

    <div class="preview-note">
        <span class="status-pill">{{ ucfirst($post->status->value) }}</span>
        <span>Draft render of the last saved content — not a public page.</span>
    </div>

    <article>
        <h1 class="post-title">{{ $post->title }}</h1>
        <div class="post-meta">
            <div class="avatar">{{ strtoupper(mb_substr($post->author?->name ?? 'U', 0, 1)) }}</div>
            <div>
                <div class="who">{{ $post->author?->name ?? 'Unknown' }}</div>
                <div>
                    @if ($post->published_at) {{ $post->published_at->format('d M Y') }} <span class="dot">·</span> @endif
                    {{ $post->readingTimeMinutes() }} min read
                </div>
            </div>
        </div>
        @if ($post->featured_image)
            <img class="featured" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($post->featured_image) }}" alt="">
        @endif
        <div class="post-body">{!! $body !!}</div>
    </article>

    <script>
        function magnaToggleTheme() {
            var dark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('magna-preview-theme', dark ? 'dark' : 'light'); } catch (e) {}
        }
    </script>
</body>
</html>
