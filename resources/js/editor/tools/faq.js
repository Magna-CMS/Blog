/**
 * FAQ — a list of question / answer pairs. A "template" (a visual style) is
 * chosen from the sidebar Block panel's gallery; it only restyles the accordion
 * chrome, so every question and answer stays fully editable in every template.
 * Output-only flags (open first item, FAQ schema) are toggled from the same panel.
 */
export const FAQ_TEMPLATES = [
    { key: 'card', label: 'Floating card' },
    { key: 'line', label: 'Line separator' },
    { key: 'accent', label: 'Left accent' },
    { key: 'neon', label: 'Neon glow' },
    { key: 'numbered', label: 'Numbered steps' },
    { key: 'badge', label: 'Icon badge' },
    { key: 'glass', label: 'Glassmorphism' },
    { key: 'meta', label: 'Metadata header' },
    { key: 'gradient', label: 'Gradient header' },
    { key: 'pill', label: 'Soft pill' },
    { key: 'elevate', label: 'Elevation pop' },
    { key: 'frame', label: 'Bold frame' },
    { key: 'terminal', label: 'Terminal' },
    { key: 'status', label: 'Status tag' },
    { key: 'strip', label: 'Full-width strip' },
    { key: 'compact', label: 'Compact box' },
    { key: 'tag', label: 'Category tag' },
    { key: 'light', label: 'Light contrast' },
    { key: 'bracket', label: 'Bracket line' },
    { key: 'plusminus', label: 'Plus / minus' },
];

const FAQ_KEYS = FAQ_TEMPLATES.map((t) => t.key);

export default class Faq {
    static get toolbox() {
        return {
            title: 'FAQ',
            icon: '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M9.1 9a3 3 0 0 1 5.8 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        };
    }

    constructor({ data, block }) {
        const items = data && Array.isArray(data.items) ? data.items : [];
        this.items = items.length ? items : [{ question: '', answer: '' }];
        this.openFirst = Boolean(data && data.openFirst);
        this.schema = data && data.schema !== undefined ? Boolean(data.schema) : true;
        this.template = FAQ_KEYS.includes(data && data.template) ? data.template : 'card';
        this.block = block;
        this.wrapper = null;
    }

    /** Called by the sidebar inspector (template + output-only flags). */
    setProp(key, value) {
        if (key === 'template') {
            this.template = FAQ_KEYS.includes(value) ? value : 'card';
            if (this.wrapper) {
                this.wrapper.dataset.template = this.template;
            }
        } else {
            this[key] = Boolean(value);
        }
        window.dispatchEvent(new CustomEvent('magna-blog:changed'));
    }

    render() {
        this.wrapper = document.createElement('div');
        this.wrapper.classList.add('magna-blog-faq');
        this.wrapper.dataset.template = this.template;

        this.list = document.createElement('div');
        this.list.classList.add('magna-blog-faq__list');
        this.wrapper.append(this.list);
        this.items.forEach((item) => this.addItem(item));

        const add = document.createElement('button');
        add.type = 'button';
        add.classList.add('magna-blog-add');
        add.contentEditable = 'false';
        add.textContent = '+ Add question';
        add.addEventListener('click', () => this.addItem({ question: '', answer: '' }));
        this.wrapper.append(add);

        if (this.block && this.block.id) {
            window.magnaBlogBlocks = window.magnaBlogBlocks || {};
            window.magnaBlogBlocks[this.block.id] = this;
        }

        return this.wrapper;
    }

    addItem(item) {
        const row = document.createElement('div');
        row.classList.add('magna-blog-faq__item');

        const head = document.createElement('div');
        head.classList.add('magna-blog-faq__head');

        const question = document.createElement('div');
        question.contentEditable = 'true';
        question.classList.add('magna-blog-faq__q');
        question.dataset.placeholder = 'Question';
        question.textContent = item.question || '';

        // Decorative toggle indicator (chevron / plus / dot), styled per template.
        const marker = document.createElement('span');
        marker.classList.add('magna-blog-faq__marker');
        marker.contentEditable = 'false';
        marker.setAttribute('aria-hidden', 'true');

        head.append(question, marker);

        const answer = document.createElement('div');
        answer.contentEditable = 'true';
        answer.classList.add('magna-blog-faq__a');
        answer.dataset.placeholder = 'Answer';
        answer.innerHTML = item.answer || '';

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.classList.add('magna-blog-remove');
        remove.contentEditable = 'false';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => row.remove());

        row.append(head, answer, remove);
        this.list.append(row);
    }

    save() {
        return {
            items: [...this.list.querySelectorAll('.magna-blog-faq__item')].map((row) => ({
                question: row.querySelector('.magna-blog-faq__q').textContent || '',
                answer: row.querySelector('.magna-blog-faq__a').innerHTML || '',
            })).filter((item) => item.question !== '' || item.answer !== ''),
            openFirst: this.openFirst,
            schema: this.schema,
            template: this.template,
        };
    }
}
