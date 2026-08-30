import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';

export default defineConfig({
  // Vite's root defaults to process.cwd(), not this config file's directory —
  // and the root scripts run `--config frontend/vite.config.ts` from the repo
  // root. Without pinning root here, outDir '../dist' resolves against the
  // repo root as cwd and writes one level above the repo. See 04-frontend.md
  // § Setup and PLAN.md Phase 0's outDir trap.
  root: fileURLToPath(new URL('.', import.meta.url)),
  base: '/',
  plugins: [preact()],
  build: { outDir: '../dist', emptyOutDir: true },
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
      '/avatars': 'http://localhost:8080',
    },
  },
});
