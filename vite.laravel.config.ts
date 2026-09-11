import {defineConfig} from 'vite';
import react from '@vitejs/plugin-react';
import {fileURLToPath} from 'node:url';

export default defineConfig({
  plugins: [react()],
  resolve: {alias: {'@': fileURLToPath(new URL('.', import.meta.url))}},
  base: '/build/',
  publicDir: false,
  build: {
    outDir: 'laravel/public/build',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: {input: 'resources/main.tsx'},
  },
});
