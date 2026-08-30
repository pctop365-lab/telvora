import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import { fileURLToPath, URL } from 'node:url';

export default defineConfig({
  plugins: [react()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  optimizeDeps: {
    exclude: ['lucide-react'],
  },

  server: {
    proxy: {
      '/manager.php': {
        target: 'https://telvora.ru',
        changeOrigin: true,
        secure: true,
      },

      '/products.php': {
        target: 'https://telvora.ru',
        changeOrigin: true,
        secure: true,
      },

      '/api.php': {
        target: 'https://telvora.ru',
        changeOrigin: true,
        secure: true,
      },
    },
  },
});