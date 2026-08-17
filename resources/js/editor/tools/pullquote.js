/** Pullquote — a visually emphasised quotation with an optional citation. */
export default class Pullquote {
    static get toolbox() {
        return {
            title: 'Pullquote',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M7 7h4v6a4 4 0 0 1-4 4"/><path d="M15 7h4v6a4 4 0 0 1-4 4"/></svg>',
        };
    }

    static get sanitize() {
        return { text: { br: true }, citation: {} };
    }

    constructor({ data }) {
        this.data = {
            text: (data && data.text) || '',
            citation: (data && data.citation) || '',
        };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('blockquote');
        this.wrapper.classList.add('magna-blog-pullquote');

        this.textEl = document.createElement('div');
        this.textEl.contentEditable = 'true';
        this.textEl.classList.add('magna-blog-pullquote__text');
        this.textEl.dataset.placeholder = 'Emphasised quotation…';
        this.textEl.innerHTML = this.data.text;

        this.citeEl = document.createElement('div');
        this.citeEl.contentEditable = 'true';
        this.citeEl.classList.add('magna-blog-pullquote__cite');
        this.citeEl.dataset.placeholder = 'Citation';
        this.citeEl.textContent = this.data.citation;

        this.wrapper.append(this.textEl, this.citeEl);
        return this.wrapper;
    }

    save() {
        return {
            text: this.textEl.innerHTML,
            citation: this.citeEl.textContent || '',
        };
    }
}
