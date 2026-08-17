/** Black or white text for readable contrast on a hex background. */
function contrastText(hex) {
    const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
    if (!m) {
        return '';
    }
    const n = parseInt(m[1], 16);
    const r = (n >> 16) & 255;
    const g = (n >> 8) & 255;
    const b = n & 255;
    // Perceived luminance.
    const lum = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
    return lum > 0.6 ? '#111827' : '#ffffff';
}

/** Call to action — heading, supporting text, and a single button. */
export default class Cta {
    static get toolbox() {
        return {
            title: 'Call to action',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="6" width="18" height="12" rx="2"/><line x1="7" y1="12" x2="13" y2="12"/><polyline points="15 9 18 12 15 15"/></svg>',
        };
    }

    constructor({ data, block }) {
        this.data = {
            title: (data && data.title) || '',
            text: (data && data.text) || '',
            buttonLabel: (data && data.buttonLabel) || '',
            buttonUrl: (data && data.buttonUrl) || '',
            align: (data && data.align) || 'center',
            background: (data && data.background) || '',
            buttonType: (data && data.buttonType) || 'filled',
            buttonColor: (data && data.buttonColor) || '#6366f1',
        };
        this.block = block;
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-cta');

        this.titleEl = this.editable('magna-blog-cta__title', 'Heading', this.data.title, false);
        this.textEl = this.editable('magna-blog-cta__text', 'Supporting text…', this.data.text, true);

        const controls = document.createElement('div');
        controls.classList.add('magna-blog-cta__controls');
        controls.contentEditable = 'false';

        this.labelInput = this.input('Button label', this.data.buttonLabel);
        this.urlInput = this.input('https://…', this.data.buttonUrl);
        controls.append(this.labelInput, this.urlInput);
        this.controls = controls;

        this.wrapper.append(this.titleEl, this.textEl, controls);
        this.applyLayout();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    applyLayout() {
        const justify = { left: 'flex-start', center: 'center', right: 'flex-end' };
        this.wrapper.style.textAlign = this.data.align || 'center';
        this.wrapper.style.background = this.data.background || '';
        if (this.controls) {
            this.controls.style.justifyContent = justify[this.data.align] || 'center';
        }

        // Keep the card text readable on a custom background: pick black/white by
        // contrast. With no custom background, defer to the theme.
        const bg = this.data.background;
        const fg = bg ? contrastText(bg) : '';
        this.wrapper.style.color = fg;
        if (this.titleEl) {
            this.titleEl.style.color = fg;
        }
        if (this.textEl) {
            this.textEl.style.color = fg ? (fg === '#ffffff' ? 'rgba(255,255,255,.85)' : 'rgba(17,24,39,.75)') : '';
        }
    }

    /** Called by the sidebar inspector. */
    setProp(key, value) {
        this.data[key] = value;
        this.applyLayout();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    editable(cls, placeholder, value, html) {
        const el = document.createElement('div');
        el.contentEditable = 'true';
        el.classList.add(cls);
        el.dataset.placeholder = placeholder;
        if (html) {
            el.innerHTML = value;
        } else {
            el.textContent = value;
        }
        return el;
    }

    input(placeholder, value) {
        const el = document.createElement('input');
        el.type = 'text';
        el.classList.add('magna-blog-cta__input');
        el.placeholder = placeholder;
        el.value = value;
        return el;
    }

    save() {
        return {
            title: this.titleEl.textContent || '',
            text: this.textEl.innerHTML,
            buttonLabel: this.labelInput.value || '',
            buttonUrl: this.urlInput.value || '',
            align: this.data.align,
            background: this.data.background,
            buttonType: this.data.buttonType,
            buttonColor: this.data.buttonColor,
        };
    }
}
