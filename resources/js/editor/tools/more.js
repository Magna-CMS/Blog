/** "Read more" separator — marks where a teaser ends. Carries no content. */
export default class More {
    static get toolbox() {
        return {
            title: 'Read more',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="3" y1="12" x2="7" y2="12"/><line x1="17" y1="12" x2="21" y2="12"/><circle cx="12" cy="12" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/></svg>',
        };
    }

    render() {
        const el = document.createElement('div');
        el.classList.add('magna-blog-marker');
        el.contentEditable = 'false';
        el.textContent = 'READ MORE';
        return el;
    }

    save() {
        return {};
    }
}
