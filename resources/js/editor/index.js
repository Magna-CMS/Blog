import EditorJS from '@editorjs/editorjs';
import Header from '@editorjs/header';
import NestedList from '@editorjs/nested-list';
import Checklist from '@editorjs/checklist';
import Quote from '@editorjs/quote';
import Table from '@editorjs/table';
import CodeTool from './tools/code.js';
import Delimiter from './tools/delimiter.js';
import Embed from '@editorjs/embed';
import Warning from '@editorjs/warning';
import RawTool from '@editorjs/raw';
import InlineCode from '@editorjs/inline-code';
import Marker from '@editorjs/marker';
import Underline from '@editorjs/underline';
import AttachesTool from '@editorjs/attaches';
import DragDrop from 'editorjs-drag-drop';
import Undo from 'editorjs-undo';
import MediaImageTool from './media-image-tool.js';
import Preformatted from './tools/preformatted.js';
import Verse from './tools/verse.js';
import Spacer from './tools/spacer.js';
import Pullquote from './tools/pullquote.js';
import ButtonsTool from './tools/buttons.js';
import More from './tools/more.js';
import PageBreak from './tools/page-break.js';
import Callout from './tools/callout.js';
import Cta from './tools/cta.js';
import Faq, { FAQ_TEMPLATES } from './tools/faq.js';
import Details from './tools/details.js';
import SocialIcons from './tools/social-icons.js';
import { Strikethrough, Subscript, Superscript, TextColor } from './tools/inline-formats.js';
import Group from './tools/group.js';
import Footnotes from './tools/footnotes.js';
import Rss from './tools/rss.js';
import Link from './tools/link.js';
import Gallery from './tools/gallery.js';
import Cover from './tools/cover.js';
import Video from './tools/video.js';
import Audio from './tools/audio.js';
import Toc from './tools/toc.js';
import RelatedPosts from './tools/related-posts.js';
import { PostExcerpt, FeaturedImage } from './tools/dynamic-marker.js';
import MapBlock from './tools/map.js';
import EditorJsColumns from '@calumk/editorjs-columns';
import StyleTune from './tunes/style-tune.js';
import Paragraph, { PARAGRAPH_TEMPLATES } from './tools/paragraph.js';
import { registerColorPicker } from './color-picker.js';

// Paragraph templates whose decorative colour is driven by the Accent picker.
const PARAGRAPH_ACCENT = [
    'dropcap', 'serif-dropcap', 'quote-minimal', 'callout', 'warning', 'takeaway', 'insight',
    'border-left', 'neon', 'terminal', 'timeline', 'stat', 'ribbon', 'badge', 'asymmetric',
];

/**
 * Default block/inline tools, keyed by the name used in the plugin's editor
 * settings. Each entry is a valid Editor.js tool definition. Other plugins can
 * add to or override this map via `window.magnaBlog.registerTool(...)` BEFORE an
 * editor initialises — the Blog plugin never needs to be modified to gain new
 * blocks (Open/Closed).
 */
