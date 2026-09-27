/**
 * Proposta di indirizzo dal nome del diario: minuscole senza accenti,
 * trattini al posto di spazi e simboli, al massimo 30 caratteri.
 * Le stesse regole dello slug lato server (Family::slugRules).
 */
export function slugify(name: string): string {
  return name
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .slice(0, 30)
    .replace(/^-+|-+$/g, '')
}

export function isValidSlug(slug: string): boolean {
  return /^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/.test(slug)
}
