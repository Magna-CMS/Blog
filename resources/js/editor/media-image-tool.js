/**
 * Editor.js block tool that reuses the Magna media library picker instead of
 * shipping its own uploader. Selecting a block dispatches the core
 * `magna:open-media-picker` Livewire event and waits for `magna:media-selected`,
 * so all upload/storage stays in the central media library.
 */
export default class MediaImageTool {
    static get toolbox() {
        return {
            title: 'Image',
            icon: '<svg width="17" height="15" viewBox="0 0 336 276" xmlns="http://www.w3.org/2000/svg"><path d="M291 150.242V79c0-18.778-15.222-34-34-34H79c-18.778 0-34 15.222-34 34v42.264l67.516-44.945a17.33 17.33 0 0 1 19.226-.7c1.735 1.161 87.028 74.03 87.028 74.03l72.24-49.443zM79 45h178c18.778 0 34 15.222 34 34v197c0 18.778-15.222 34-34 34H79c-18.778 0-34-15.222-34-34V79c0-18.778 15.222-34 34-34z"/></svg>',
        };
    }

    static get sanitize() {
        return {
            url: false,
            caption: {},
            alt: false,
            align: false,
            width: false,
            rounded: false,
            linkUrl: false,
        };
    }

    constructor({ data, block }) {
        this.data = {
            url: (data && data.url) || '',
            caption: (data && data.caption) || '',
            alt: (data && data.alt) || '',
            align: (data && data.align) || '',
            width: (data && data.width) || 'full',
            rounded: Boolean(data && data.rounded),
            linkUrl: (data && data.linkUrl) || '',
        };
        this.block = block;
        this.wrapper = null;
        this.pickerTarget = 'editorjs-image-' + Math.random().toString(36).slice(2);
        this.onSelected = this.onSelected.bind(this);
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-image');
        this.renderContent();

        // Register for the sidebar inspector (mutated live, persisted via save()).
        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    /** Called by the sidebar inspector to change a layout setting live. */
    setProp(key, value) {
        this.data[key] = key === 'rounded' ? Boolean(value) : value;
        this.renderContent();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    applyLayout(figure, img) {
        const widths = { small: '25%', medium: '50%', large: '75%', full: '100%' };
        const aligns = { left: 'flex-start', center: 'center', right: 'flex-end' };
        figure.style.display = 'flex';
        figure.style.flexDirection = 'column';
        figure.style.alignItems = aligns[this.data.align] || 'stretch';
        img.style.width = widths[this.data.width] || '100%';
        img.style.borderRadius = this.data.rounded ? '0.6rem' : '';
    }

    renderContent() {
        this.wrapper.innerHTML = '';

        if (this.data.url) {
            const figure = document.createElement('div');
            figure.classList.add('magna-blog-image__figure');

            const img = document.createElement('img');
            img.src = this.data.url;
            img.alt = this.data.alt || '';
            img.classList.add('magna-blog-image__img');
            this.applyLayout(figure, img);

            const caption = document.createElement('div');
            caption.contentEditable = 'true';
            caption.dataset.placeholder = 'Caption';
            caption.classList.add('magna-blog-image__caption');
            caption.textContent = this.data.caption || '';
            caption.addEventListener('input', () => {
                this.data.caption = caption.textContent || '';
            });

            const replace = document.createElement('button');
            replace.type = 'button';
            replace.textContent = 'Replace image';
            replace.classList.add('magna-blog-image__button');
            replace.addEventListener('click', () => this.openPicker());

            figure.append(img, caption);
            this.wrapper.append(figure, replace);
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.textContent = 'Select from media library';
        button.classList.add('magna-blog-image__button');
        button.addEventListener('click', () => this.openPicker());
        this.wrapper.append(button);
    }

    openPicker() {
        if (typeof window.Livewire === 'undefined') {
            return;
        }

        // Listen once for this block's selection, keyed by a unique target so
        // concurrent image blocks never cross-wire.
        window.Livewire.on('magna:media-selected', this.onSelected);
        window.Livewire.dispatch('magna:open-media-picker', { target: this.pickerTarget });
    }

    onSelected(payload) {
        const detail = Array.isArray(payload) ? payload[0] : payload;
        if (!detail || detail.target !== this.pickerTarget) {
            return;
        }

        this.data.url = detail.url || '';
        this.data.alt = this.data.alt || '';
        this.renderContent();
    }

    save() {
        return {
            url: this.data.url,
            caption: this.data.caption,
            alt: this.data.alt,
            align: this.data.align,
            width: this.data.width,
            rounded: this.data.rounded,
            linkUrl: this.data.linkUrl,
        };
    }

    validate(data) {
        return Boolean(data.url);
    }
}
