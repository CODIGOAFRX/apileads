import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// En desarrollo, /api se envía al backend PHP (docker compose o php -S).
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': 'http://localhost:8080',
    },
  },
  build: {
    outDir: 'dist',
    emptyOutDir: true,
  },
  // Pruebas de componentes (npm test): navegador simulado con jsdom.
  test: {
    environment: 'jsdom',
    include: ['test/**/*.test.jsx'],
    setupFiles: ['test/setup.js'],
  },
})
