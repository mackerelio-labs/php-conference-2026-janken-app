import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// 開発時のみ /api を nginx へ proxy して同一オリジンを保つ
export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': process.env.VITE_PROXY_TARGET ?? 'http://localhost:8080',
    },
  },
})
