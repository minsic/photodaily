import { describe, expect, it } from 'vitest'

import {
  clampTransform,
  containedSize,
  IDENTITY,
  isTap,
  MAX_SCALE,
  swipeOutcome,
  zoomAround,
} from './gestures'

describe('zoomAround', () => {
  it('tiene fermo il punto sotto le dita', () => {
    const zoomed = zoomAround(IDENTITY, 2, { x: 100, y: 50 })

    // Il punto (100, 50) della foto a scala 1, dopo lo zoom, deve restare lì.
    expect(zoomed).toEqual({ scale: 2, x: -100, y: -50 })
    expect(100 * zoomed.scale + zoomed.x).toBe(100)
  })

  it('non va oltre i limiti di scala', () => {
    expect(zoomAround(IDENTITY, 12, { x: 0, y: 0 }).scale).toBe(MAX_SCALE)
    expect(zoomAround({ x: 30, y: 0, scale: 2 }, 0.3, { x: 0, y: 0 })).toEqual({ scale: 1, x: 15, y: 0 })
  })
})

describe('containedSize e clampTransform', () => {
  const viewport = { width: 400, height: 800 }

  it('adatta la foto allo schermo come object-contain', () => {
    expect(containedSize({ width: 4000, height: 3000 }, viewport)).toEqual({ width: 400, height: 300 })
  })

  it('a scala 1 la foto resta centrata', () => {
    expect(clampTransform({ x: 80, y: -40, scale: 1 }, { width: 400, height: 300 }, viewport)).toEqual({
      x: 0,
      y: 0,
      scale: 1,
    })
  })

  it('ingrandita si sposta solo fino ai bordi', () => {
    // 400x300 a scala 3 = 1200x900: 400 px di margine per lato in orizzontale, 50 in verticale.
    expect(clampTransform({ x: 999, y: -999, scale: 3 }, { width: 400, height: 300 }, viewport)).toEqual({
      x: 400,
      y: -50,
      scale: 3,
    })
  })
})

describe('swipeOutcome e isTap', () => {
  it('riconosce lo swipe lungo e quello veloce', () => {
    expect(swipeOutcome(-150, 10, 600, 400)).toBe('next')
    expect(swipeOutcome(60, 5, 80, 400)).toBe('prev')
  })

  it('ignora i trascinamenti corti, lenti o verticali', () => {
    expect(swipeOutcome(60, 5, 600, 400)).toBeNull()
    expect(swipeOutcome(150, 200, 200, 400)).toBeNull()
  })

  it('distingue il tocco dal trascinamento', () => {
    expect(isTap({ x: 0, y: 0 }, { x: 4, y: 3 }, 120)).toBe(true)
    expect(isTap({ x: 0, y: 0 }, { x: 30, y: 0 }, 120)).toBe(false)
    expect(isTap({ x: 0, y: 0 }, { x: 0, y: 0 }, 600)).toBe(false)
  })
})
