<?php

declare(strict_types=1);

namespace MagnaCms\Blog\Editor;

/**
 * Single source of truth for the block allowlists shared by the two server-side
 * authorities that must never disagree: EditorJsSanitizer (what may be persisted)
 * and BlockRenderer (what may be emitted). Keeping these lists in one place means
 * a template, language or network can never be accepted by one side and silently
 * dropped by the other — the copy-paste drift that a per-file constant invites.
 *
 * Pure data, no behaviour: statically analysable, no runtime discovery, no magic.
 * The JavaScript editor keeps its own tool registry (client extensibility), but
 * security never depends on it — these server lists are authoritative.
 */
final class BlockSchema
{
    /**
     * Every block type the sanitizer accepts and the renderer knows how to emit.
     * Used to prove (in tests) that an unknown type can never survive persistence.
     *
     * @var list<string>
     */
    public const TYPES = [
        'paragraph', 'header', 'list', 'checklist', 'quote', 'table', 'code', 'raw',
        'delimiter', 'image', 'embed', 'warning', 'link', 'attaches', 'preformatted',
        'verse', 'spacer', 'pullquote', 'buttons', 'more', 'pageBreak', 'callout',
        'cta', 'faq', 'details', 'socialIcons', 'footnotes', 'rss', 'mediaText',
        'gallery', 'cover', 'video', 'audio', 'toc', 'relatedPosts', 'postExcerpt',
        'featuredImage', 'map', 'columns', 'group',
    ];

    /** Paragraph block templates (mirrors PARAGRAPH_TEMPLATES in paragraph.js). */
    public const PARAGRAPH_TEMPLATES = [
        'standard', 'lead', 'hero-lead', 'dropcap', 'serif-dropcap', 'pullquote', 'quote-fancy',
        'quote-minimal', 'quote-strip', 'callout', 'warning', 'takeaway', 'insight', 'card', 'glass',
        'dark', 'neon', 'dark-glass', 'gradient-border', 'pastel', 'retro', 'border-left', 'pill',
        'conclusion', 'terminal', 'ribbon', 'badge', 'timeline', 'stat', 'asymmetric', 'twocol',
        'split-line', 'image-left', 'image-right', 'photo-overlay', 'author-note',
    ];

    /** FAQ block templates (mirrors FAQ_TEMPLATES in faq.js). */
    public const FAQ_TEMPLATES = [
        'card', 'line', 'accent', 'neon', 'numbered', 'badge', 'glass', 'meta', 'gradient', 'pill',
        'elevate', 'frame', 'terminal', 'status', 'strip', 'compact', 'tag', 'light', 'bracket', 'plusminus',
    ];

    /** Syntax-highlight languages the code block accepts. */
    public const CODE_LANGUAGES = [
        'bash', 'css', 'html', 'js', 'ts', 'json', 'php', 'python', 'sql', 'yaml', 'go', 'rust', 'java',
    ];

    /** Social-icon networks (mirrors SOCIAL_NETWORKS in social-icons.js). */
    public const SOCIAL_NETWORKS = [
        'x', 'facebook', 'instagram', 'linkedin', 'youtube', 'github', 'tiktok', 'mastodon', 'email', 'rss', 'website',
    ];
}
