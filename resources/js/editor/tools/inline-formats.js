/**
 * Custom Editor.js inline tools that Editor.js doesn't ship: strikethrough,
 * subscript, superscript and a palette-based text colour. They appear on the
 * inline toolbar of every block that enables inlineToolbar.
 *
 * Colour is applied as a class from a fixed palette (mgb-c-<key>), never an
 * arbitrary inline style, so the server sanitiser can allow it without opening
 * a CSS-injection surface. The other three wrap the selection in a semantic tag.
 */

const SVG = (inner) => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + inner + '</svg>';

/** Factory: a toggle inline tool backed by a document.execCommand command. */
function commandTool({ title, command, tags, icon }) {
    return class {
        static get isInline() {
            return true;
        }

        static get title() {
            return title;
        }

        static get sanitize() {
            // Editor.js keeps only these tags on save; execCommand output varies
            // by browser (e.g. strikeThrough may emit <s> or <strike>).
            return tags.reduce((acc, t) => ({ ...acc, [t]: {} }), {});
        }

        constructor() {
            this.button = null;
        }

        render() {
            this.button = document.createElement('button');
            this.button.type = 'button';
            this.button.classList.add('ce-inline-tool');
            this.button.innerHTML = icon;
            return this.button;
        }

        surround() {
            document.execCommand(command, false);
        }

        checkState() {
            const active = document.queryCommandState(command);
            this.button?.classList.toggle('ce-inline-tool--active', active);
            return active;
        }
    };
}

export const Strikethrough = commandTool({
    title: 'Strikethrough',
    command: 'strikeThrough',
    tags: ['s', 'strike'],
    icon: SVG('<path d="M5 12h14"/><path d="M16 6.5A4 4 0 0 0 12 5c-2 0-3.5 1-3.5 2.5S10 10 12 10.5"/><path d="M8 17a4 4 0 0 0 4 1.5c2 0 3.5-1 3.5-2.5"/>'),
});

export const Subscript = commandTool({
    title: 'Subscript',
    command: 'subscript',
    tags: ['sub'],
    icon: SVG('<path d="M5 6l7 8"/><path d="M12 6l-7 8"/><path d="M20 20h-4l3.5-3.5a1.5 1.5 0 0 0-2.5-1.6"/>'),
});

export const Superscript = commandTool({
    title: 'Superscript',
    command: 'superscript',
    tags: ['sup'],
    icon: SVG('<path d="M5 10l7 8"/><path d="M12 10l-7 8"/><path d="M20 9h-4l3.5-3.5a1.5 1.5 0 0 0-2.5-1.6"/>'),
});

/** Palette keys → the class suffix used in editor.css (mgb-c-<key>). */
export const TEXT_COLORS = ['red', 'orange', 'amber', 'green', 'teal', 'blue', 'indigo', 'violet', 'pink', 'slate'];

export class TextColor {
    static get isInline() {
        return true;
    }

    static get title() {
        return 'Text colour';
    }

    static get sanitize() {
        // Allow a class-only span; the class value is re-validated server-side.
        return { span: { class: true } };
    }

    constructor({ api }) {
        this.api = api;
        this.button = null;
        this.actions = null;
        this.range = null;
    }

    render() {
        this.button = document.createElement('button');
        this.button.type = 'button';
        this.button.classList.add('ce-inline-tool');
        this.button.innerHTML = SVG('<path d="M12 3l5 13H7z"/><line x1="5" y1="20" x2="19" y2="20"/>');
        return this.button;
    }

    /** Editor.js keeps the selection; stash it so the palette click can reuse it. */
    surround(range) {
        if (range) {
            this.range = range;
        }
    }

    renderActions() {
        this.actions = document.createElement('div');
        this.actions.classList.add('mgb-color-actions');

        const clear = document.createElement('button');
        clear.type = 'button';
        clear.classList.add('mgb-color-clear');
        clear.textContent = 'None';
        clear.addEventListener('click', () => this.applyColor(null));
        this.actions.append(clear);

        TEXT_COLORS.forEach((key) => {
            const swatch = document.createElement('button');
            swatch.type = 'button';
            swatch.classList.add('mgb-color-swatch', 'mgb-c-' + key);
            swatch.title = key;
            swatch.addEventListener('click', () => this.applyColor(key));
            this.actions.append(swatch);
        });

        return this.actions;
    }

    applyColor(key) {
        const sel = window.getSelection();
        if (this.range) {
            sel.removeAllRanges();
            sel.addRange(this.range);
        }
        if (!sel.rangeCount || sel.isCollapsed) {
            return;
        }
        const range = sel.getRangeAt(0);

        // Unwrap any colour spans already inside the selection, then re-wrap.
        const existing = this.wrappingSpan(range);
        if (existing) {
            this.unwrap(existing);
        }
        if (key) {
            const span = document.createElement('span');
            span.className = 'mgb-c-' + key;
            try {
                range.surroundContents(span);
            } catch (e) {
                // Selection spans element boundaries — fall back to extract+wrap.
                span.appendChild(range.extractContents());
                range.insertNode(span);
            }
        }
        this.api?.inlineToolbar?.close();
    }

    wrappingSpan(range) {
        let node = range.commonAncestorContainer;
        node = node.nodeType === 3 ? node.parentNode : node;
        while (node && node !== document.body) {
            if (node.tagName === 'SPAN' && /(^|\s)mgb-c-/.test(node.className)) {
                return node;
            }
            node = node.parentNode;
        }
        return null;
    }

    unwrap(span) {
        const parent = span.parentNode;
        while (span.firstChild) {
            parent.insertBefore(span.firstChild, span);
        }
        parent.removeChild(span);
    }

    checkState() {
        return false;
    }
}
