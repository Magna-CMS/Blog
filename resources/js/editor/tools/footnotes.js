/**
 * Footnotes — a numbered reference list, usually placed at the end of a post.
 * Each note is rich text and is rendered as an <li id="fn-N">, so an author can
 * link to it from the body with a superscript (e.g. <sup><a href="#fn-1">1</a>).
 * Kept deliberately simple (no central auto-numbering store): the list numbers
 * itself, and cross-references are the author's to place.
 */
export default class Footnotes {
    static get toolbox() {
        return {
            title: 'Footnotes',
            icon: '<svg width="16" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="14" y2="12"/><text x="16" y="15" font-size="9" fill="currentColor" stroke="none">1</text></svg>',
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    constructor({ data, block, readOnly }) {
        this.readOnly = Boolean(readOnly);
        const items = data && Array.isArray(data.items) ? data.items : [];
        this.items = items.length ? items : [{ text: '' }];
        this.title = (data && typeof data.title === 'string') ? data.title : 'Footnotes';
        this.block = block;
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-footnotes');

        this.titleEl = document.createElement('div');
        this.titleEl.classList.add('magna-blog-footnotes__title');
        this.titleEl.contentEditable = String(!this.readOnly);
        this.titleEl.dataset.placeholder = 'Heading (optional)';
        this.titleEl.textContent = this.title;

        this.list = document.createElement('ol');
        this.list.classList.add('magna-blog-footnotes__list');
        this.wrapper.append(this.titleEl, this.list);
        this.items.forEach((item) => this.addItem(item));

        if (!this.readOnly) {
            const add = document.createElement('button');
            add.type = 'button';
            add.classList.add('magna-blog-add');
            add.contentEditable = 'false';
            add.textContent = '+ Add note';
            add.addEventListener('click', () => this.addItem({ text: '' }));
            this.wrapper.append(add);
        }

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    addItem(item) {
        const row = document.createElement('li');
        row.classList.add('magna-blog-footnotes__item');

        const note = document.createElement('div');
        note.contentEditable = String(!this.readOnly);
        note.classList.add('magna-blog-footnotes__note');
        note.dataset.placeholder = 'Note text…';
        note.innerHTML = item.text || '';

        row.append(note);

        if (!this.readOnly) {
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.classList.add('magna-blog-remove');
            remove.contentEditable = 'false';
            remove.textContent = 'Remove';
            remove.addEventListener('click', () => row.remove());
            row.append(remove);
        }

        this.list.append(row);
    }

    save() {
        return {
            title: this.titleEl.textContent || '',
            items: [...this.list.querySelectorAll('.magna-blog-footnotes__item')]
                .map((row) => ({ text: row.querySelector('.magna-blog-footnotes__note').innerHTML || '' }))
                .filter((item) => item.text.trim() !== ''),
        };
    }
}
