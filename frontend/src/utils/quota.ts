import type { Quota } from '@/api/types'

/** "750 MB" sotto il GB, "0,8 GB" / "12 GB" sopra: come lo leggerebbe una persona. */
export function formatStorage(mb: number): string {
  if (mb < 1000) {
    return `${Math.round(mb)} MB`
  }

  const gb = mb / 1024

  return `${gb.toLocaleString('it-IT', { maximumFractionDigits: gb < 10 ? 1 : 0 })} GB`
}

/** "Spazio usato 0,8 GB di 5 GB", o "… (senza limite)" per i piani illimitati. */
export function describeStorage(quota: Quota): string {
  const used = formatStorage(quota.storage_used_mb)

  return quota.max_storage_mb === null
    ? `Spazio usato ${used} (senza limite)`
    : `Spazio usato ${used} di ${formatStorage(quota.max_storage_mb)}`
}

/**
 * Perché non si possono caricare `count` foto, o null se si può. Il server
 * controlla comunque ogni caricamento: questo evita solo di scegliere trenta
 * foto per sentirsi dire di no alla prima.
 */
export function uploadBlocked(quota: Quota, count = 1): string | null {
  if (quota.blocked) {
    return quota.blocked
  }

  if (quota.max_photos !== null && quota.photos + count > quota.max_photos) {
    const left = Math.max(0, quota.max_photos - quota.photos)

    return `Il piano "${quota.plan}" arriva a ${quota.max_photos} foto: ne puoi caricare ancora ${left}. Togline qualcuna dalla selezione o passa a un piano superiore.`
  }

  return null
}
