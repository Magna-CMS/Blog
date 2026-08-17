/**
 * Group — a container that hosts its own nested Editor.js document and gives it
 * a shared background, padding, radius and a layout (stack / row / grid). Think
 * of Gutenberg's Group + Row + Stack folded into one block. It never offers
 * Group or Columns as child tools, so nesting is bounded (the server sanitiser
 * also drops any group nested past the top level).
 *
 * The child editor is a real Editor.js instance; save() is async and awaits the
 * nested document so the parent's save captures it.
 */
const LAYOUTS = ['stack', 'row', 'grid'];

export default class Group {
    static get toolbox() {
        return {
            title: 'Group',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/></svg>',
        };
    }

    constructor({ data, config, block, readOnly }) {
        this.readOnly = Boolean(readOnly);
        this.config = config || {};
        this.block = block;
        this.data = {
            blocks: (data && Array.isArray(data.blocks)) ? data.blocks : [],
            layout: LAYOUTS.includes(data && data.layout) ? data.layout : 'stack',
            columns: [2, 3, 4].includes(Number(data && data.columns)) ? Number(data.columns) : 2,
            gap: Number.isFinite(Number(data && data.gap)) ? Number(data.gap) : 16,
            padding: Number.isFinite(Number(data && data.padding)) ? Number(data.padding) : 20,
            radius: Number.isFinite(Number(data && data.radius)) ? Number(data.radius) : 12,
            background: (data && typeof data.background === 'string') ? data.background : '',
            align: ['', 'left', 'center', 'right'].includes(data && data.align) ? data.align : '',
        };
        this.editor = null;
        this.ready = false;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-group');

        this.holder = document.createElement('div');
        this.holder.classList.add('magna-blog-group__holder');
        this.holder.id = 'group-' + Math.random().toString(36).slice(2);
        this.wrapper.append(this.holder);

        this.applyStyle();
        this.mountEditor();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    mountEditor() {
        const EditorJS = this.config.EditorJS;
        if (typeof EditorJS !== 'function') {
            // No library handed in — degrade to a read-only notice rather than throw.
            this.holder.textContent = 'Group editor unavailable.';
            return;
        }

        this.editor = new EditorJS({
            // Pass the element itself: the holder is not attached to the document
            // yet when the tool renders, so an id lookup would find nothing.
            holder: this.holder,
            readOnly: this.readOnly,
            minHeight: 60,
            placeholder: 'Add blocks to the group…',
            data: { blocks: this.data.blocks },
            tools: this.config.tools || {},
            onReady: () => { this.ready = true; },
            onChange: () => window.dispatchEvent(new CustomEvent('magna-blog:changed')),
        });
    }

    applyStyle() {
        const d = this.data;
        this.wrapper.dataset.layout = d.layout;
        this.wrapper.dataset.columns = String(d.columns);
        this.wrapper.dataset.align = d.align || '';
        this.wrapper.style.setProperty('--g-gap', d.gap + 'px');
        this.wrapper.style.setProperty('--g-pad', d.padding + 'px');
        this.wrapper.style.setProperty('--g-radius', d.radius + 'px');
        this.wrapper.style.setProperty('--g-bg', d.background || 'transparent');
    }

    /** Sidebar inspector. */
    setProp(key, value) {
        if (key === 'layout') {
            this.data.layout = LAYOUTS.includes(value) ? value : 'stack';
        } else if (key === 'columns') {
            this.data.columns = [2, 3, 4].includes(Number(value)) ? Number(value) : 2;
        } else if (key === 'gap' || key === 'padding' || key === 'radius') {
            this.data[key] = Number.isFinite(Number(value)) ? Number(value) : this.data[key];
        } else if (key === 'background') {
            this.data.background = typeof value === 'string' ? value : '';
        } else if (key === 'align') {
            this.data.align = ['', 'left', 'center', 'right'].includes(value) ? value : '';
        }
        this.applyStyle();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    async save() {
        let nested = { blocks: this.data.blocks };
        if (this.editor && this.ready && typeof this.editor.save === 'function') {
            try {
                nested = await this.editor.save();
            } catch (e) {
                // Keep the last-known blocks if the nested save fails.
            }
        }
        this.data.blocks = Array.isArray(nested.blocks) ? nested.blocks : [];

        return {
            blocks: this.data.blocks,
            layout: this.data.layout,
            columns: this.data.columns,
            gap: this.data.gap,
            padding: this.data.padding,
            radius: this.data.radius,
            background: this.data.background,
            align: this.data.align,
        };
    }

    destroy() {
        if (this.editor && typeof this.editor.destroy === 'function') {
            this.editor.destroy();
            this.editor = null;
        }
    }
}
