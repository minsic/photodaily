import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vitest/config'

// Test unitari delle funzioni pure (npm test). Config separata da quella di
// Vite per non caricare il plugin PWA nei test.
export default defineConfig({
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  test: {
    environment: 'node',
    include: ['src/**/*.test.ts'],
  },
})
