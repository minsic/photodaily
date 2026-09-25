/**
 * Cosa può fare questo browser con le notifiche push:
 * - `ok`: supportate;
 * - `ios-da-installare`: iPhone/iPad in Safari: le notifiche arrivano solo
 *   se l'app è installata sulla schermata Home (iOS 16.4 o successivo);
 * - `no`: browser senza Web Push.
 */
export type PushSupport = 'ok' | 'ios-da-installare' | 'no'

export function isStandalone(): boolean {
  return (
    window.matchMedia('(display-mode: standalone)').matches ||
    (navigator as Navigator & { standalone?: boolean }).standalone === true
  )
}

export function isIos(): boolean {
  return /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.userAgent.includes('Mac') && 'ontouchend' in document)
}

export function pushSupport(): PushSupport {
  const supported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window

  if (isIos() && !isStandalone()) {
    return 'ios-da-installare'
  }

  return supported ? 'ok' : 'no'
}

/** La chiave VAPID arriva in base64 "url-safe": pushManager.subscribe vuole i byte. */
export function vapidKeyToBytes(base64: string): Uint8Array<ArrayBuffer> {
  const padded = (base64 + '='.repeat((4 - (base64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/')
  const raw = atob(padded)
  const bytes = new Uint8Array(new ArrayBuffer(raw.length))

  for (let i = 0; i < raw.length; i++) {
    bytes[i] = raw.charCodeAt(i)
  }

  return bytes
}
