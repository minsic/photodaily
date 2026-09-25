import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Foto scelte (o condivise dalla galleria) prima di arrivare alla pagina che
 * le usa: il pulsante "Carica" apre subito il selettore, e i file passano da
 * qui a /carica o a /recupera. Solo in memoria.
 */
export const useIncomingFilesStore = defineStore('incoming-files', () => {
  const files = ref<File[]>([])

  function put(next: File[]): void {
    files.value = next
  }

  /** Li restituisce e li dimentica: una pagina li usa una volta sola. */
  function take(): File[] {
    const taken = files.value

    files.value = []

    return taken
  }

  return { files, put, take }
})
