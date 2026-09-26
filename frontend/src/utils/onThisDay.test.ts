import { describe, expect, it } from 'vitest'

import { hideFor, isHiddenFor, yearsAgoLabel } from './onThisDay'

function memory() {
  const values = new Map<string, string>()

  return { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => void values.set(key, value) }
}

describe('Un anno fa oggi', () => {
  it('scrive quanti anni fa', () => {
    expect(yearsAgoLabel(1)).toBe('Un anno fa')
    expect(yearsAgoLabel(3)).toBe('3 anni fa')
  })

  it('si chiude solo per il giorno in cui la si chiude', () => {
    const storage = memory()

    expect(isHiddenFor('2026-09-27', storage)).toBe(false)
    hideFor('2026-09-27', storage)
    expect(isHiddenFor('2026-09-27', storage)).toBe(true)
    expect(isHiddenFor('2026-09-28', storage)).toBe(false)
  })

  it('non si rompe senza storage', () => {
    const broken = {
      getItem: () => {
        throw new Error('bloccato')
      },
      setItem: () => {
        throw new Error('bloccato')
      },
    }

    expect(() => hideFor('2026-09-27', broken)).not.toThrow()
    expect(isHiddenFor('2026-09-27', broken)).toBe(false)
  })
})
