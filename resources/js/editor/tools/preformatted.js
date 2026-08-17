/** Preformatted text — monospace, whitespace preserved. */
export default class Preformatted {
    static get toolbox() {
        return {
            title: 'Preformatted',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = { text: (data && data.text) || '' };
        this.el = null;
    }

    render() {
        const el = document.createElement('pre');
        el.contentEditable = 'true';
        el.classList.add('magna-blog-pre');
        el.dataset.placeholder = 'Preformatted text…';
        el.textContent = this.data.text;
        this.el = el;
        return el;
    }

    save() {
        return { text: this.el.innerText };
    }
}
