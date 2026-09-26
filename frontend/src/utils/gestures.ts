/**
 * Geometria dei gesti del lettore (pizzico, trascinamento, swipe). Le
 * coordinate delle trasformazioni sono in pixel rispetto al centro della
 * foto: translate(x, y) scale(scale) con transform-origin al centro.
 */

export interface Point {
  x: number
  y: number
}

export interface Transform {
  x: number
  y: number
  scale: number
}

export interface Size {
  width: number
  height: number
}

export const IDENTITY: Transform = { x: 0, y: 0, scale: 1 }
export const MIN_SCALE = 1
export const MAX_SCALE = 5
/** Scala del doppio tap. */
export const DOUBLE_TAP_SCALE = 2.5

export function distance(a: Point, b: Point): number {
  return Math.hypot(a.x - b.x, a.y - b.y)
}

export function midpoint(a: Point, b: Point): Point {
  return { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 }
}

export function clampScale(scale: number): number {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale))
}

/**
 * Nuova trasformazione con la scala `scale`, tenendo fermo sotto il dito il
 * punto `focus` (relativo al centro della foto).
 */
export function zoomAround(transform: Transform, scale: number, focus: Point): Transform {
  const next = clampScale(scale)
  const ratio = next / transform.scale

  return {
    scale: next,
    x: focus.x - (focus.x - transform.x) * ratio,
    y: focus.y - (focus.y - transform.y) * ratio,
  }
}

/** Misura della foto a scala 1 dentro lo schermo (object-contain). */
export function containedSize(image: Size, viewport: Size): Size {
  const fit = Math.min(viewport.width / image.width, viewport.height / image.height)

  return { width: image.width * fit, height: image.height * fit }
}

/** Il pan non porta mai un bordo della foto ingrandita dentro lo schermo. */
export function clampTransform(transform: Transform, content: Size, viewport: Size): Transform {
  const maxX = Math.max(0, (content.width * transform.scale - viewport.width) / 2)
  const maxY = Math.max(0, (content.height * transform.scale - viewport.height) / 2)

  // "+ 0" trasforma un eventuale -0 in 0.
  return {
    scale: transform.scale,
    x: Math.min(maxX, Math.max(-maxX, transform.x)) + 0,
    y: Math.min(maxY, Math.max(-maxY, transform.y)) + 0,
  }
}

/**
 * Esito di un trascinamento orizzontale: un quarto di schermo, o uno scatto
 * veloce. Verso destra si torna alla foto precedente.
 */
export function swipeOutcome(dx: number, dy: number, durationMs: number, width: number): 'prev' | 'next' | null {
  if (Math.abs(dx) < Math.abs(dy) * 1.2) {
    return null
  }

  const far = Math.abs(dx) > width * 0.25
  const quick = Math.abs(dx) > 40 && Math.abs(dx) / Math.max(1, durationMs) > 0.5

  if (!far && !quick) {
    return null
  }

  return dx > 0 ? 'prev' : 'next'
}

/** Un tocco, non un trascinamento. */
export function isTap(start: Point, end: Point, durationMs: number): boolean {
  return distance(start, end) < 10 && durationMs < 300
}
