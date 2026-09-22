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
})
