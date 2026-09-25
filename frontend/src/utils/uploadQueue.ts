export type TaskStatus = 'attesa' | 'invio' | 'fatto' | 'errore'

export interface QueueTask {
  id: string
  status: TaskStatus
  error: string | null
}

/**
 * Esegue i compiti in attesa, al massimo `concurrency` alla volta. Un errore
 * segna solo quel compito: gli altri vanno avanti. Si può richiamare per
 * riprovare quelli rimasti in errore dopo averli rimessi in attesa.
 */
export async function runQueue<T extends QueueTask>(
  tasks: T[],
  worker: (task: T) => Promise<void>,
  concurrency = 3,
  describeError: (cause: unknown) => string = () => 'Non caricata.',
): Promise<void> {
  const pending = tasks.filter((task) => task.status === 'attesa')

  async function next(): Promise<void> {
    const task = pending.shift()

    if (!task) {
      return
    }

    task.status = 'invio'
    task.error = null

    try {
      await worker(task)
      task.status = 'fatto'
    } catch (cause) {
      task.status = 'errore'
      task.error = describeError(cause)
    }

    await next()
  }

  await Promise.all(Array.from({ length: Math.min(concurrency, pending.length) }, next))
}

/** Rimette in attesa i compiti falliti, per riprovarli con runQueue. */
export function requeueFailed(tasks: QueueTask[]): number {
  const failed = tasks.filter((task) => task.status === 'errore')

  for (const task of failed) {
    task.status = 'attesa'
    task.error = null
  }

  return failed.length
}
