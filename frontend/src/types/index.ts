export type UserRole = 'admin' | 'enseignant' | 'parent' | 'eleve' | 'secretariat'
export type AdminScope = 'global' | 'primary_maternal' | 'secondary_technical'

export interface AuthUser {
  id: number
  name: string
  email: string
  role: UserRole
  admin_scope?: AdminScope | null
  admin_scope_label?: string | null
  admin_cycles?: LevelCycle[]
  /** null = trimestres + semestres (admin général). */
  term_applicable_cycles?: TermCycle[] | null
  teacher_cycles?: LevelCycle[]
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
  is_archived?: boolean
  closed_at?: string | null
  archived_at?: string | null
  archived_by?: SchoolYearArchivedBy | null
  school_classes_count?: number
  terms?: Term[]
  stats?: SchoolYearStats
  created_at?: string
  updated_at?: string
}

export interface SchoolYearArchivedBy {
  id: number
  name: string
  email: string
}

export type SchoolYearFinalStatus = 'admis' | 'redouble' | 'en_cours'

export interface SchoolYearStudentRow {
  id: number
  full_name: string
  registration_number?: string | null
  gender?: 'F' | 'M' | 'Autre' | null
  classroom_id: number | null
  classroom: string | null
  level: string | null
  grade_average: number | null
  final_status: SchoolYearFinalStatus
  absences: number
  unjustified_absences: number
  lates: number
}

export interface SchoolYearHistoryTerm {
  id: number
  name: string
  position: number
  closed_at: string | null
  is_closed: boolean
}

export interface SchoolYearHistory {
  closed_at: string | null
  archived_at: string | null
  archived_by: SchoolYearArchivedBy | null
  terms: SchoolYearHistoryTerm[]
}

export interface SchoolYearStats {
  summary: {
    classes: number
    students: number
    parents: number
    terms: number
    closed_terms: number
    periods: number
    closed_periods: number
    teacher_assignments: number
    assigned_teachers: number
    assigned_classrooms: number
    assigned_subjects: number
    evaluations: number
    grades_entered: number
    grade_average: number | null
    success_rate: number | null
    students_passing: number
    students_evaluated: number
    attendance_records: number
    absences: number
    unjustified_absences: number
    lates: number
  }
  terms: SchoolYearTermStats[]
  periods: SchoolYearPeriodStats[]
  class_averages: SchoolYearClassStats[]
  monthly_attendance: SchoolYearMonthlyAttendance[]
  students: SchoolYearStudentRow[]
  history: SchoolYearHistory
}

export interface SchoolYearClassStats {
  classroom_id: number
  classroom: string
  class_code?: string | null
  school_class_id?: number | null
  cycle?: LevelCycle | null
  level_name?: string | null
  option_name?: string | null
  capacity?: number | null
  student_count: number
  parent_count: number
  teacher_count: number
  subject_count: number
  evaluations: number
  grades_entered: number
  grade_average: number | null
  attendance_records: number
  absences: number
  lates: number
}

export interface SchoolYearClassDetails {
  school_year: {
    id: number
    name: string
    starts_on: string
    ends_on: string
  }
  classroom: {
    id: number
    full_name: string
    section: string
    level: { id: number; name: string } | null
  }
  main_teacher: {
    assignment_id: number
    id: number
    name?: string | null
    email?: string | null
    speciality?: string | null
    subject_id?: number | null
    subject?: string | null
  } | null
  summary: {
    students: number
    parents: number
    teachers: number
    subjects: number
    evaluations: number
    grades_entered: number
    grade_average: number | null
    attendance_records: number
    present: number
    absences: number
    justified_absences: number
    unjustified_absences: number
    lates: number
  }
  students: Array<{
    id: number
    full_name: string
    registration_number?: string | null
    gender?: 'F' | 'M' | 'Autre' | null
    parents: Array<{
      id: number
      name?: string | null
      email?: string | null
      phone?: string | null
      relation?: string | null
    }>
    attendance: {
      present: number
      absences: number
      unjustified_absences: number
      lates: number
    }
  }>
  parents: Array<{
    id: number
    user_id: number
    name?: string | null
    email?: string | null
    phone?: string | null
    address?: string | null
    children: Array<{ id: number; full_name: string; relation?: string | null }>
  }>
  courses: Array<{
    id: number
    subject: { id: number; name: string } | null
    teacher: {
      id: number
      name?: string | null
      email?: string | null
      speciality?: string | null
    } | null
  }>
  timetable: Array<{
    id: number
    day_of_week: number
    starts_at: string
    ends_at: string
    room?: string | null
    subject: { id: number; name: string } | null
    teacher: { id: number; name?: string | null } | null
  }>
  recent_attendances: Array<{
    id: number
    date: string | null
    status: AttendanceStatus
    justified: boolean
    student: { id: number; full_name: string } | null
    subject: { id: number; name: string } | null
  }>
  evaluations: Array<{
    id: number
    name: string
    type: string
    held_on: string | null
    max_value: number
    grades_count: number
    subject: { id: number; name: string } | null
    term: { id: number; name: string } | null
  }>
}

