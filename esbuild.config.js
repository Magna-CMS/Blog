import esbuild from 'esbuild';

const watch = process.argv.includes('--watch');

/**
 * Bundles the Editor.js integration into a single, self-contained asset that
 * the plugin registers with Filament. No dev server is required — Filament
 * serves the built file from the plugin's dist/ directory.
 */
const context = await esbuild.context({
    entryPoints: ['resources/js/editor/index.js'],
    bundle: true,
    minify: !watch,
    sourcemap: watch,
    format: 'iife',
    target: 'es2020',
    outfile: 'dist/blog-editor.js',
    logLevel: 'info',
});

if (watch) {
    await context.watch();
    console.log('Watching Editor.js bundle for changes…');
} else {
    await context.rebuild();
    await context.dispose();
}
