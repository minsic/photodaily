/**
 * "Piace a te, Nonna e Zia": chi ha messo il cuore, con "te" per primo.
 * Oltre tre nomi: "Piace a te, Nonna e altri 3".
 */
export function describeHearts(mine: boolean, others: string[]): string | null {
  const names = [...(mine ? ['te'] : []), ...others]

  if (names.length === 0) {
    return null
  }

  if (names.length > 3) {
    return `Piace a ${names.slice(0, 2).join(', ')} e altri ${names.length - 2}`
  }

  return names.length === 1 ? `Piace a ${names[0]}` : `Piace a ${names.slice(0, -1).join(', ')} e ${names.at(-1)}`
}
