/** Page break — splits content into pages. Carries no content. */
export default class PageBreak {
    static get toolbox() {
        return {
            title: 'Page break',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4h10l6 6v10a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2Z"/><polyline points="14 4 14 10 20 10"/><line x1="8" y1="15" x2="16" y2="15" stroke-dasharray="2 2"/></svg>',
        };
    }

    render() {
        const el = document.createElement('div');
        el.classList.add('magna-blog-marker');
        el.contentEditable = 'false';
        el.textContent = 'PAGE BREAK';
        return el;
    }

    save() {
        return {};
    }
}
