/** Callout / Notice — coloured box with a type, optional icon, title and body.
 * Type and icon are edited from the sidebar Block settings (no inline tabs). */
const ICONS = { info: 'ℹ', success: '✓', warning: '⚠', danger: '✕' };

export default class Callout {
    static get toolbox() {
        return {
            title: 'Callout',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>',
        };
    }

    static get TYPES() {
        return ['info', 'success', 'warning', 'danger'];
    }

    constructor({ data, block }) {
        this.data = {
            type: Callout.TYPES.includes(data && data.type) ? data.type : 'info',
            title: (data && data.title) || '',
            text: (data && data.text) || '',
            icon: data && data.icon !== undefined ? Boolean(data.icon) : true,
        };
        this.block = block;
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-callout');

        this.iconEl = document.createElement('div');
        this.iconEl.classList.add('magna-blog-callout__icon');
        this.iconEl.contentEditable = 'false';

        this.titleEl = document.createElement('div');
        this.titleEl.contentEditable = 'true';
        this.titleEl.classList.add('magna-blog-callout__title');
        this.titleEl.dataset.placeholder = 'Title (optional)';
        this.titleEl.textContent = this.data.title;

        this.textEl = document.createElement('div');
        this.textEl.contentEditable = 'true';
        this.textEl.classList.add('magna-blog-callout__text');
        this.textEl.dataset.placeholder = 'Callout text…';
        this.textEl.innerHTML = this.data.text;

        const body = document.createElement('div');
        body.classList.add('magna-blog-callout__body');
        body.append(this.titleEl, this.textEl);

        this.wrapper.append(this.iconEl, body);
        this.applyType();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    applyType() {
        this.wrapper.dataset.type = this.data.type;
        this.iconEl.textContent = ICONS[this.data.type] || ICONS.info;
        this.iconEl.style.display = this.data.icon ? '' : 'none';
    }

    setProp(key, value) {
        if (key === 'type') {
            this.data.type = Callout.TYPES.includes(value) ? value : 'info';
        } else if (key === 'icon') {
            this.data.icon = Boolean(value);
        }
        this.applyType();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            type: this.data.type,
            title: this.titleEl.textContent || '',
            text: this.textEl.innerHTML,
            icon: this.data.icon,
        };
    }
}
