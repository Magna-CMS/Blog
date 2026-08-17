<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Support;

use MagnaCms\Blog\Editor\BlockSchema;

/**
 * Renders a (already-sanitised) Editor.js block document to HTML for the admin
 * preview. Text fields hold sanitiser-approved inline HTML and are emitted as-is;
 * every URL/attribute and plain-text value is escaped here. This is a preview
 * aid, not the production frontend renderer.
 */
final class BlockRenderer
{
    /**
     * @param  array<string, mixed>  $document
     */
    public function render(array $document): string
    {
        $blocks = is_array($document['blocks'] ?? null) ? $document['blocks'] : [];
        $html = '';

        foreach ($blocks as $block) {
            if (is_array($block)) {
                $inner = $this->block(
                    is_string($block['type'] ?? null) ? $block['type'] : '',
                    is_array($block['data'] ?? null) ? $block['data'] : [],
                );
                $html .= $this->styleWrap($inner, $block['tunes'] ?? null);
            }
        }

        return $html;
    }

    private const STYLE_SIZE_REM = [
        'sm' => '0.875rem', 'base' => '1rem', 'lg' => '1.25rem', 'xl' => '1.5rem',
    ];

    /**
     * Wrap rendered block HTML with the Style tune's alignment / colour / size.
     * Values are the sanitiser's allowlisted tokens, mapped to CSS here; empty
     * content or no style returns the inner HTML untouched.
     */
    private function styleWrap(string $inner, mixed $tunes): string
    {
        if ($inner === '' || ! is_array($tunes) || ! is_array($tunes['style'] ?? null)) {
            return $inner;
        }

        $style = $tunes['style'];
        $css = [];

        if (in_array($style['align'] ?? '', ['left', 'center', 'right'], true)) {
            $css[] = 'text-align:'.$style['align'];
        }
        $color = $this->hex($style['color'] ?? '', '');
        if ($color !== '') {
            $css[] = 'color:'.$color;
            // Consumed by templates whose inner elements carry a default text
            // colour, via var(--p-text, …), so the user's choice still wins.
            $css[] = '--p-text:'.$color;
        }
        $bg = $this->hex($style['bg'] ?? '', '');
        if ($bg !== '') {
            // Paint the wrapper so plain blocks honour the colour, and expose it
            // as --p-bg for paragraph templates that manage their own box. Add
            // padding + rounded corners so text is not flush against the edge
            // (mirrors StyleTune.applyStyle in the editor).
            $css[] = 'background-color:'.$bg;
            $css[] = '--p-bg:'.$bg;
            $css[] = 'padding:0.75rem 1rem';
            $css[] = 'border-radius:8px';
        }
        $accent = $this->hex($style['accent'] ?? '', '');
        if ($accent !== '') {
            $css[] = '--p-accent:'.$accent;
        }
        if (is_string($style['fontSize'] ?? null) && isset(self::STYLE_SIZE_REM[$style['fontSize']])) {
            $css[] = 'font-size:'.self::STYLE_SIZE_REM[$style['fontSize']];
        }
        $fontStack = is_string($style['font'] ?? null) && $style['font'] !== '' ? FontRegistry::stack($style['font']) : null;
        if ($fontStack !== null) {
            $css[] = 'font-family:'.$fontStack;
        }

        $classes = [];
        foreach (['striped', 'compact', 'bordered'] as $flag) {
            if (! empty($style[$flag])) {
                $classes[] = 'is-'.$flag;
            }
        }
        if (($style['valign'] ?? '') === 'top') {
            $classes[] = 'is-vtop';
        } elseif (($style['valign'] ?? '') === 'center') {
            $classes[] = 'is-vcenter';
        }
        if (in_array($style['ratio'] ?? '', ['2-1', '1-2', '3-1', '1-3'], true)) {
            $classes[] = 'ratio-'.$style['ratio'];
        }

        if ($css === [] && $classes === []) {
            return $inner;
        }

        $attr = $css !== [] ? ' style="'.$this->esc(implode(';', $css)).'"' : '';
        $attr .= $classes !== [] ? ' class="'.implode(' ', $classes).'"' : '';

        return '<div'.$attr.'>'.$inner.'</div>';
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function block(string $type, array $d): string
    {
        return match ($type) {
            'paragraph' => $this->paragraph($d),
            'header' => $this->header($d),
            'list' => $this->list($d),
            'checklist' => $this->checklist($d),
            'quote' => '<blockquote><p>'.$this->html($d['text'] ?? '').'</p>'.($this->plain($d['caption'] ?? '') !== '' ? '<cite>'.$this->html($d['caption'] ?? '').'</cite>' : '').'</blockquote>',
            'pullquote' => '<blockquote class="pullquote"><p>'.$this->html($d['text'] ?? '').'</p>'.($this->plain($d['citation'] ?? '') !== '' ? '<cite>'.$this->esc($d['citation'] ?? '').'</cite>' : '').'</blockquote>',
            'code' => $this->code($d),
            'preformatted' => '<pre>'.$this->esc($d['text'] ?? '').'</pre>',
            'verse' => '<pre class="verse">'.$this->esc($d['text'] ?? '').'</pre>',
            'raw' => (string) ($d['html'] ?? ''),
            'delimiter' => $this->delimiter($d),
            'spacer' => $this->spacer($d),
            'more', 'pageBreak' => '<hr class="marker">',
            'table' => $this->table($d),
            'image' => $this->image($d),
            'gallery' => $this->gallery($d),
            'cover' => $this->cover($d),
            'video' => $this->media('video', $d),
            'audio' => $this->media('audio', $d),
            'embed' => $this->embed($d),
            'map' => $this->map($d),
            'warning', 'callout' => $this->callout($d),
            'cta' => $this->cta($d),
            'buttons' => $this->buttons($d),
            'faq' => $this->faq($d),
            'details' => $this->details($d),
            'socialIcons' => $this->socialIcons($d),
            'mediaText' => $this->mediaText($d),
            'footnotes' => $this->footnotes($d),
            'rss' => $this->rss($d),
            'link' => $this->link($d),
            'group' => $this->group($d),
            'columns' => $this->columns($d),
            'postExcerpt' => isset($d['text']) ? '<p class="excerpt">'.$this->esc($d['text']).'</p>' : '',
            'featuredImage' => isset($d['url']) && $d['url'] ? '<img class="featured" src="'.$this->esc($d['url']).'" alt="">' : '',
            'toc' => $this->toc($d),
            'relatedPosts' => $this->related($d),
            default => '',
        };
    }

    /** @param array<string, mixed> $d */
    private function spacer(array $d): string
    {
        $height = max(8, min(160, is_numeric($d['height'] ?? null) ? (int) $d['height'] : 32));
        if ($d['divider'] ?? false) {
            return '<div class="spacer" style="height:'.$height.'px;display:flex;align-items:center">'
                .'<hr style="width:100%;border:0;border-top:1px solid var(--border,#e5e7eb);margin:0"></div>';
        }

        return '<div class="spacer" style="height:'.$height.'px"></div>';
    }

    /** @param array<string, mixed> $d */
    private function delimiter(array $d): string
    {
        return match ($d['style'] ?? 'dots') {
            'line' => '<hr class="delimiter-line">',
            'dashed' => '<hr class="marker">',
            'asterisks' => '<div class="delimiter">✳ ✳ ✳</div>',
            default => '<div class="delimiter">• • •</div>',
        };
    }

    private const PARAGRAPH_IMAGE = ['image-left', 'image-right', 'photo-overlay', 'author-note'];

    private const PARAGRAPH_LABEL = [
        'ribbon' => 'Featured', 'badge' => 'Update', 'timeline' => '1', 'stat' => '94%', 'asymmetric' => 'Pro tip',
        'takeaway' => 'Key takeaway', 'insight' => 'Key insight',
    ];

    /** @param array<string, mixed> $d */
    private function paragraph(array $d): string
    {
        $text = $this->html($d['text'] ?? '');
        $template = in_array($d['template'] ?? '', BlockSchema::PARAGRAPH_TEMPLATES, true) ? $d['template'] : 'standard';
        $image = $this->cleanUrlOut($d['image'] ?? '');

        if ($template === 'standard') {
            return '<p>'.$text.'</p>';
        }

        // Image templates degrade to a plain paragraph when no image is set.
        $isImage = in_array($template, self::PARAGRAPH_IMAGE, true);
        if ($isImage && $image === '') {
            return '<p>'.$text.'</p>';
        }

        // Mirror the editor DOM so one stylesheet (editor.css, linked into the
        // preview) styles both surfaces identically.
        $media = $isImage
            ? '<div class="magna-blog-p__media"><img src="'.$this->esc($image).'" alt="'.$this->esc($d['imageAlt'] ?? '').'"></div>'
            : '';

        $label = '';
        if (isset(self::PARAGRAPH_LABEL[$template])) {
            $stored = $this->plain($d['label'] ?? '');
            $label = '<span class="magna-blog-p__label">'.$this->esc($stored !== '' ? $stored : self::PARAGRAPH_LABEL[$template]).'</span>';
        }

        return '<div class="magna-blog-p" data-template="'.$this->esc($template).'">'
            .'<div class="magna-blog-p__text">'.$text.'</div>'.$media.$label.'</div>';
    }

    /** Re-validate a stored URL for output (defence in depth). */
    private function cleanUrlOut(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') && ! str_starts_with($url, '//')) {
            return $url;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /** @param array<string, mixed> $d */
    private function header(array $d): string
    {
        $level = $this->level($d);
        $text = $this->html($d['text'] ?? '');
        $id = $this->anchor($this->plain(strip_tags((string) ($d['text'] ?? ''))));
        $idAttr = $id !== '' ? ' id="'.$id.'"' : '';

        return sprintf('<h%1$d%3$s>%2$s</h%1$d>', $level, $text, $idAttr);
    }

    /** @param array<string, mixed> $d */
    private function code(array $d): string
    {
        $lang = in_array($d['language'] ?? '', BlockSchema::CODE_LANGUAGES, true) ? $d['language'] : '';
        $class = $lang !== '' ? ' class="language-'.$lang.'"' : '';

        return '<pre class="code" data-lang="'.$this->esc($lang).'"><code'.$class.'>'.$this->esc($d['code'] ?? '').'</code></pre>';
    }

    /** A URL-safe heading anchor slug. */
    private function anchor(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';

        return trim($slug, '-');
    }

    /** @param array<string, mixed> $d */
    private function list(array $d): string
    {
        $tag = ($d['style'] ?? '') === 'ordered' ? 'ol' : 'ul';
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];

        return '<'.$tag.'>'.$this->listItems($items).'</'.$tag.'>';
    }

    /** @param array<int, mixed> $items */
    private function listItems(array $items): string
    {
        $out = '';
        foreach ($items as $item) {
            if (is_string($item)) {
                $out .= '<li>'.$this->html($item).'</li>';
            } elseif (is_array($item)) {
                $children = is_array($item['items'] ?? null) && $item['items'] !== [] ? '<ul>'.$this->listItems($item['items']).'</ul>' : '';
                $out .= '<li>'.$this->html($item['content'] ?? '').$children.'</li>';
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $d */
    private function checklist(array $d): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $out = '<ul class="checklist">';
        foreach ($items as $item) {
            if (is_array($item)) {
                $checked = ($item['checked'] ?? false) ? '☑' : '☐';
                $out .= '<li>'.$checked.' '.$this->html($item['text'] ?? '').'</li>';
            }
        }

        return $out.'</ul>';
    }

    /** @param array<string, mixed> $d */
    private function table(array $d): string
    {
        $rows = is_array($d['content'] ?? null) ? $d['content'] : [];
        $out = '<table>';
        foreach ($rows as $i => $row) {
            if (! is_array($row)) {
                continue;
            }
            $cell = ($i === 0 && ($d['withHeadings'] ?? false)) ? 'th' : 'td';
            $out .= '<tr>';
            foreach ($row as $value) {
                $out .= '<'.$cell.'>'.$this->html($value).'</'.$cell.'>';
            }
            $out .= '</tr>';
        }

        return $out.'</table>';
    }

    private const IMAGE_WIDTH = ['small' => '25%', 'medium' => '50%', 'large' => '75%', 'full' => '100%'];

    /** @param array<string, mixed> $d */
    private function image(array $d): string
    {
        if (($d['url'] ?? '') === '') {
            return '';
        }
        $caption = $this->plain($d['caption'] ?? '');

        $imgCss = 'width:'.(self::IMAGE_WIDTH[$d['width'] ?? 'full'] ?? '100%');
        if ($d['rounded'] ?? false) {
            $imgCss .= ';border-radius:0.6rem';
        }
        $img = '<img src="'.$this->esc($d['url']).'" alt="'.$this->esc($d['alt'] ?? '').'" style="'.$imgCss.'">';

        if (($d['linkUrl'] ?? '') !== '') {
            $img = '<a href="'.$this->esc($d['linkUrl']).'">'.$img.'</a>';
        }

        $figCss = match ($d['align'] ?? '') {
            'center' => 'text-align:center',
            'right' => 'text-align:right',
            default => '',
        };
        $figStyle = $figCss !== '' ? ' style="'.$figCss.'"' : '';

        return '<figure'.$figStyle.'>'.$img.($caption !== '' ? '<figcaption>'.$this->esc($d['caption']).'</figcaption>' : '').'</figure>';
    }

    private const GALLERY_CROP = ['square' => '1/1', '4-3' => '4/3', '16-9' => '16/9'];

    /** @param array<string, mixed> $d */
    private function gallery(array $d): string
    {
        $images = is_array($d['images'] ?? null) ? $d['images'] : [];
        $cols = max(1, min(6, is_numeric($d['columns'] ?? null) ? (int) $d['columns'] : 3));
        $gap = max(0, min(40, is_numeric($d['gap'] ?? null) ? (int) $d['gap'] : 8));
        $aspect = self::GALLERY_CROP[$d['crop'] ?? ''] ?? null;
        $rounded = ($d['rounded'] ?? false) ? ';border-radius:.5rem' : '';

        $imgCss = 'width:100%';
        if ($aspect !== null) {
            $imgCss .= ';aspect-ratio:'.$aspect.';height:100%;object-fit:cover';
        }
        $imgCss .= $rounded;

        $out = '<div class="gallery" style="display:grid;grid-template-columns:repeat('.$cols.',1fr);gap:'.$gap.'px">';
        foreach ($images as $image) {
            if (is_array($image) && ($image['url'] ?? '') !== '') {
                $out .= '<img src="'.$this->esc($image['url']).'" alt="'.$this->esc($image['alt'] ?? '').'" style="'.$imgCss.'">';
            }
        }

        return $out.'</div>';
    }

    private const COVER_HEIGHT = ['small' => '180px', 'medium' => '280px', 'large' => '440px'];

    /** @param array<string, mixed> $d */
    private function cover(array $d): string
    {
        $height = self::COVER_HEIGHT[$d['height'] ?? 'medium'] ?? '280px';
        $overlay = max(0, min(100, is_numeric($d['overlay'] ?? null) ? (int) $d['overlay'] : 40)) / 100;
        $align = in_array($d['align'] ?? '', ['left', 'center', 'right'], true) ? $d['align'] : 'center';
        $justify = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'][$align];

        $css = 'min-height:'.$height.';text-align:'.$align.';align-items:'.$justify;
        if (($d['url'] ?? '') !== '') {
            $css .= ';background-image:linear-gradient(rgba(0,0,0,'.$overlay.'),rgba(0,0,0,'.$overlay.')),url('.$this->esc($d['url']).')';
        }

        return '<div class="cover" style="'.$css.'"><h2>'.$this->esc($d['title'] ?? '').'</h2><div>'.$this->html($d['text'] ?? '').'</div></div>';
    }

    /** @param array<string, mixed> $d */
    private function media(string $tag, array $d): string
    {
        if (($d['url'] ?? '') === '') {
            return '';
        }
        $caption = $this->plain($d['caption'] ?? '');

        $attrs = '';
        if ($d['controls'] ?? true) {
            $attrs .= ' controls';
        }
        if ($d['autoplay'] ?? false) {
            $attrs .= ' autoplay';
        }
        if ($d['loop'] ?? false) {
            $attrs .= ' loop';
        }
        if (($d['muted'] ?? false) || ($tag === 'video' && ($d['autoplay'] ?? false))) {
            // Browsers only honour autoplay when muted.
            $attrs .= ' muted';
        }
        if ($tag === 'video') {
            $attrs .= ' playsinline';
        }

        return '<figure><'.$tag.$attrs.' src="'.$this->esc($d['url']).'"></'.$tag.'>'.($caption !== '' ? '<figcaption>'.$this->esc($d['caption']).'</figcaption>' : '').'</figure>';
    }

    /** @param array<string, mixed> $d */
    private function embed(array $d): string
    {
        if (($d['embed'] ?? '') === '') {
            return '';
        }

        return '<div class="embed"><iframe src="'.$this->esc($d['embed']).'" frameborder="0" allowfullscreen loading="lazy"></iframe></div>';
    }

    /** @param array<string, mixed> $d */
    private function map(array $d): string
    {
        if ($this->plain($d['query'] ?? '') === '') {
            return '';
        }
        $src = 'https://maps.google.com/maps?q='.rawurlencode((string) $d['query']).'&output=embed';

        return '<div class="embed"><iframe src="'.$this->esc($src).'" frameborder="0" loading="lazy"></iframe></div>';
    }

    private const CALLOUT_ICON = ['info' => 'ℹ', 'success' => '✓', 'warning' => '⚠', 'danger' => '✕'];

    /** @param array<string, mixed> $d */
    private function callout(array $d): string
    {
        $type = in_array($d['type'] ?? '', ['info', 'success', 'warning', 'danger'], true) ? $d['type'] : 'info';
        $title = $this->plain($d['title'] ?? '');
        $body = $this->html($d['text'] ?? $d['message'] ?? '');
        $icon = ($d['icon'] ?? false)
            ? '<span class="callout-icon">'.self::CALLOUT_ICON[$type].'</span>'
            : '';

        return '<div class="callout callout-'.$this->esc($type).'">'.$icon.'<div class="callout-body">'
            .($title !== '' ? '<strong>'.$this->esc($d['title']).'</strong>' : '').'<div>'.$body.'</div></div></div>';
    }

    /** @param array<string, mixed> $d */
    private function cta(array $d): string
    {
        $align = in_array($d['align'] ?? '', ['left', 'center', 'right'], true) ? $d['align'] : 'center';
        $bg = $this->hex($d['background'] ?? '', '');
        $boxStyle = 'text-align:'.$align;
        if ($bg !== '') {
            $boxStyle .= ';background:'.$bg.';color:'.$this->contrastText($bg);
        }

        $button = '';
        if (($d['buttonUrl'] ?? '') !== '') {
            $color = $this->hex($d['buttonColor'] ?? '', '#6366f1');
            $outline = ($d['buttonType'] ?? 'filled') === 'outline';
            $btnCss = 'border:1.5px solid '.$color.($outline
                ? ';background:transparent;color:'.$color
                : ';background:'.$color.';color:#fff');
            $button = '<a class="btn" href="'.$this->esc($d['buttonUrl']).'" style="'.$btnCss.'">'.$this->esc($d['buttonLabel'] ?? 'Learn more').'</a>';
        }

        return '<div class="cta" style="'.$this->esc($boxStyle).'"><h3>'.$this->esc($d['title'] ?? '').'</h3><div>'.$this->html($d['text'] ?? '').'</div>'.$button.'</div>';
    }

    private const BUTTON_PAD = ['sm' => '0.35rem 0.75rem', 'md' => '0.55rem 1.1rem', 'lg' => '0.75rem 1.6rem'];

    private const BUTTON_FONT_SIZE = ['sm' => '0.85rem', 'md' => '0.95rem', 'lg' => '1.1rem'];

    private const BUTTON_JUSTIFY = ['left' => 'flex-start', 'center' => 'center', 'right' => 'flex-end'];

    /** @param array<string, mixed> $d */
    private function buttons(array $d): string
    {
        $buttons = is_array($d['buttons'] ?? null) ? $d['buttons'] : [];
        $justify = self::BUTTON_JUSTIFY[$d['align'] ?? 'left'] ?? 'flex-start';
        $gap = max(0, min(64, is_numeric($d['gap'] ?? null) ? (int) $d['gap'] : 8));

        $out = '<div class="buttons" style="display:flex;flex-wrap:wrap;align-items:center;'
            .'justify-content:'.$justify.';gap:'.$gap.'px">';

        foreach ($buttons as $button) {
            if (is_array($button) && ($button['url'] ?? '') !== '') {
                $out .= $this->button($button);
            }
        }

        return $out.'</div>';
    }

    /** @param array<string, mixed> $b */
    private function button(array $b): string
    {
        $type = in_array($b['type'] ?? '', ['filled', 'outline', 'ghost', 'link'], true) ? $b['type'] : 'filled';
        $color = $this->hex($b['color'] ?? '', '#6366f1');
        $text = $this->hex($b['textColor'] ?? '', '');
        $size = in_array($b['size'] ?? '', ['sm', 'md', 'lg'], true) ? $b['size'] : 'md';
        $radius = max(0, min(50, is_numeric($b['radius'] ?? null) ? (int) $b['radius'] : 8));

        $css = [
            'display:inline-flex', 'align-items:center', 'justify-content:center',
            'font-weight:600', 'text-decoration:none', 'line-height:1.2',
            'font-size:'.self::BUTTON_FONT_SIZE[$size],
            'border-radius:'.$radius.'px',
            'border:1.5px solid '.$color,
        ];
        if (($b['fullWidth'] ?? false)) {
            $css[] = 'width:100%';
        }
        $fontStack = is_string($b['font'] ?? null) && $b['font'] !== '' ? FontRegistry::stack($b['font']) : null;
        if ($fontStack !== null) {
            $css[] = 'font-family:'.$fontStack;
        }

        if ($type === 'filled') {
            $css[] = 'padding:'.self::BUTTON_PAD[$size];
            $css[] = 'background:'.$color;
            $css[] = 'color:'.($text !== '' ? $text : '#ffffff');
        } elseif ($type === 'outline') {
            $css[] = 'padding:'.self::BUTTON_PAD[$size];
            $css[] = 'background:transparent';
            $css[] = 'color:'.($text !== '' ? $text : $color);
        } elseif ($type === 'ghost') {
            $css[] = 'padding:'.self::BUTTON_PAD[$size];
            $css[] = 'background:transparent';
            $css[] = 'border-color:transparent';
            $css[] = 'color:'.($text !== '' ? $text : $color);
        } else { // link
            $css[] = 'background:transparent';
            $css[] = 'border:0';
            $css[] = 'text-decoration:underline';
            $css[] = 'color:'.($text !== '' ? $text : $color);
        }

        $rel = [];
        if (($b['newTab'] ?? false)) {
            $rel[] = 'noopener';
        }
        if (($b['nofollow'] ?? false)) {
            $rel[] = 'nofollow';
        }
        $attrs = ' href="'.$this->esc($b['url']).'"';
        if (($b['newTab'] ?? false)) {
            $attrs .= ' target="_blank"';
        }
        if ($rel !== []) {
            $attrs .= ' rel="'.implode(' ', $rel).'"';
        }

        return '<a'.$attrs.' style="'.$this->esc(implode(';', $css)).'">'.$this->esc($b['label'] ?? '').'</a>';
    }

    /** Hex-literal colour or the fallback (never raw CSS). */
    private function hex(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1 ? $value : $default;
    }

    /** Black or white text for readable contrast on a #rrggbb background. */
    private function contrastText(string $hex): string
    {
        if (preg_match('/^#([0-9a-fA-F]{6})$/', $hex, $m) !== 1) {
            return '#111827';
        }
        $n = (int) hexdec($m[1]);
        $lum = (0.299 * (($n >> 16) & 255) + 0.587 * (($n >> 8) & 255) + 0.114 * ($n & 255)) / 255;

        return $lum > 0.6 ? '#111827' : '#ffffff';
    }

    /** @param array<string, mixed> $d */
    private function faq(array $d): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $openFirst = (bool) ($d['openFirst'] ?? false);
        $template = in_array($d['template'] ?? '', BlockSchema::FAQ_TEMPLATES, true) ? $d['template'] : 'card';

        $out = '<div class="faq" data-template="'.$this->esc($template).'">';
        $first = true;
        foreach ($items as $item) {
            if (is_array($item)) {
                $open = ($openFirst && $first) ? ' open' : '';
                $out .= '<details'.$open.'><summary>'.$this->esc($item['question'] ?? '').'</summary><div>'.$this->html($item['answer'] ?? '').'</div></details>';
                $first = false;
            }
        }
        $out .= '</div>';

        if ($d['schema'] ?? false) {
            $out .= $this->faqSchema($items);
        }

        return $out;
    }

    /**
     * FAQPage JSON-LD for SEO. Answer text is stripped to plain text and the
     * whole payload is json_encoded with tags hex-escaped, so it cannot break
     * out of the <script> element.
     *
     * @param  array<int, mixed>  $items
     */
    private function faqSchema(array $items): string
    {
        $entities = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $question = trim(strip_tags((string) ($item['question'] ?? '')));
            $answer = trim(strip_tags((string) ($item['answer'] ?? '')));
            if ($question === '' || $answer === '') {
                continue;
            }
            $entities[] = [
                '@type' => 'Question',
                'name' => $question,
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer],
            ];
        }

        if ($entities === []) {
            return '';
        }

        $json = json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'FAQPage',
            'mainEntity' => $entities,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE);

