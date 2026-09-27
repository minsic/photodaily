/**
 * Dati di chi gestisce il servizio, usati dalle pagine legali.
 * Finché restano tra parentesi quadre sono segnaposto da riempire.
 */
export const legal = {
  holder: '[TITOLARE]',
  vatNumber: '[P.IVA]',
  address: '[INDIRIZZO]',
  email: '[EMAIL DI CONTATTO]',
  /** Foro competente per chi non è consumatore. */
  court: '[FORO]',
  updatedAt: '27 settembre 2026',
} as const
