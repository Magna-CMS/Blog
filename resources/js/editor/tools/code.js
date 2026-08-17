/** Code — a plain code block with an optional language, chosen from the sidebar.
 * Replaces @editorjs/code so the language can be stored and emitted as a
 * `language-xxx` class for frontend syntax highlighters. */
export const CODE_LANGS = ['', 'bash', 'css', 'html', 'js', 'ts', 'json', 'php', 'python', 'sql', 'yaml', 'go', 'rust', 'java'];

// Pretty labels for the in-editor language badge (mirrors the sidebar options).
const LANG_LABELS = {
    '': 'Plain', bash: 'Bash', css: 'CSS', html: 'HTML', js: 'JavaScript', ts: 'TypeScript',
    json: 'JSON', php: 'PHP', python: 'Python', sql: 'SQL', yaml: 'YAML', go: 'Go', rust: 'Rust', java: 'Java',
};

export default class Code {
    static get toolbox() {
        return {
            title: 'Code',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/></svg>',
        };
    }

    static get isReadOnlySupported() {
        return true;
    }

    constructor({ data, block, readOnly }) {
        this.data = {
            code: (data && data.code) || '',
            language: CODE_LANGS.includes(data && data.language) ? data.language : '',
        };
        this.block = block;
        this.readOnly = readOnly;
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-code');

        this.ta = document.createElement('textarea');
        this.ta.classList.add('magna-blog-code__ta');
        this.ta.value = this.data.code;
        this.ta.placeholder = 'Type or paste code…';
        this.ta.spellcheck = false;
        this.ta.readOnly = Boolean(this.readOnly);
        this.ta.rows = Math.max(3, this.data.code.split('\n').length);
        this.ta.addEventListener('input', () => {
            this.data.code = this.ta.value;
            this.ta.rows = Math.max(3, this.ta.value.split('\n').length);
        });
        // Tab inserts an indent instead of leaving the field.
        this.ta.addEventListener('keydown', (event) => {
            if (event.key === 'Tab') {
                event.preventDefault();
                const s = this.ta.selectionStart;
                const e = this.ta.selectionEnd;
                this.ta.value = this.ta.value.slice(0, s) + '  ' + this.ta.value.slice(e);
                this.ta.selectionStart = this.ta.selectionEnd = s + 2;
            }
        });

        // Language badge — visible feedback that the sidebar's Language setting
        // applied. Frontend/preview turns the stored language into a
        // `language-xxx` class for syntax highlighting.
        this.badge = document.createElement('span');
        this.badge.classList.add('magna-blog-code__lang');
        this.updateBadge();

        this.wrapper.append(this.badge, this.ta);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    updateBadge() {
        if (this.badge) {
            this.badge.textContent = LANG_LABELS[this.data.language] || 'Plain';
        }
    }

    setProp(key, value) {
        if (key === 'language') {
            this.data.language = CODE_LANGS.includes(value) ? value : '';
            this.updateBadge();
            window.dispatchEvent(new CustomEvent('magna-blog:changed'));
        }
    }

    save() {
        return { code: this.ta.value, language: this.data.language };
    }
}
