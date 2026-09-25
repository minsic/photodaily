import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import vue from '@vitejs/plugin-vue'
import { defineConfig } from 'vite'
import { VitePWA } from 'vite-plugin-pwa'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    tailwindcss(),
    VitePWA({
      registerType: 'autoUpdate',
      // Service worker scritto a mano (src/sw.ts): serve per ricevere le foto
      // condivise dalla galleria (share_target). Il precache resta lo stesso.
      strategies: 'injectManifest',
      srcDir: 'src',
      filename: 'sw.ts',
      includeAssets: ['icons/favicon.ico', 'icons/apple-touch-icon.png', 'fonts/*.woff2'],
      manifest: {
        name: 'PhotoDaily',
        short_name: 'PhotoDaily',
        description: 'Il diario fotografico di famiglia, una foto al giorno.',
        lang: 'it',
        start_url: '/',
        display: 'standalone',
        background_color: '#cedee9',
        theme_color: '#cedee9',
        icons: [
          { src: '/icons/android-chrome-192x192.png', sizes: '192x192', type: 'image/png' },
          { src: '/icons/android-chrome-512x512.png', sizes: '512x512', type: 'image/png' },
          { src: '/icons/android-chrome-512x512.png', sizes: '512x512', type: 'image/png', purpose: 'maskable' },
        ],
        // "Condividi" dalla galleria (Android/Chrome con l'app installata): le
        // foto arrivano in POST a /condividi, dove le prende il service worker.
        share_target: {
          action: '/condividi',
          method: 'POST',
          enctype: 'multipart/form-data',
          params: {
            files: [{ name: 'photos', accept: ['image/*'] }],
          },
        },
      },
      injectManifest: {
        // Solo il guscio dell'app: le foto stanno su URL firmati che cambiano
        // a ogni richiesta, quindi metterle in cache non servirebbe a nulla.
        globPatterns: ['**/*.{js,css,html,woff2,svg,png,ico}'],
      },
    }),
  ],
  optimizeDeps: {
    // Caricato con import() solo nel recupero dei giorni: dichiararlo evita che
    // Vite lo scopra a pagina aperta e debba ricaricarla.
    include: ['exifr/dist/lite.esm.mjs'],
  },
  server: {
    // Come in produzione, l'API sta sullo stesso host del frontend (/api).
    // Herd distingue i siti dall'host, quindi la richiesta arriva come
    // photodaily.test e l'host del frontend (es. giopellino.localhost:5173)
    // viaggia in X-Forwarded-Host: è da lì che il backend riconosce la famiglia.
    proxy: {
      '/api': {
        target: 'http://photodaily.test',
        changeOrigin: true,
        configure(proxy) {
          proxy.on('proxyReq', (proxyReq, req) => {
            proxyReq.setHeader('X-Forwarded-Host', req.headers.host ?? '')
          })
        },
      },
    },
  },
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
})
