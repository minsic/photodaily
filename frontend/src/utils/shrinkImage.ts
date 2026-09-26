/**
 * Riduzione delle foto nel browser prima dell'upload: lato lungo al massimo
 * 4096 px, JPEG qualità 0,88. Una foto da 48 MP dell'iPhone passa da 15-20 MB
 * a 2-3 MB, e il telefono non la decodifica mai a piena risoluzione:
 * createImageBitmap la legge già ridotta.
 *
 * La foto ridotta non ha più metadati (GPS compreso), ed è voluto: la data di
 * scatto va letta dal file originale prima di chiamare shrinkForUpload.
 */

export const MAX_SIDE = 4096
export const JPEG_QUALITY = 0.88
/** Un JPEG già entro i 4096 px e sotto questo peso si carica così com'è. */
export const PASSTHROUGH_MAX_BYTES = 4 * 1024 * 1024
/** Byte letti per trovare le dimensioni: gli APP dell'EXIF (con la miniatura) stanno nei primi 64 KB. */
const HEADER_BYTES = 256 * 1024

export interface ImageHeader {
  type: 'jpeg' | 'png' | 'webp'
  width: number
  height: number
}

export interface ShrinkResult {
  /** Il file da caricare: la versione ridotta o l'originale. */
  file: File
  originalBytes: number
  bytes: number
  /** False se si carica l'originale (già leggero, o non decodificabile qui). */
  shrunk: boolean
}

/**
 * Dimensioni lette dall'intestazione del file, senza decodificarlo.
 * Sono quelle dei pixel salvati, prima della rotazione EXIF.
 */
export function readImageHeader(bytes: Uint8Array): ImageHeader | null {
  const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
  const ascii = (offset: number, length: number) =>
    String.fromCharCode(...bytes.subarray(offset, offset + length))

  if (bytes.length >= 4 && bytes[0] === 0xff && bytes[1] === 0xd8) {
    let pos = 2

    while (pos + 9 <= bytes.length) {
      if (bytes[pos] !== 0xff) {
        return null
      }

      const marker = bytes[pos + 1]!

      // Byte di riempimento fra un segmento e l'altro.
      if (marker === 0xff) {
        pos++
        continue
      }

      // SOF0-SOF15, tranne DHT (C4), JPG (C8) e DAC (CC): lì ci sono le dimensioni.
      if (marker >= 0xc0 && marker <= 0xcf && marker !== 0xc4 && marker !== 0xc8 && marker !== 0xcc) {
        return { type: 'jpeg', height: view.getUint16(pos + 5), width: view.getUint16(pos + 7) }
      }

      pos += 2 + view.getUint16(pos + 2)
    }

    return null
  }

  if (bytes.length >= 24 && ascii(1, 3) === 'PNG' && ascii(12, 4) === 'IHDR') {
    return { type: 'png', width: view.getUint32(16), height: view.getUint32(20) }
  }

  if (bytes.length >= 30 && ascii(0, 4) === 'RIFF' && ascii(8, 4) === 'WEBP') {
    const chunk = ascii(12, 4)
    const u24 = (offset: number) => bytes[offset]! | (bytes[offset + 1]! << 8) | (bytes[offset + 2]! << 16)

    if (chunk === 'VP8X') {
      return { type: 'webp', width: u24(24) + 1, height: u24(27) + 1 }
    }

    if (chunk === 'VP8 ') {
      return { type: 'webp', width: view.getUint16(26, true) & 0x3fff, height: view.getUint16(28, true) & 0x3fff }
    }

    if (chunk === 'VP8L') {
      const bits = view.getUint32(21, true)

      return { type: 'webp', width: (bits & 0x3fff) + 1, height: ((bits >> 14) & 0x3fff) + 1 }
    }
  }

  return null
}

/** Le orientazioni EXIF 5-8 ruotano di 90°: larghezza e altezza si scambiano. */
export function orientedSize(width: number, height: number, orientation: number | null): { width: number; height: number } {
  return orientation !== null && orientation >= 5 && orientation <= 8
    ? { width: height, height: width }
    : { width, height }
}

/** Misura finale entro `max` sul lato lungo, senza mai ingrandire. */
export function fitWithin(width: number, height: number, max = MAX_SIDE): { width: number; height: number } {
  const scale = Math.min(1, max / Math.max(width, height))

  return {
    width: Math.max(1, Math.round(width * scale)),
    height: Math.max(1, Math.round(height * scale)),
  }
}

