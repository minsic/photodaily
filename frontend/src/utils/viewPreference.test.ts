import { describe, expect, it } from 'vitest'

import { readViewPreference, saveViewPreference } from './viewPreference'

function memory() {
  const values = new Map<string, string>()

  return { getItem: (key: string) => values.get(key) ?? null, setItem: (key: string, value: string) => void values.set(key, value) }
}

describe('preferenza di vista', () => {
  it('ricorda la vista scelta, e senza scelta parte dalla timeline', () => {
    const storage = memory()

    expect(readViewPreference(storage)).toBe('timeline')
    saveViewPreference('mese', storage)
    expect(readViewPreference(storage)).toBe('mese')
  })

  it('non si rompe se lo storage lancia o manca', () => {
    const broken = {
      getItem: () => {
        throw new Error('bloccato')
      },
      setItem: () => {
        throw new Error('bloccato')
      },
    }

    expect(() => saveViewPreference('mese', broken)).not.toThrow()
    expect(readViewPreference(broken)).toBe('timeline')
    expect(readViewPreference(null)).toBe('timeline')
  })
})
