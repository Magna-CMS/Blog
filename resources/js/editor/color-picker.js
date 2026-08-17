/**
 * Global, reusable colour-picker Alpine component: a compact swatch grid, a
 * native colour input and a hex field, with no external dependency. Registered
 * as `magnaColorPicker` and used anywhere a colour is chosen (button/text
 * colour, block backgrounds…). The host passes an initial value and an
 * `onPick(hex)` callback evaluated in the caller's Alpine scope.
 *
 * Values are always `#rrggbb` (or '' for "default/none"). The server sanitiser
 * re-validates the hex, so an arbitrary string can never reach rendered CSS —
 * this component is a convenience, never a security boundary.
 */
export const COLOR_SWATCHES = [
    '#0f172a', '#475569', '#94a3b8', '#ffffff',
    '#ef4444', '#f97316', '#f59e0b', '#eab308',
    '#22c55e', '#10b981', '#06b6d4', '#3b82f6',
    '#6366f1', '#8b5cf6', '#d946ef', '#ec4899',
];

/** Register the `magnaColorPicker` Alpine component (idempotent — safe to re-run). */
export function registerColorPicker() {
    window.Alpine.data('magnaColorPicker', (config) => ({
        open: false,
        value: config && typeof config.value === 'string' ? config.value : '',
        swatches: COLOR_SWATCHES,
        allowClear: !(config && config.allowClear === false),

        pick(hex) {
            this.value = hex;
            if (config && typeof config.onPick === 'function') {
                config.onPick(hex);
            }
        },

        clear() {
            this.pick('');
        },

        isValid(hex) {
            return /^#[0-9a-fA-F]{3,8}$/.test(hex);
        },

        onHex(event) {
            const hex = (event.target.value || '').trim();
            if (this.isValid(hex)) {
                this.pick(hex);
            }
        },

        get display() {
            return this.value || 'transparent';
        },
    }));
}
