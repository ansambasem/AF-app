import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import react from '@vitejs/plugin-react';
import path from 'node:path';
import fs from 'node:fs';

/** PDF fonts and decoders stay on the application's own origin in development and production. */
function pdfAssets() {
    const root = path.resolve(import.meta.dirname, 'node_modules/pdfjs-dist');
    const directories = ['cmaps', 'standard_fonts', 'wasm'];
    return {
        name: 'company-pdf-assets',
        configureServer(server) {
            server.middlewares.use((request, response, next) => {
                const match = request.url?.split('?')[0].match(/^\/pdfjs\/(cmaps|standard_fonts|wasm)\/([a-zA-Z0-9_.-]+)$/);
                if (!match || !directories.includes(match[1])) return next();
                const file = path.join(root, match[1], match[2]);
                if (!fs.existsSync(file) || !fs.statSync(file).isFile()) return next();
                response.setHeader('Content-Type', file.endsWith('.wasm') ? 'application/wasm' : 'application/octet-stream');
                fs.createReadStream(file).pipe(response);
            });
        },
        generateBundle() {
            for (const directory of directories) {
                for (const name of fs.readdirSync(path.join(root, directory))) {
                    const file = path.join(root, directory, name);
                    if (fs.statSync(file).isFile()) this.emitFile({ type: 'asset', fileName: `pdfjs/${directory}/${name}`, source: fs.readFileSync(file) });
                }
            }
        },
    };
}

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/css/app.css', 'resources/js/app.js', 'resources/js/app.jsx'],
            refresh: true,
        }),
        react(),
        pdfAssets(),
    ],
    resolve: {
        alias: {
            '@': path.resolve(import.meta.dirname, 'resources/js'),
        },
    },
});