const defaultTools = {
    // Override the built-in default paragraph with our template-aware one.
    paragraph: { class: Paragraph, inlineToolbar: true },
    header: { class: Header, inlineToolbar: true, config: { levels: [2, 3, 4], defaultLevel: 2 } },
    list: { class: NestedList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
    checklist: { class: Checklist, inlineToolbar: true },
    quote: { class: Quote, inlineToolbar: true },
    table: { class: Table, inlineToolbar: true },
    code: { class: CodeTool },
    delimiter: { class: Delimiter },
    image: { class: MediaImageTool },
    embed: {
        class: Embed,
        config: {
            services: {
                youtube: true,
                vimeo: true,
                codepen: true,
                twitter: true,
                instagram: true,
                facebook: true,
                twitch: true,
                pinterest: true,
                coub: true,
                spotify: {
                    regex: /https?:\/\/open\.spotify\.com\/((?:track|album|playlist|episode|show)\/[a-zA-Z0-9]+)/,
                    embedUrl: 'https://open.spotify.com/embed/<%= remote_id %>',
                    html: "<iframe style='border-radius:12px' width='100%' height='352' frameborder='0' allow='autoplay; clipboard-write; encrypted-media; fullscreen; picture-in-picture'></iframe>",
                    height: 352,
                    width: 600,
                    id: (groups) => groups[0],
                },
                soundcloud: {
                    regex: /https?:\/\/soundcloud\.com\/([^?]+)/,
                    embedUrl: 'https://w.soundcloud.com/player/?url=https://soundcloud.com/<%= remote_id %>',
                    html: "<iframe width='100%' height='166' frameborder='0' allow='autoplay'></iframe>",
                    height: 166,
                    width: 600,
                    id: (groups) => groups[0],
                },
                tiktok: {
                    regex: /https?:\/\/www\.tiktok\.com\/@[^/]+\/video\/(\d+)/,
                    embedUrl: 'https://www.tiktok.com/embed/v2/<%= remote_id %>',
                    html: "<iframe width='100%' height='740' frameborder='0' allow='encrypted-media'></iframe>",
                    height: 740,
                    width: 340,
                    id: (groups) => groups[0],
                },
            },
        },
    },
    warning: { class: Warning, inlineToolbar: true },
    raw: { class: RawTool },
    inlineCode: { class: InlineCode },
    marker: { class: Marker },
    underline: { class: Underline },
    strikethrough: { class: Strikethrough },
    subscript: { class: Subscript },
    superscript: { class: Superscript },
    textColor: { class: TextColor },
    preformatted: { class: Preformatted },
    verse: { class: Verse },
    spacer: { class: Spacer },
    pullquote: { class: Pullquote, inlineToolbar: true },
    buttons: { class: ButtonsTool },
    more: { class: More },
    pageBreak: { class: PageBreak },
    callout: { class: Callout, inlineToolbar: true },
    cta: { class: Cta, inlineToolbar: true },
    faq: { class: Faq, inlineToolbar: true },
    details: { class: Details, inlineToolbar: true },
    socialIcons: { class: SocialIcons },
    // "Media & text" removed from the block menu: the Image and Text (paragraph)
    // blocks — or a two-column Group — cover the same need. The renderer and
    // sanitiser still handle the `mediaText` type so any existing posts keep
    // rendering; it just can't be inserted anew.
    footnotes: { class: Footnotes, inlineToolbar: true },
    rss: { class: Rss },
    link: { class: Link },
    gallery: { class: Gallery },
    cover: { class: Cover, inlineToolbar: true },
    video: { class: Video },
    audio: { class: Audio },
    toc: { class: Toc },
    relatedPosts: { class: RelatedPosts },
    postExcerpt: { class: PostExcerpt },
    featuredImage: { class: FeaturedImage },
    map: { class: MapBlock },
};

// Columns is a layout block: each column hosts its own child Editor.js instance
// with a curated subset of tools (never Columns itself, to avoid infinite
// nesting). Registered after defaultTools so it can reference those definitions.
const columnToolNames = ['header', 'list', 'checklist', 'quote', 'image', 'callout', 'buttons', 'delimiter', 'marker', 'inlineCode'];
const columnTools = {};
columnToolNames.forEach((name) => {
    if (defaultTools[name]) {
        columnTools[name] = defaultTools[name];
    }
});
defaultTools.columns = {
    class: EditorJsColumns,
    config: { EditorJsLibrary: EditorJS, tools: columnTools },
};

// Group hosts a single nested Editor.js document with the same curated child
// tools (never Group or Columns, so nesting stays bounded).
defaultTools.group = {
    class: Group,
    config: { EditorJS, tools: columnTools },
};

const registry = {
    tools: { ...defaultTools },

    // Global font list (populated from the server registry on editor init).
    fonts: [],

    /** Register or override a tool. Call before an editor mounts. */
    registerTool(name, definition) {
        this.tools[name] = definition;
    },

    /** CSS font stack for a font key ('' → default/inherit). */
    fontStack(key) {
        const font = (this.fonts || []).find((f) => f.key === key);
        return font ? font.stack : '';
    },
};

// Public extension point.
window.magnaBlog = window.magnaBlog || registry;

// Paragraph template gallery (rendered in the Block panel when a paragraph is
// selected). Other plugins can extend this list.
window.magnaBlog.paragraphTemplates = window.magnaBlog.paragraphTemplates || PARAGRAPH_TEMPLATES;

// FAQ template gallery (rendered in the Block panel when an FAQ block is
// selected). Other plugins can extend this list.
window.magnaBlog.faqTemplates = window.magnaBlog.faqTemplates || FAQ_TEMPLATES;

/**
 * Declarative "Block settings" schemas, keyed by block type. Each field edits a
 * key in the block's saved data; the sidebar Block tab renders these controls
 * and writes changes back via editor.blocks.update(). Other plugins can extend
 * this map. Only scalar settings that already live in a block's data are exposed
 * here — richer text styling (colour / size) arrives with the Block Style tune.
 */
window.magnaBlog.inspectors = window.magnaBlog.inspectors || {
    image: [
        { key: 'align', label: 'Alignment', type: 'segmented', options: { '': 'Default', left: 'Left', center: 'Center', right: 'Right' } },
        { key: 'width', label: 'Width', type: 'select', options: { full: 'Full width', large: 'Large', medium: 'Medium', small: 'Small' } },
        { key: 'rounded', label: 'Rounded corners', type: 'toggle' },
        { key: 'alt', label: 'Alt text (accessibility)', type: 'text', placeholder: 'Describe the image' },
        { key: 'linkUrl', label: 'Link URL', type: 'text', placeholder: 'https://…' },
    ],
    gallery: [
        { key: 'columns', label: 'Columns', type: 'segmented', options: { 2: '2', 3: '3', 4: '4', 5: '5' } },
        { key: 'gap', label: 'Gap', type: 'range', min: 0, max: 40, step: 1 },
        { key: 'crop', label: 'Crop', type: 'select', options: { '': 'Original', square: 'Square', '4-3': '4 : 3', '16-9': '16 : 9' } },
        { key: 'rounded', label: 'Rounded corners', type: 'toggle' },
    ],
    cover: [
        { key: 'height', label: 'Height', type: 'segmented', options: { small: 'S', medium: 'M', large: 'L' } },
        { key: 'overlay', label: 'Overlay darkness', type: 'range', min: 0, max: 100, step: 5 },
        { key: 'align', label: 'Text alignment', type: 'segmented', options: { left: 'Left', center: 'Center', right: 'Right' } },
    ],
    video: [
        { key: 'controls', label: 'Show controls', type: 'toggle' },
        { key: 'autoplay', label: 'Autoplay', type: 'toggle' },
        { key: 'loop', label: 'Loop', type: 'toggle' },
        { key: 'muted', label: 'Muted', type: 'toggle' },
    ],
    audio: [
        { key: 'controls', label: 'Show controls', type: 'toggle' },
        { key: 'autoplay', label: 'Autoplay', type: 'toggle' },
        { key: 'loop', label: 'Loop', type: 'toggle' },
    ],
    cta: [
        { key: 'align', label: 'Alignment', type: 'segmented', options: { left: 'Left', center: 'Center', right: 'Right' } },
        { key: 'background', label: 'Card background', type: 'color', clearable: true },
        { key: 'buttonType', label: 'Button style', type: 'segmented', options: { filled: 'Filled', outline: 'Outline' } },
        { key: 'buttonColor', label: 'Button colour', type: 'color', clearable: false },
    ],
    faq: [
        { key: 'openFirst', label: 'Open first item', type: 'toggle' },
        { key: 'schema', label: 'Add FAQ schema (SEO)', type: 'toggle' },
    ],
    details: [
        { key: 'open', label: 'Open by default', type: 'toggle' },
    ],
    rss: [
        { key: 'count', label: 'How many items', type: 'range', min: 1, max: 20, step: 1 },
        { key: 'showDate', label: 'Show dates', type: 'toggle' },
    ],
    link: [
        { key: 'newTab', label: 'Open in new tab', type: 'toggle' },
        { key: 'nofollow', label: 'Add rel="nofollow"', type: 'toggle' },
    ],
    socialIcons: [
        { key: 'style', label: 'Colour', type: 'segmented', options: { brand: 'Brand', mono: 'Mono', outline: 'Outline' } },
        { key: 'shape', label: 'Shape', type: 'segmented', options: { rounded: 'Rounded', square: 'Square', circle: 'Circle' } },
        { key: 'size', label: 'Size', type: 'segmented', options: { sm: 'S', md: 'M', lg: 'L' } },
        { key: 'align', label: 'Alignment', type: 'segmented', options: { left: 'Left', center: 'Center', right: 'Right' } },
    ],
    group: [
        { key: 'layout', label: 'Layout', type: 'segmented', options: { stack: 'Stack', row: 'Row', grid: 'Grid' } },
        { key: 'columns', label: 'Grid columns', type: 'segmented', options: { 2: '2', 3: '3', 4: '4' } },
        { key: 'gap', label: 'Gap', type: 'range', min: 0, max: 48, step: 1 },
        { key: 'padding', label: 'Padding', type: 'range', min: 0, max: 64, step: 1 },
        { key: 'radius', label: 'Corner radius', type: 'range', min: 0, max: 40, step: 1 },
        { key: 'background', label: 'Background', type: 'color', clearable: true },
        { key: 'align', label: 'Align', type: 'segmented', options: { '': 'Default', left: 'Left', center: 'Center', right: 'Right' } },
    ],
    delimiter: [
        { key: 'style', label: 'Style', type: 'segmented', options: { dots: 'Dots', line: 'Line', dashed: 'Dashed', asterisks: 'Stars' } },
    ],
    code: [
        { key: 'language', label: 'Language', type: 'select', options: { '': 'Plain', bash: 'Bash', css: 'CSS', html: 'HTML', js: 'JavaScript', ts: 'TypeScript', json: 'JSON', php: 'PHP', python: 'Python', sql: 'SQL', yaml: 'YAML', go: 'Go', rust: 'Rust', java: 'Java' } },
    ],
    table: [
        { key: 'withHeadings', label: 'Header row', type: 'toggle' },
    ],
    header: [
        { key: 'level', label: 'Heading level', type: 'select', options: { 2: 'Heading 2', 3: 'Heading 3', 4: 'Heading 4' } },
    ],
    // Quote has no block-specific settings: alignment is handled by the universal
    // Style tune (align), so a quote-only "Alignment" here would just duplicate it.
    quote: [],
    callout: [
        { key: 'type', label: 'Style', type: 'segmented', options: { info: 'Info', success: 'Success', warning: 'Warning', danger: 'Danger' } },
        { key: 'icon', label: 'Show icon', type: 'toggle' },
    ],
    spacer: [
        { key: 'height', label: 'Height', type: 'range', min: 8, max: 160, step: 4 },
        { key: 'divider', label: 'Divider line', type: 'toggle' },
    ],
    list: [
        { key: 'style', label: 'List style', type: 'select', options: { unordered: 'Bulleted', ordered: 'Numbered' } },
    ],
    toc: [
        { key: 'depth', label: 'Depth', type: 'select', options: { 2: 'H2 only', 3: 'H2–H3', 4: 'H2–H4' } },
        { key: 'ordered', label: 'Numbered', type: 'toggle' },
    ],
    relatedPosts: [
        { key: 'count', label: 'How many', type: 'range', min: 1, max: 12, step: 1 },
        { key: 'by', label: 'Related by', type: 'segmented', options: { category: 'Category', tag: 'Tag' } },
        { key: 'layout', label: 'Layout', type: 'segmented', options: { grid: 'Grid', list: 'List' } },
        { key: 'showImage', label: 'Show images', type: 'toggle' },
    ],
};

/**
 * Universal "Style" controls shown for every block in the Block tab. These edit
 * the block's Style tune (alignment / text colour / font size), stored as safe
 * TOKENS and validated server-side. Values are written through
 * window.magnaBlogTunes rather than block data. Extendable by other plugins.
 */
window.magnaBlog.styleInspector = window.magnaBlog.styleInspector || [
    { key: 'align', label: 'Alignment', type: 'select', options: { '': 'Default', left: 'Left', center: 'Center', right: 'Right' } },
    { key: 'color', label: 'Text colour', type: 'select', options: { '': 'Default', slate: 'Slate', red: 'Red', orange: 'Orange', green: 'Green', blue: 'Blue', violet: 'Violet' } },
    { key: 'fontSize', label: 'Font size', type: 'select', options: { '': 'Default', sm: 'Small', base: 'Normal', lg: 'Large', xl: 'Extra large' } },
];

/**
 * Which universal Style controls (align / color / bg / fontSize / font) each block
 * type can actually honour. A block NOT listed here supports them all (the default
 * for text blocks). Listed blocks get a curated subset — media/structural blocks
 * expose none (they own their presentation via their own inspector), and fixed
 * formatting blocks (preformatted / verse) expose only alignment, since a text
 * colour / font / size would contradict their monospace-or-serif definition or
 * simply never reach the element. Keeps the panel free of controls that do
 * nothing. Extendable by other plugins.
 */
window.magnaBlog.styleSupports = window.magnaBlog.styleSupports || {
    // Media & structural blocks: no text styling (own inspector controls them).
    image: [], gallery: [], video: [], audio: [], embed: [], map: [],
    delimiter: [], spacer: [], more: [], pageBreak: [],
    rss: [], toc: [], relatedPosts: [], postExcerpt: [], featuredImage: [],
    code: [], buttons: [],
    socialIcons: ['align'],
    // Cover has its own text-alignment control in its inspector; the universal
    // Style alignment does nothing useful on it, so hide the whole Style group.
    cover: [],
    // CTA has its own Alignment + Card background in its inspector, so drop those
    // two from the universal Style panel to avoid duplicate controls.
    cta: ['color', 'fontSize', 'font'],
    // Raw HTML: alignment / text colour / background wrap the rendered output
    // (font / size belong in the author's own HTML).
    raw: ['align', 'color', 'bg'],
    // Fixed-format text: only alignment applies; monospace / serif is intrinsic.
    preformatted: ['align'], verse: ['align'],
};

/**
 * Build the Editor.js `tools` config from the registry, limited to the names
 * enabled in settings. Unknown names are skipped so a stale setting can never
 * break initialisation.
 */
function resolveTools(enabled) {
    const source = window.magnaBlog.tools || defaultTools;

    if (!Array.isArray(enabled) || enabled.length === 0) {
        return { ...source };
    }

    const tools = {};
    enabled.forEach((name) => {
        if (source[name]) {
            tools[name] = source[name];
        }
    });

    return tools;
}

/**
 * Publish the server font list to the registry and load the actual faces once:
 * a combined Google Fonts stylesheet + @font-face rules for uploaded fonts, so
 * every font in the picker renders live in the editor.
 */
function injectFonts(config) {
    if (Array.isArray(config.fonts)) {
        window.magnaBlog.fonts = config.fonts;
    }
    if (window.magnaBlogFontsInjected) {
        return;
    }
    window.magnaBlogFontsInjected = true;

    if (config.googleFontsHref) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = config.googleFontsHref;
        document.head.appendChild(link);
    }

    const custom = (window.magnaBlog.fonts || []).filter((f) => f.url);
    if (custom.length) {
        const css = custom.map((f) =>
            `@font-face{font-family:'MagnaFont-${f.key}';font-display:swap;src:url('${f.url}') format('${f.format || 'woff2'}');}`,
        ).join('');
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
    }
}

