import { defineStore } from 'pinia'
import { ref } from 'vue'

export type MessageComposeStudentTarget = 'parent' | 'student' | 'auto'

export type MessageComposeIntent =
  | { kind: 'recipient'; recipientId: number; subject?: string }
  | { kind: 'student'; studentId: number; target: MessageComposeStudentTarget }

export const useMessageComposeIntentStore = defineStore('messageComposeIntent', () => {
  const pending = ref<MessageComposeIntent | null>(null)

  function queue(intent: MessageComposeIntent): void {
    pending.value = intent
  }

  function consume(): MessageComposeIntent | null {
    const intent = pending.value
    pending.value = null
    return intent
  }

  function hasPending(): boolean {
    return pending.value !== null
  }

  return { pending, queue, consume, hasPending }
})
