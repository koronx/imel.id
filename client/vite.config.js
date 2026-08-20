import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// During `npm run dev` the API runs in Docker on :8000; in production nginx
// proxies /api to the api container, so the same relative paths work in both.
export default defineConfig({
  plugins: [react()],
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/api': {
        target: process.env.VITE_API_TARGET || 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
    chunkSizeWarningLimit: 900,
  },
});
