<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import { api, ApiError } from '../api/client'
import type { ApiResource, Period, Term } from '../types'
import Modal from './Modal.vue'

const props = defineProps<{
  open: boolean
  term: Term | null
  period: Period | null
}>()

const emit = defineEmits<{
  close: []
  saved: []
}>()

const form = reactive({
  name: '',
  position: 1,
  starts_on: '',
  ends_on: '',
})

const submitting = ref(false)
const formError = ref('')
const formErrors = reactive<Record<string, string[]>>({})

const boundsError = computed(() => {
  if (!props.term || !form.starts_on || !form.ends_on) return ''
  if (form.starts_on < props.term.starts_on || form.ends_on > props.term.ends_on) {
    return 'La période doit rester dans les dates du trimestre.'
  }
  return ''
})

const allowedPositions = computed(() => {
  const first = (((props.term?.position ?? 1) - 1) * 2) + 1
  return [first, first + 1]
})

function defaultPosition(): number {
  const used = new Set((props.term?.periods ?? []).map((period) => period.position))
  return allowedPositions.value.find((position) => !used.has(position)) ?? allowedPositions.value[0]
}

watch(
  () => [props.open, props.period, props.term] as const,
  () => {
    if (!props.open) return
    const position = props.period?.position ?? defaultPosition()
    form.name = props.period?.name ?? `Période ${position}`
    form.position = position
    form.starts_on = props.period?.starts_on ?? props.term?.starts_on ?? ''
    form.ends_on = props.period?.ends_on ?? props.term?.ends_on ?? ''
    formError.value = ''
    Object.keys(formErrors).forEach((key) => delete formErrors[key])
  },
  { immediate: true },
)

watch(
  () => form.position,
  (position) => {
    if (!props.open || props.period) return
    if (form.name === '' || /^Période \d+$/.test(form.name)) {
      form.name = `Période ${position}`
    }
  },
)

async function submit(): Promise<void> {
  if (!props.term || boundsError.value) return
  submitting.value = true
  formError.value = ''
  Object.keys(formErrors).forEach((key) => delete formErrors[key])

  const body = {
    term_id: props.term.id,
    name: form.name,
    position: form.position,
    starts_on: form.starts_on,
    ends_on: form.ends_on,
  }

  try {
    if (props.period) {
      await api<ApiResource<Period>>(`/api/v1/periods/${props.period.id}`, { method: 'PUT', body })
    } else {
      await api<ApiResource<Period>>('/api/v1/periods', { method: 'POST', body })
    }
    emit('saved')
  } catch (err) {
    if (err instanceof ApiError) {
      formError.value = err.message
      if (err.errors) Object.assign(formErrors, err.errors)
    } else {
      formError.value = 'Erreur réseau.'
    }
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <Modal
    :open="open"
    :title="period ? 'Modifier une période' : 'Nouvelle période'"
    @close="emit('close')"
  >
    <form id="period-form" @submit.prevent="submit">
      <div class="field">
        <label for="p-name">Nom</label>
        <input id="p-name" v-model="form.name" type="text" required maxlength="64" />
        <small v-if="formErrors.name" class="err">{{ formErrors.name[0] }}</small>
      </div>

      <div class="form-grid">
        <div class="field">
          <label for="p-position">Position</label>
          <select id="p-position" v-model.number="form.position" required>
            <option v-for="position in allowedPositions" :key="position" :value="position">
              Période {{ position }}
            </option>
          </select>
          <small v-if="formErrors.position" class="err">{{ formErrors.position[0] }}</small>
        </div>

        <div class="field">
          <label for="p-starts">Date de début</label>
          <input id="p-starts" v-model="form.starts_on" type="date" required />
          <small v-if="formErrors.starts_on" class="err">{{ formErrors.starts_on[0] }}</small>
        </div>
      </div>

      <div class="field">
        <label for="p-ends">Date de fin</label>
        <input id="p-ends" v-model="form.ends_on" type="date" required />
        <small v-if="formErrors.ends_on" class="err">{{ formErrors.ends_on[0] }}</small>
      </div>

      <p v-if="boundsError" class="alert alert-error">{{ boundsError }}</p>
      <p v-if="formError" class="alert alert-error">{{ formError }}</p>
    </form>

    <template #footer>
      <button type="button" @click="emit('close')">Annuler</button>
      <button type="submit" form="period-form" class="btn-primary" :disabled="submitting || !!boundsError">
        {{ submitting ? 'Enregistrement...' : period ? 'Enregistrer' : 'Créer' }}
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.err {
  display: block;
  color: var(--danger);
  font-size: 0.78rem;
  margin-top: 0.25rem;
}
</style>
