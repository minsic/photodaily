import { readFileSync } from 'node:fs'
import { fileURLToPath, URL } from 'node:url'

import { defineConfig, mergeConfig } from 'vite'

import base from './vite.config.ts'

/**
 * `vite preview` con gli stessi header di sicurezza del Caddyfile di produzione,
 * letti da deploy/Caddyfile: serve a controllare in locale che la
 * Content-Security-Policy non blocchi nulla (npm run preview:csp).
 * Solo upgrade-insecure-requests si toglie, perché in locale si è in http.
 */
const caddyfile = readFileSync(fileURLToPath(new URL('../deploy/Caddyfile', import.meta.url)), 'utf8')

function caddyHeader(name: string): string {
  const match = caddyfile.match(new RegExp(`^\\s*${name} "([^"]+)"`, 'm'))

  if (!match) {
    throw new Error(`Header ${name} non trovato in deploy/Caddyfile`)
  }

  return match[1]
}

export default mergeConfig(
  base,
  defineConfig({
    preview: {
      headers: {
        'Content-Security-Policy': caddyHeader('Content-Security-Policy').replace(
          /;\s*upgrade-insecure-requests/,
          '',
        ),
        'Permissions-Policy': caddyHeader('Permissions-Policy'),
      },
    },
  }),
)
