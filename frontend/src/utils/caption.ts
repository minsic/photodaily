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

/**
 * Testo da mettere nella textarea di modifica: i paragrafi diventano righe
 * separate da una riga vuota, gli a capo restano a capo. Eventuale
 * formattazione (grassetto, corsivo, link) si riduce al suo testo.
 */
export function captionToEditableText(html: string | null): string {
  if (!html) {
    return ''
  }

  const parsed = new DOMParser().parseFromString(html, 'text/html')
  const blocks = Array.from(parsed.body.childNodes)
  const hasParagraphs = blocks.some((node) => node.nodeName === 'P')

  const textOf = (node: Node): string =>
    Array.from(node.childNodes)
      .map((child) => (child.nodeName === 'BR' ? '\n' : child.childNodes.length ? textOf(child) : (child.textContent ?? '')))
      .join('')

  return (hasParagraphs ? blocks.filter((node) => node.nodeName === 'P').map(textOf) : [textOf(parsed.body)])
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph !== '')
    .join('\n\n')
}

/**
 * Riporta il testo della textarea nel formato salvato dal server
 * (CaptionHtml::fromPlainText): una riga vuota separa i paragrafi, un a capo
 * diventa <br>. Si converte qui e non sul server perché, ricevendo testo, il
 * server decide in base ai tag presenti: un "ti voglio bene <3" verrebbe
 * scambiato per HTML malformato e troncato.
 */
export function editableTextToCaptionHtml(text: string): string {
  const escape = (value: string) =>
    value.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')

  return text
    .replace(/\r\n?/g, '\n')
    .split(/\n{2,}/)
    .map((paragraph) => paragraph.trim())
    .filter((paragraph) => paragraph !== '')
    .map((paragraph) => `<p>${escape(paragraph).replace(/\n/g, '<br>')}</p>`)
    .join('')
}
