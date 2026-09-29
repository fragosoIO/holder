<script setup lang="ts">
import { ref } from 'vue'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import EmptyState from '../components/EmptyState.vue'
import { useWorkspace } from '../composables/workspace'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'

const board = useBoard()
const chrome = useChrome()
const { company } = storeToRefs(board)
const { goals, loading, error: loadError } = useWorkspace()
const dialog = ref<HTMLDialogElement | null>(null)
const title = ref('')
const description = ref('')
const parentId = ref('')
const error = ref('')

async function create() {
  if (!company.value) return
  error.value = ''
  try {
    await api(`/api/v1/companies/${company.value.id}/goals`, {
      method: 'POST',
      body: JSON.stringify({ title: title.value, description: description.value, parentId: parentId.value }),
    })
    title.value = ''
    description.value = ''
    parentId.value = ''
    dialog.value?.close()
    chrome.bump()
  } catch (reason) {
    error.value = failureMessage(reason, 'The goal could not be added.')
  }
}

function parentTitle(id: string | null) {
  if (!id) return ''
  return goals.value.find((goal) => goal.id === id)?.title ?? ''
}
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading goals…</p>
  <p v-else-if="loadError" class="text-sm text-destructive" role="alert">{{ loadError }}</p>
  <div v-else class="space-y-3">
    <div class="flex justify-end">
      <button class="btn btn-primary" type="button" @click="dialog?.showModal()">New goal</button>
    </div>
    <EmptyState v-if="goals.length === 0" message="No goals yet." />
    <div v-else class="card divide-y divide-border overflow-hidden">
      <article v-for="goal in goals" :key="goal.id" class="px-4 py-3">
        <h2 class="text-sm font-medium">{{ goal.title }}</h2>
        <p v-if="goal.description" class="mt-1 text-sm text-muted-foreground">{{ goal.description }}</p>
        <p v-if="parentTitle(goal.parentId)" class="mt-1 text-xs text-muted-foreground">Parent: {{ parentTitle(goal.parentId) }}</p>
      </article>
    </div>
    <dialog ref="dialog" class="sheet">
      <form class="grid gap-3 p-5" @submit.prevent="create">
        <div class="flex items-center justify-between">
          <h2 class="text-base font-semibold">New goal</h2>
          <button class="btn btn-ghost" type="button" @click="dialog?.close()">Close</button>
        </div>
        <label class="label">Title
          <input v-model="title" required class="field" />
        </label>
        <label class="label">Description
          <textarea v-model="description" rows="3" class="field" />
        </label>
        <label class="label">Parent
          <select v-model="parentId" class="field">
            <option value="">No parent</option>
            <option v-for="goal in goals" :key="goal.id" :value="goal.id">{{ goal.title }}</option>
          </select>
        </label>
        <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
        <div class="flex justify-end">
          <button class="btn btn-primary" type="submit">Add goal</button>
        </div>
      </form>
    </dialog>
  </div>
</template>
