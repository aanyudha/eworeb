import { defineConfig } from 'vite'

export default defineConfig({
  base: './',
  build: {
    outDir: '../public/workspace-assets',
    emptyOutDir: true,
    manifest: 'manifest.json',
    rollupOptions: { input: ['src/main.tsx', 'src/knowledge.tsx'] },
  },
})
