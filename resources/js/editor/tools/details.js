/**
 * Details — a single native <details> disclosure: a clickable summary and a
 * rich body that expands/collapses. Distinct from the FAQ block (a list of
 * question/answer pairs); use Details for one spoiler / "read more" / aside.
 * "Open by default" is toggled from the sidebar Block settings.
 */
export default class Details {
    static get toolbox() {
        return {
            title: 'Details',
            icon: '<svg width="16" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 6 15 12 9 18"/><line x1="4" y1="4" x2="4" y2="20"/></svg>',
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    constructor({ data, block, readOnly }) {
        this.readOnly = Boolean(readOnly);
        this.data = {
            summary: (data && typeof data.summary === 'string') ? data.summary : '',
            text: (data && typeof data.text === 'string') ? data.text : '',
            open: Boolean(data && data.open),
        };
        this.block = block;
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-details');

        this.summaryEl = document.createElement('div');
        this.summaryEl.classList.add('magna-blog-details__summary');
        this.summaryEl.contentEditable = String(!this.readOnly);
        this.summaryEl.dataset.placeholder = 'Summary (click to expand)…';
        this.summaryEl.textContent = this.data.summary;

        this.textEl = document.createElement('div');
        this.textEl.classList.add('magna-blog-details__text');
        this.textEl.contentEditable = String(!this.readOnly);
        this.textEl.dataset.placeholder = 'Hidden content…';
        this.textEl.innerHTML = this.data.text;

        this.wrapper.append(this.summaryEl, this.textEl);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    /** Sidebar inspector: the "open by default" flag. */
    setProp(key, value) {
        if (key === 'open') {
            this.data.open = Boolean(value);
        }
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            summary: this.summaryEl.textContent || '',
            text: this.textEl.innerHTML,
            open: this.data.open,
        };
    }
}
