<script setup lang="ts" generic="T extends Record<string, any>">
import { computed } from 'vue'

export interface Column<T> {
  key: string
  label: string
  width?: string
  align?: 'left' | 'center' | 'right'
  /** Accès optionnel typé à la valeur de la cellule (extension). */
  accessor?: (row: T) => unknown
}

const props = defineProps<{
  items: T[]
  columns: Column<T>[]
  keyField?: string
  selectable?: boolean
  selectedIds?: (string | number)[]
  rowClickable?: boolean
}>()

const emit = defineEmits<{
  (e: 'update:selectedIds', value: (string | number)[]): void
  (e: 'row-click', item: T): void
}>()

const itemKey = computed(() => props.keyField || 'id')

const allSelected = computed(() => {
  if (props.items.length === 0) return false
  return props.items.every((item) => props.selectedIds?.includes(item[itemKey.value]))
})

const someSelected = computed(() => {
  if (props.items.length === 0 || !props.selectedIds) return false
  const selectedCount = props.items.filter((item) => props.selectedIds?.includes(item[itemKey.value])).length
  return selectedCount > 0 && selectedCount < props.items.length
})

function toggleSelectAll(event: Event) {
  const checked = (event.target as HTMLInputElement).checked
  if (checked) {
    const newIds = [...new Set([...(props.selectedIds || []), ...props.items.map((i) => i[itemKey.value])])]
    emit('update:selectedIds', newIds)
  } else {
    const visibleIds = new Set(props.items.map((i) => i[itemKey.value]))
    const newIds = (props.selectedIds || []).filter((id) => !visibleIds.has(id))
    emit('update:selectedIds', newIds)
  }
}

function toggleRow(item: T, event: Event) {
  const checked = (event.target as HTMLInputElement).checked
  const id = item[itemKey.value]
  const currentIds = props.selectedIds || []
  if (checked) {
    emit('update:selectedIds', [...new Set([...currentIds, id])])
  } else {
    emit('update:selectedIds', currentIds.filter((i) => i !== id))
  }
}

function onRowClick(item: T, event: Event) {
  if (!props.rowClickable) return
  // Ignore clicks on interactive elements inside the row
  const target = event.target as HTMLElement
  if (target.tagName === 'INPUT' || target.tagName === 'BUTTON' || target.tagName === 'A' || target.closest('button') || target.closest('a')) {
    return
  }
  emit('row-click', item)
}
</script>

<template>
  <div class="table-container">
    <table class="data-table">
      <thead>
        <tr>
          <th v-if="selectable" class="select-col">
            <input
              type="checkbox"
              aria-label="Sélectionner tous"
              :checked="allSelected"
              :indeterminate="someSelected"
              @change="toggleSelectAll"
            />
          </th>
          <th
            v-for="col in columns"
            :key="String(col.key)"
            :style="{ width: col.width, textAlign: col.align || 'left' }"
          >
            {{ col.label }}
          </th>
        </tr>
      </thead>
      <tbody v-if="items.length > 0">
        <tr
          v-for="(item, index) in items"
          :key="item[itemKey]"
          :class="{
            'clickable-row': rowClickable,
            'is-selected': selectable && selectedIds?.includes(item[itemKey])
          }"
          :tabindex="rowClickable ? 0 : undefined"
          role="row"
          @click="onRowClick(item, $event)"
          @keydown.enter.prevent="onRowClick(item, $event)"
          @keydown.space.prevent="onRowClick(item, $event)"
        >
          <td v-if="selectable" class="select-col" @click.stop @keydown.stop>
            <input
              type="checkbox"
              :checked="selectedIds?.includes(item[itemKey])"
              @change="toggleRow(item, $event)"
            />
          </td>
          <td
            v-for="col in columns"
            :key="String(col.key)"
            :style="{ textAlign: col.align || 'left' }"
          >
            <slot :name="'col-' + String(col.key)" :item="item" :index="index">
              {{ item[col.key] }}
            </slot>
          </td>
        </tr>
      </tbody>
      <tbody v-else>
        <tr>
          <td :colspan="columns.length + (selectable ? 1 : 0)" class="empty-state">
            <slot name="empty">Aucune donnée disponible.</slot>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<style scoped>
.table-container {
  overflow-x: auto;
}
.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
}
.data-table th {
  background: var(--bg-soft);
  color: var(--text-soft);
  font-weight: 700;
  text-transform: uppercase;
  font-size: 0.75rem;
  letter-spacing: 0.05em;
  padding: 0.75rem 1rem;
  border-bottom: 2px solid var(--border);
}
.data-table td {
  padding: 0.75rem 1rem;
  border-bottom: 1px solid var(--border);
  color: var(--text);
  vertical-align: middle;
}
.clickable-row {
  cursor: pointer;
  transition: background-color 0.15s;
}
.clickable-row:hover {
  background-color: var(--bg-soft);
}
.is-selected {
  background-color: var(--primary-soft);
}
.is-selected td:first-child {
  box-shadow: inset 3px 0 0 var(--primary);
}
.select-col {
  width: 3rem;
  text-align: center;
}
.empty-state {
  text-align: center;
  padding: 3rem 1rem;
  color: var(--text-soft);
  font-style: italic;
}
</style>
