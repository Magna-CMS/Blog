@php
    $isEdit          = $this->isEditMode;
    $canPublish      = $this->userCanPublish();
    $isPending       = $isEdit && $this->record?->status?->value === 'pending_review';
    $publishBtnLabel = $isPending
        ? 'Approve & publish'
        : (($isEdit && $this->record?->status?->value === 'published') ? 'Update' : 'Publish post');
    $statusLabel     = match($this->editorStatus) {
        'draft_saved' => 'Draft saved',
        'published'   => 'Published',
        'pending'     => 'Pending review',
        default       => '',
    };
    $autosaveInterval = max(2, (int) \MagnaCms\Blog\Settings\BlogSettings::get()->autosave_interval);
    $previewUrl = $isEdit ? route('blog.editor.preview', $this->record) : null;
@endphp

<x-filament-panels::page>

@push('styles')
<style>
/* Design tokens — dark-mode tokens live on html.de-dark so Livewire morphing
   never strips them. Cloned from the Magna Docs page builder for a consistent
   editing experience across content types. */
:root {
    --de-bg:         #f8fafc;
    --de-bg-panel:   #ffffff;
    --de-bg-main:    #ffffff;
    --de-border:     #e2e8f0;
    --de-text:       #0f172a;
    --de-text-muted: #64748b;
    --de-text-ph:    #94a3b8;
    --de-brand:      #6366f1;
    --de-brand-hov:  #4f46e5;
    --de-danger:     #dc2626;
}
html.de-dark {
    --de-bg:         #020617;
    --de-bg-panel:   #0f172a;
    --de-bg-main:    rgba(15,23,42,.55);
    --de-border:     #1e293b;
    --de-text:       #f1f5f9;
    --de-text-muted: #94a3b8;
    --de-text-ph:    #475569;
    --de-brand-hov:  #818cf8;
}

.doc-editor-root {
    position: fixed !important; inset: 0 !important; z-index: 500 !important;
    display: flex; flex-direction: column; overflow: hidden;
    background: var(--de-bg); color: var(--de-text);
    font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif;
    -webkit-font-smoothing: antialiased;
}
[x-data*="modal"], [x-data*="dialog"], [x-data*="notification"],
.fi-modal, .fi-notification, .fi-notifications,
.fi-modal-container, .fi-notification-container,
#filament-modals, .filament-notifications-container { z-index: 1000 !important; }
/* The takeover canvas is a position:fixed overlay at z-index:500. Filament v4's
   .fi-modal wrapper is position:static, so its z-index is inert; the real
   positioned layer is .fi-modal-window-ctn (position:fixed, z-index:40), which
   would otherwise paint BEHIND the overlay and make createOption / action modals
   invisible. Lift the positioned modal layers above the overlay. */
.fi-modal-window-ctn,
.fi-modal-close-overlay { z-index: 1001 !important; }

.de-topbar {
    height: 56px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: space-between;
    padding: 0 1rem; gap: .75rem;
    background: var(--de-bg-panel); border-bottom: 1px solid var(--de-border);
}
.de-topbar-left, .de-topbar-right { display: flex; align-items: center; gap: .5rem; }

.de-back-btn {
    width: 32px; height: 32px; display: flex; align-items: center; justify-content: center;
    border-radius: 7px; color: var(--de-text-muted);
    cursor: pointer; background: transparent; border: 0;
    transition: background .15s, color .15s; text-decoration: none;
}
.de-back-btn:hover { background: var(--de-border); color: var(--de-text); }
.de-back-btn svg { width: 16px; height: 16px; }

.de-sep { width: 1px; height: 18px; background: var(--de-border); flex-shrink: 0; margin: 0 .125rem; }

.de-brand-wrap { display: flex; align-items: center; gap: .45rem; }
.de-brand-icon  { width: 24px; height: 24px; flex-shrink: 0; }
.de-brand-text  {
    font-size: .72rem; font-weight: 700; color: var(--de-text-muted);
    letter-spacing: .07em; text-transform: uppercase;
}

.mgb-line {
    stroke-dasharray: 28 56; stroke-dashoffset: 84;
    animation: mgb-chase 2s cubic-bezier(0.25,1,0.5,1) infinite;
}
@keyframes mgb-chase {
    0%   { stroke-dasharray: 15 69; stroke-dashoffset: 84; }
    40%  { stroke-dasharray: 38 46; }
    100% { stroke-dasharray: 15 69; stroke-dashoffset: 0; }
}

