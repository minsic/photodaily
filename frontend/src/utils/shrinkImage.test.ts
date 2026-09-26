import { describe, expect, it } from 'vitest'

import {
  canUploadAsIs,
  describeSaving,
  fitWithin,
  orientedSize,
  PASSTHROUGH_MAX_BYTES,
  readImageHeader,
} from './shrinkImage'

function jpeg(width: number, height: number, app1Length = 1000): Uint8Array {
  const app1 = [0xff, 0xe1, app1Length >> 8, app1Length & 0xff, ...new Array(app1Length - 2).fill(0)]
  const sof0 = [0xff, 0xc0, 0x00, 0x11, 0x08, height >> 8, height & 0xff, width >> 8, width & 0xff, 0x03]

  return new Uint8Array([0xff, 0xd8, ...app1, 0xff, 0xdb, 0x00, 0x04, 0x00, 0x00, ...sof0, ...new Array(20).fill(0)])
}

function ascii(text: string): number[] {
  return [...text].map((char) => char.charCodeAt(0))
}

describe('readImageHeader', () => {
  it('trova le dimensioni di un JPEG dopo i segmenti APP e DQT', () => {
    expect(readImageHeader(jpeg(8064, 6048, 30000))).toEqual({ type: 'jpeg', width: 8064, height: 6048 })
  })

  it('legge IHDR di un PNG', () => {
    const png = new Uint8Array(32)
    png.set([0x89, ...ascii('PNG'), 0x0d, 0x0a, 0x1a, 0x0a, 0, 0, 0, 13, ...ascii('IHDR')])
    new DataView(png.buffer).setUint32(16, 5000)
    new DataView(png.buffer).setUint32(20, 3000)

    expect(readImageHeader(png)).toEqual({ type: 'png', width: 5000, height: 3000 })
  })

  it('legge le tre varianti di WebP', () => {
    const webp = (chunk: string, fill: (view: DataView) => void) => {
      const bytes = new Uint8Array(40)
      bytes.set([...ascii('RIFF'), 0, 0, 0, 0, ...ascii('WEBP'), ...ascii(chunk)])
      fill(new DataView(bytes.buffer))

      return readImageHeader(bytes)
    }

    expect(
      webp('VP8X', (view) => {
        view.setUint16(24, 4999, true)
        view.setUint16(27, 2999, true)
      }),
    ).toEqual({ type: 'webp', width: 5000, height: 3000 })

    expect(
      webp('VP8 ', (view) => {
        view.setUint16(26, 1400, true)
        view.setUint16(28, 1050, true)
      }),
    ).toEqual({ type: 'webp', width: 1400, height: 1050 })

    expect(webp('VP8L', (view) => view.setUint32(21, 399 | (299 << 14), true))).toEqual({
      type: 'webp',
      width: 400,
      height: 300,
    })
  })

  it('non riconosce HEIC né file troncati', () => {
    const heic = new Uint8Array([0, 0, 0, 24, ...ascii('ftypheic'), ...new Array(20).fill(0)])

    expect(readImageHeader(heic)).toBeNull()
    expect(readImageHeader(jpeg(4000, 3000).subarray(0, 50))).toBeNull()
  })
})

describe('orientedSize e fitWithin', () => {
  it('scambia i lati per le orientazioni ruotate di 90°', () => {
    expect(orientedSize(8064, 6048, 6)).toEqual({ width: 6048, height: 8064 })
    expect(orientedSize(8064, 6048, 1)).toEqual({ width: 8064, height: 6048 })
    expect(orientedSize(8064, 6048, null)).toEqual({ width: 8064, height: 6048 })
  })

  it('porta il lato lungo a 4096 e non ingrandisce mai', () => {
    expect(fitWithin(6048, 8064)).toEqual({ width: 3072, height: 4096 })
    expect(fitWithin(4000, 3000)).toEqual({ width: 4000, height: 3000 })
  })
})

describe('canUploadAsIs', () => {
  it('lascia passare solo i JPEG già entro 4096 px e 4 MB', () => {
    const small = { type: 'jpeg' as const, width: 4032, height: 3024 }

    expect(canUploadAsIs(small, 3_000_000)).toBe(true)
    expect(canUploadAsIs(small, PASSTHROUGH_MAX_BYTES + 1)).toBe(false)
    expect(canUploadAsIs({ ...small, width: 8064 }, 3_000_000)).toBe(false)
    expect(canUploadAsIs({ ...small, type: 'png' }, 3_000_000)).toBe(false)
    expect(canUploadAsIs(null, 1000)).toBe(false)
  })
})

describe('describeSaving', () => {
  it('scrive i MB alla maniera italiana', () => {
    expect(describeSaving({ originalBytes: 15.2 * 1024 * 1024, bytes: 2.1 * 1024 * 1024 })).toBe('15,2 MB → 2,1 MB')
  })
})
