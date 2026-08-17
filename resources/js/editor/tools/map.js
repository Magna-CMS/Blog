/**
 * Google Map — stores a place query (address or "lat,lng"). The frontend builds
 * the embed (https://maps.google.com/maps?q=<query>&output=embed), so no API key
 * is needed here.
 */
export default class MapBlock {
    static get toolbox() {
        return {
            title: 'Map',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 10c0 6-9 12-9 12s-9-6-9-12a9 9 0 0 1 18 0Z"/><circle cx="12" cy="10" r="3"/></svg>',
        };
    }

    constructor({ data }) {
        this.data = { query: (data && data.query) || '' };
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-media');
        this.wrapper.contentEditable = 'false';

        this.input = document.createElement('input');
        this.input.type = 'text';
        this.input.classList.add('magna-blog-media__url');
        this.input.placeholder = 'Address or "lat,lng"';
        this.input.value = this.data.query;

        this.wrapper.append(this.input);
        return this.wrapper;
    }

    save() {
        return { query: this.input.value.trim() };
    }
}