export interface SchoolYearTermStats {
  id: number
  name: string
  position: number
  starts_on: string
  ends_on: string
  is_closed: boolean
  evaluations: number
  grades_entered: number
  grade_average: number | null
  absences: number
  lates: number
}

export interface SchoolYearPeriodStats {
  id: number
  term_id: number
  school_year_id?: number | null
  name: string
  position: number
  starts_on: string
  ends_on: string
  closed_at?: string | null
  is_closed: boolean
}

export interface SchoolYearMonthlyAttendance {
  value: string
  label: string
  absences: number
  lates: number
}

export interface MonthlyAttendancePoint {
  value: string
  label: string
  absences: number
  lates: number
}

export interface TermAveragePoint {
  term_id: number
  school_year_id: number
  label: string
  average: number | null
}

export interface PeriodAveragePoint {
  period_id: number
  term_id: number
  school_year_id: number | null
  label: string
  average: number | null
}

export interface AnnualAveragePoint {
  school_year_id: number
  label: string
  average: number | null
}

export interface StudentTimeline {
  term_averages: TermAveragePoint[]
  period_averages?: PeriodAveragePoint[]
  annual_averages?: AnnualAveragePoint[]
  monthly_attendance: MonthlyAttendancePoint[]
}

export interface ChartSeries {
  name: string
  data: Array<number | null>
}

export interface Term {
  id: number
  school_year_id: number
  name: string
  position: number
  term_type?: TermType | null
  applicable_cycle?: TermCycle | null
  starts_on: string
  ends_on: string
  closed_at?: string | null
  is_closed?: boolean
  periods?: Period[]
  created_at?: string
  updated_at?: string
}

export interface Period {
  id: number
  term_id: number
  school_year_id?: number | null
  name: string
  position: number
  starts_on: string
  ends_on: string
  closed_at?: string | null
  is_closed?: boolean
  term?: Term
  created_at?: string
  updated_at?: string
}

export type LevelCycle = 'maternel' | 'primaire' | 'cteb' | 'secondaire'
export type TermType  = 'trimestre' | 'semestre'
export type TermCycle = 'primaire' | 'secondaire'

export interface Level {
  id: number
  name: string
  abbreviation?: string | null
  cycle: LevelCycle
  order: number
  has_options?: boolean
  classrooms?: ClassRoom[]
  created_at?: string
  updated_at?: string
}

export interface ClassRoom {
  id: number
  school_class_id?: number | null
  level_id: number
  school_option_id?: number | null
  section: string
  option: string
  capacity?: number
  active?: boolean
  full_name: string
  student_count?: number
  main_teacher?: {
    assignment_id?: number
    id: number
    name?: string | null
    email?: string | null
    speciality?: string | null
    subject_id?: number | null
    subject?: string | null
  } | null
  grade_average?: number | null
  current_school_year_id?: number | null
  level?: Level
  school_option?: SchoolOption
  school_class?: SchoolClass
  created_at?: string
  updated_at?: string
}

