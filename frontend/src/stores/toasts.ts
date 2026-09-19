import { defineStore } from 'pinia'
import { ref } from 'vue'

export type ToastKind = 'success' | 'error'

export interface Toast {
  id: number
  kind: ToastKind
  text: string
}

let nextId = 1

export const useToastsStore = defineStore('toasts', () => {
  const items = ref<Toast[]>([])

  function push(kind: ToastKind, text: string): void {
    const toast: Toast = { id: nextId++, kind, text }

    items.value.push(toast)
    window.setTimeout(() => dismiss(toast.id), 5000)
  }

  function success(text: string): void {
    push('success', text)
  }

  function error(text: string): void {
    push('error', text)
  }

  function dismiss(id: number): void {
    items.value = items.value.filter((toast) => toast.id !== id)
  }

  return { items, success, error, dismiss }
})
