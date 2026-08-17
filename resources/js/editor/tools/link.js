/**
 * Link — a rich link card. Unlike the stock @editorjs/link tool this block is
 * fully editable at any time: a URL field, an optional link-text field (the
 * anchor label), and a "Preview" button that fetches Open Graph metadata from
 * the server endpoint (SSRF-guarded) and shows a title/description/image card.
 *
 * The fetched preview is stored on the block, so rendering never makes an HTTP
 * call. New-tab / nofollow toggles live in the sidebar "Block settings"
 * inspector; the tool registers its live instance in
 * `window.magnaBlogBlocks[blockId]` so the sidebar can update them instantly.
 */
export default class Link {
    static get toolbox() {
        return {
            title: 'Link',
            icon: '<svg width="16" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>',
        };
    }

    constructor({ data, config, block }) {
        this.config = config || {};
        this.block = block;
        const meta = (data && typeof data.meta === 'object' && data.meta) ? data.meta : {};
        const image = (meta && typeof meta.image === 'object' && meta.image) ? meta.image : {};
        this.data = {
            link: (data && typeof data.link === 'string') ? data.link : '',
            text: (data && typeof data.text === 'string') ? data.text : '',
            newTab: !!(data && data.newTab),
            nofollow: !!(data && data.nofollow),
            meta: {
                title: (meta && typeof meta.title === 'string') ? meta.title : '',
                description: (meta && typeof meta.description === 'string') ? meta.description : '',
                image: { url: (image && typeof image.url === 'string') ? image.url : '' },
            },
        };
        this.wrapper = null;
        // In-flight preview fetch, so a new fetch (or block removal) cancels a
        // stale one instead of letting it resolve into a detached card.
        this.controller = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-link');
        this.wrapper.contentEditable = 'false';

        const bar = document.createElement('div');
        bar.classList.add('magna-blog-link__bar');

        this.urlInput = document.createElement('input');
        this.urlInput.type = 'url';
        this.urlInput.classList.add('de-input', 'magna-blog-link__url');
        this.urlInput.placeholder = 'https://example.com/page';
        this.urlInput.value = this.data.link;
        this.urlInput.addEventListener('input', () => {
            this.data.link = (this.urlInput.value || '').trim();
            this.change();
        });

        this.button = document.createElement('button');
        this.button.type = 'button';
        this.button.classList.add('magna-blog-add', 'magna-blog-link__fetch');
        this.button.textContent = 'Preview';
        this.button.addEventListener('click', () => this.fetchPreview());

        bar.append(this.urlInput, this.button);

        this.textInput = document.createElement('input');
        this.textInput.type = 'text';
        this.textInput.classList.add('de-input', 'magna-blog-link__text');
        this.textInput.placeholder = 'Link text (optional — shown instead of the URL)';
        this.textInput.value = this.data.text;
        this.textInput.addEventListener('input', () => {
            this.data.text = this.textInput.value || '';
            this.change();
        });

        this.status = document.createElement('div');
        this.status.classList.add('magna-blog-link__status');

        this.card = document.createElement('div');
        this.card.classList.add('magna-blog-link__card');

        this.wrapper.append(bar, this.textInput, this.status, this.card);
        this.renderCard();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    /** Sidebar inspector (new-tab / nofollow toggles). */
    setProp(key, value) {
        if (key === 'newTab') {
            this.data.newTab = Boolean(value);
        } else if (key === 'nofollow') {
            this.data.nofollow = Boolean(value);
        }
        this.change();
    }

    async fetchPreview() {
        const url = (this.urlInput.value || '').trim();
        this.data.link = url;
        if (!url) {
            this.status.textContent = 'Enter a URL first.';
            return;
        }
        if (!this.config.endpoint) {
            // No preview endpoint available — the link still saves and renders,
            // just without an Open Graph card.
            this.status.textContent = 'Preview unavailable; the link will still work.';
            return;
        }
        this.status.textContent = 'Fetching preview…';
        // Cancel any preview still in flight so its (late) response cannot
        // overwrite this newer request's result.
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
            if (!json || json.success !== 1 || !json.meta) {
                this.status.textContent = 'No preview available for that URL. The link still works.';
                this.data.meta = { title: '', description: '', image: { url: '' } };
                this.renderCard();
                this.change();
                return;
            }
            const meta = json.meta;
            const image = (meta.image && typeof meta.image === 'object') ? meta.image : {};
            this.data.meta = {
                title: typeof meta.title === 'string' ? meta.title : '',
                description: typeof meta.description === 'string' ? meta.description : '',
                image: { url: typeof image.url === 'string' ? image.url : '' },
            };
            this.status.textContent = 'Preview loaded.';
            this.renderCard();
            this.change();
        } catch (e) {
            // A cancelled request is not a failure — say nothing to the user.
            if (e && e.name === 'AbortError') {
                return;
            }
            this.status.textContent = 'Could not fetch a preview. The link still works.';
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

    renderCard() {
        if (!this.card) {
            return;
        }
        const { title, description, image } = this.data.meta;
        if (!title && !description && !(image && image.url)) {
            this.card.innerHTML = '';
            this.card.hidden = true;
            return;
        }
        this.card.hidden = false;
        this.card.innerHTML = '';

        if (image && image.url) {
            const img = document.createElement('div');
            img.classList.add('magna-blog-link__img');
            img.style.backgroundImage = 'url("' + image.url.replace(/"/g, '') + '")';
            this.card.append(img);
        }

        const body = document.createElement('div');
        body.classList.add('magna-blog-link__body');

        if (title) {
            const t = document.createElement('div');
            t.classList.add('magna-blog-link__title');
            t.textContent = title;
            body.append(t);
        }
        if (description) {
            const d = document.createElement('div');
            d.classList.add('magna-blog-link__desc');
            d.textContent = description;
            body.append(d);
        }
        const host = document.createElement('div');
        host.classList.add('magna-blog-link__host');
        host.textContent = this.hostname(this.data.link);
        body.append(host);

        this.card.append(body);
    }

    hostname(url) {
        try {
            return new URL(url).hostname;
        } catch (e) {
            return url;
        }
    }

    /** Nudge the editor to persist (inputs mutate outside Editor.js onChange). */
    change() {
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            link: this.data.link,
            text: this.data.text,
            newTab: this.data.newTab,
            nofollow: this.data.nofollow,
            meta: this.data.meta,
        };
    }
}
