/**
 * Paragraph — replaces the default Editor.js paragraph. Keeps ordinary typing
 * (Enter splits, Backspace merges) but adds a rich "template" (a visual style),
 * an optional image (for image templates) and an optional short label (used as
 * the ribbon / badge / stat / step / banner text on the templates that show
 * one). Templates are chosen from the sidebar Block panel's gallery; colours,
 * font and background are customised through the Style tune.
 */
export const PARAGRAPH_TEMPLATES = [
    { key: 'standard', label: 'Standard' },
    { key: 'lead', label: 'Lead' },
    { key: 'hero-lead', label: 'Gradient lead' },
    { key: 'dropcap', label: 'Drop cap' },
    { key: 'serif-dropcap', label: 'Serif drop cap' },
    { key: 'pullquote', label: 'Pull quote' },
    { key: 'quote-fancy', label: 'Fancy quote' },
    { key: 'quote-minimal', label: 'Minimal quote' },
    { key: 'quote-strip', label: 'Quote strip' },
    { key: 'callout', label: 'Callout' },
    { key: 'warning', label: 'Warning' },
    { key: 'takeaway', label: 'Key takeaway', needsLabel: true, defaultLabel: 'Key takeaway' },
    { key: 'insight', label: 'Insight', needsLabel: true, defaultLabel: 'Key insight' },
    { key: 'card', label: 'Card' },
    { key: 'glass', label: 'Glass card' },
    { key: 'dark', label: 'Dark card' },
    { key: 'neon', label: 'Neon glow' },
    { key: 'dark-glass', label: 'Dark glass' },
    { key: 'gradient-border', label: 'Gradient border' },
    { key: 'pastel', label: 'Pastel' },
    { key: 'retro', label: 'Retro' },
    { key: 'border-left', label: 'Left border' },
    { key: 'pill', label: 'Pill' },
    { key: 'conclusion', label: 'Conclusion' },
    { key: 'terminal', label: 'Terminal' },
    { key: 'ribbon', label: 'Ribbon', needsLabel: true, defaultLabel: 'Featured' },
    { key: 'badge', label: 'Badge', needsLabel: true, defaultLabel: 'Update' },
    { key: 'timeline', label: 'Timeline step', needsLabel: true, defaultLabel: '1' },
    { key: 'stat', label: 'Stat', needsLabel: true, defaultLabel: '94%' },
    { key: 'asymmetric', label: 'Split banner', needsLabel: true, defaultLabel: 'Pro tip' },
    { key: 'twocol', label: 'Two columns' },
    { key: 'split-line', label: 'Split + divider' },
    { key: 'image-left', label: 'Image left', image: true },
    { key: 'image-right', label: 'Image right', image: true },
    { key: 'photo-overlay', label: 'Photo overlay', image: true },
    { key: 'author-note', label: 'Author note', image: true },
];

const BY_KEY = Object.fromEntries(PARAGRAPH_TEMPLATES.map((t) => [t.key, t]));
const KEYS = PARAGRAPH_TEMPLATES.map((t) => t.key);
const IMAGE_TEMPLATES = PARAGRAPH_TEMPLATES.filter((t) => t.image).map((t) => t.key);
const LABEL_TEMPLATES = PARAGRAPH_TEMPLATES.filter((t) => t.needsLabel).map((t) => t.key);

