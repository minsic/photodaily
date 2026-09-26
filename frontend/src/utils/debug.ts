/**
 * Modalità debug: sempre in sviluppo; in produzione si accende su un
 * dispositivo con localStorage['photodaily.debug'] = '1' (dalla console).
 */
export function isDebug(): boolean {
  if (import.meta.env.DEV) {
    return true
  }

  try {
    return localStorage.getItem('photodaily.debug') === '1'
  } catch {
    return false
  }
}
