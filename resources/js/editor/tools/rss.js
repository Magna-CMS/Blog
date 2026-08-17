/**
 * RSS — embeds items from an external RSS/Atom feed. The feed is fetched by a
 * server endpoint (SSRF-guarded, XXE-safe, cached) which returns a snapshot of
 * items; that snapshot is stored in the block, so rendering never makes an HTTP
 * call. Press "Fetch" after entering a URL to refresh the snapshot. How many
 * items to show and whether to show dates are edited from the Block panel.
 */
export default class Rss {
    static get toolbox() {
        return {
            title: 'RSS feed',
            icon: '<svg width="16" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 11a9 9 0 0 1 9 9"/><path d="M4 4a16 16 0 0 1 16 16"/><circle cx="5" cy="19" r="1"/></svg>',
        };
    }

    constructor({ data, config, block }) {
        this.config = config || {};
        this.block = block;
        this.data = {
            url: (data && typeof data.url === 'string') ? data.url : '',
            feedTitle: (data && typeof data.feedTitle === 'string') ? data.feedTitle : '',
            items: (data && Array.isArray(data.items)) ? data.items : [],
            count: Number.isFinite(Number(data && data.count)) ? Math.min(20, Math.max(1, Number(data.count))) : 5,
            showDate: data && data.showDate !== undefined ? Boolean(data.showDate) : true,
        };
        this.wrapper = null;
        // In-flight feed fetch, cancelled when a newer fetch starts or the block
        // is removed, so a stale response never lands in a detached list.
        this.controller = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-rss');
        this.wrapper.contentEditable = 'false';

        const bar = document.createElement('div');
        bar.classList.add('magna-blog-rss__bar');

        this.input = document.createElement('input');
        this.input.type = 'url';
        this.input.classList.add('de-input', 'magna-blog-rss__url');
        this.input.placeholder = 'https://example.com/feed.xml';
        this.input.value = this.data.url;

        this.button = document.createElement('button');
        this.button.type = 'button';
        this.button.classList.add('magna-blog-add', 'magna-blog-rss__fetch');
        this.button.textContent = 'Fetch';
        this.button.addEventListener('click', () => this.fetchFeed());

        bar.append(this.input, this.button);

        this.status = document.createElement('div');
        this.status.classList.add('magna-blog-rss__status');

        this.list = document.createElement('div');
        this.list.classList.add('magna-blog-rss__list');

        this.wrapper.append(bar, this.status, this.list);
        this.renderList();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    /** Sidebar inspector. */
    setProp(key, value) {
        if (key === 'count') {
            this.data.count = Math.min(20, Math.max(1, Number(value) || 5));
        } else if (key === 'showDate') {
            this.data.showDate = Boolean(value);
        }
        this.renderList();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    async fetchFeed() {
        const url = (this.input.value || '').trim();
        if (!url || !this.config.endpoint) {
            return;
        }
        this.data.url = url;
        this.status.textContent = 'Fetching…';
        if (this.controller) {
            this.controller.abort();
        }
        this.controller = new AbortController();
        try {
            const res = await fetch(this.config.endpoint + '?url=' + encodeURIComponent(url), {
                headers: { Accept: 'application/json' },
                signal: this.controller.signal,
            });
            const json = await res.json();
            if (!json || json.success !== 1) {
                this.status.textContent = 'Could not load that feed.';
                return;
            }
            this.data.feedTitle = (json.feed && json.feed.title) || '';
            this.data.items = Array.isArray(json.items) ? json.items : [];
            this.status.textContent = this.data.items.length + ' items loaded' + (this.data.feedTitle ? ' from “' + this.data.feedTitle + '”' : '');
            this.renderList();
            window.dispatchEvent(new CustomEvent('magna-blog:changed'));
        } catch (e) {
            // A cancelled request is not a failure — leave the status alone.
            if (e && e.name === 'AbortError') {
                return;
            }
            this.status.textContent = 'Could not load that feed.';
        }
    }

    /** Editor.js calls this when the block is removed — cancel any live fetch. */
    destroy() {
        if (this.controller) {
            this.controller.abort();
        }
        if (this.block && this.block.id && window.magnaBlogBlocks) {
            delete window.magnaBlogBlocks[this.block.id];
        }
    }

    renderList() {
        if (!this.list) {
            return;
        }
        this.list.innerHTML = '';
        this.data.items.slice(0, this.data.count).forEach((item) => {
            const row = document.createElement('div');
            row.classList.add('magna-blog-rss__item');

            const title = document.createElement('div');
            title.classList.add('magna-blog-rss__title');
            title.textContent = item.title || '(untitled)';
            row.append(title);

            if (this.data.showDate && item.date) {
                const date = document.createElement('div');
                date.classList.add('magna-blog-rss__date');
                date.textContent = item.date;
                row.append(date);
            }
            this.list.append(row);
        });
        if (this.data.items.length === 0) {
            this.list.innerHTML = '<div class="magna-blog-rss__empty">No items yet — enter a feed URL and press Fetch.</div>';
        }
    }

    save() {
        return {
            url: this.data.url,
            feedTitle: this.data.feedTitle,
            items: this.data.items,
            count: this.data.count,
            showDate: this.data.showDate,
        };
    }
}