export interface SchoolClass {
  id: number
  school_year_id: number
  level_id: number
  school_option_id?: number | null
  name: string
  active: boolean
  level?: Level
  school_option?: SchoolOption | null
  divisions?: ClassRoom[]
  divisions_count?: number
  created_at?: string
  updated_at?: string
}

export type SchoolOptionFiliere = 'generale' | 'technique' | 'professionnelle'

export interface SchoolOption {
  id: number
  name: string
  abbreviation?: string | null
  cycle?: LevelCycle | null
  filiere?: SchoolOptionFiliere | null
  created_at?: string
  updated_at?: string
}

export interface Subject {
  id: number
  row_key?: string
  name: string
  code?: string | null
  description?: string | null
  default_coefficient?: number
  coefficient?: number
  evaluation_type?: 'sur_10' | 'sur_20' | 'pourcentage'
  status?: 'actif' | 'inactif'
  classroom_id?: number | null
  school_year_id?: number | null
  term_id?: number | null
  teacher_id?: number | null
  weekly_hours?: number | null
  classroom?: ClassRoom | null
  school_year?: SchoolYear | null
  term?: Term | null
  teacher?: Teacher | null
  created_at?: string
  updated_at?: string
}

export interface Teacher {
  id: number
  user_id: number
  teacher_type?: 'primaire' | 'secondaire' | null
  secondary_role?: 'principal' | 'specialist' | null
  cycle?: LevelCycle | null
  assignment_role?: 'principal' | 'intervenant' | null
  registration_number?: string | null
  gender?: 'F' | 'M' | null
  birth_date?: string | null
  address?: string | null
  grade?: string | null
  contract_type?: 'Permanent' | 'Vacataire' | null
  hired_on?: string | null
  speciality?: string | null
  assigned_courses_count?: number
  phone?: string | null
  main_classroom?: { id: number; full_name?: string | null } | null
  assigned_classrooms?: Array<{ id: number; full_name?: string | null }>
  subject?: { id: number; name?: string | null } | null
  user?: { id: number; name: string; email: string | null }
  created_at?: string
  updated_at?: string
}

export interface Assignment {
  id: number
  teacher_id: number
  classroom_id: number
  subject_id: number | null
  school_year_id: number
  term_id?: number | null
  weekly_hours?: number | null
  is_main?: boolean
  teacher?: Teacher
  classroom?: ClassRoom
  subject?: Subject
  term?: Term
  created_at?: string
  updated_at?: string
}

export interface Student {
  id: number
  user_id?: number | null
  classroom_id?: number | null
  enrollment_school_year_id?: number | null
  first_name: string
  last_name: string
  middle_name: string
  full_name: string
  date_of_birth?: string | null
  place_of_birth?: string | null
  gender?: 'F' | 'M' | null
  nationality?: string | null
  registration_number?: string | null
  photo_path?: string | null
  enrollment_status?: 'actif' | 'redoublant' | 'transfere' | 'inactif' | null
  order_number?: string | null
  enrolled_on?: string | null
  previous_school?: string | null
  father_name?: string | null
  mother_name?: string | null
  legal_guardian_name?: string | null
  guardian_relationship?: string | null
  primary_phone?: string | null
  secondary_phone?: string | null
  parent_email?: string | null
  residential_address?: string | null
  father_profession?: string | null
  mother_profession?: string | null
  notes?: string | null
  classroom?: ClassRoom
  enrollment_school_year?: SchoolYear
  parents?: ParentProfile[]
  student_portal_status?: StudentPortalStatus
  student_portal_eligible?: boolean
  relation?: string
  created_at?: string
  updated_at?: string
}

export type StudentPortalStatus =
  | 'active'
  | 'inactive'
  | 'not_created'
  | 'disabled_until_7e'
  | 'not_created_until_7e'

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
  period_id: number
  teacher_id?: number | null
  name: string
  type: 'interrogation' | 'devoir' | 'controle' | 'examen' | 'oral' | 'projet'
  type_label?: string
  component?: 'continuous' | 'exam'
  held_on: string
  max_value: number
  published_at?: string | null
  is_published?: boolean
  classroom?: ClassRoom
  subject?: Subject
  period?: Period
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

