/** Delimiter — a section break with a selectable style (dots, line, dashed,
 * asterisks). Style is chosen from the sidebar Block settings. */
const STYLES = ['dots', 'line', 'dashed', 'asterisks'];
const GLYPHS = { dots: '• • •', asterisks: '✳ ✳ ✳', line: '', dashed: '' };

export default class Delimiter {
    static get toolbox() {
        return {
            title: 'Delimiter',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><line x1="5" y1="12" x2="19" y2="12"/><circle cx="12" cy="12" r="0.5" fill="currentColor"/></svg>',
        };
    }

    constructor({ data, block }) {
        this.data = { style: STYLES.includes(data && data.style) ? data.style : 'dots' };
        this.block = block;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-delimiter');
        this.wrapper.contentEditable = 'false';
        this.apply();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    apply() {
        this.wrapper.dataset.style = this.data.style;
        this.wrapper.textContent = GLYPHS[this.data.style] || '';
    }

    setProp(key, value) {
        if (key === 'style') {
            this.data.style = STYLES.includes(value) ? value : 'dots';
            this.apply();
            window.dispatchEvent(new CustomEvent('magna-blog:changed'));
        }
    }

    save() {
        return { style: this.data.style };
    }
}
