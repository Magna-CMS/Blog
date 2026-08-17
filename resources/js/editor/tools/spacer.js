/** Spacer — vertical spacing with a custom height and an optional divider line.
 * Height and divider are set from the sidebar Block settings. */
const LEGACY = { small: 16, medium: 32, large: 64 };

function toPx(value) {
    if (typeof value === 'string' && LEGACY[value] !== undefined) {
        return LEGACY[value];
    }
    const n = Number(value);
    return Number.isFinite(n) ? Math.max(8, Math.min(160, Math.round(n))) : 32;
}

export default class Spacer {
    static get toolbox() {
        return {
            title: 'Spacer',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="8 7 12 3 16 7"/><polyline points="8 17 12 21 16 17"/><line x1="12" y1="3" x2="12" y2="21"/></svg>',
        };
    }

    constructor({ data, block }) {
        this.data = {
            height: toPx(data && data.height),
            divider: Boolean(data && data.divider),
        };
        this.block = block;
        this.wrapper = null;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-spacer');
        this.wrapper.contentEditable = 'false';

        this.label = document.createElement('span');
        this.label.classList.add('magna-blog-spacer__label');
        this.wrapper.append(this.label);
        this.apply();

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    apply() {
        this.wrapper.style.height = this.data.height + 'px';
        this.wrapper.classList.toggle('has-divider', this.data.divider);
        this.label.textContent = 'Spacer · ' + this.data.height + 'px' + (this.data.divider ? ' · divider' : '');
    }

    setProp(key, value) {
        if (key === 'height') {
            this.data.height = toPx(value);
        } else if (key === 'divider') {
            this.data.divider = Boolean(value);
        }
        this.apply();
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    save() {
        return { height: this.data.height, divider: this.data.divider };
    }
}
