<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { api } from '../api/client'
import { useAuthStore } from '../stores/auth'

const auth = useAuthStore()

interface AdminDashboard {
  counts: Record<string, number>
  attendance: { total_absences: number; unjustified: number; total_lates: number; absence_rate_per_student: number }
  current_term: { id: number; name: string } | null
  classrooms: { classroom_id: number; full_name: string; student_count: number; class_average: number | null; absences: number }[]
}

interface TeacherDashboard {
  teacher_name: string
  current_term: { id: number; name: string } | null
  assignments: {
    classroom_id: number; classroom: string
    subject_id: number; subject: string
    student_count: number; evaluations: number
    grades_entered: number; class_average: number | null
    absences: number
  }[]
}

const adminData = ref<AdminDashboard | null>(null)
const teacherData = ref<TeacherDashboard | null>(null)
const loading = ref(false)
const error = ref('')

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    if (auth.hasRole('admin')) {
      const res = await api<{ data: AdminDashboard }>('/api/v1/admin/dashboard')
      adminData.value = res.data
    } else if (auth.hasRole('enseignant')) {
      const res = await api<{ data: TeacherDashboard }>('/api/v1/teacher/dashboard')
      teacherData.value = res.data
    }
  } catch {
    error.value = 'Impossible de charger le tableau de bord.'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <section>
    <h1 style="margin: 0 0 0.25rem">Bienvenue, {{ auth.user?.name }}</h1>
    <p class="text-soft" style="margin: 0 0 1.25rem; font-size: 0.93rem">
      Rôle : <strong>{{ auth.user?.role }}</strong>
      <template v-if="adminData?.current_term"> · Trimestre en cours : <strong>{{ adminData.current_term.name }}</strong></template>
      <template v-else-if="teacherData?.current_term"> · Trimestre en cours : <strong>{{ teacherData.current_term.name }}</strong></template>
    </p>

    <p v-if="error" class="alert alert-error">{{ error }}</p>
    <div v-if="loading" class="empty-state">Chargement…</div>

    <!-- ────────── ADMIN ────────── -->
    <template v-else-if="adminData">
      <div class="kpi-grid">
        <div class="kpi-card">
          <span class="kpi-value">{{ adminData.counts.students }}</span>
          <span class="kpi-label">Élèves</span>
        </div>
        <div class="kpi-card">
          <span class="kpi-value">{{ adminData.counts.teachers }}</span>
          <span class="kpi-label">Enseignants</span>
        </div>
        <div class="kpi-card">
          <span class="kpi-value">{{ adminData.counts.parents }}</span>
          <span class="kpi-label">Parents</span>
        </div>
        <div class="kpi-card">
          <span class="kpi-value">{{ adminData.counts.classrooms }}</span>
          <span class="kpi-label">Classes</span>
        </div>
        <div class="kpi-card">
          <span class="kpi-value">{{ adminData.counts.subjects }}</span>
          <span class="kpi-label">Matières</span>
        </div>
        <div class="kpi-card warn">
          <span class="kpi-value">{{ adminData.attendance.total_absences }}</span>
          <span class="kpi-label">Absences</span>
        </div>
        <div class="kpi-card warn">
          <span class="kpi-value">{{ adminData.attendance.unjustified }}</span>
          <span class="kpi-label">Non justifiées</span>
        </div>
        <div class="kpi-card">
          <span class="kpi-value">{{ adminData.attendance.total_lates }}</span>
          <span class="kpi-label">Retards</span>
        </div>
      </div>

      <div class="card" style="margin-top: 1.25rem" v-if="adminData.classrooms.length > 0">
        <div class="card-header"><h2 style="margin: 0">Résultats par classe</h2></div>
        <table>
          <thead>
            <tr>
              <th>Classe</th>
              <th style="text-align: right">Effectif</th>
              <th style="text-align: right">Moyenne</th>
              <th style="text-align: right">Absences</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="c in adminData.classrooms" :key="c.classroom_id">
              <td>{{ c.full_name }}</td>
              <td style="text-align: right">{{ c.student_count }}</td>
              <td style="text-align: right; font-weight: 600" :class="{ good: (c.class_average ?? 0) >= 10, low: (c.class_average ?? 0) < 10 && c.class_average !== null }">
                {{ c.class_average !== null ? c.class_average.toFixed(2) : '—' }}
              </td>
              <td style="text-align: right">{{ c.absences }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="card" style="margin-top: 1rem">
        <div class="card-header"><h2 style="margin: 0">Raccourcis</h2></div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.25rem 0">
          <RouterLink :to="{ name: 'students' }" class="shortcut">Élèves</RouterLink>
          <RouterLink :to="{ name: 'evaluations' }" class="shortcut">Évaluations</RouterLink>
          <RouterLink :to="{ name: 'attendances' }" class="shortcut">Présences</RouterLink>
          <RouterLink :to="{ name: 'school-years' }" class="shortcut">Années scolaires</RouterLink>
          <RouterLink :to="{ name: 'users' }" class="shortcut">Utilisateurs</RouterLink>
        </div>
      </div>
    </template>

    <!-- ────────── ENSEIGNANT ────────── -->
    <template v-else-if="teacherData">
      <div class="card" v-if="teacherData.assignments.length > 0">
        <div class="card-header"><h2 style="margin: 0">Mes classes &amp; matières</h2></div>
        <table>
          <thead>
            <tr>
              <th>Classe</th>
              <th>Matière</th>
              <th style="text-align: right">Élèves</th>
              <th style="text-align: right">Évaluations</th>
              <th style="text-align: right">Notes saisies</th>
              <th style="text-align: right">Moyenne</th>
              <th style="text-align: right">Absences</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(a, i) in teacherData.assignments" :key="i">
              <td>{{ a.classroom }}</td>
              <td>{{ a.subject }}</td>
              <td style="text-align: right">{{ a.student_count }}</td>
              <td style="text-align: right">{{ a.evaluations }}</td>
              <td style="text-align: right">{{ a.grades_entered }}</td>
              <td style="text-align: right; font-weight: 600" :class="{ good: (a.class_average ?? 0) >= 10, low: (a.class_average ?? 0) < 10 && a.class_average !== null }">
                {{ a.class_average !== null ? a.class_average.toFixed(2) : '—' }}
              </td>
              <td style="text-align: right">{{ a.absences }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else class="empty-state">
        Aucune affectation trouvée pour votre profil enseignant.
      </div>

      <div class="card" style="margin-top: 1rem">
        <div class="card-header"><h2 style="margin: 0">Raccourcis</h2></div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.25rem 0">
          <RouterLink :to="{ name: 'evaluations' }" class="shortcut">Évaluations &amp; Notes</RouterLink>
          <RouterLink :to="{ name: 'attendances' }" class="shortcut">Présences</RouterLink>
          <RouterLink :to="{ name: 'messages' }" class="shortcut">Messagerie</RouterLink>
        </div>
      </div>
    </template>

    <!-- ────────── AUTRE RÔLE (secretariat, etc.) ────────── -->
    <template v-else>
      <div class="card">
        <div class="card-header"><h2 style="margin: 0">Raccourcis</h2></div>
        <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; padding: 0.25rem 0">
          <RouterLink :to="{ name: 'attendances' }" class="shortcut">Présences</RouterLink>
          <RouterLink :to="{ name: 'messages' }" class="shortcut">Messagerie</RouterLink>
        </div>
      </div>
    </template>
  </section>
</template>

<style scoped>
.text-soft { color: var(--text-soft); }
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
  gap: 0.75rem;
}
.kpi-card {
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 1rem;
  text-align: center;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.kpi-card.warn { border-color: #fdba74; }
.kpi-value {
  font-size: 1.6rem;
  font-weight: 700;
  line-height: 1;
}
.kpi-label {
  font-size: 0.78rem;
  color: var(--text-soft);
  text-transform: uppercase;
  letter-spacing: 0.03em;
}
.good { color: #16a34a; }
.low { color: #ea580c; }
.shortcut {
  display: inline-block;
  padding: 0.4rem 0.85rem;
  border: 1px solid var(--border);
  border-radius: 6px;
  font-size: 0.88rem;
  text-decoration: none;
  color: var(--text);
}
.shortcut:hover { background: var(--primary-soft); }
</style>
