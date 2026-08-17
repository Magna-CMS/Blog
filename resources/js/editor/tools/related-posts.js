/**
 * Related posts — a dynamic block. Stores how many, by what relation, and how
 * to present them; the delivery API resolves the actual posts at request time.
 * All settings live in the sidebar Block panel.
 */
export default class RelatedPosts {
    static get toolbox() {
        return {
            title: 'Related posts',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="7" height="16" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="6" rx="1"/></svg>',
        };
    }

    constructor({ data, block }) {
        const count = data && Number(data.count);
        this.data = {
            count: Number.isInteger(count) && count > 0 && count <= 12 ? count : 3,
            by: (data && data.by) === 'tag' ? 'tag' : 'category',
            layout: (data && data.layout) === 'list' ? 'list' : 'grid',
            showImage: data && data.showImage !== undefined ? Boolean(data.showImage) : true,
        };
        this.block = block;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-dynamic');
        this.wrapper.contentEditable = 'false';

        const label = document.createElement('span');
        label.textContent = 'Related posts — resolved by delivery. Configure in Block settings →';
        this.wrapper.append(label);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    setProp(key, value) {
        if (key === 'count') {
            this.data.count = Math.max(1, Math.min(12, Number(value) || 3));
        } else if (key === 'by') {
            this.data.by = value === 'tag' ? 'tag' : 'category';
        } else if (key === 'layout') {
            this.data.layout = value === 'list' ? 'list' : 'grid';
        } else if (key === 'showImage') {
            this.data.showImage = Boolean(value);
        }
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return {
            count: this.data.count,
            by: this.data.by,
            layout: this.data.layout,
            showImage: this.data.showImage,
        };
    }
}