        return '<script type="application/ld+json">'.($json !== false ? $json : '').'</script>';
    }

    /** @param array<string, mixed> $d */
    private function details(array $d): string
    {
        $summary = $this->plain($d['summary'] ?? '');
        $body = $this->html($d['text'] ?? '');
        if ($summary === '' && $body === '') {
            return '';
        }
        $open = ($d['open'] ?? false) ? ' open' : '';

        return '<details class="magna-details"'.$open.'><summary>'.$this->esc($summary !== '' ? $summary : 'Details')
            .'</summary><div class="magna-details__body">'.$body.'</div></details>';
    }

    /**
     * Social-icon brand colour + SVG path (mirrors SOCIAL_NETWORKS in
     * social-icons.js). Path data only — wrapped in a fixed <svg> at render time.
     *
     * @var array<string, array{color: string, path: string}>
     */
    private const SOCIAL_ICONS = [
        'x' => ['color' => '#000000', 'path' => 'M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z'],
        'facebook' => ['color' => '#1877F2', 'path' => 'M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z'],
        'instagram' => ['color' => '#E4405F', 'path' => 'M12 2.163c3.204 0 3.584.012 4.85.07 3.252.148 4.771 1.691 4.919 4.919.058 1.265.069 1.645.069 4.849 0 3.205-.012 3.584-.069 4.849-.149 3.225-1.664 4.771-4.919 4.919-1.266.058-1.644.07-4.85.07-3.204 0-3.584-.012-4.849-.07-3.26-.149-4.771-1.699-4.919-4.92-.058-1.265-.07-1.644-.07-4.849 0-3.204.013-3.583.07-4.849.149-3.227 1.664-4.771 4.919-4.919 1.266-.057 1.645-.069 4.849-.069zM12 0C8.741 0 8.333.014 7.053.072 2.695.272.273 2.69.073 7.052.014 8.333 0 8.741 0 12c0 3.259.014 3.668.072 4.948.2 4.358 2.618 6.78 6.98 6.98C8.333 23.986 8.741 24 12 24c3.259 0 3.668-.014 4.948-.072 4.354-.2 6.782-2.618 6.979-6.98.059-1.28.073-1.689.073-4.948 0-3.259-.014-3.667-.072-4.947-.196-4.354-2.617-6.78-6.979-6.98C15.668.014 15.259 0 12 0zm0 5.838a6.162 6.162 0 100 12.324 6.162 6.162 0 000-12.324zM12 16a4 4 0 110-8 4 4 0 010 8zm6.406-11.845a1.44 1.44 0 100 2.881 1.44 1.44 0 000-2.881z'],
        'linkedin' => ['color' => '#0A66C2', 'path' => 'M20.447 20.452h-3.554v-5.569c0-1.328-.027-3.037-1.852-3.037-1.853 0-2.136 1.445-2.136 2.939v5.667H9.351V9h3.414v1.561h.046c.477-.9 1.637-1.85 3.37-1.85 3.601 0 4.267 2.37 4.267 5.455v6.286zM5.337 7.433a2.062 2.062 0 01-2.063-2.065 2.064 2.064 0 112.063 2.065zm1.782 13.019H3.555V9h3.564v11.452zM22.225 0H1.771C.792 0 0 .774 0 1.729v20.542C0 23.227.792 24 1.771 24h20.451C23.2 24 24 23.227 24 22.271V1.729C24 .774 23.2 0 22.222 0h.003z'],
        'youtube' => ['color' => '#FF0000', 'path' => 'M23.498 6.186a3.016 3.016 0 00-2.122-2.136C19.505 3.545 12 3.545 12 3.545s-7.505 0-9.377.505A3.017 3.017 0 00.502 6.186C0 8.07 0 12 0 12s0 3.93.502 5.814a3.016 3.016 0 002.122 2.136c1.871.505 9.376.505 9.376.505s7.505 0 9.377-.505a3.015 3.015 0 002.122-2.136C24 15.93 24 12 24 12s0-3.93-.502-5.814zM9.545 15.568V8.432L15.818 12l-6.273 3.568z'],
        'github' => ['color' => '#181717', 'path' => 'M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61C4.422 18.07 3.633 17.7 3.633 17.7c-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12'],
        'tiktok' => ['color' => '#000000', 'path' => 'M12.525.02c1.31-.02 2.61-.01 3.91-.02.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z'],
        'mastodon' => ['color' => '#6364FF', 'path' => 'M23.268 5.313c-.35-2.578-2.617-4.61-5.304-5.004C17.51.242 15.792 0 11.813 0h-.03c-3.98 0-4.835.242-5.288.309C3.882.692 1.496 2.518.917 5.127.64 6.412.61 7.837.661 9.143c.074 1.874.088 3.745.26 5.611.118 1.24.325 2.47.62 3.68.55 2.237 2.777 4.098 4.96 4.857 2.336.792 4.849.923 7.256.38.265-.061.527-.132.786-.213.585-.184 1.27-.39 1.774-.753a.057.057 0 00.023-.043v-1.809a.052.052 0 00-.02-.041.053.053 0 00-.046-.01 20.282 20.282 0 01-4.709.545c-2.73 0-3.463-1.284-3.674-1.818a5.593 5.593 0 01-.319-1.433.053.053 0 01.066-.054c1.517.363 3.072.546 4.632.546.376 0 .75 0 1.125-.01 1.57-.044 3.224-.124 4.768-.422.038-.008.077-.015.11-.024 2.435-.464 4.753-1.92 4.989-5.604.008-.145.03-1.52.03-1.67.002-.512.167-3.63-.024-5.545zm-3.748 9.195h-2.561V8.29c0-1.309-.55-1.976-1.67-1.976-1.23 0-1.846.79-1.846 2.35v3.403h-2.546V8.663c0-1.56-.617-2.35-1.848-2.35-1.112 0-1.668.668-1.669 1.977v6.218H4.822V8.102c0-1.31.337-2.35 1.011-3.12.696-.77 1.608-1.164 2.74-1.164 1.311 0 2.302.5 2.962 1.498l.638 1.06.638-1.06c.66-.999 1.65-1.498 2.96-1.498 1.13 0 2.043.395 2.74 1.164.675.77 1.012 1.81 1.012 3.12z'],
        'email' => ['color' => '#0f172a', 'path' => 'M20 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z'],
        'rss' => ['color' => '#FFA500', 'path' => 'M6.503 20.752c0 1.794-1.456 3.248-3.251 3.248-1.796 0-3.252-1.454-3.252-3.248 0-1.794 1.456-3.248 3.252-3.248 1.795.001 3.251 1.454 3.251 3.248zm-6.503-12.572v4.811c6.05.062 10.96 4.966 11.022 11.009h4.817c-.062-8.71-7.118-15.758-15.839-15.82zm0-3.368c10.58.046 19.152 8.594 19.183 19.188h4.817c-.03-13.231-10.755-23.954-24-24v4.812z'],
        'website' => ['color' => '#0f172a', 'path' => 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-1 17.93c-3.95-.49-7-3.85-7-7.93 0-.62.08-1.21.21-1.79L9 15v1c0 1.1.9 2 2 2v1.93zm6.9-2.54c-.26-.81-1-1.39-1.9-1.39h-1v-3c0-.55-.45-1-1-1H8v-2h2c.55 0 1-.45 1-1V7h2c1.1 0 2-.9 2-2v-.41c2.93 1.19 5 4.06 5 7.41 0 2.08-.8 3.97-2.1 5.39z'],
    ];

    /** @param array<string, mixed> $d */
    private function socialIcons(array $d): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $shape = in_array($d['shape'] ?? '', ['rounded', 'square', 'circle'], true) ? $d['shape'] : 'rounded';
        $style = in_array($d['style'] ?? '', ['brand', 'mono', 'outline'], true) ? $d['style'] : 'brand';
        $size = in_array($d['size'] ?? '', ['sm', 'md', 'lg'], true) ? $d['size'] : 'md';
        $align = in_array($d['align'] ?? '', ['left', 'center', 'right'], true) ? $d['align'] : 'left';

        $links = '';
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $network = is_string($item['network'] ?? null) && isset(self::SOCIAL_ICONS[$item['network']]) ? $item['network'] : 'website';
            $url = $this->cleanUrlOut($item['url'] ?? '');
            if ($url === '' && ! str_starts_with((string) ($item['url'] ?? ''), 'mailto:')) {
                continue;
            }
            if ($url === '') {
                $url = (string) $item['url'];
            }
            $icon = self::SOCIAL_ICONS[$network];
            $colorStyle = $style === 'brand' ? ' style="--sc:'.$this->esc($icon['color']).'"' : '';
            $svg = '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true"><path d="'.$icon['path'].'"/></svg>';
            $links .= '<a class="magna-social__link" href="'.$this->esc($url).'" target="_blank" rel="noopener nofollow"'
                .$colorStyle.' aria-label="'.$this->esc($network).'">'.$svg.'</a>';
        }

        if ($links === '') {
            return '';
        }

        return '<div class="magna-social" data-shape="'.$shape.'" data-style="'.$style.'" data-size="'.$size.'" data-align="'.$align.'">'.$links.'</div>';
    }

    private const MEDIATEXT_RATIO = [
        '1-1' => '1fr 1fr', '2-1' => '2fr 1fr', '1-2' => '1fr 2fr', '2-3' => '2fr 3fr', '3-2' => '3fr 2fr',
    ];

    /** @param array<string, mixed> $d */
    private function mediaText(array $d): string
    {
        $text = $this->html($d['text'] ?? '');
        $image = $this->cleanUrlOut($d['image'] ?? '');

        // No image → plain text column.
        if ($image === '') {
            return $text !== '' ? '<div class="magna-mediatext-plain">'.$text.'</div>' : '';
        }

        $side = ($d['mediaSide'] ?? '') === 'right' ? 'right' : 'left';
        $ratio = is_string($d['ratio'] ?? null) && isset(self::MEDIATEXT_RATIO[$d['ratio']]) ? $d['ratio'] : '1-1';
        $vAlign = in_array($d['vAlign'] ?? '', ['top', 'center', 'bottom'], true) ? $d['vAlign'] : 'center';
        $stack = ($d['stackMobile'] ?? true) ? '1' : '0';

        $media = '<div class="magna-mediatext__media"><img src="'.$this->esc($image).'" alt="'.$this->esc($d['imageAlt'] ?? '').'"></div>';
        $body = '<div class="magna-mediatext__text">'.$text.'</div>';

        return '<div class="magna-mediatext" data-side="'.$side.'" data-ratio="'.$ratio.'" data-valign="'.$vAlign.'" data-stack="'.$stack.'">'
            .$media.$body.'</div>';
    }

    /** @param array<string, mixed> $d */
    private function footnotes(array $d): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $notes = '';
        $n = 0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $text = $this->html($item['text'] ?? '');
            if (trim(strip_tags($text)) === '') {
                continue;
            }
            $n++;
            // Anchored so the body can link to it (e.g. <sup><a href="#fn-1">1</a>);
            // a back-reference link points at the matching #fnref-N if present.
            $notes .= '<li id="fn-'.$n.'" class="footnote"><span class="footnote__body">'.$text.'</span>'
                .' <a class="footnote__back" href="#fnref-'.$n.'" aria-label="Back to reference">↩</a></li>';
        }

        if ($notes === '') {
            return '';
        }

        $title = $this->plain($d['title'] ?? '');
        $heading = $title !== '' ? '<h2 class="footnotes__title">'.$this->esc($title).'</h2>' : '';

        return '<section class="footnotes">'.$heading.'<ol class="footnotes__list">'.$notes.'</ol></section>';
    }

    /** @param array<string, mixed> $d */
    private function rss(array $d): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        $count = max(1, min(20, is_numeric($d['count'] ?? null) ? (int) $d['count'] : 5));
        $showDate = (bool) ($d['showDate'] ?? true);

        $rows = '';
        $shown = 0;
        foreach ($items as $item) {
            if (! is_array($item) || $shown >= $count) {
                continue;
            }
            $title = $this->plain($item['title'] ?? '');
            $link = $this->cleanUrlOut($item['link'] ?? '');
            if ($title === '' && $link === '') {
                continue;
            }
            $label = $title !== '' ? $this->esc($title) : $this->esc($link);
            $head = $link !== ''
                ? '<a href="'.$this->esc($link).'" rel="noopener nofollow" target="_blank">'.$label.'</a>'
                : $label;
            $date = ($showDate && $this->plain($item['date'] ?? '') !== '')
                ? '<span class="rss__date">'.$this->esc($item['date']).'</span>'
                : '';
            $rows .= '<li class="rss__item"><span class="rss__title">'.$head.'</span>'.$date.'</li>';
            $shown++;
        }

        if ($rows === '') {
            return '';
        }

        $title = $this->plain($d['feedTitle'] ?? '');
        $heading = $title !== '' ? '<strong class="rss__feed">'.$this->esc($title).'</strong>' : '';

        return '<div class="rss">'.$heading.'<ul class="rss__list">'.$rows.'</ul></div>';
    }

    /** @param array<string, mixed> $d */
    private function link(array $d): string
    {
        $url = $this->cleanUrlOut($d['link'] ?? '');
        if ($url === '') {
            return '';
        }

        $meta = is_array($d['meta'] ?? null) ? $d['meta'] : [];
        $image = is_array($meta['image'] ?? null) ? $meta['image'] : [];
        $title = $this->plain($meta['title'] ?? '');
        $desc = $this->plain($meta['description'] ?? '');
        $img = $this->cleanUrlOut($image['url'] ?? '');
        $text = $this->plain($d['text'] ?? '');

        // rel: noopener is always safe; add nofollow when asked. target=_blank
        // only when the author opted into a new tab.
        $rel = 'noopener'.((bool) ($d['nofollow'] ?? false) ? ' nofollow' : '');
        $target = (bool) ($d['newTab'] ?? false) ? ' target="_blank"' : '';
        $attrs = ' href="'.$this->esc($url).'" rel="'.$rel.'"'.$target;

        // With Open Graph metadata we render a rich card; otherwise a plain
        // anchor using the link text (falling back to the URL).
        if ($title !== '' || $desc !== '' || $img !== '') {
            $media = $img !== ''
                ? '<span class="link-card__img" style="background-image:url(\''.$this->esc($img).'\')"></span>'
                : '';
            $body = '<span class="link-card__body">';
            if ($title !== '') {
                $body .= '<span class="link-card__title">'.$this->esc($title).'</span>';
            }
            if ($desc !== '') {
                $body .= '<span class="link-card__desc">'.$this->esc($desc).'</span>';
            }
            $body .= '<span class="link-card__host">'.$this->esc($this->host($url)).'</span></span>';

            return '<a class="link-card"'.$attrs.'>'.$media.$body.'</a>';
        }

        $label = $text !== '' ? $this->esc($text) : $this->esc($url);

        return '<p class="link-plain"><a'.$attrs.'>'.$label.'</a></p>';
    }

    private function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? $host : $url;
    }

    /** @param array<string, mixed> $d */
    private function group(array $d): string
    {
        $blocks = is_array($d['blocks'] ?? null) ? $d['blocks'] : [];
        $inner = $this->render(['blocks' => $blocks]);
        if ($inner === '') {
            return '';
        }

        $layout = in_array($d['layout'] ?? '', ['stack', 'row', 'grid'], true) ? $d['layout'] : 'stack';
        $cols = in_array((int) ($d['columns'] ?? 2), [2, 3, 4], true) ? (int) $d['columns'] : 2;
        $gap = max(0, min(48, is_numeric($d['gap'] ?? null) ? (int) $d['gap'] : 16));
        $pad = max(0, min(64, is_numeric($d['padding'] ?? null) ? (int) $d['padding'] : 20));
        $radius = max(0, min(40, is_numeric($d['radius'] ?? null) ? (int) $d['radius'] : 12));
        $align = in_array($d['align'] ?? '', ['left', 'center', 'right'], true) ? $d['align'] : '';

        $css = ['--g-gap:'.$gap.'px', '--g-pad:'.$pad.'px', '--g-radius:'.$radius.'px', '--g-cols:'.$cols];
        $bg = $this->hex($d['background'] ?? '', '');
        if ($bg !== '') {
            $css[] = '--g-bg:'.$bg;
        }

        return '<div class="magna-group" data-layout="'.$layout.'" data-align="'.$this->esc($align).'" style="'.$this->esc(implode(';', $css)).'">'
            .$inner.'</div>';
    }

    /** @param array<string, mixed> $d */
    private function columns(array $d): string
    {
        $cols = is_array($d['cols'] ?? null) ? $d['cols'] : [];
        // The count rides on a CSS var so the preview stylesheet can collapse
        // columns to a single column on small screens (inline grid can't be
        // overridden responsively).
        $out = '<div class="columns" style="--cols:'.max(1, count($cols)).'">';
        foreach ($cols as $col) {
            $out .= '<div class="column">'.(is_array($col) ? $this->render($col) : '').'</div>';
        }

        return $out.'</div>';
    }

    /** @param array<string, mixed> $d */
    private function toc(array $d): string
    {
        $items = is_array($d['items'] ?? null) ? $d['items'] : [];
        if ($items === []) {
            return '';
        }
        $title = $this->plain($d['title'] ?? 'Contents');
        $tag = ($d['ordered'] ?? false) ? 'ol' : 'ul';

        $out = '<nav class="toc">'.($title !== '' ? '<strong>'.$this->esc($title).'</strong>' : '').'<'.$tag.'>';
        foreach ($items as $item) {
            if (is_array($item)) {
                $text = (string) ($item['text'] ?? '');
                $id = $this->anchor(strip_tags($text));
                $label = $this->esc($text);
                $out .= '<li>'.($id !== '' ? '<a href="#'.$id.'">'.$label.'</a>' : $label).'</li>';
            }
        }

        return $out.'</'.$tag.'></nav>';
    }

    /** @param array<string, mixed> $d */
    private function related(array $d): string
    {
        $posts = is_array($d['posts'] ?? null) ? $d['posts'] : [];
        if ($posts === []) {
            return '';
        }
        $layout = in_array($d['layout'] ?? '', ['grid', 'list'], true) ? $d['layout'] : 'grid';
        $showImage = (bool) ($d['showImage'] ?? true);

        $out = '<div class="related related-'.$layout.'"><strong>Related posts</strong><div class="related-items">';
        foreach ($posts as $post) {
            if (! is_array($post)) {
                continue;
            }
            $title = $this->esc($post['title'] ?? '');
            $inner = ($post['url'] ?? '') !== '' ? '<a href="'.$this->esc($post['url']).'">'.$title.'</a>' : $title;
            $img = ($showImage && ($post['image'] ?? '') !== '')
                ? '<img src="'.$this->esc($post['image']).'" alt="">'
                : '';
            $out .= '<div class="related-item">'.$img.'<span>'.$inner.'</span></div>';
        }

        return $out.'</div></div>';
    }

    /** @param array<string, mixed> $d */
    private function level(array $d): int
    {
        $level = (int) ($d['level'] ?? 2);

        return max(2, min(4, $level));
    }

    private function html(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function plain(mixed $value): string
    {
        return trim(is_string($value) ? $value : '');
    }

    private function esc(mixed $value): string
    {
        return htmlspecialchars(is_string($value) ? $value : '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
