/**
 * Età del protagonista del diario in una certa data, a parole:
 * "Gio ha 1 anno, 3 mesi e 4 giorni".
 *
 * Lavora solo su stringhe YYYY-MM-DD e aritmetica in UTC: niente librerie di
 * date e niente Date locali, che attorno all'ora legale spostano i giorni.
 */

export interface Age {
  years: number
  months: number
  days: number
}

interface YMD {
  y: number
  m: number
  d: number
}

const ISO_DATE = /^(\d{4})-(\d{2})-(\d{2})$/

function parse(iso: string): YMD | null {
  const match = ISO_DATE.exec(iso)

  return match ? { y: Number(match[1]), m: Number(match[2]), d: Number(match[3]) } : null
}

function daysInMonth(y: number, m: number): number {
  return new Date(Date.UTC(y, m, 0)).getUTCDate()
}

function toUtc({ y, m, d }: YMD): number {
  return Date.UTC(y, m - 1, d)
}

/** La nascita spostata di `months` mesi; se il giorno non esiste (31, 29 febbraio) si prende l'ultimo del mese. */
function addMonths(birth: YMD, months: number): YMD {
  const index = birth.y * 12 + (birth.m - 1) + months
  const y = Math.floor(index / 12)
  const m = (index % 12) + 1

  return { y, m, d: Math.min(birth.d, daysInMonth(y, m)) }
}

/** Anni, mesi e giorni compiuti in `on`; null se `on` è prima della nascita o una data non è valida. */
export function ageOn(birthdate: string, on: string): Age | null {
  const birth = parse(birthdate)
  const day = parse(on)

  if (!birth || !day || toUtc(day) < toUtc(birth)) {
    return null
  }

  let months = (day.y - birth.y) * 12 + (day.m - birth.m)

  if (toUtc(addMonths(birth, months)) > toUtc(day)) {
    months -= 1
  }

  const days = Math.round((toUtc(day) - toUtc(addMonths(birth, months))) / 86_400_000)

  return { years: Math.floor(months / 12), months: months % 12, days }
}

function plural(count: number, one: string, many: string): string {
  return `${count} ${count === 1 ? one : many}`
}

/** "1 anno, 3 mesi e 4 giorni": le parti a zero si saltano. */
function joinParts(parts: string[]): string {
  return parts.length <= 1 ? (parts[0] ?? '') : `${parts.slice(0, -1).join(', ')} e ${parts[parts.length - 1]}`
}

/**
 * Frase da mostrare sotto la data di una foto, o null se non c'è niente da
 * dire (data di nascita assente, o foto scattata prima della nascita).
 * Le frasi non hanno genere: vanno bene per qualunque protagonista.
 */
export function describeAge(
  birthdate: string | null | undefined,
  on: string,
  name?: string | null,
): string | null {
  if (!birthdate) {
    return null
  }

  const age = ageOn(birthdate, on)

  if (!age) {
    return null
  }

  const who = name?.trim() || null
  const subject = (verb: string) => (who ? `${who} ${verb}` : verb.charAt(0).toUpperCase() + verb.slice(1))

  if (age.years === 0 && age.months === 0 && age.days === 0) {
    return subject('nasce oggi')
  }

  if (age.years > 0 && age.months === 0 && age.days === 0) {
    return `${subject('compie')} ${plural(age.years, 'anno', 'anni')}`
  }

  const parts = [
    age.years > 0 ? plural(age.years, 'anno', 'anni') : null,
    age.months > 0 ? plural(age.months, 'mese', 'mesi') : null,
    age.days > 0 ? plural(age.days, 'giorno', 'giorni') : null,
  ].filter((part): part is string => part !== null)

  return `${subject('ha')} ${joinParts(parts)}`
}
