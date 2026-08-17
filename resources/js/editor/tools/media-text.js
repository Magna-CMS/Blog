/**
 * Media & Text — an image beside a rich text column. Layout (which side the
 * media sits, column ratio, vertical alignment, mobile stacking) and the image
 * (via the shared media picker) are all edited live. The text keeps the inline
 * toolbar. Degrades to a plain text column when no image is chosen.
 */
const RATIOS = ['1-1', '2-1', '1-2', '2-3', '3-2'];

export default class MediaText {
    static get toolbox() {
        return {
            title: 'Media & text',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="8" height="16" rx="1"/><line x1="14" y1="7" x2="21" y2="7"/><line x1="14" y1="12" x2="21" y2="12"/><line x1="14" y1="17" x2="19" y2="17"/></svg>',
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    constructor({ data, block, readOnly }) {
        this.readOnly = Boolean(readOnly);
        this.data = {
            image: (data && typeof data.image === 'string') ? data.image : '',
            imageAlt: (data && typeof data.imageAlt === 'string') ? data.imageAlt : '',
            text: (data && typeof data.text === 'string') ? data.text : '',
            mediaSide: (data && data.mediaSide === 'right') ? 'right' : 'left',
            ratio: RATIOS.includes(data && data.ratio) ? data.ratio : '1-1',
            vAlign: ['top', 'center', 'bottom'].includes(data && data.vAlign) ? data.vAlign : 'center',
            stackMobile: data && data.stackMobile !== undefined ? Boolean(data.stackMobile) : true,
        };
        this.block = block;
        this.pickerTarget = 'mediatext-' + Math.random().toString(36).slice(2);
        this.onSelected = this.onSelected.bind(this);
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-mediatext');

        this.media = document.createElement('div');
        this.media.classList.add('magna-blog-mediatext__media');
        this.media.contentEditable = 'false';

        this.textEl = document.createElement('div');
        this.textEl.classList.add('magna-blog-mediatext__text');
        this.textEl.contentEditable = String(!this.readOnly);
        this.textEl.dataset.placeholder = 'Text…';
        this.textEl.innerHTML = this.data.text;

        // Text first so Editor.js treats it as the block's editable input.
        this.wrapper.append(this.textEl, this.media);
        this.apply();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    apply() {
        this.wrapper.dataset.side = this.data.mediaSide;
        this.wrapper.dataset.ratio = this.data.ratio;
        this.wrapper.dataset.valign = this.data.vAlign;
        this.wrapper.dataset.stack = this.data.stackMobile ? '1' : '0';

        this.media.innerHTML = '';
        if (this.data.image) {
            const img = document.createElement('img');
            img.src = this.data.image;
            img.alt = this.data.imageAlt || '';
            this.media.append(img);
        } else {
            const ph = document.createElement('div');
            ph.classList.add('magna-blog-mediatext__placeholder');
            ph.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/></svg><span>Add image</span>';
            this.media.append(ph);
        }
        if (! this.readOnly) {
            this.media.style.cursor = 'pointer';
            this.media.onclick = () => this.openPicker();
        }
    }

    setProp(key, value) {
        if (key === 'mediaSide') {
            this.data.mediaSide = value === 'right' ? 'right' : 'left';
        } else if (key === 'ratio') {
            this.data.ratio = RATIOS.includes(value) ? value : '1-1';
        } else if (key === 'vAlign') {
            this.data.vAlign = ['top', 'center', 'bottom'].includes(value) ? value : 'center';
        } else if (key === 'stackMobile') {
            this.data.stackMobile = Boolean(value);
        } else if (key === 'imageAlt') {
            this.data.imageAlt = typeof value === 'string' ? value : '';
        } else if (key === 'pickImage') {
            this.openPicker();
            return;
        } else if (key === 'image') {
            this.data.image = typeof value === 'string' ? value : '';
        }
        this.apply();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    openPicker() {
        if (typeof window.Livewire === 'undefined') {
            return;
        }
        window.Livewire.on('magna:media-selected', this.onSelected);
        window.Livewire.dispatch('magna:open-media-picker', { target: this.pickerTarget });
    }

    onSelected(payload) {
        const detail = Array.isArray(payload) ? payload[0] : payload;
        if (! detail || detail.target !== this.pickerTarget) {
            return;
        }
        this.data.image = detail.url || '';
        this.apply();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            image: this.data.image,
            imageAlt: this.data.imageAlt,
            text: this.textEl.innerHTML,
            mediaSide: this.data.mediaSide,
            ratio: this.data.ratio,
            vAlign: this.data.vAlign,
            stackMobile: this.data.stackMobile,
        };
    }
}
