import { useAuthStore } from '@/stores/auth'
import { useSiteStore } from '@/stores/site'
import { DEFAULT_TIMEZONE, todayIso } from '@/utils/date'

/**
 * "Oggi" nel fuso della famiglia (families.timezone), non in quello del
 * telefono: chi è in viaggio vede lo stesso giorno di chi è a casa.
 */
export function useFamilyToday(): { timezone: () => string; today: () => string } {
  const auth = useAuthStore()
  const site = useSiteStore()

  const timezone = () => auth.family?.timezone ?? site.family?.timezone ?? DEFAULT_TIMEZONE

  return { timezone, today: () => todayIso(timezone()) }
}
