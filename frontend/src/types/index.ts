export type UserRole = 'admin' | 'enseignant' | 'parent' | 'eleve' | 'secretariat'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: UserRole
}

export interface LoginResponse {
  token: string
  token_type: string
  user: AuthUser
}

export interface SchoolYear {
  id: number
  name: string
  starts_on: string
  ends_on: string
  is_current: boolean
  terms?: Term[]
  created_at?: string
  updated_at?: string
}

export interface Term {
  id: number
  school_year_id: number
  name: string
  position: number
  starts_on: string
  ends_on: string
  closed_at?: string | null
  is_closed?: boolean
  created_at?: string
  updated_at?: string
}

export interface Level {
  id: number
  name: string
  order: number
  classrooms?: ClassRoom[]
  created_at?: string
  updated_at?: string
}

export interface ClassRoom {
  id: number
  level_id: number
  section: string
  full_name: string
  capacity: number
  level?: Level
  created_at?: string
  updated_at?: string
}

export interface Subject {
  id: number
  name: string
  description?: string | null
  coefficient?: number
  created_at?: string
  updated_at?: string
}

export interface Teacher {
  id: number
  user_id: number
  speciality?: string | null
  phone?: string | null
  user?: { id: number; name: string; email: string }
  created_at?: string
  updated_at?: string
}

export interface Assignment {
  id: number
  teacher_id: number
  classroom_id: number
  subject_id: number
  school_year_id: number
  teacher?: Teacher
  classroom?: ClassRoom
  subject?: Subject
  created_at?: string
  updated_at?: string
}

export interface Student {
  id: number
  user_id?: number | null
  classroom_id?: number | null
  first_name: string
  last_name: string
  full_name: string
  date_of_birth?: string | null
  gender?: 'F' | 'M' | 'Autre' | null
  registration_number?: string | null
  photo_path?: string | null
  notes?: string | null
  classroom?: ClassRoom
  parents?: ParentProfile[]
  relation?: string
  created_at?: string
  updated_at?: string
}

export interface ParentProfile {
  id: number
  user_id: number
  phone?: string | null
  address?: string | null
  user?: { id: number; name: string; email: string }
  students?: Student[]
  students_count?: number
  relation?: string
  created_at?: string
  updated_at?: string
}

export interface Evaluation {
  id: number
  classroom_id: number
  subject_id: number
  term_id: number
  teacher_id?: number | null
  name: string
  type: 'devoir' | 'controle' | 'examen' | 'oral' | 'projet'
  held_on: string
  max_value: number
  classroom?: ClassRoom
  subject?: Subject
  grades_count?: number
  created_at?: string
  updated_at?: string
}

export interface GradeRow {
  id: number | null
  evaluation_id: number
  student_id: number
  value: number | null
  absent: boolean
  student?: Student
}

export interface ReportCardData {
  student: { id: number; full_name: string; registration_number?: string | null; classroom?: string | null }
  term: { id: number; name: string }
  subjects: { subject_id: number; subject_name: string; coefficient: number; count: number; average: number | null }[]
  overall_average: number | null
  total_coefficient: number
  appreciation?: string | null
}

export type AttendanceStatus = 'present' | 'absent' | 'late'

export interface AttendanceRecord {
  id: number | null
  student_id: number
  classroom_id: number | null
  subject_id: number | null
  date: string | null
  status: AttendanceStatus
  justified: boolean
  justification?: string | null
  student?: Student
  subject?: Subject | null
}

export interface AttendanceAlertInfo {
  triggered: boolean
  reasons: string[]
  count_recent_30d: number
  consecutive: number
}

export interface StudentAttendanceSummary {
  student_id: number
  full_name: string
  total_absences: number
  unjustified: number
  justified: number
  late_count: number
  alert: AttendanceAlertInfo
}


export interface Paginated<T> {
  data: T[]
  links?: { first?: string | null; last?: string | null; prev?: string | null; next?: string | null }
  meta?: {
    current_page: number
    from: number
    last_page: number
    per_page: number
    to: number
    total: number
  }
}

export interface ApiResource<T> {
  data: T
}

export interface MessageContact {
  id: number
  name: string
  email: string
  role: UserRole
}

export interface Message {
  id: number
  sender_id: number
  recipient_id: number
  parent_message_id: number | null
  subject: string
  body: string
  read_at: string | null
  is_read: boolean
  sender?: MessageContact
  recipient?: MessageContact
  replies?: Message[]
  replies_count?: number
  created_at?: string
  updated_at?: string
}