/** Un JPEG già piccolo e leggero non si ricomprime: si perderebbe qualità per niente. */
export function canUploadAsIs(header: ImageHeader | null, bytes: number): boolean {
  return (
    header !== null &&
    header.type === 'jpeg' &&
    Math.max(header.width, header.height) <= MAX_SIDE &&
    bytes <= PASSTHROUGH_MAX_BYTES
  )
}

/** "15,2 MB → 2,1 MB", per la modalità debug. */
export function describeSaving(result: Pick<ShrinkResult, 'originalBytes' | 'bytes'>): string {
  const mb = (bytes: number) =>
    `${(bytes / 1024 / 1024).toLocaleString('it-IT', { minimumFractionDigits: 1, maximumFractionDigits: 1 })} MB`

  return `${mb(result.originalBytes)} → ${mb(result.bytes)}`
}

/**
 * Una riduzione alla volta, anche se la coda invia tre foto insieme: ogni
 * decodifica tiene in memoria una bitmap da decine di MB.
 */
let queue: Promise<unknown> = Promise.resolve()

export function shrinkForUpload(file: File): Promise<ShrinkResult> {
  const run = queue.then(() => shrink(file))

  queue = run.catch(() => undefined)

  return run
}

async function shrink(file: File): Promise<ShrinkResult> {
  const asIs: ShrinkResult = { file, originalBytes: file.size, bytes: file.size, shrunk: false }
  const header = readImageHeader(new Uint8Array(await file.slice(0, HEADER_BYTES).arrayBuffer()))

  if (canUploadAsIs(header, file.size)) {
    return asIs
  }

  try {
    const blob = await encode(file, header)

    // Può succedere con un PNG già piccolo: il JPEG non deve pesare di più.
    if (blob.size >= file.size && header !== null && Math.max(header.width, header.height) <= MAX_SIDE) {
      return asIs
    }

    const name = file.name.replace(/\.[^.]+$/, '') + '.jpg'

    return {
      file: new File([blob], name, { type: 'image/jpeg', lastModified: file.lastModified }),
      originalBytes: file.size,
      bytes: blob.size,
      shrunk: true,
    }
  } catch {
    // Formato che questo browser non decodifica (HEIC su Chrome desktop):
    // si carica l'originale e ci pensa il server.
    return asIs
  }
}

async function encode(file: File, header: ImageHeader | null): Promise<Blob> {
  const orientation = header?.type === 'jpeg' ? await readOrientation(file) : null
  const oriented = header ? orientedSize(header.width, header.height, orientation) : null
  const target = oriented ? fitWithin(oriented.width, oriented.height) : null

  let bitmap = await createImageBitmap(
    file,
    target
      ? { imageOrientation: 'from-image', resizeWidth: target.width, resizeHeight: target.height, resizeQuality: 'high' }
      : { imageOrientation: 'from-image' },
  )

  // Un browser che non applica le opzioni di ridimensionamento, o che le
  // applica prima della rotazione, restituisce proporzioni diverse da quelle
  // attese: si decodifica a piena risoluzione e si riduce nel canvas.
  if (target && (bitmap.width !== target.width || bitmap.height !== target.height)) {
    bitmap.close()
    bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' })
  }

  try {
    const size = fitWithin(bitmap.width, bitmap.height)

    return await toJpeg(bitmap, size.width, size.height)
  } finally {
    bitmap.close()
  }
}

async function toJpeg(bitmap: ImageBitmap, width: number, height: number): Promise<Blob> {
  if (typeof OffscreenCanvas !== 'undefined') {
    const canvas = new OffscreenCanvas(width, height)
    const context = canvas.getContext('2d')

    if (context) {
      context.drawImage(bitmap, 0, 0, width, height)

      return canvas.convertToBlob({ type: 'image/jpeg', quality: JPEG_QUALITY })
    }
  }

  const canvas = document.createElement('canvas')
  canvas.width = width
  canvas.height = height
  canvas.getContext('2d')?.drawImage(bitmap, 0, 0, width, height)

  const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', JPEG_QUALITY))

  // Libera subito la memoria del canvas: Safari la trattiene a lungo.
  canvas.width = 0
  canvas.height = 0

  if (!blob) {
    throw new Error('Codifica JPEG non riuscita.')
  }

  return blob
}

async function readOrientation(file: File): Promise<number | null> {
  try {
    const { default: exifr } = await import('exifr/dist/lite.esm.mjs')
    // parse() traduce il tag in testo ("Rotate 90 CW"): orientation() dà il numero.
    const orientation: unknown = await exifr.orientation(file)

    return typeof orientation === 'number' ? orientation : null
  } catch {
    return null
  }
}
