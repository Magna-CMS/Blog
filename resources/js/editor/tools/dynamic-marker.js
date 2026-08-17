/**
 * Factory for simple dynamic placeholder blocks (Post Excerpt, Featured Image).
 * They store no configuration; the delivery API fills them from the post at
 * request time. Each concrete tool sets a static `title` and `blockLabel`.
 */
export function makeDynamicMarker({ title, label, icon }) {
    return class DynamicMarker {
        static get toolbox() {
            return { title, icon };
        }

        render() {
            const wrapper = document.createElement('div');
            wrapper.classList.add('magna-blog-dynamic');
            wrapper.contentEditable = 'false';
            const span = document.createElement('span');
            span.textContent = label;
            wrapper.append(span);
            return wrapper;
        }

        save() {
            return {};
        }
    };
}

const IMAGE_ICON = '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="m21 16-5-5L5 20"/></svg>';
const TEXT_ICON = '<svg width="17" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="4" y1="7" x2="20" y2="7"/><line x1="4" y1="12" x2="20" y2="12"/><line x1="4" y1="17" x2="14" y2="17"/></svg>';

export const PostExcerpt = makeDynamicMarker({
    title: 'Post excerpt',
    label: 'Post excerpt — filled by delivery',
    icon: TEXT_ICON,
});

export const FeaturedImage = makeDynamicMarker({
    title: 'Featured image',
    label: 'Featured image — filled by delivery',
    icon: IMAGE_ICON,
});