function normaliseData(value) {
    if (value && typeof value === 'object' && Array.isArray(value.blocks)) {
        return value;
    }

    return { blocks: [] };
}

/**
 * Inject the tools that need server endpoints (link preview, file attaches).
 * They are only added when their endpoint is provided and the tool is enabled,
 * so the editor still works if the backend routes are unavailable.
 */
function withServerTools(tools, config) {
    const enabled = Array.isArray(config.tools) ? config.tools : [];
    const isEnabled = (name) => enabled.length === 0 || enabled.includes(name);

    // Link keeps its class registered even without an endpoint (the link still
    // saves and renders, just without an Open Graph preview card); inject the
    // preview endpoint when it's available and the tool is enabled.
    if (config.linkEndpoint && isEnabled('link') && tools.link) {
        tools.link = { class: tools.link.class, config: { endpoint: config.linkEndpoint } };
    }

    if (config.attachesEndpoint && isEnabled('attaches')) {
        tools.attaches = {
            class: AttachesTool,
            config: {
                endpoint: config.attachesEndpoint,
                field: 'file',
                additionalRequestHeaders: config.csrfToken ? { 'X-CSRF-TOKEN': config.csrfToken } : {},
            },
        };
    }

    // RSS needs its fetch endpoint injected; keep the class registered even
    // without it (renders stored items, just can't refresh) so old content still
    // shows if the route is unavailable.
    if (config.rssEndpoint && isEnabled('rss') && tools.rss) {
        tools.rss = { class: tools.rss.class, config: { endpoint: config.rssEndpoint } };
    }

    return tools;
}

