/** Gallery — multiple images picked from the Magna media library, with a
 * configurable grid (columns / gap / crop / rounded) driven by the sidebar. */
const CROPS = { square: '1 / 1', '4-3': '4 / 3', '16-9': '16 / 9' };

export default class Gallery {
    static get toolbox() {
        return {
            title: 'Gallery',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="8" height="8" rx="1"/><rect x="13" y="3" width="8" height="8" rx="1"/><rect x="3" y="13" width="8" height="8" rx="1"/><rect x="13" y="13" width="8" height="8" rx="1"/></svg>',
        };
    }

    constructor({ data, block }) {
        this.images = data && Array.isArray(data.images) ? data.images : [];
        this.columns = Number.isFinite(data && data.columns) ? data.columns : 3;
        this.gap = Number.isFinite(data && data.gap) ? data.gap : 8;
        this.crop = typeof (data && data.crop) === 'string' && CROPS[data.crop] ? data.crop : '';
        this.rounded = Boolean(data && data.rounded);
        this.block = block;
        this.target = 'gallery-' + Math.random().toString(36).slice(2);
        this.wrapper = null;
        this.onSelected = this.onSelected.bind(this);
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-gallery');
        this.wrapper.contentEditable = 'false';

        this.grid = document.createElement('div');
        this.grid.classList.add('magna-blog-gallery__grid');
        this.wrapper.append(this.grid);
        this.images.forEach((image) => this.addThumb(image));
        this.applyLayout();

        const add = document.createElement('button');
        add.type = 'button';
        add.classList.add('magna-blog-add');
        add.textContent = '+ Add image';
        add.addEventListener('click', () => this.openPicker());
        this.wrapper.append(add);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    applyLayout() {
        this.grid.style.display = 'grid';
        this.grid.style.gridTemplateColumns = `repeat(${Math.max(1, Math.min(6, this.columns))}, 1fr)`;
        this.grid.style.gap = Math.max(0, Math.min(40, this.gap)) + 'px';
        [...this.grid.children].forEach((cell) => this.styleCell(cell));
    }

    styleCell(cell) {
        const img = cell.querySelector('img');
        cell.style.aspectRatio = this.crop ? CROPS[this.crop] : '';
        cell.style.borderRadius = this.rounded ? '0.5rem' : '';
        if (img) {
            img.style.objectFit = this.crop ? 'cover' : '';
            img.style.width = '100%';
            img.style.height = this.crop ? '100%' : '';
        }
    }

    addThumb(image) {
        const cell = document.createElement('div');
        cell.classList.add('magna-blog-gallery__cell');

        const img = document.createElement('img');
        img.src = image.url;
        img.alt = image.alt || '';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.classList.add('magna-blog-gallery__remove');
        remove.textContent = '×';
        remove.addEventListener('click', () => {
            cell.remove();
            this.change();
        });

        cell.dataset.url = image.url;
        cell.append(img, remove);
        this.grid.append(cell);
        this.styleCell(cell);
    }

    // --- Sidebar inspector API ---
    setProp(key, value) {
        if (key === 'columns' || key === 'gap') {
            this[key] = Number(value);
        } else if (key === 'crop') {
            this.crop = CROPS[value] ? value : '';
        } else if (key === 'rounded') {
            this.rounded = Boolean(value);
        }
        this.applyLayout();
        this.change();
    }

    change() {
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
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
        this.addThumb({ url: detail.url || '', alt: '' });
        this.change();
    }

    save() {
        return {
            images: [...this.grid.querySelectorAll('.magna-blog-gallery__cell')].map((cell) => ({
                url: cell.dataset.url || '',
                alt: cell.querySelector('img').alt || '',
            })).filter((image) => image.url !== ''),
            columns: this.columns,
            gap: this.gap,
            crop: this.crop,
            rounded: this.rounded,
        };
    }
}
