/** Audio — a URL (external or picked from the media library) + optional caption. */
export default class Audio {
    static get toolbox() {
        return {
            title: 'Audio',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>',
        };
    }

    constructor({ data, block }) {
        this.data = {
            url: (data && data.url) || '',
            caption: (data && data.caption) || '',
            controls: data && data.controls !== undefined ? Boolean(data.controls) : true,
            autoplay: Boolean(data && data.autoplay),
            loop: Boolean(data && data.loop),
            muted: Boolean(data && data.muted),
        };
        this.block = block;
        this.target = 'audio-' + Math.random().toString(36).slice(2);
        this.wrapper = null;
        this.onSelected = this.onSelected.bind(this);
    }

    /** Called by the sidebar inspector (playback flags are data-only). */
    setProp(key, value) {
        this.data[key] = Boolean(value);
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-media');
        this.wrapper.contentEditable = 'false';

        this.urlInput = document.createElement('input');
        this.urlInput.type = 'text';
        this.urlInput.classList.add('magna-blog-media__url');
        this.urlInput.placeholder = 'https://…/audio.mp3';
        this.urlInput.value = this.data.url;

        this.captionInput = document.createElement('input');
        this.captionInput.type = 'text';
        this.captionInput.classList.add('magna-blog-media__caption');
        this.captionInput.placeholder = 'Caption (optional)';
        this.captionInput.value = this.data.caption;

        const pick = document.createElement('button');
        pick.type = 'button';
        pick.classList.add('magna-blog-add');
        pick.textContent = 'Pick from media library';
        pick.addEventListener('click', () => this.openPicker());

        this.wrapper.append(this.urlInput, this.captionInput, pick);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    openPicker() {
        if (typeof window.Livewire === 'undefined') {
            return;
        }
        window.Livewire.on('magna:media-selected', this.onSelected);
        window.Livewire.dispatch('magna:open-media-picker', { target: this.target });
    }

    onSelected(payload) {
        const detail = Array.isArray(payload) ? payload[0] : payload;
        if (!detail || detail.target !== this.target) {
            return;
        }
        this.urlInput.value = detail.url || '';
    }

    save() {
        return {
            url: this.urlInput.value.trim(),
            caption: this.captionInput.value,
            controls: this.data.controls,
            autoplay: this.data.autoplay,
            loop: this.data.loop,
            muted: this.data.muted,
        };
    }
}
