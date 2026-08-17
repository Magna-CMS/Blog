/** Cover — a background image (from the media library) with overlaid heading + text. */
export default class Cover {
    static get toolbox() {
        return {
            title: 'Cover',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><path d="M3 15l5-4 4 3 4-4 5 4"/><line x1="8" y1="10" x2="16" y2="10"/></svg>',
        };
    }

    constructor({ data, block }) {
        this.data = {
            url: (data && data.url) || '',
            title: (data && data.title) || '',
            text: (data && data.text) || '',
            height: (data && data.height) || 'medium',
            overlay: Number.isFinite(data && data.overlay) ? data.overlay : 40,
            align: (data && data.align) || 'center',
        };
        this.block = block;
        this.target = 'cover-' + Math.random().toString(36).slice(2);
        this.wrapper = null;
        this.onSelected = this.onSelected.bind(this);
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-cover');
        this.renderContent();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    applyLayout() {
        const heights = { small: '180px', medium: '280px', large: '440px' };
        const aligns = { left: 'flex-start', center: 'center', right: 'flex-end' };
        this.wrapper.style.minHeight = heights[this.data.height] || '280px';
        this.wrapper.style.setProperty('--cover-overlay', Math.max(0, Math.min(100, this.data.overlay)) / 100);
        this.wrapper.style.alignItems = aligns[this.data.align] || 'center';
        this.wrapper.style.textAlign = this.data.align || 'center';
    }

    setProp(key, value) {
        this.data[key] = key === 'overlay' ? Number(value) : value;
        this.applyLayout();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    renderContent() {
        this.wrapper.innerHTML = '';
        this.wrapper.style.backgroundImage = this.data.url ? `url("${this.data.url}")` : '';
        this.wrapper.classList.toggle('has-image', Boolean(this.data.url));
        this.applyLayout();

        this.titleEl = document.createElement('div');
        this.titleEl.contentEditable = 'true';
        this.titleEl.classList.add('magna-blog-cover__title');
        this.titleEl.dataset.placeholder = 'Cover heading';
        this.titleEl.textContent = this.data.title;

        this.textEl = document.createElement('div');
        this.textEl.contentEditable = 'true';
        this.textEl.classList.add('magna-blog-cover__text');
        this.textEl.dataset.placeholder = 'Cover text…';
        this.textEl.innerHTML = this.data.text;

        const button = document.createElement('button');
        button.type = 'button';
        button.classList.add('magna-blog-cover__pick');
        button.contentEditable = 'false';
        button.textContent = this.data.url ? 'Replace background' : 'Select background image';
        button.addEventListener('click', () => this.openPicker());

        this.wrapper.append(this.titleEl, this.textEl, button);
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
        this.data.title = this.titleEl.textContent || '';
        this.data.text = this.textEl.innerHTML;
        this.data.url = detail.url || '';
        this.renderContent();
    }

    save() {
        return {
            url: this.data.url,
            title: this.titleEl.textContent || '',
            text: this.textEl.innerHTML,
            height: this.data.height,
            overlay: this.data.overlay,
            align: this.data.align,
        };
    }
}