export interface ReportCardEvaluation {
  id: number | null
  evaluation_id: number
  name?: string | null
  type?: Evaluation['type'] | string | null
  type_label?: string | null
  component?: 'continuous' | 'exam' | string | null
  period_id?: number | null
  held_on?: string | null
  value: number | null
  max_value: number
  normalized_value: number | null
  absent: boolean
}

export interface ReportCardSubject {
  subject_id: number
  subject_name: string
  coefficient: number
  count: number
  average: number | null
  evaluations?: ReportCardEvaluation[]
}

export interface ReportCardData {
  student: { id: number; full_name: string; registration_number?: string | null; classroom?: string | null }
  term: { id: number; name: string }
  subjects: ReportCardSubject[]
  period_averages?: { period_id: number; name: string; position: number; average: number | null }[]
  overall_average: number | null
  total_coefficient: number
  appreciation?: string | null
  scoped_period_id?: number | null
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
  student_justification?: string | null
  student_justified_at?: string | null
  justification_status?: 'awaiting_student' | 'pending_parent' | 'confirmed' | 'expired'
  can_student_justify?: boolean
  can_parent_confirm?: boolean
  student?: Student
  subject?: Subject | null
}

export interface AttendanceAlertInfo {
  triggered: boolean
  reasons: string[]
  count_recent_30d: number
  consecutive: number
  late_count?: number
  thresholds?: {
    consecutive: number
    rolling: number
    rolling_window_days: number
    late: number
    late_window_days: number
  }
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

export type AppSettingType = 'integer' | 'float' | 'boolean'

export interface AppSettingRow {
  key: string
  value: number | boolean | string
  default: number | boolean | string
  type: AppSettingType
  description: string | null
  min: number | null
  max: number | null
}

export interface StudentAtRiskTriggers {
  absences_consecutive: number
  absences_rolling: number
  lates: number
  has_absence_alert: boolean
  has_late_alert: boolean
  has_low_grade_alert: boolean
}

export interface StudentAtRiskGradeTerm {
  id: number
  name: string
  type_label?: string
}

export interface StudentAtRisk {
  id: number
  full_name: string
  classroom: string | null
  classroom_id: number | null
  level: string | null
  cycle?: LevelCycle | null
  grade_term?: StudentAtRiskGradeTerm | null
  student_user_id?: number | null
  student_portal_active?: boolean
  parent_users?: Array<{ id: number; name: string }>
  triggers: StudentAtRiskTriggers
  average: number | null
}

export interface StudentsAtRiskMeta {
  thresholds: {
    consecutive: number
    rolling: number
    rolling_window_days: number
    late: number
    late_window_days: number
    low_grade: number
  }
  term: { id: number; name: string; term_type?: string; type_label?: string } | null
  terms?: Record<
    string,
    { id: number; name: string; term_type?: string; type_label?: string; applicable_cycle?: string }
  > | null
  type: string
  admin_scope?: AdminScope | null
  admin_scope_label?: string | null
  admin_cycles?: LevelCycle[]
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
  cycles?: LevelCycle[]
  classrooms?: Array<{
    id: number
    name: string
    cycle?: LevelCycle | null
  }>
}

export interface BroadcastRecipient {
  id: number
  name: string
  email: string
  role: UserRole
  is_read: boolean
  read_at: string | null
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
  is_announcement?: boolean
  broadcast_id?: string | null
  sender?: MessageContact
  recipient?: MessageContact
  replies?: Message[]
  replies_count?: number
  recipients_count?: number
  broadcast_recipients?: BroadcastRecipient[]
  created_at?: string
  updated_at?: string
}

export type BroadcastAudienceType =
  | 'all_users'
  | 'all_parents'
  | 'all_teachers'
  | 'all_students'
  | 'classroom'
  | 'cycle'
  | 'custom'

export interface BroadcastPayload {
  subject: string
  body: string
  audience_type: BroadcastAudienceType
  classroom_id?: number | null
  cycle?: LevelCycle | null
  user_ids?: number[]
}
