import { useRouter } from 'vue-router'
import {
  useMessageComposeIntentStore,
  type MessageComposeStudentTarget,
} from '../stores/messageComposeIntent'

export interface StudentMessageTarget {
  id: number
  full_name: string
  student_user_id?: number | null
  student_portal_active?: boolean
  parent_users?: Array<{ id: number; name: string }>
}

export function studentHasPortalAccess(row: StudentMessageTarget): boolean {
  return row.student_portal_active === true && row.student_user_id != null
}

export function useStudentMessageNavigation() {
  const router = useRouter()
  const composeIntent = useMessageComposeIntentStore()

  function navigateToRecipient(recipientUserId: number, studentName: string): void {
    const subject = `Suivi de ${studentName}`
    composeIntent.queue({ kind: 'recipient', recipientId: recipientUserId, subject })

    void router.push({
      name: 'messages',
      query: {
        recipient_id: String(recipientUserId),
        subject,
      },
    })
  }

  function navigateToStudentConversation(
    row: StudentMessageTarget,
    target: MessageComposeStudentTarget,
  ): void {
    composeIntent.queue({ kind: 'student', studentId: row.id, target })

    void router.push({
      name: 'messages',
      query: {
        compose: '1',
        student_id: String(row.id),
        target,
      },
    })
  }

  function openParentConversation(row: StudentMessageTarget): boolean {
    const parent = row.parent_users?.[0]
    if (!parent) {
      return false
    }

    navigateToRecipient(parent.id, row.full_name)
    return true
  }

  function openStudentConversation(row: StudentMessageTarget): boolean {
    if (!row.student_user_id) {
      return false
    }

    navigateToRecipient(row.student_user_id, row.full_name)
    return true
  }

  return {
    studentHasPortalAccess,
    openParentConversation,
    openStudentConversation,
    navigateToRecipient,
  }
}
