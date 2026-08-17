/**
 * Buttons — one or more call-to-action buttons. WYSIWYG: the canvas renders
 * the real, styled buttons with inline-editable labels. All styling (type,
 * colour, text colour, size, radius, font, block alignment…) lives in the
 * sidebar "Block settings" inspector, not inline in the content.
 *
 * The tool registers its live instance in `window.magnaBlogBlocks[blockId]` so
 * the sidebar can read/update a specific button without a full data round-trip
 * (mirrors the Style-tune registry). Clicking a button makes it "active" and
 * dispatches `magna-blog:button-selected`; the sidebar renders that button's
 * settings and calls back into `setButtonProp()` for instant, live restyling.
 */

const TYPES = ['filled', 'outline', 'ghost', 'link'];
const SIZES = ['sm', 'md', 'lg'];

function normButton(button) {
    button = button && typeof button === 'object' ? button : {};

    return {
        label: typeof button.label === 'string' ? button.label : '',
        url: typeof button.url === 'string' ? button.url : '',
        type: TYPES.includes(button.type) ? button.type : 'filled',
        color: typeof button.color === 'string' ? button.color : '#6366f1',
        textColor: typeof button.textColor === 'string' ? button.textColor : '',
        size: SIZES.includes(button.size) ? button.size : 'md',
        radius: Number.isFinite(button.radius) ? button.radius : 8,
        font: typeof button.font === 'string' ? button.font : '',
        fullWidth: Boolean(button.fullWidth),
        newTab: Boolean(button.newTab),
        nofollow: Boolean(button.nofollow),
    };
}

const SIZE_PAD = { sm: '0.35rem 0.75rem', md: '0.55rem 1.1rem', lg: '0.75rem 1.6rem' };
const SIZE_FONT = { sm: '0.85rem', md: '0.95rem', lg: '1.1rem' };

export default class Buttons {
    static get toolbox() {
        return {
            title: 'Buttons',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="8" width="9" height="8" rx="2"/><rect x="13" y="8" width="9" height="8" rx="2"/></svg>',
        };
    }

    constructor({ data, block }) {
        const items = data && Array.isArray(data.buttons) ? data.buttons : [];
        this.buttons = (items.length ? items : [{ label: 'Button' }]).map(normButton);
        this.align = ['left', 'center', 'right'].includes(data && data.align) ? data.align : 'left';
        this.gap = Number.isFinite(data && data.gap) ? data.gap : 8;
        this.block = block;
        this.wrapper = null;
        this.activeIndex = 0;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-buttons');
        this.wrapper.contentEditable = 'false';

        this.list = document.createElement('div');
        this.list.classList.add('magna-blog-buttons__list');
        this.wrapper.append(this.list);

        this.buttons.forEach((button, index) => this.addButton(button, index));
        this.applyLayout();

        // Register this live instance for the sidebar inspector.
        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    addButton(button, index) {
        const el = document.createElement('a');
        el.className = 'magna-blog-btn';
        el.dataset.index = String(index);
        el.href = 'javascript:void(0)';
        el.setAttribute('role', 'button');

        const label = document.createElement('span');
        label.className = 'magna-blog-btn__label';
        label.contentEditable = 'true';
        label.textContent = button.label || 'Button';
        label.addEventListener('input', () => {
            this.buttons[index].label = label.textContent || '';
            this.change();
        });
        label.addEventListener('focus', () => this.select(index));
        el.append(label);

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'magna-blog-btn__remove';
        remove.textContent = '×';
        remove.title = 'Remove button';
        remove.addEventListener('click', (event) => {
            event.stopPropagation();
            this.removeButton(index);
        });
        el.append(remove);

        el.addEventListener('click', (event) => {
            if (event.target === label) {
                return;
            }
            event.preventDefault();
            this.select(index);
        });

        this.styleButton(el, this.buttons[index]);
        this.list.append(el);
    }

    styleButton(el, button) {
        const hex = /^#[0-9a-fA-F]{3,8}$/.test(button.color) ? button.color : '#6366f1';
        const text = /^#[0-9a-fA-F]{3,8}$/.test(button.textColor) ? button.textColor : '';
        const radius = Math.max(0, Math.min(50, Number(button.radius) || 0));

        el.style.padding = SIZE_PAD[button.size] || SIZE_PAD.md;
        el.style.fontSize = SIZE_FONT[button.size] || SIZE_FONT.md;
        el.style.borderRadius = radius + 'px';
        el.style.fontFamily = (window.magnaBlog && window.magnaBlog.fontStack)
            ? window.magnaBlog.fontStack(button.font) : '';
        el.style.width = button.fullWidth ? '100%' : '';
        el.style.borderStyle = 'solid';
        el.style.borderWidth = button.type === 'link' ? '0' : '1.5px';
        el.style.borderColor = hex;
        el.style.textDecoration = button.type === 'link' ? 'underline' : 'none';

        if (button.type === 'filled') {
            el.style.background = hex;
            el.style.color = text || '#ffffff';
        } else if (button.type === 'outline') {
            el.style.background = 'transparent';
            el.style.color = text || hex;
        } else if (button.type === 'ghost') {
            el.style.background = 'transparent';
            el.style.borderColor = 'transparent';
            el.style.color = text || hex;
        } else {
            // link
            el.style.background = 'transparent';
            el.style.color = text || hex;
            el.style.padding = '0';
            el.style.textDecoration = 'underline';
        }
    }

    applyLayout() {
        const map = { left: 'flex-start', center: 'center', right: 'flex-end' };
        this.list.style.justifyContent = map[this.align] || 'flex-start';
        this.list.style.gap = Math.max(0, Math.min(64, Number(this.gap) || 0)) + 'px';
    }

    select(index) {
        this.activeIndex = index;
        [...this.list.children].forEach((child, i) => {
            child.classList.toggle('is-active', i === index);
        });
        document.dispatchEvent(new CustomEvent('magna-blog:button-selected', {
            detail: { blockId: this.block && this.block.id, index, button: { ...this.buttons[index] }, align: this.align, gap: this.gap },
        }));
    }

    removeButton(index) {
        if (this.buttons.length <= 1) {
            return;
        }
        this.buttons.splice(index, 1);
        this.rebuild();
        this.select(Math.max(0, index - 1));
        this.change();
    }

    rebuild() {
        this.list.innerHTML = '';
        this.buttons.forEach((button, index) => this.addButton(button, index));
        this.applyLayout();
    }

    // --- Sidebar inspector API -------------------------------------------

    /** Add a new button (called from the sidebar) and select it. */
    appendButton() {
        const index = this.buttons.push(normButton({ label: 'Button' })) - 1;
        this.addButton(this.buttons[index], index);
        this.applyLayout();
        this.select(index);
        this.change();
    }

    setButtonProp(index, key, value) {
        if (! this.buttons[index]) {
            return;
        }
        this.buttons[index] = normButton({ ...this.buttons[index], [key]: value });
        const el = this.list.children[index];
        if (el) {
            this.styleButton(el, this.buttons[index]);
        }
        this.change();
    }

    setBlockProp(key, value) {
        if (key === 'align') {
            this.align = ['left', 'center', 'right'].includes(value) ? value : 'left';
        } else if (key === 'gap') {
            this.gap = Number(value);
        }
        this.applyLayout();
        this.change();
    }

    /** Nudge the editor to persist (the tool mutates outside Editor.js onChange). */
    change() {
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            buttons: this.buttons
                .map(normButton)
                .filter((button) => button.label !== '' || button.url !== ''),
            align: this.align,
            gap: this.gap,
        };
    }
}
