# Adding a new Editor.js block

A block travels through three trust zones: the **editor** (JavaScript, authors it),
the **sanitiser** (PHP, decides what may persist), and the **renderer** (PHP, the
admin preview). Security lives on the PHP side; the JS is a convenience that the
server never trusts. Adding a block means touching each zone once.

The security allowlists that both PHP zones share live in one file —
`src/Editor/BlockSchema.php` — and a drift test keeps the JS registry honest.

## Checklist

1. **Write the JS tool** — `resources/js/editor/tools/my-block.js`, a standard
   Editor.js tool class (`render()`, `save()`, `static get toolbox()`). If it
   carries enumerated presentation options (templates, a network list, …), export
   them as a `const` so the drift test can read them.

2. **Register it** — in `resources/js/editor/index.js`, import the class and add
   it to `defaultTools` under its block-type name:

   ```js
   import MyBlock from './tools/my-block.js';
   // …
   myBlock: { class: MyBlock, inlineToolbar: true },
   ```

   External plugins can instead call `window.magnaBlog.registerTool('myBlock', …)`
   before an editor mounts — no fork required.

3. **Allowlist the type** — add `'myBlock'` to `BlockSchema::TYPES`. A type absent
   here is dropped on save (fail-closed). If the block has enumerated option lists,
   add matching consts to `BlockSchema` too (see `PARAGRAPH_TEMPLATES`, etc.).

4. **Sanitise it** — add a `case 'myBlock'` arm to `EditorJsSanitizer::sanitizeBlock()`.
   Return only the keys you accept: run free text through `cleanHtml()`, plain text
   through `cleanText()`, URLs through `cleanUrl()`, colours through `cleanHex()`,
   and clamp every enum/number against an allowlist. Anything you do not copy is
   discarded. Add an "empty block is dropped" guard below the `match` if the block
   is meaningless without a URL/items.

5. **Render it** — add a `case 'myBlock'` arm to `BlockRenderer::block()` and a
   private method that emits HTML. Escape every attribute/plain value with `esc()`;
   emit sanitiser-approved inline HTML through `html()`; re-validate URLs with
   `cleanUrlOut()` (defence in depth).

6. **Inspector & style (optional)** — for sidebar settings, add an entry to
   `window.magnaBlog.inspectors` in `index.js`. To curate which universal Style
   controls apply, add the type to `window.magnaBlog.styleSupports`.

7. **Test it** — add a case to `tests/Unit/EditorJsSanitizerTest.php` (a crafted
   payload is cleaned as expected) and `tests/Unit/BlockRendererTest.php` (the
   sanitised block renders the HTML you expect, and an XSS attempt is escaped). If
   you added enumerated consts, extend `tests/Unit/JsRegistryDriftTest.php` so the
   PHP↔JS lists stay in lock-step.

8. **Build** — `npm run build` regenerates `dist/blog-editor.js`; the suite's
   `EditorAssetTest` checks it is present.

## Rule of thumb

If a value can reach the browser, it must survive the sanitiser (step 4) and be
escaped by the renderer (step 5). The JS (steps 1–2, 6) only shapes the authoring
experience — never rely on it for safety.
