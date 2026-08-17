/** Verse — poetry / special formatting; preserves line breaks. */
export default class Verse {
    static get toolbox() {
        return {
            title: 'Verse',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="6" x2="16" y2="6"/><line x1="4" y1="10" x2="20" y2="10"/><line x1="4" y1="14" x2="14" y2="14"/><line x1="4" y1="18" x2="18" y2="18"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = { text: (data && data.text) || '' };
        this.el = null;
    }

    render() {
        const el = document.createElement('pre');
        el.contentEditable = 'true';
        el.classList.add('magna-blog-verse');
        el.dataset.placeholder = 'Write verse…';
        el.textContent = this.data.text;
        this.el = el;
        return el;
    }

    save() {
        return { text: this.el.innerText };
    }
}
