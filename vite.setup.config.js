import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

// Builds resources/setup/setup.css, the stylesheet layouts/setup inlines (§5.3.4, OD-1): one CSS file, no manifest,
// no public directory. `npm run build:setup` runs it; CI checks the committed file is current.
export default defineConfig({
    publicDir: false,
    plugins: [tailwindcss()],
    build: {
        outDir: 'resources/setup',
        emptyOutDir: false,
        manifest: false,
        rolldownOptions: {
            input: 'resources/css/setup.css',
            output: { assetFileNames: 'setup.css' },
        },
    },
});