.de-status-badge {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 2px 9px; border-radius: 999px;
    font-size: .7rem; font-weight: 600; letter-spacing: .01em;
}
.de-status-badge.is-draft  { background: #f0fdf4; color: #15803d; }
.de-status-badge.is-pub    { background: #eef2ff; color: #4338ca; }
html.de-dark .de-status-badge.is-draft { background: rgba(22,101,52,.25); color: #4ade80; }
html.de-dark .de-status-badge.is-pub   { background: rgba(67,56,202,.25); color: #818cf8; }
.de-status-dot {
    width: 6px; height: 6px; border-radius: 50%; background: currentColor;
    animation: de-pulse 2s ease-in-out infinite;
}
@keyframes de-pulse { 0%,100%{opacity:1} 50%{opacity:.35} }

.de-btn {
    display: inline-flex; align-items: center; gap: .35rem;
    padding: .38rem .8rem; border-radius: 7px;
    font-size: .8rem; font-weight: 500; border: 0;
    cursor: pointer; transition: background .15s, color .15s, opacity .15s;
    white-space: nowrap; color: var(--de-text-muted); background: transparent;
    text-decoration: none; line-height: 1;
}
.de-btn:hover { background: var(--de-border); color: var(--de-text); text-decoration: none; }
.de-btn svg { width: 14px; height: 14px; flex-shrink: 0; }
.de-btn-icon { width: 32px; height: 32px; padding: 0; justify-content: center; }
.de-btn-draft { border: 1px solid var(--de-border); color: var(--de-text-muted); background: transparent; }
.de-btn-draft:hover { background: var(--de-border); color: var(--de-text); }
.de-btn-publish {
    background: var(--de-brand); color: #fff; font-weight: 600;
    box-shadow: 0 1px 3px rgba(99,102,241,.25);
}
.de-btn-publish:hover { background: var(--de-brand-hov); box-shadow: 0 2px 8px rgba(99,102,241,.35); }
.de-btn-delete { color: var(--de-danger); border: 1px solid transparent; }
.de-btn-delete:hover { background: #fef2f2; border-color: #fecaca; }
html.de-dark .de-btn-delete:hover { background: rgba(220,38,38,.12); border-color: rgba(220,38,38,.3); }
.de-btn:disabled, .de-btn[disabled] { opacity: .5; pointer-events: none; }

.de-body { flex: 1; display: flex; overflow: hidden; }

.de-canvas {
    flex: 1; min-width: 0; overflow-y: auto;
    background: var(--de-bg-main); padding: 3rem 5rem 6rem;
}
.de-canvas::-webkit-scrollbar { width: 5px; }
.de-canvas::-webkit-scrollbar-thumb { background: var(--de-border); border-radius: 99px; }
.de-canvas-inner { max-width: 780px; margin: 0 auto; }

.de-canvas-inner .fi-form,
.de-canvas-inner .fi-fo-component-ctn,
.de-canvas-inner [class*="fi-fo-grid"] {
    display: flex !important; flex-direction: column !important; gap: 0 !important;
}

.de-canvas-inner input.doc-editor-title-input {
    font-size: 2.25rem !important; font-weight: 800 !important;
    letter-spacing: -.025em !important; line-height: 1.15 !important;
    color: var(--de-text) !important; background: transparent !important;
    border: 0 !important; border-bottom: 1px solid transparent !important;
    border-radius: 0 !important; box-shadow: none !important;
    padding: .25rem 0 .5rem !important; width: 100%; outline: none; display: block !important;
}
.de-canvas-inner input.doc-editor-title-input::placeholder {
    color: var(--de-text-ph) !important; font-weight: 800 !important; opacity: 1 !important;
}
.de-canvas-inner input.doc-editor-title-input:focus { border-bottom-color: var(--de-border) !important; }
.doc-editor-root .de-canvas-inner .fi-input-wrp:has(input.doc-editor-title-input),
.doc-editor-root .de-canvas-inner .fi-input-wrp:has(input.doc-editor-title-input) .fi-input-wrp-content-ctn {
    background: transparent !important; background-color: transparent !important;
    border: none !important; box-shadow: none !important;
    padding: 0 !important; border-radius: 0 !important; --tw-ring-shadow: 0 0 !important;
}
.doc-editor-root .de-canvas-inner .fi-fo-field:has(input.doc-editor-title-input) {
    width: 100% !important; margin-bottom: 1rem !important;
}

/* Editor.js canvas — borderless inside the takeover, tall like the Docs editor. */
.de-canvas-inner .magna-blog-editor {
    min-height: 520px; border: 0 !important; padding: 0 !important; background: transparent !important;
}
.de-canvas-inner .fi-fo-field-wrp:has(.magna-blog-editor),
.de-canvas-inner .fi-fo-field:has(.magna-blog-editor) { width: 100% !important; }

.de-sidebar {
    width: 320px; flex-shrink: 0; overflow-y: auto;
    display: flex; flex-direction: column;
    background: var(--de-bg-panel); border-left: 1px solid var(--de-border);
}
.de-sidebar::-webkit-scrollbar { width: 4px; }
.de-sidebar::-webkit-scrollbar-thumb { background: var(--de-border); border-radius: 99px; }
.de-sidebar-tabs {
    display: flex; border-bottom: 1px solid var(--de-border);
    padding: .5rem .75rem; gap: .25rem; flex-shrink: 0;
}
.de-sidebar-tab {
    flex: 1; padding: .35rem .5rem; border-radius: 6px;
    font-size: .78rem; font-weight: 600; border: 0; cursor: pointer;
    text-align: center; background: var(--de-border); color: var(--de-text-muted);
    transition: background .15s, color .15s;
}
.de-sidebar-tab.is-active { background: var(--de-brand); color: #fff; }
.de-inspector-empty { padding: 2.5rem 1rem; color: var(--de-text-muted); font-size: .82rem; text-align: center; }
.de-inspector { padding: 1rem; }
.de-inspector-type { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--de-text-muted); margin-bottom: .85rem; }
.de-inspector-group { padding-bottom: .9rem; margin-bottom: .9rem; border-bottom: 1px solid var(--de-border); }
.de-inspector-group:last-child { border-bottom: 0; margin-bottom: 0; padding-bottom: 0; }
.de-inspector-label { font-size: .68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .08em; color: var(--de-text-muted); margin-bottom: .6rem; }
.de-inspector-field { margin-bottom: .8rem; }
.de-inspector-field label { display: block; font-size: .78rem; font-weight: 500; margin-bottom: .3rem; }
.de-inspector-field select {
    width: 100%; height: 34px; border-radius: 7px; border: 1px solid var(--de-border);
    background: var(--de-bg-panel); color: var(--de-text); font-size: .82rem; padding: 0 .55rem; cursor: pointer;
}
html.de-dark .de-inspector-field select { background: #172033; border-color: #3c4a63; }

/* Text input */
.de-input {
    width: 100%; height: 34px; border-radius: 7px; border: 1px solid var(--de-border);
    background: var(--de-bg-panel); color: var(--de-text); font-size: .82rem; padding: 0 .55rem;
}
html.de-dark .de-input { background: #172033; border-color: #3c4a63; }
.de-input:focus { outline: none; border-color: var(--de-brand); }

/* Segmented control */
.de-segmented { display: flex; gap: 2px; padding: 3px; border-radius: 9px; background: var(--de-bg-muted, #eef0f4); }
html.de-dark .de-segmented { background: #131b2b; }
.de-segmented button {
    flex: 1; border: 0; background: transparent; color: var(--de-text-muted); cursor: pointer;
    font-size: .76rem; font-weight: 600; padding: .35rem .2rem; border-radius: 6px; transition: all .12s;
}
.de-segmented button:hover { color: var(--de-text); }
.de-segmented button.is-active { background: var(--de-brand); color: #fff; }

/* Range slider */
.de-range { width: 100%; accent-color: var(--de-brand); cursor: pointer; }
.de-range-value { float: right; font-weight: 600; color: var(--de-text-muted); }

/* Toggle rows */
.de-toggle { display: flex !important; align-items: center; gap: .5rem; font-size: .8rem !important;
    font-weight: 500 !important; margin-bottom: .5rem !important; cursor: pointer; }
.de-toggle input { accent-color: var(--de-brand); width: 15px; height: 15px; cursor: pointer; }
.de-toggle code { font-size: .72rem; background: var(--de-bg-muted, #eef0f4); padding: 0 .25rem; border-radius: 3px; }
html.de-dark .de-toggle code { background: #131b2b; }

/* Colour picker */
.de-colorpicker { position: relative; }
.de-colorpicker__trigger {
    width: 100%; display: flex; align-items: center; gap: .5rem; height: 34px; padding: 0 .55rem;
    border-radius: 7px; border: 1px solid var(--de-border); background: var(--de-bg-panel);
    color: var(--de-text); cursor: pointer; font-size: .8rem;
}
html.de-dark .de-colorpicker__trigger { background: #172033; border-color: #3c4a63; }
.de-colorpicker__swatch { width: 18px; height: 18px; border-radius: 5px; border: 1px solid rgba(0,0,0,.15);
    background-image: linear-gradient(45deg,#ccc 25%,transparent 25%),linear-gradient(-45deg,#ccc 25%,transparent 25%),linear-gradient(45deg,transparent 75%,#ccc 75%),linear-gradient(-45deg,transparent 75%,#ccc 75%);
    background-size: 8px 8px; background-position: 0 0,0 4px,4px -4px,-4px 0; flex-shrink: 0; }
.de-colorpicker__hex { flex: 1; text-align: left; text-transform: uppercase; letter-spacing: .02em; }
.de-colorpicker__pop {
    position: absolute; z-index: 40; top: calc(100% + 6px); left: 0; right: 0; padding: .7rem;
    border-radius: 10px; border: 1px solid var(--de-border); background: var(--de-bg-panel);
    box-shadow: 0 12px 30px rgba(0,0,0,.18);
}
html.de-dark .de-colorpicker__pop { background: #172033; border-color: #3c4a63; box-shadow: 0 12px 30px rgba(0,0,0,.5); }
.de-colorpicker__grid { display: grid; grid-template-columns: repeat(8, 1fr); gap: 5px; margin-bottom: .6rem; }
.de-colorpicker__cell { aspect-ratio: 1; border-radius: 5px; border: 1px solid rgba(0,0,0,.12); cursor: pointer; padding: 0; }
.de-colorpicker__cell.is-active { outline: 2px solid var(--de-brand); outline-offset: 1px; }
.de-colorpicker__custom { display: flex; align-items: center; gap: .4rem; }
.de-colorpicker__custom input[type=color] { width: 32px; height: 32px; padding: 0; border: 1px solid var(--de-border); border-radius: 6px; background: none; cursor: pointer; }
.de-colorpicker__hexinput { height: 32px; }
.de-colorpicker__clear { height: 32px; padding: 0 .6rem; border-radius: 6px; border: 1px solid var(--de-border);
    background: transparent; color: var(--de-text-muted); font-size: .74rem; cursor: pointer; white-space: nowrap; }

.de-add-btn { width: 100%; margin-top: .3rem; padding: .5rem; border-radius: 8px; border: 1px dashed var(--de-brand);
    background: transparent; color: var(--de-brand); font-size: .8rem; font-weight: 600; cursor: pointer; }
.de-add-btn:hover { background: color-mix(in srgb, var(--de-brand) 10%, transparent); }

/* Paragraph template gallery */
.de-tpl-grid { display: grid; grid-template-columns: 1fr 1fr; gap: .5rem; }
.de-tpl { display: flex; flex-direction: column; gap: .35rem; padding: .4rem; border: 1px solid var(--de-border);
    border-radius: 9px; background: var(--de-bg-panel); cursor: pointer; text-align: left; }
html.de-dark .de-tpl { background: #172033; border-color: #3c4a63; }
.de-tpl:hover { border-color: var(--de-brand); }
.de-tpl.is-active { border-color: var(--de-brand); box-shadow: 0 0 0 1px var(--de-brand); }
.de-tpl-name { font-size: .68rem; font-weight: 600; color: var(--de-text-muted); }
.de-tpl.is-active .de-tpl-name { color: var(--de-text); }
/* Mini-preview: three faux text lines inside a small canvas, restyled per template */
.de-tpl-preview { position: relative; display: block; height: 46px; border-radius: 5px; overflow: hidden;
    background: var(--de-bg-muted, #eef0f4); padding: 7px; }
html.de-dark .de-tpl-preview { background: #0f1626; }
.de-tpl-preview i { display: block; height: 3px; border-radius: 2px; background: #9aa4bd; margin-bottom: 4px; }
.de-tpl-preview .p1 { width: 90%; } .de-tpl-preview .p2 { width: 100%; } .de-tpl-preview .p3 { width: 60%; }
/* per-template */
.de-tpl-preview[data-template="lead"] i { height: 4px; background: #64708c; }
.de-tpl-preview[data-template="dropcap"]::before { content: 'A'; position: absolute; left: 7px; top: 4px; font-size: 22px;
    font-weight: 800; color: var(--de-brand); line-height: 1; }
.de-tpl-preview[data-template="dropcap"] i { margin-left: 20px; width: calc(100% - 26px) !important; }
.de-tpl-preview[data-template="pullquote"] { border-top: 2px solid #9aa4bd; border-bottom: 2px solid #9aa4bd; background: transparent; }
.de-tpl-preview[data-template="pullquote"] i { margin: 3px auto; background: #7c86a0; }
.de-tpl-preview[data-template="callout"] { border-left: 3px solid var(--de-brand); background: color-mix(in srgb, var(--de-brand) 12%, transparent); }
.de-tpl-preview[data-template="takeaway"]::before { content: ''; position: absolute; left: 7px; top: 6px; width: 26px; height: 6px; border-radius: 3px; background: #14b8a6; }
.de-tpl-preview[data-template="takeaway"] i { margin-top: 4px; } .de-tpl-preview[data-template="takeaway"] .p1 { margin-top: 14px; }
.de-tpl-preview[data-template="card"] { background: var(--de-bg-panel); box-shadow: 0 2px 6px rgba(0,0,0,.18); }
.de-tpl-preview[data-template="twocol"] { column-count: 2; column-gap: 8px; }
.de-tpl-preview[data-template="image-left"], .de-tpl-preview[data-template="image-right"] { display: grid; grid-template-columns: 20px 1fr; gap: 6px; align-items: center; }
.de-tpl-preview[data-template="image-right"] { grid-template-columns: 1fr 20px; }
.de-tpl-preview[data-template="image-left"]::before, .de-tpl-preview[data-template="image-right"]::before {
    content: ''; height: 32px; border-radius: 4px; background: linear-gradient(135deg, var(--de-brand), #8b5cf6); }
.de-tpl-preview[data-template="image-right"]::before { order: 2; }
.de-tpl-preview[data-template="image-left"] i, .de-tpl-preview[data-template="image-right"] i { width: 100% !important; }

/* FAQ template gallery mini-preview: two faux accordion rows (a title bar + a
   toggle dot), restyled per template. */
.de-faq-preview { display: flex; flex-direction: column; gap: 5px; height: 46px; border-radius: 5px; overflow: hidden;
    background: var(--de-bg-muted, #eef0f4); padding: 7px; }
html.de-dark .de-faq-preview { background: #0f1626; }
.de-faq-preview .row { display: flex; align-items: center; justify-content: space-between; gap: 6px;
    padding: 4px 6px; border-radius: 4px; background: #ffffff; }
html.de-dark .de-faq-preview .row { background: #172033; }
.de-faq-preview .bar { display: block; height: 3px; border-radius: 2px; background: #9aa4bd; flex: 1; }
.de-faq-preview .dot { width: 6px; height: 6px; border-radius: 50%; background: #c3cad8; flex-shrink: 0; }
/* per-template accents (mirror the frontend look, kept intentionally simple) */
.de-faq-preview[data-template="line"] .row { background: transparent; border-bottom: 1px solid #c3cad8; border-radius: 0; }
.de-faq-preview[data-template="accent"] .row { border-left: 3px solid #10b981; }
.de-faq-preview[data-template="neon"] .row { background: #1e293b; box-shadow: 0 0 6px rgba(168,85,247,.5); }
.de-faq-preview[data-template="neon"] .bar { background: #cbd5e1; }
.de-faq-preview[data-template="numbered"] .dot { width: auto; height: auto; border-radius: 0; background: transparent; }
.de-faq-preview[data-template="numbered"] .row::before { content: '1'; font-size: 8px; font-weight: 800; color: var(--de-brand); margin-right: 4px; }
.de-faq-preview[data-template="numbered"] .row:last-child::before { content: '2'; }
.de-faq-preview[data-template="badge"] .row::before { content: ''; width: 8px; height: 8px; border-radius: 3px; background: rgba(99,102,241,.35); margin-right: 4px; flex-shrink: 0; }
.de-faq-preview[data-template="glass"] .row { background: rgba(255,255,255,.55); backdrop-filter: blur(4px); }
.de-faq-preview[data-template="gradient"] .row { background: linear-gradient(135deg,#1e1b4b,#311b92); }
.de-faq-preview[data-template="gradient"] .bar { background: #c7d2fe; }
.de-faq-preview[data-template="pill"] .row { border-radius: 999px; }
.de-faq-preview[data-template="pill"] .dot { background: #3b82f6; }
.de-faq-preview[data-template="elevate"] .row { box-shadow: 0 3px 6px rgba(0,0,0,.25); }
.de-faq-preview[data-template="frame"] .row { background: transparent; border: 1.5px solid #c3cad8; }
.de-faq-preview[data-template="terminal"] .row { background: #020617; border: 1px solid #0284c7; border-radius: 2px; }
.de-faq-preview[data-template="terminal"] .bar { background: #38bdf8; }
.de-faq-preview[data-template="status"] .row::before { content: ''; width: 6px; height: 6px; border-radius: 50%; background: #22c55e; margin-right: 4px; flex-shrink: 0; }
.de-faq-preview[data-template="strip"] .row { background: transparent; border-bottom: 2px solid #c3cad8; border-radius: 0; padding-left: 0; padding-right: 0; }
.de-faq-preview[data-template="compact"] { gap: 3px; }
.de-faq-preview[data-template="compact"] .row { padding: 2px 5px; background: #18181b; }
.de-faq-preview[data-template="compact"] .bar { background: #a1a1aa; }
.de-faq-preview[data-template="tag"] .dot { width: 12px; border-radius: 3px; background: #a5b4fc; }
.de-faq-preview[data-template="light"] .row { background: #f8fafc; border: 1px solid #e2e8f0; }
.de-faq-preview[data-template="light"] .bar { background: #64748b; }
.de-faq-preview[data-template="bracket"] .row { background: transparent; border-left: 2px solid var(--de-brand); border-radius: 0; }
.de-faq-preview[data-template="plusminus"] .dot { width: 9px; height: 9px; border-radius: 0; background: transparent;
    background-image: linear-gradient(var(--de-text-muted,#64748b),var(--de-text-muted,#64748b)),linear-gradient(var(--de-text-muted,#64748b),var(--de-text-muted,#64748b));
    background-size: 9px 1.5px, 1.5px 9px; background-position: center; background-repeat: no-repeat; }

.de-sidebar-body { flex: 1; }
.de-sidebar-body .fi-section {
    border: none !important; border-radius: 0 !important;
    box-shadow: none !important; background: transparent !important;
    border-bottom: 1px solid var(--de-border) !important;
}
.de-sidebar-body .fi-section-header,
.de-sidebar-body button.fi-section-header,
.de-sidebar-body .fi-section-header-ctn { padding: .75rem 1rem !important; background: transparent !important; }
.de-sidebar-body .fi-section-header-heading {
    font-size: .68rem !important; font-weight: 700 !important;
    text-transform: uppercase !important; letter-spacing: .08em !important;
    color: var(--de-text-muted) !important;
}
.de-sidebar-body .fi-section-content,
.de-sidebar-body .fi-section-content-ctn { padding: .25rem 1rem .875rem !important; }
.de-sidebar-body label.fi-label,
.de-sidebar-body .fi-fo-field-wrp-label { font-size: .78rem !important; font-weight: 500 !important; }
.de-sidebar-body .fi-fo-helper-text,
.de-sidebar-body .fi-fo-field-wrp-helper-text { font-size: .7rem !important; }
.de-sidebar-body .fi-fo-placeholder { font-size: .82rem !important; color: var(--de-text) !important; }

html.de-dark .doc-editor-root .fi-input:not(.doc-editor-title-input),
html.de-dark .doc-editor-root .fi-select-input,
html.de-dark .doc-editor-root textarea {
    background: transparent !important; color: #f1f5f9 !important; border-color: transparent !important;
}
html.de-dark .doc-editor-root .fi-input-wrp,
html.de-dark .doc-editor-root .fi-input-wrapper {
    background: #172033 !important; border: 1px solid #334155 !important; box-shadow: none !important;
}
html.de-dark .doc-editor-root .fi-input-wrp-content-ctn,
html.de-dark .doc-editor-root .fi-input-wrp > [class*="fi-input-wrp-"],
html.de-dark .doc-editor-root .fi-input-wrp-prefix,
html.de-dark .doc-editor-root .fi-input-wrp-suffix {
    background: transparent !important; border: 0 !important; box-shadow: none !important; border-radius: 0 !important;
}
html.de-dark .doc-editor-root .fi-input-wrp:focus-within { border-color: var(--de-brand) !important; }
html.de-dark .doc-editor-root .fi-fo-field-wrp-label,
html.de-dark .doc-editor-root label.fi-label { color: #94a3b8 !important; }
html.de-dark .doc-editor-root .fi-fo-helper-text { color: #475569 !important; }
html.de-dark .doc-editor-root .fi-section-header-heading { color: #94a3b8 !important; }
html.de-dark .doc-editor-root .fi-fo-placeholder { color: #e2e8f0 !important; }
html.de-dark .doc-editor-root .de-sidebar-tab { background: #1e293b; color: #f1f5f9; }
html.de-dark .doc-editor-root .magna-blog-editor { color: #f1f5f9; }

html.de-dark .doc-editor-root .fi-select-input,
html.de-dark .doc-editor-root .fi-select-input * {
    background-color: transparent !important; color: #f1f5f9 !important; border-color: transparent !important;
}
html.de-dark .doc-editor-root .fi-select-input input::placeholder,
html.de-dark .doc-editor-root .fi-select-input [class*="placeholder"] { color: #94a3b8 !important; }
html.de-dark .doc-editor-root .fi-select-input [role="listbox"],
html.de-dark .doc-editor-root .fi-select-input [class*="dropdown"] {
    background-color: #172033 !important; border: 1px solid #334155 !important;
    border-radius: 10px !important; box-shadow: 0 10px 30px rgba(0,0,0,.5) !important; overflow: hidden !important;
}
html.de-dark .doc-editor-root .fi-select-input [role="listbox"] *,
html.de-dark .doc-editor-root .fi-select-input [class*="dropdown"] * {
    background-color: transparent !important; border-color: transparent !important;
    box-shadow: none !important; border-radius: 0 !important;
}
html.de-dark .doc-editor-root .fi-select-input [role="option"]:hover,
html.de-dark .doc-editor-root .fi-select-input [role="option"][aria-selected="true"] { background-color: #24304a !important; }
html.de-dark .doc-editor-root select:not(.fi-hidden):not([class*="hidden"]) {
    background-color: #172033 !important; color: #f1f5f9 !important; border: 1px solid #334155 !important;
}
html.de-dark .doc-editor-root [class*="date-time-picker"] input {
    background-color: transparent !important; color: #f1f5f9 !important; border-color: transparent !important;
}

.doc-editor-root .de-browse-link,
.doc-editor-root button.de-browse-link {
    display: flex; align-items: center; justify-content: center; gap: .35rem;
    width: 100%; margin: .55rem 0 0; padding: .45rem .6rem;
    background: transparent; border: 1px dashed var(--de-border); border-radius: 8px; box-shadow: none;
    color: var(--de-brand); font-size: .78rem; font-weight: 500; cursor: pointer; text-decoration: none;
    transition: border-color .15s, background .15s;
}
.doc-editor-root .de-browse-link:hover { border-color: var(--de-brand); background: rgba(99,102,241,.05); color: var(--de-brand-hov); }
.doc-editor-root .de-browse-link svg { width: 15px; height: 15px; }
html.de-dark .doc-editor-root .de-browse-link { color: var(--de-brand-hov); }

.de-featured-preview {
    position: relative; border-radius: 10px; overflow: hidden;
    border: 1px solid var(--de-border); aspect-ratio: 16 / 9; background: var(--de-border);
}
.de-featured-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
.de-featured-preview-remove {
    position: absolute; top: .45rem; right: .45rem;
    width: 26px; height: 26px; border-radius: 7px; border: 0;
    background: rgba(0,0,0,.6); color: #fff; cursor: pointer;
    font-size: 1.1rem; line-height: 1; display: flex; align-items: center; justify-content: center;
    transition: background .15s;
}
.de-featured-preview-remove:hover { background: rgba(0,0,0,.82); }
.de-featured-preview-tag {
    position: absolute; bottom: 0; left: 0; right: 0; padding: .3rem .55rem;
    background: linear-gradient(to top, rgba(0,0,0,.6), transparent);
    color: #fff; font-size: .65rem; font-weight: 500;
}

.de-topbar .de-btn-draft,
.de-topbar .de-btn-publish { padding-top: .6rem; padding-bottom: .6rem; }

.de-published-toast {
    position: fixed; bottom: 1.5rem; left: 50%; transform: translateX(-50%);
    z-index: 700;
    display: flex; align-items: center; gap: .625rem;
    padding: .75rem 1.25rem; border-radius: 10px;
    background: #16a34a; color: #fff;
    font-size: .875rem; font-weight: 600;
    box-shadow: 0 8px 32px rgba(22,163,74,.35);
    animation: de-toast-in .25s ease forwards;
}
.de-published-toast svg { width: 18px; height: 18px; flex-shrink: 0; }
[x-cloak] { display: none !important; }
@keyframes de-toast-in {
    from { opacity: 0; transform: translateX(-50%) translateY(12px); }
    to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

@media (max-width: 900px) { .de-sidebar { display: none; } .de-canvas { padding: 2rem 1.5rem 4rem; } }
@media (max-width: 640px) { .de-canvas-inner input.doc-editor-title-input { font-size: 1.75rem !important; } .de-canvas { padding: 1.5rem 1rem 3rem; } }
</style>
@endpush

<script>
/* Editor theme management — dark class lives on <html> so Livewire morphing
   never strips it. */
(function () {
    var KEY = 'blog-editor-theme';

    function getStored() {
        return localStorage.getItem(KEY)
            || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    }

    function applyTheme(theme) {
        var html = document.documentElement;
        if (theme === 'dark') { html.classList.add('de-dark', 'dark'); }
        else { html.classList.remove('de-dark', 'dark'); }
        var moon = document.getElementById('de-icon-moon');
        var sun  = document.getElementById('de-icon-sun');
        if (moon) moon.style.display = theme === 'dark' ? 'none' : '';
        if (sun)  sun.style.display  = theme === 'dark' ? ''     : 'none';
    }

    function toggleTheme() {
        var next = document.documentElement.classList.contains('de-dark') ? 'light' : 'dark';
        localStorage.setItem(KEY, next);
        applyTheme(next);
    }

    function init() {
        applyTheme(getStored());
        var btn = document.getElementById('de-theme-toggle');
        if (btn && !btn._deInit) { btn._deInit = true; btn.addEventListener('click', toggleTheme); }
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();

    document.addEventListener('livewire:updated', function () { applyTheme(getStored()); });
    document.addEventListener('livewire:navigated', init);
})();
</script>

<div class="doc-editor-root" id="blogEditorRoot"
    @if ($previewUrl) data-preview-url="{{ $previewUrl }}" @endif
    x-data="{
        dirty: false,
        iv: null,
        async flush() {
            window.dispatchEvent(new CustomEvent('magna-blog:flush'));
            await Promise.race([
                new Promise((r) => document.addEventListener('magna-blog:flushed', r, { once: true })),
                new Promise((r) => setTimeout(r, 800)),
            ]);
        },
        async flushThen(method) { await this.flush(); this.dirty = false; return this.$wire[method](); },
        savePreview() {
            try {
                window.dispatchEvent(new CustomEvent('magna-blog:flush'));
                this.dirty = false;
                this.$wire.autosave();
            } catch (e) { /* the tab still opens to the last saved content */ }
        },
    }"
    {{-- Debounced autosave: the timer resets on every keystroke and fires only
         after the user pauses (autosave interval), so it never runs mid-typing.
         This matters most on the Create page, where an autosave creates + would
         otherwise reload the page. NOTE: no // comments inside x-init — Alpine
         collapses the expression and a line comment would swallow the rest. --}}
    x-init="
        const save = async () => { if (dirty) { dirty = false; await flush(); $wire.autosave(); } };
        const schedule = () => { clearTimeout(iv); iv = setTimeout(save, {{ $autosaveInterval * 1000 }}); };
        const mark = () => { dirty = true; schedule(); };
        $el.addEventListener('input', mark);
        $el.addEventListener('keyup', mark);
        const warn = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
        window.addEventListener('beforeunload', warn);
        document.addEventListener('livewire:navigating', () => { clearTimeout(iv); dirty = false; });
    "
    @blog-autosaved.window="dirty = false"
>

    {{-- ── TOPBAR ───────────────────────────────────────────────────────── --}}
    <header class="de-topbar">

        <div class="de-topbar-left">
            <a href="{{ $this->getResource()::getUrl('index') }}" class="de-back-btn" title="All posts">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                     stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <polyline points="15 18 9 12 15 6"/>
                </svg>
            </a>

            <div class="de-sep"></div>

            <div class="de-brand-wrap">
                <svg class="de-brand-icon" viewBox="0 0 34 34" fill="none"
                     xmlns="http://www.w3.org/2000/svg" aria-hidden="true" focusable="false">
                    <defs>
                        <linearGradient id="de-grad" x1="0" y1="0" x2="34" y2="34">
                            <stop stop-color="#6366f1"/>
                            <stop offset="1" stop-color="#8b5cf6"/>
                        </linearGradient>
                        <linearGradient id="de-neon" x1="0" y1="0" x2="34" y2="34">
                            <stop offset="0%" stop-color="#00f2fe"/>
                            <stop offset="50%" stop-color="#4facfe"/>
                            <stop offset="100%" stop-color="#f355da"/>
                        </linearGradient>
                    </defs>
                    <path d="M17 1 31 9v16l-14 8L3 25V9l14-8Z"
                          stroke="url(#de-grad)" stroke-width="2.4" fill="rgba(99,102,241,.06)"/>
                    <path class="mgb-line" d="M17 1 31 9v16l-14 8L3 25V9l14-8Z"
                          stroke="url(#de-neon)" stroke-width="2.6" stroke-linecap="round"/>
                    <path d="M10 23V11.5l7 6 7-6V23"
                          stroke="url(#de-grad)" stroke-width="2.4"
                          stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                </svg>
                <span class="de-brand-text">Magna Blog</span>
            </div>

            @if ($statusLabel)
                <div class="de-sep"></div>
                <span class="de-status-badge {{ $this->editorStatus === 'published' ? 'is-pub' : 'is-draft' }}"
                      role="status" aria-live="polite">
                    <span class="de-status-dot" aria-hidden="true"></span>
                    {{ $statusLabel }}
                </span>
            @endif
        </div>

        <div class="de-topbar-right">

            @if ($previewUrl)
                {{-- A real new-tab anchor, NOT a JS pop-up. The browser opens the
                     tab natively on click, so it can never be pop-up-blocked and it
                     keeps working even if the Alpine/Livewire scripting on this page
                     breaks. The @click only flushes + autosaves so the opened tab
                     shows the latest edits; navigation is native (no preventDefault),
                     so nothing here can stop the tab from opening. Do NOT convert
                     this back to a <button>+window.open — that regresses to a
                     blockable, JS-dependent pop-up. --}}
                <a class="de-btn" href="{{ $previewUrl }}" target="_blank" rel="noopener"
                   title="Save and preview the current content"
                   x-on:click="savePreview()">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7Z"/><circle cx="12" cy="12" r="3"/>
                    </svg>
                    <span>Preview</span>
                </a>
                <div class="de-sep"></div>
            @endif

            <button id="de-theme-toggle" class="de-btn de-btn-icon" type="button"
                    title="Toggle dark / light mode" aria-label="Toggle dark mode">
                <svg id="de-icon-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                </svg>
                <svg id="de-icon-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                     style="display:none" aria-hidden="true">
                    <circle cx="12" cy="12" r="5"/>
                    <line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/>
                    <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/>
                    <line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/>
                    <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                </svg>
            </button>

            <div class="de-sep"></div>

            @if ($isEdit)
                <button class="de-btn de-btn-delete" type="button"
                        wire:click="deletePost"
                        wire:confirm="Move this post to trash?"
                        wire:loading.attr="disabled" wire:target="deletePost" title="Delete post">
                    <svg wire:loading.remove wire:target="deletePost" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2" stroke-linecap="round"
                         stroke-linejoin="round" aria-hidden="true">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14H6L5 6"/>
                        <path d="M10 11v6M14 11v6M9 6V4h6v2"/>
                    </svg>
                    <span wire:loading.remove wire:target="deletePost">Delete</span>
                    <span wire:loading wire:target="deletePost">Deleting…</span>
                </button>
                <div class="de-sep"></div>
            @endif

            <button type="button" class="de-btn de-btn-draft"
                    x-on:click="flushThen('saveDraft')"
                    wire:loading.attr="disabled"
                    wire:target="saveDraft,publish,deletePost">
                <span wire:loading.remove wire:target="saveDraft">Save Draft</span>
                <span wire:loading wire:target="saveDraft">Saving…</span>
            </button>

            @if ($canPublish)
                @if ($isPending)
                    <button type="button" class="de-btn de-btn-draft"
                            x-on:click="$wire.sendBack(window.prompt('Reason for sending back (optional):') || '')"
                            wire:loading.attr="disabled"
                            wire:target="saveDraft,publish,sendBack,deletePost">
                        <span wire:loading.remove wire:target="sendBack">Send back</span>
                        <span wire:loading wire:target="sendBack">Sending…</span>
                    </button>
                @endif

                <button type="button" class="de-btn de-btn-publish"
                        x-on:click="flushThen('publish')"
                        wire:loading.attr="disabled"
                        wire:target="saveDraft,publish,sendBack,deletePost">
                    <span wire:loading.remove wire:target="publish">{{ $publishBtnLabel }}</span>
                    <span wire:loading wire:target="publish">Publishing…</span>
                </button>
            @else
                <button type="button" class="de-btn de-btn-publish"
                        x-on:click="flushThen('submitForReview')"
                        wire:loading.attr="disabled"
                        wire:target="saveDraft,submitForReview,deletePost">
                    <span wire:loading.remove wire:target="submitForReview">Submit for review</span>
                    <span wire:loading wire:target="submitForReview">Submitting…</span>
                </button>
            @endif

        </div>
    </header>

    {{-- ── BODY ─────────────────────────────────────────────────────────── --}}
    <div class="de-body">

        <main class="de-canvas">
            <div class="de-canvas-inner">
                {{ $this->form }}
            </div>
        </main>

        <aside class="de-sidebar" aria-label="Post settings" x-data="magnaBlogSidebar()">
            <div class="de-sidebar-tabs" role="tablist">
                <button type="button" class="de-sidebar-tab" :class="{ 'is-active': tab === 'general' }"
                        role="tab" @click="tab = 'general'">General</button>
                <button type="button" class="de-sidebar-tab" :class="{ 'is-active': tab === 'block' }"
                        role="tab" @click="tab = 'block'">Block</button>
                <button type="button" class="de-sidebar-tab" :class="{ 'is-active': tab === 'seo' }"
                        role="tab" @click="tab = 'seo'">SEO</button>
            </div>

            {{-- General: the post settings form --}}
            <div class="de-sidebar-body" x-show="tab === 'general'">
                {{ $this->sidebarForm }}
            </div>

            {{-- Block: contextual settings for the selected block --}}
            <div class="de-sidebar-body" x-show="tab === 'block'" x-cloak>
                <template x-if="mode === 'generic' && ! block">
                    <div class="de-inspector-empty">Select a block to edit its settings.</div>
                </template>

                {{-- Generic inspector: scalar data fields + universal style tune --}}
                <template x-if="mode === 'generic' && block">
                    <div class="de-inspector">
                        <div class="de-inspector-type" x-text="label(block.type)"></div>

                        {{-- Paragraph template gallery --}}
                        <template x-if="block.type === 'paragraph'">
                            <div class="de-inspector-group">
                                <div class="de-inspector-label">Template</div>
                                <div class="de-tpl-grid">
                                    <template x-for="t in (window.magnaBlog?.paragraphTemplates || [])" :key="t.key">
                                        <button type="button" class="de-tpl" :class="{ 'is-active': paraTemplate === t.key }"
                                                @click="setTemplate(t.key)" :title="t.label">
                                            <span class="de-tpl-preview" :data-template="t.key" aria-hidden="true">
                                                <i class="p1"></i><i class="p2"></i><i class="p3"></i>
                                            </span>
                                            <span class="de-tpl-name" x-text="t.label"></span>
                                        </button>
                                    </template>
                                </div>
                                <template x-if="paraNeedsImage">
                                    <div>
                                        <button type="button" class="de-add-btn" @click="pickParaImage()">＋ Choose image</button>
                                        <div class="de-inspector-field" style="margin-top:.6rem">
                                            <label>Image alt text (accessibility)</label>
                                            <input type="text" class="de-input" placeholder="Describe the image"
                                                   :value="paraImageAlt" @input="setParaImageAlt($event.target.value)">
                                        </div>
                                    </div>
                                </template>
                                <template x-if="paraNeedsLabel">
                                    <div class="de-inspector-field" style="margin-top:.6rem">
                                        <label>Label</label>
                                        <input type="text" class="de-input" placeholder="Badge / ribbon text"
                                               :value="paraLabel" @input="setParaLabel($event.target.value)">
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- FAQ template gallery --}}
                        <template x-if="block.type === 'faq'">
                            <div class="de-inspector-group">
                                <div class="de-inspector-label">Template</div>
                                <div class="de-tpl-grid">
                                    <template x-for="t in (window.magnaBlog?.faqTemplates || [])" :key="t.key">
                                        <button type="button" class="de-tpl" :class="{ 'is-active': faqTemplate === t.key }"
                                                @click="setFaqTemplate(t.key)" :title="t.label">
                                            <span class="de-faq-preview" :data-template="t.key" aria-hidden="true">
                                                <span class="row"><i class="bar"></i><i class="dot"></i></span>
                                                <span class="row"><i class="bar"></i><i class="dot"></i></span>
                                            </span>
                                            <span class="de-tpl-name" x-text="t.label"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </template>

                        {{-- Block-specific settings (stored in block data). Rendered by
                             field type so any block can declare a rich inspector. --}}
                        <template x-if="(block.schema || []).length">
                            <div class="de-inspector-group">
                                <template x-for="field in block.schema" :key="field.key">
                                    <div class="de-inspector-field">
                                        {{-- toggles render their own inline label --}}
                                        <template x-if="field.type !== 'toggle'">
                                            <label x-text="field.label"></label>
                                        </template>

                                        {{-- select (default) --}}
                                        <template x-if="!field.type || field.type === 'select'">
                                            <select @change="apply(field.key, $event.target.value)">
                                                <template x-for="(optLabel, optValue) in field.options" :key="optValue">
                                                    <option :value="optValue" x-text="optLabel"
                                                            :selected="String(values[field.key] ?? '') === String(optValue)"></option>
                                                </template>
                                            </select>
                                        </template>

                                        {{-- segmented --}}
                                        <template x-if="field.type === 'segmented'">
                                            <div class="de-segmented">
                                                <template x-for="(optLabel, optValue) in field.options" :key="optValue">
                                                    <button type="button" :class="{ 'is-active': String(values[field.key] ?? '') === String(optValue) }"
                                                            @click="apply(field.key, optValue)" x-text="optLabel"></button>
                                                </template>
                                            </div>
                                        </template>

                                        {{-- text --}}
                                        <template x-if="field.type === 'text'">
                                            <input type="text" class="de-input" :placeholder="field.placeholder || ''"
                                                   :value="values[field.key] || ''" @input="apply(field.key, $event.target.value)">
                                        </template>

                                        {{-- toggle --}}
                                        <template x-if="field.type === 'toggle'">
                                            <label class="de-toggle">
                                                <input type="checkbox" :checked="!!values[field.key]"
                                                       @change="apply(field.key, $event.target.checked)">
                                                <span x-text="field.label"></span>
                                            </label>
                                        </template>

                                        {{-- range --}}
                                        <template x-if="field.type === 'range'">
                                            <input type="range" class="de-range" :min="field.min ?? 0" :max="field.max ?? 100" :step="field.step ?? 1"
                                                   :value="values[field.key] ?? (field.min ?? 0)"
                                                   @input="apply(field.key, Number($event.target.value))">
                                        </template>

                                        {{-- color (global picker) --}}
                                        <template x-if="field.type === 'color'">
                                            <div class="de-colorpicker"
                                                 x-data="magnaColorPicker({ value: values[field.key] || '', allowClear: field.clearable !== false, onPick: (hex) => apply(field.key, hex) })"
                                                 x-effect="value = (values[field.key] || '')" @click.outside="open = false">
                                                <button type="button" class="de-colorpicker__trigger" @click="open = !open">
                                                    <span class="de-colorpicker__swatch" :style="`background:${display}`"></span>
                                                    <span class="de-colorpicker__hex" x-text="value || 'None'"></span>
                                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 12 15 18 9"/></svg>
                                                </button>
                                                <div class="de-colorpicker__pop" x-show="open" x-cloak x-transition.opacity>
                                                    <div class="de-colorpicker__grid">
                                                        <template x-for="hex in swatches" :key="hex">
                                                            <button type="button" class="de-colorpicker__cell"
                                                                    :class="{ 'is-active': value && value.toLowerCase() === hex.toLowerCase() }"
                                                                    :style="`background:${hex}`" @click="pick(hex)"></button>
                                                        </template>
                                                    </div>
                                                    <div class="de-colorpicker__custom">
                                                        <input type="color" :value="isValid(value) ? value : '#6366f1'" @input="pick($event.target.value)">
                                                        <input type="text" class="de-input de-colorpicker__hexinput" placeholder="#rrggbb" :value="value" @change="onHex($event)">
                                                        <template x-if="allowClear"><button type="button" class="de-colorpicker__clear" @click="clear()">None</button></template>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>

                        {{-- Universal style settings (stored in the block's Style tune).
                             Each control is gated by styleAllows() so a block only shows
                             the ones it can actually honour (e.g. an image has none). --}}
                        <template x-if="(block.styleSchema || []).length && styleAllowsAny()">
                            <div class="de-inspector-group">
                                <div class="de-inspector-label">Style</div>

                                <template x-if="styleAllows('align')">
                                    <div class="de-inspector-field">
                                        <label>Alignment</label>
                                        <div class="de-segmented">
                                            <template x-for="opt in [['','Default'],['left','Left'],['center','Center'],['right','Right']]" :key="opt[0]">
                                                <button type="button" :class="{ 'is-active': (styleValues.align || '') === opt[0] }"
                                                        @click="applyStyle('align', opt[0])" x-text="opt[1]"></button>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="styleAllows('color')">
                                    <div class="de-inspector-field">
                                        <label>Text colour</label>
                                        @include('blog::filament.partials.color-picker', ['bind' => "styleValues.color", 'onpick' => "applyStyle('color', hex)", 'clearable' => 'true'])
                                    </div>
                                </template>

                                <template x-if="styleAllows('bg')">
                                    <div class="de-inspector-field">
                                        <label>Background</label>
                                        @include('blog::filament.partials.color-picker', ['bind' => "styleValues.bg", 'onpick' => "applyStyle('bg', hex)", 'clearable' => 'true'])
                                    </div>
                                </template>

                                {{-- Accent colour — drives the decorative colour (border / badge /
                                     icon / number) of templates that have one. --}}
                                <template x-if="block.type === 'paragraph' && paraUsesAccent">
                                    <div class="de-inspector-field">
                                        <label>Accent colour</label>
                                        @include('blog::filament.partials.color-picker', ['bind' => "styleValues.accent", 'onpick' => "applyStyle('accent', hex)", 'clearable' => 'true'])
                                    </div>
                                </template>

                                <template x-if="styleAllows('fontSize')">
                                    <div class="de-inspector-field">
                                        <label>Font size</label>
                                        <select @change="applyStyle('fontSize', $event.target.value)">
                                            <template x-for="opt in [['','Default'],['sm','Small'],['base','Normal'],['lg','Large'],['xl','Extra large']]" :key="opt[0]">
                                                <option :value="opt[0]" x-text="opt[1]"
                                                        :selected="(styleValues.fontSize || '') === opt[0]"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>

                                <template x-if="styleAllows('font')">
                                    <div class="de-inspector-field">
                                        <label>Font</label>
                                        <select @change="applyStyle('font', $event.target.value)">
                                            <template x-for="f in (window.magnaBlog?.fonts || [])" :key="f.key">
                                                <option :value="f.key" x-text="f.label"
                                                        :style="f.stack ? ('font-family:'+f.stack) : ''"
                                                        :selected="(styleValues.font || '') === f.key"></option>
                                            </template>
                                        </select>
                                    </div>
                                </template>

                                {{-- Columns-only layout, stored on the same tune --}}
                                <template x-if="block.type === 'columns'">
                                    <div>
                                        <div class="de-inspector-field">
                                            <label>Vertical align</label>
                                            <div class="de-segmented">
                                                <template x-for="opt in [['top','Top'],['center','Center'],['','Stretch']]" :key="opt[0]">
                                                    <button type="button" :class="{ 'is-active': (styleValues.valign || '') === opt[0] }"
                                                            @click="applyStyle('valign', opt[0])" x-text="opt[1]"></button>
                                                </template>
                                            </div>
                                        </div>
                                        <div class="de-inspector-field">
                                            <label>Ratio (2 columns)</label>
                                            <select @change="applyStyle('ratio', $event.target.value)">
                                                <template x-for="opt in [['','Equal'],['2-1','2 : 1'],['1-2','1 : 2'],['3-1','3 : 1'],['1-3','1 : 3']]" :key="opt[0]">
                                                    <option :value="opt[0]" x-text="opt[1]" :selected="(styleValues.ratio || '') === opt[0]"></option>
                                                </template>
                                            </select>
                                        </div>
                                    </div>
                                </template>

                                {{-- Table-only presentation, stored on the same tune --}}
                                <template x-if="block.type === 'table'">
                                    <div>
                                        <label class="de-toggle">
                                            <input type="checkbox" :checked="!!styleValues.striped" @change="applyStyle('striped', $event.target.checked)">
                                            <span>Striped rows</span>
                                        </label>
                                        <label class="de-toggle">
                                            <input type="checkbox" :checked="!!styleValues.bordered" @change="applyStyle('bordered', $event.target.checked)">
                                            <span>Bordered</span>
                                        </label>
                                        <label class="de-toggle">
                                            <input type="checkbox" :checked="!!styleValues.compact" @change="applyStyle('compact', $event.target.checked)">
                                            <span>Compact</span>
                                        </label>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Button studio: rich per-button + block-level controls --}}
                <template x-if="mode === 'buttons'">
                    <div class="de-inspector">
                        <div class="de-inspector-type">Button</div>

                        <div class="de-inspector-group">
                            {{-- Link URL --}}
                            <div class="de-inspector-field">
                                <label>Link URL</label>
                                <input type="text" class="de-input" placeholder="https://…"
                                       :value="button.url || ''" @input="setButton('url', $event.target.value)">
                            </div>

                            {{-- Type: segmented --}}
                            <div class="de-inspector-field">
                                <label>Type</label>
                                <div class="de-segmented">
                                    <template x-for="opt in [['filled','Filled'],['outline','Outline'],['ghost','Ghost'],['link','Link']]" :key="opt[0]">
                                        <button type="button" :class="{ 'is-active': button.type === opt[0] }"
                                                @click="setButton('type', opt[0])" x-text="opt[1]"></button>
                                    </template>
                                </div>
                            </div>

                            {{-- Button colour --}}
                            <div class="de-inspector-field">
                                <label>Button colour</label>
                                @include('blog::filament.partials.color-picker', ['bind' => "button.color", 'onpick' => "setButton('color', hex)", 'clearable' => 'false'])
                            </div>

                            {{-- Text colour --}}
                            <div class="de-inspector-field">
                                <label>Text colour</label>
                                @include('blog::filament.partials.color-picker', ['bind' => "button.textColor", 'onpick' => "setButton('textColor', hex)", 'clearable' => 'true'])
                            </div>

                            {{-- Size: segmented --}}
                            <div class="de-inspector-field">
                                <label>Size</label>
                                <div class="de-segmented">
                                    <template x-for="opt in [['sm','S'],['md','M'],['lg','L']]" :key="opt[0]">
                                        <button type="button" :class="{ 'is-active': button.size === opt[0] }"
                                                @click="setButton('size', opt[0])" x-text="opt[1]"></button>
                                    </template>
                                </div>
                            </div>

                            {{-- Font --}}
                            <div class="de-inspector-field">
                                <label>Font</label>
                                <select @change="setButton('font', $event.target.value)">
                                    <template x-for="f in (window.magnaBlog?.fonts || [])" :key="f.key">
                                        <option :value="f.key" x-text="f.label"
                                                :style="f.stack ? ('font-family:'+f.stack) : ''"
                                                :selected="(button.font || '') === f.key"></option>
                                    </template>
                                </select>
                            </div>

                            {{-- Radius: slider --}}
                            <div class="de-inspector-field">
                                <label>Corner radius <span class="de-range-value" x-text="(button.radius ?? 8) + 'px'"></span></label>
                                <input type="range" min="0" max="50" step="1" class="de-range"
                                       :value="button.radius ?? 8"
                                       @input="setButton('radius', Number($event.target.value))">
                            </div>

                            {{-- Toggles --}}
                            <div class="de-inspector-field">
                                <label class="de-toggle">
                                    <input type="checkbox" :checked="button.fullWidth" @change="setButton('fullWidth', $event.target.checked)">
                                    <span>Full width</span>
                                </label>
                                <label class="de-toggle">
                                    <input type="checkbox" :checked="button.newTab" @change="setButton('newTab', $event.target.checked)">
                                    <span>Open in new tab</span>
                                </label>
                                <label class="de-toggle">
                                    <input type="checkbox" :checked="button.nofollow" @change="setButton('nofollow', $event.target.checked)">
                                    <span>Add <code>rel="nofollow"</code></span>
                                </label>
                            </div>
                        </div>

                        {{-- Block-level layout --}}
                        <div class="de-inspector-group">
                            <div class="de-inspector-label">Layout</div>
                            <div class="de-inspector-field">
                                <label>Alignment</label>
                                <div class="de-segmented">
                                    <template x-for="opt in [['left','Left'],['center','Center'],['right','Right']]" :key="opt[0]">
                                        <button type="button" :class="{ 'is-active': blockOpts.align === opt[0] }"
                                                @click="setBlockOpt('align', opt[0])" x-text="opt[1]"></button>
                                    </template>
                                </div>
                            </div>
                            <div class="de-inspector-field">
                                <label>Gap <span class="de-range-value" x-text="(blockOpts.gap ?? 8) + 'px'"></span></label>
                                <input type="range" min="0" max="48" step="1" class="de-range"
                                       :value="blockOpts.gap ?? 8"
                                       @input="setBlockOpt('gap', Number($event.target.value))">
                            </div>
                            <button type="button" class="de-add-btn" @click="addButton()">+ Add another button</button>
                        </div>
                    </div>
                </template>
            </div>

            {{-- SEO: the optional Magna SEO plugin's per-post meta, or a prompt to
                 install it. The form itself decides which, so this stays a thin
                 mount point. --}}
            <div class="de-sidebar-body" x-show="tab === 'seo'" x-cloak>
                {{ $this->seoForm }}
            </div>
        </aside>

    </div>

    {{-- ── PUBLISHED TOAST ─────────────────────────────────────────────── --}}
    <div class="de-published-toast" role="status" aria-live="polite"
         x-data="{ show: false, msg: 'Post published', t: null }"
         x-show="show" x-cloak style="display:none"
         x-on:blog-published.window="msg = ($event.detail && $event.detail.message) || 'Post published'; show = true; clearTimeout(t); t = setTimeout(() => show = false, 3200)">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <polyline points="20 6 9 17 4 12"/>
        </svg>
        <span x-text="msg">Post published</span>
    </div>

    {{-- Global media picker — opened by the "Browse media library" button --}}
    <livewire:magna-media-picker />

</div>{{-- /.doc-editor-root --}}

</x-filament-panels::page>
