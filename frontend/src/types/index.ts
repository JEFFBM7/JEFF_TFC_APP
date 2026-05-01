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
