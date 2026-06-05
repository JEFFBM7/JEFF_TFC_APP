<script setup lang="ts">
import Modal from './Modal.vue'
import { useConfirmStore } from '../stores/confirm'

const confirmDialog = useConfirmStore()
</script>

<template>
  <Modal
    :open="confirmDialog.open"
    :title="confirmDialog.options.title"
    max-width="34rem"
    @close="confirmDialog.cancel"
  >
    <div
      class="confirm-dialog"
      :class="{
        danger: confirmDialog.isDanger,
        warning: confirmDialog.isWarning,
      }"
    >
      <div class="confirm-mark" aria-hidden="true">
        {{ confirmDialog.isDanger ? '!' : '?' }}
      </div>
      <div class="confirm-copy">
        <p>{{ confirmDialog.options.message }}</p>
      </div>

      <ul v-if="confirmDialog.options.details?.length" class="confirm-details">
        <li v-for="detail in confirmDialog.options.details" :key="detail">{{ detail }}</li>
      </ul>

      <p v-if="confirmDialog.options.note" class="confirm-note">
        {{ confirmDialog.options.note }}
      </p>
    </div>

    <template #footer>
      <button type="button" @click="confirmDialog.cancel">
        {{ confirmDialog.options.cancelLabel }}
      </button>
      <button
        type="button"
        class="confirm-action"
        :class="{
          danger: confirmDialog.isDanger,
          warning: confirmDialog.isWarning,
        }"
        @click="confirmDialog.confirm"
      >
        {{ confirmDialog.options.confirmLabel }}
      </button>
    </template>
  </Modal>
</template>

<style scoped>
.confirm-dialog {
  display: grid;
  grid-template-columns: 2.75rem minmax(0, 1fr);
  gap: 0.9rem;
  align-items: start;
}

.confirm-mark {
  width: 2.75rem;
  height: 2.75rem;
  display: grid;
  place-items: center;
  border-radius: 999px;
  background: var(--primary-soft);
  color: var(--primary);
  font-size: 1.15rem;
  font-weight: 950;
}

.confirm-dialog.danger .confirm-mark {
  background: var(--danger-soft);
  color: var(--danger);
}

.confirm-dialog.warning .confirm-mark {
  background: #fff7ed;
  color: #c2410c;
}

.confirm-copy {
  display: grid;
  gap: 0.35rem;
}

.confirm-copy p {
  margin: 0;
  color: var(--text);
  line-height: 1.5;
  font-size: 0.96rem;
  font-weight: 760;
}

.confirm-details {
  grid-column: 1 / -1;
  display: grid;
  gap: 0.35rem;
  margin: 0;
  padding: 0;
  list-style: none;
}

.confirm-details li {
  padding: 0.55rem 0.7rem;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: #f8fafc;
  color: var(--text);
  font-size: 0.88rem;
  font-weight: 800;
}

.confirm-note {
  grid-column: 1 / -1;
  margin: 0;
  padding: 0.7rem 0.8rem;
  border: 1px solid #bfdbfe;
  border-radius: 8px;
  background: #eff6ff;
  color: #1d4ed8;
  font-size: 0.85rem;
  font-weight: 750;
}

.confirm-dialog.danger .confirm-note {
  border-color: #fecdd3;
  background: #fff1f2;
  color: #9f1239;
}

.confirm-dialog.warning .confirm-note {
  border-color: #fed7aa;
  background: #fff7ed;
  color: #9a3412;
}

.confirm-action.danger {
  border-color: var(--danger);
  background: var(--danger);
  color: #fff;
}

.confirm-action.danger:hover {
  border-color: #b42318;
  background: #b42318;
}

.confirm-action.warning {
  border-color: #ea580c;
  background: #ea580c;
  color: #fff;
}

.confirm-action.warning:hover {
  border-color: #c2410c;
  background: #c2410c;
}
</style>
