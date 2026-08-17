/**
 * A block-level style tune applied to every block. Stores alignment, text
 * colour and font size as safe TOKENS (not raw CSS) under the block's `tunes`,
 * and applies them as inline styles. It is edited from the sidebar Block tab —
 * each live instance registers itself in `window.magnaBlogTunes[blockId]` so the
 * inspector can set values without going through Editor.js's own popover.
 *
 * The server sanitiser validates these tokens; the frontend/preview renderer
 * maps them to CSS. Keeping tokens (never arbitrary colour/size) closes the XSS
 * surface these styles would otherwise open.
 */
export const ALIGNS = ['left', 'center', 'right'];
export const SIZES = ['sm', 'base', 'lg', 'xl'];

const SIZE_REM = { sm: '0.875rem', base: '1rem', lg: '1.25rem', xl: '1.5rem' };

function isHex(value) {
    return typeof value === 'string' && /^#[0-9a-fA-F]{3,8}$/.test(value);
}

function clean(data) {
    data = data && typeof data === 'object' ? data : {};
    return {
        align: ALIGNS.includes(data.align) ? data.align : '',
        color: isHex(data.color) ? data.color : '',
        bg: isHex(data.bg) ? data.bg : '',
        accent: isHex(data.accent) ? data.accent : '',
        fontSize: SIZES.includes(data.fontSize) ? data.fontSize : '',
        font: typeof data.font === 'string' ? data.font : '',
        // Table-only presentation flags (harmless on other blocks).
        striped: Boolean(data.striped),
        compact: Boolean(data.compact),
        bordered: Boolean(data.bordered),
        // Columns-only vertical alignment + 2-column ratio.
        valign: ['top', 'center'].includes(data.valign) ? data.valign : '',
        ratio: ['2-1', '1-2', '3-1', '1-3'].includes(data.ratio) ? data.ratio : '',
    };
}

export default class StyleTune {
    static get isTune() {
        return true;
    }

    constructor({ data, block }) {
        this.data = clean(data);
        this.block = block;
        this.wrapper = null;
    }

    wrap(blockContent) {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-styled');
        this.wrapper.append(blockContent);
        this.applyStyle();

        if (this.block && this.block.id) {
            window.magnaBlogTunes = window.magnaBlogTunes || {};
            window.magnaBlogTunes[this.block.id] = this;
        }

        return this.wrapper;
    }

    applyStyle() {
        if (! this.wrapper) {
            return;
        }
        this.wrapper.style.textAlign = this.data.align || '';
        this.wrapper.style.color = this.data.color || '';
        // Also expose the colour as a custom prop so templates whose inner
        // elements set their own (theme-safe) default text colour can defer to
        // the user's choice via var(--p-text, default) instead of overriding it.
        this.wrapper.style.setProperty('--p-text', this.data.color || '');
        // Background: paint the wrapper directly so plain blocks (paragraph,
        // header, list…) honour the chosen colour, AND expose it as --p-bg so
        // paragraph templates that manage their own box can consume it too. When
        // a background is set, add breathing room + rounded corners so text is
        // not flush against the colour edge; clear all three when it is removed.
        this.wrapper.style.backgroundColor = this.data.bg || '';
        this.wrapper.style.setProperty('--p-bg', this.data.bg || '');
        this.wrapper.style.padding = this.data.bg ? '0.75rem 1rem' : '';
        this.wrapper.style.borderRadius = this.data.bg ? '8px' : '';
        this.wrapper.style.setProperty('--p-accent', this.data.accent || '');
        this.wrapper.style.fontSize = this.data.fontSize ? SIZE_REM[this.data.fontSize] : '';
        this.wrapper.style.fontFamily = (this.data.font && window.magnaBlog && window.magnaBlog.fontStack)
            ? window.magnaBlog.fontStack(this.data.font) : '';
        this.wrapper.classList.toggle('is-striped', this.data.striped);
        this.wrapper.classList.toggle('is-compact', this.data.compact);
        this.wrapper.classList.toggle('is-bordered', this.data.bordered);
        this.wrapper.classList.toggle('is-vtop', this.data.valign === 'top');
        this.wrapper.classList.toggle('is-vcenter', this.data.valign === 'center');
        ['2-1', '1-2', '3-1', '1-3'].forEach((r) => this.wrapper.classList.toggle('ratio-' + r, this.data.ratio === r));
    }

    /** Called by the sidebar inspector. */
    set(key, value) {
        if (key === 'align') {
            this.data.align = ALIGNS.includes(value) ? value : '';
        } else if (key === 'color') {
            this.data.color = isHex(value) ? value : '';
        } else if (key === 'bg') {
            this.data.bg = isHex(value) ? value : '';
        } else if (key === 'accent') {
            this.data.accent = isHex(value) ? value : '';
        } else if (key === 'fontSize') {
            this.data.fontSize = SIZES.includes(value) ? value : '';
        } else if (key === 'font') {
            this.data.font = typeof value === 'string' ? value : '';
        } else if (key === 'striped' || key === 'compact' || key === 'bordered') {
            this.data[key] = Boolean(value);
        } else if (key === 'valign') {
            this.data.valign = ['top', 'center'].includes(value) ? value : '';
        } else if (key === 'ratio') {
            this.data.ratio = ['2-1', '1-2', '3-1', '1-3'].includes(value) ? value : '';
        }
        this.applyStyle();
    }

    /** Editor.js renders this in the block's ⋮ popover; we edit from the sidebar. */
    render() {
        const hint = document.createElement('div');
        hint.classList.add('magna-blog-tune-hint');
        hint.textContent = 'Style: use the Block tab in the sidebar';
        return hint;
    }

    save() {
        return { ...this.data };
    }
}
