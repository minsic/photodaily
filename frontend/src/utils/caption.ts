/**
 * La didascalia arriva dall'API in HTML minimale (già sanificato lato
 * server). Per l'attributo alt delle immagini serve la versione a testo
 * nudo: i tag letti da uno screen reader sarebbero rumore.
 */
export function captionToPlainText(html: string | null): string {
  if (!html) {
    return ''
  }

  const withBreaks = html.replace(/<\/p\s*>|<br\s*\/?>/gi, '\n')

  // DOMParser e non innerHTML su un div: il documento che produce è inerte,
  // quindi un eventuale <img onerror> non prova nemmeno a caricarsi.
  const parsed = new DOMParser().parseFromString(withBreaks, 'text/html')

  return (parsed.body.textContent ?? '').replace(/\n{3,}/g, '\n\n').trim()
}

/**
 * Stessa didascalia senza i link, per la timeline: là la card è già un
 * RouterLink e un <a> dentro un <a> viene spezzato dal parser HTML.
 */
export function captionWithoutLinks(html: string | null): string {
  if (!html) {
    return ''
  }

  const parsed = new DOMParser().parseFromString(html, 'text/html')

  for (const anchor of Array.from(parsed.body.querySelectorAll('a'))) {
    anchor.replaceWith(...Array.from(anchor.childNodes))
  }

  return parsed.body.innerHTML
}