function registerAlpineComponent() {
    if (!window.Alpine) {
        return;
    }

    registerColorPicker();

    window.Alpine.data('magnaBlogEditor', (config) => ({
        editor: null,
        saveTimer: null,

        init() {
            // Publish the font list + load faces (Google + uploaded) once.
            injectFonts(config);

            // Read the stored document straight from Livewire so the editor
            // hydrates existing content on edit.
            const initial = this.$wire.get(config.statePath);

            this.editor = new EditorJS({
                holder: config.holderId,
                readOnly: Boolean(config.readOnly),
                placeholder: config.placeholder || 'Click here, or press Tab / the + button to add a block…',
                minHeight: 240,
                data: normaliseData(initial),
                tools: {
                    ...withServerTools(resolveTools(config.tools), config),
                    style: { class: StyleTune },
                },
                tunes: ['style'],
                onChange: () => this.scheduleSave(),
                onReady: () => {
                    if (config.readOnly) {
                        return;
                    }
                    // Undo/redo history (Ctrl/Cmd+Z, Ctrl/Cmd+Y) and block drag-and-drop
                    // reordering — the interactions that make it feel like the demo.
                    try {
                        new Undo({ editor: this.editor });
                        new DragDrop(this.editor);
                    } catch (error) {
                        console.error('Editor.js UX plugins failed to initialise', error);
                    }
                },
            });

            // Expose the active editor for the Block-settings inspector, and
            // publish the selected block (type + settings) on caret/click so the
            // sidebar's Block tab can render its settings.
            window.magnaBlogEditorApi = this.editor;
            this.track = async (event) => {
                try {
                    // Clicks inside a Buttons block (including one nested in a
                    // Columns child editor) are owned by that tool, which fires
                    // its own button-selected event. Skip here so we don't clobber
                    // it with the parent block's selection (e.g. the Columns block).
                    if (event && event.target && typeof event.target.closest === 'function'
                        && event.target.closest('.magna-blog-buttons')) {
                        return;
                    }
                    const index = this.editor.blocks.getCurrentBlockIndex();
                    if (index < 0) {
                        return;
                    }
                    const block = this.editor.blocks.getBlockByIndex(index);
                    if (!block) {
                        return;
                    }
                    const schema = (window.magnaBlog.inspectors || {})[block.name] || null;
                    let values = {};
                    if (schema) {
                        const saved = await block.save();
                        values = saved && saved.data ? saved.data : {};
                    }
                    // Style tune values live outside block data — read them from
                    // the live tune instance registered for this block.
                    const tune = (window.magnaBlogTunes || {})[block.id];
                    const styleValues = tune ? { ...tune.data } : {};
                    document.dispatchEvent(new CustomEvent('magna-blog:block-selected', {
                        detail: {
                            type: block.name,
                            schema,
                            values,
                            styleSchema: window.magnaBlog.styleInspector || [],
                            styleValues,
                            blockId: block.id,
                        },
                    }));
                } catch (error) {
                    // Selection tracking is best-effort.
                }
            };
            this.$el.addEventListener('click', this.track);
            this.$el.addEventListener('keyup', this.track);

            // Flush the editor's current content to Livewire on demand, so the
            // Save Draft / Publish / autosave flow never persists a stale document
            // when the user saves before the debounce fires.
            this.onFlush = async () => {
                await this.sync();
                document.dispatchEvent(new CustomEvent('magna-blog:flushed'));
            };
            window.addEventListener('magna-blog:flush', this.onFlush);

            // Changes made outside Editor.js (e.g. the sidebar Style tune) don't
            // trigger onChange; this lets them schedule a debounced save too.
            this.onChanged = () => this.scheduleSave();
            window.addEventListener('magna-blog:changed', this.onChanged);

            // Release the editor when the page SPA-navigates away. Livewire fires
            // livewire:navigating on `document` (the event does not bubble down to
            // this element), matching admin-nav.js. Store the handler so destroy()
            // can detach it — otherwise the listener (and the orphaned editor it
            // closes over) would leak on every return to the builder.
            this.onNavigating = () => this.destroy();
            document.addEventListener('livewire:navigating', this.onNavigating);
        },

        scheduleSave() {
            if (config.readOnly) {
                return;
            }

            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.sync(), 500);
        },

        async sync() {
            if (!this.editor) {
                return;
            }

            try {
                const data = await this.editor.save();
                // Update Livewire locally (no request); the value rides along on
                // the next Save Draft / Publish / autosave call.
                this.$wire.set(config.statePath, data, false);
            } catch (error) {
                console.error('Editor.js save failed', error);
            }
        },

        destroy() {
            clearTimeout(this.saveTimer);
            if (this.onNavigating) {
                document.removeEventListener('livewire:navigating', this.onNavigating);
                this.onNavigating = null;
            }
            if (this.onFlush) {
                window.removeEventListener('magna-blog:flush', this.onFlush);
            }
            if (this.onChanged) {
                window.removeEventListener('magna-blog:changed', this.onChanged);
            }
            if (this.track) {
                this.$el.removeEventListener('click', this.track);
                this.$el.removeEventListener('keyup', this.track);
            }
            if (window.magnaBlogEditorApi === this.editor) {
                window.magnaBlogEditorApi = null;
            }
            if (this.editor && typeof this.editor.destroy === 'function') {
                this.editor.destroy();
                this.editor = null;
            }
        },
    }));

    // Sidebar Block-settings inspector: reflects the selected block and writes
    // changes back to the editor. Runs entirely client-side for instant feel.
    window.Alpine.data('magnaBlogSidebar', () => ({
        // mode: 'generic' (scalar fields + style tune) or 'buttons' (button studio)
        mode: 'generic',
        tab: 'general',
        block: null,
        values: {},
        styleValues: {},
        // Paragraph template state.
        paraTemplate: 'standard',
        paraNeedsImage: false,
        paraNeedsLabel: false,
        paraUsesAccent: false,
        paraLabel: '',
        paraImageAlt: '',
        // FAQ template state.
        faqTemplate: 'card',
        // Button studio state.
        button: {},
        buttonIndex: 0,
        buttonBlockId: null,
        blockOpts: {},

        init() {
            this.onSelected = (event) => {
                const detail = event.detail || {};
                if (detail.type === 'buttons') {
                    // The dedicated button-selected event carries the active
                    // button; here we just switch mode and open the Block tab.
                    this.mode = 'buttons';
                    this.buttonBlockId = detail.blockId;
                    this.tab = 'block';
                    return;
                }
                this.mode = 'generic';
                this.block = {
                    type: detail.type,
                    schema: detail.schema || [],
                    styleSchema: detail.styleSchema || [],
                    id: detail.blockId,
                };
                this.values = { ...detail.values };
                this.styleValues = { ...detail.styleValues };

                // Seed the paragraph template gallery from the live block.
                if (detail.type === 'paragraph') {
                    const inst = (window.magnaBlogBlocks || {})[detail.blockId];
                    this.paraTemplate = (inst && inst.data && inst.data.template) || 'standard';
                    this.paraLabel = (inst && inst.data && inst.data.label) || '';
                    this.paraImageAlt = (inst && inst.data && inst.data.imageAlt) || '';
                    const meta = (PARAGRAPH_TEMPLATES.find((t) => t.key === this.paraTemplate)) || {};
                    this.paraNeedsImage = !!meta.image;
                    this.paraNeedsLabel = !!meta.needsLabel;
                    this.paraUsesAccent = PARAGRAPH_ACCENT.includes(this.paraTemplate);
                }

                // Seed the FAQ template gallery from the live block.
                if (detail.type === 'faq') {
                    const inst = (window.magnaBlogBlocks || {})[detail.blockId];
                    this.faqTemplate = (inst && inst.template) || 'card';
                }

                this.tab = 'block';
            };
            this.onButton = (event) => {
                const detail = event.detail || {};
                this.mode = 'buttons';
                this.buttonBlockId = detail.blockId;
                this.buttonIndex = detail.index || 0;
                this.button = { ...detail.button };
                this.blockOpts = { align: detail.align || 'left', gap: detail.gap ?? 8 };
                this.tab = 'block';
            };
            document.addEventListener('magna-blog:block-selected', this.onSelected);
            document.addEventListener('magna-blog:button-selected', this.onButton);
            // Detach on SPA navigation. livewire:navigating fires on `document`,
            // not on this element, so the teardown must listen there too — and
            // remove itself — or these document listeners would stack on every
            // return to the builder.
            this.onNavigating = () => {
                document.removeEventListener('magna-blog:block-selected', this.onSelected);
                document.removeEventListener('magna-blog:button-selected', this.onButton);
                document.removeEventListener('livewire:navigating', this.onNavigating);
            };
            document.addEventListener('livewire:navigating', this.onNavigating);
        },

        label(type) {
            return String(type || '').replace(/([A-Z])/g, ' $1').replace(/^./, (c) => c.toUpperCase());
        },

        /** Whether a universal Style control applies to the selected block type. */
        styleAllows(key) {
            const map = (window.magnaBlog && window.magnaBlog.styleSupports) || {};
            const type = this.block && this.block.type;
            if (!type || !(type in map)) {
                return true; // Unlisted blocks support the full Style panel.
            }
            return map[type].includes(key);
        },

        /** Whether the Style group has any control worth showing for this block. */
        styleAllowsAny() {
            return ['align', 'color', 'bg', 'fontSize', 'font'].some((k) => this.styleAllows(k));
        },

        /**
         * Write a block-data setting. Blocks that register a live instance
         * (with setProp) are mutated directly — instant, and no re-instantiation
         * desync; the rest fall back to editor.blocks.update().
         */
        apply(key, value) {
            const coerced = typeof value === 'string' && /^\d+$/.test(value) ? Number(value) : value;
            this.values[key] = coerced;
            const inst = this.block ? (window.magnaBlogBlocks || {})[this.block.id] : null;
            if (inst && typeof inst.setProp === 'function') {
                inst.setProp(key, coerced);
                return;
            }
            if (window.magnaBlogEditorApi && this.block) {
                window.magnaBlogEditorApi.blocks.update(this.block.id, { ...this.values });
            }
        },

        /** Apply a paragraph template via its live instance. */
        setTemplate(key) {
            this.paraTemplate = key;
            const meta = (PARAGRAPH_TEMPLATES.find((t) => t.key === key)) || {};
            this.paraNeedsImage = !!meta.image;
            this.paraNeedsLabel = !!meta.needsLabel;
            this.paraUsesAccent = PARAGRAPH_ACCENT.includes(key);
            const inst = this.block ? (window.magnaBlogBlocks || {})[this.block.id] : null;
            if (inst) {
                inst.setProp('template', key);
                this.paraLabel = (inst.data && inst.data.label) || '';
            }
        },

        /** Apply an FAQ template via its live instance. */
        setFaqTemplate(key) {
            this.faqTemplate = key;
            const inst = this.block ? (window.magnaBlogBlocks || {})[this.block.id] : null;
            if (inst && typeof inst.setProp === 'function') {
                inst.setProp('template', key);
            }
        },

        setParaLabel(value) {
            this.paraLabel = value;
            const inst = this.block ? (window.magnaBlogBlocks || {})[this.block.id] : null;
            if (inst) {
                inst.setProp('label', value);
            }
        },

        setParaImageAlt(value) {
            this.paraImageAlt = value;
            const inst = this.block ? (window.magnaBlogBlocks || {})[this.block.id] : null;
            if (inst) {
                inst.setProp('imageAlt', value);
            }
        },

        /** Open the media picker for the paragraph's image template. */
        pickParaImage() {
            const inst = this.block ? (window.magnaBlogBlocks || {})[this.block.id] : null;
            if (inst) {
                inst.setProp('pickImage');
            }
        },

        /** Write a Style-tune setting through the live tune instance. */
        applyStyle(key, value) {
            this.styleValues[key] = value;
            const tune = this.block ? (window.magnaBlogTunes || {})[this.block.id] : null;
            if (tune) {
                tune.set(key, value);
                // Tune mutations don't fire the editor's onChange, so nudge a
                // save ourselves to capture the new style on the next flush.
                window.dispatchEvent(new CustomEvent('magna-blog:changed'));
            }
        },

        // --- Button studio --------------------------------------------------

        buttonBlock() {
            return (window.magnaBlogBlocks || {})[this.buttonBlockId] || null;
        },

        setButton(key, value) {
            this.button[key] = value;
            const block = this.buttonBlock();
            if (block) {
                block.setButtonProp(this.buttonIndex, key, value);
            }
        },

        toggleButton(key) {
            this.setButton(key, !this.button[key]);
        },

        setBlockOpt(key, value) {
            this.blockOpts[key] = value;
            const block = this.buttonBlock();
            if (block) {
                block.setBlockProp(key, value);
            }
        },

        addButton() {
            const block = this.buttonBlock();
            if (block) {
                // appendButton() dispatches button-selected, which refreshes
                // this component's active-button state via onButton.
                block.appendButton();
            }
        },
    }));
}

document.addEventListener('alpine:init', registerAlpineComponent);

// Alpine may already be running when this asset loads late (Filament SPA).
if (window.Alpine) {
    registerAlpineComponent();
}
