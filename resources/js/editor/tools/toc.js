/**
 * Table of Contents — a dynamic block. Stores its title, depth and numbering;
 * the delivery API builds the actual list from the post's heading blocks at
 * request time. Depth / numbering are edited from the sidebar Block settings.
 */
export default class Toc {
    static get toolbox() {
        return {
            title: 'Table of contents',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="4" y2="6"/><line x1="8" y1="6" x2="20" y2="6"/><line x1="4" y1="12" x2="4" y2="12"/><line x1="8" y1="12" x2="20" y2="12"/><line x1="4" y1="18" x2="4" y2="18"/><line x1="8" y1="18" x2="20" y2="18"/></svg>',
        };
    }

    constructor({ data, block }) {
        const depth = data && Number(data.depth);
        this.data = {
            title: (data && typeof data.title === 'string') ? data.title : 'Contents',
            depth: [2, 3, 4].includes(depth) ? depth : 3,
            ordered: Boolean(data && data.ordered),
        };
        this.block = block;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-dynamic');

        this.titleEl = document.createElement('div');
        this.titleEl.contentEditable = 'true';
        this.titleEl.classList.add('magna-blog-toc__title');
        this.titleEl.dataset.placeholder = 'Contents heading';
        this.titleEl.textContent = this.data.title;

        const note = document.createElement('span');
        note.classList.add('magna-blog-dynamic__note');
        note.contentEditable = 'false';
        note.textContent = 'Table of contents — built from your headings';

        this.wrapper.append(this.titleEl, note);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    setProp(key, value) {
        if (key === 'depth') {
            this.data.depth = [2, 3, 4].includes(Number(value)) ? Number(value) : 3;
        } else if (key === 'ordered') {
            this.data.ordered = Boolean(value);
        }
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            title: this.titleEl.textContent || 'Contents',
            depth: this.data.depth,
            ordered: this.data.ordered,
        };
    }
}