export default class Paragraph {
    static get toolbox() {
        return {
            title: 'Text',
            icon: '<svg width="16" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="14" y2="18"/></svg>',
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    static get conversionConfig() {
        return { export: 'text', import: 'text' };
    }

    static get sanitize() {
        return {
            text: { br: true, b: true, i: true, a: { href: true }, mark: true, code: true, u: true },
            template: false, image: false, imageAlt: false, label: false,
        };
    }

    /**
     * Tell Editor.js this tool accepts pasted <p> content. Without a pasteConfig
     * the paste module preventDefaults the paste but has no `onPaste` to hand the
     * content to, so pasting silently does nothing. Mirrors the stock paragraph.
     */
    static get pasteConfig() {
        return { tags: ['P'] };
    }

    /**
     * Receive pasted content (from another block, the browser, or an external
     * site) and drop it into the text field. Editor.js has already sanitised it
     * per `sanitize()` above.
     */
    onPaste(event) {
        const pasted = event && event.detail && event.detail.data;
        const html = pasted
            ? (typeof pasted.innerHTML === 'string' ? pasted.innerHTML : (pasted.textContent || ''))
            : '';
        this.data.text = html;
        const apply = () => {
            if (this.text) {
                this.text.innerHTML = this.data.text || '';
            }
        };
        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(apply);
        } else {
            apply();
        }
    }

    constructor({ data, block, readOnly }) {
        this.block = block;
        this.readOnly = Boolean(readOnly);
        this.data = {
            text: (data && typeof data.text === 'string') ? data.text : '',
            template: KEYS.includes(data && data.template) ? data.template : 'standard',
            image: (data && typeof data.image === 'string') ? data.image : '',
            imageAlt: (data && typeof data.imageAlt === 'string') ? data.imageAlt : '',
            label: (data && typeof data.label === 'string') ? data.label : '',
        };
        this.pickerTarget = 'paragraph-' + Math.random().toString(36).slice(2);
        this.onSelected = this.onSelected.bind(this);
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-p');

        this.text = document.createElement('div');
        this.text.classList.add('magna-blog-p__text');
        this.text.contentEditable = String(!this.readOnly);
        this.text.dataset.placeholder = 'Text';
        this.text.innerHTML = this.data.text;
        this.text.addEventListener('input', () => {
            this.data.text = this.text.innerHTML;
        });

        this.media = document.createElement('div');
        this.media.classList.add('magna-blog-p__media');
        this.media.contentEditable = 'false';

        // The label (badge / ribbon / stat / step text) is edited inline right
        // in the canvas so nothing on a template is hardcoded.
        this.labelEl = document.createElement('span');
        this.labelEl.classList.add('magna-blog-p__label');
        this.labelEl.contentEditable = String(!this.readOnly);
        this.labelEl.dataset.placeholder = 'Label';
        this.labelEl.addEventListener('input', () => {
            this.data.label = this.labelEl.textContent || '';
        });
        // Keep the label single-line; Enter should not split it into a block.
        this.labelEl.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                this.labelEl.blur();
            }
        });

        // Text FIRST so Editor.js treats it as the block's editable input.
        this.wrapper.append(this.text, this.media, this.labelEl);
        this.applyTemplate();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    applyTemplate() {
        const meta = BY_KEY[this.data.template] || BY_KEY.standard;
        this.wrapper.dataset.template = this.data.template;

        const needsImage = IMAGE_TEMPLATES.includes(this.data.template);
        this.media.style.display = needsImage ? '' : 'none';
        this.media.innerHTML = '';
        if (needsImage) {
            if (this.data.image) {
                const img = document.createElement('img');
                img.src = this.data.image;
                img.alt = this.data.imageAlt || '';
                this.media.append(img);
            } else {
                const ph = document.createElement('div');
                ph.classList.add('magna-blog-p__placeholder');
                ph.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>Add image</span>';
                this.media.append(ph);
            }
            if (! this.readOnly) {
                this.media.style.cursor = 'pointer';
                this.media.onclick = () => this.openPicker();
            }
        }

        const needsLabel = LABEL_TEMPLATES.includes(this.data.template);
        this.labelEl.style.display = needsLabel ? '' : 'none';
        this.labelEl.textContent = needsLabel ? (this.data.label || meta.defaultLabel || '') : '';
    }

    // --- Sidebar inspector API -------------------------------------------
    setProp(key, value) {
        if (key === 'template') {
            const next = KEYS.includes(value) ? value : 'standard';
            // Seed a sensible default label when switching to a labelled template.
            if (LABEL_TEMPLATES.includes(next) && ! this.data.label) {
                this.data.label = (BY_KEY[next].defaultLabel) || '';
            }
            this.data.template = next;
        } else if (key === 'image') {
            this.data.image = typeof value === 'string' ? value : '';
        } else if (key === 'label') {
            this.data.label = typeof value === 'string' ? value : '';
        } else if (key === 'imageAlt') {
            this.data.imageAlt = typeof value === 'string' ? value : '';
        } else if (key === 'pickImage') {
            this.openPicker();
            return;
        }
        this.applyTemplate();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    openPicker() {
        if (typeof window.Livewire === 'undefined') {
            return;
        }
        window.Livewire.on('magna:media-selected', this.onSelected);
        window.Livewire.dispatch('magna:open-media-picker', { target: this.pickerTarget });
    }

    onSelected(payload) {
        const detail = Array.isArray(payload) ? payload[0] : payload;
        if (! detail || detail.target !== this.pickerTarget) {
            return;
        }
        this.data.image = detail.url || '';
        this.applyTemplate();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    merge(data) {
        this.data.text += (data && data.text) || '';
        this.text.innerHTML = this.data.text;
    }

    save() {
        return {
            text: this.text.innerHTML,
            template: this.data.template,
            image: this.data.image,
            imageAlt: this.data.imageAlt,
            label: this.data.label,
        };
    }
}
