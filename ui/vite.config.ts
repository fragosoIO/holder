import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'

const apiProxy = process.env.HOLDER_API_PROXY || 'http://127.0.0.1:8080'

export default defineConfig({
  plugins: [vue(), tailwindcss()],
  server: {
    // .localhost is allowed by Vite itself. The other names match the Traefik host rule.
    allowedHosts: ['.test', '.zixio.de', '.traefik.me'],
    // Keep the browser Host so local mode can recognize holder.localhost behind the proxy.
    proxy: {
      '/api': {
        target: apiProxy,
        changeOrigin: false,
      },
    },
    hmr: process.env.HOLDER_BEHIND_PROXY === '1'
      ? { protocol: 'wss', clientPort: 443 }
      : undefined,
  },
  preview: {
    allowedHosts: ['.test', '.zixio.de', '.traefik.me'],
    proxy: {
      '/api': {
        target: apiProxy,
        changeOrigin: false,
      },
    },
  },
})
