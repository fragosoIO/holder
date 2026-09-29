<script setup lang="ts">
import { ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useWorkspace } from '../composables/workspace'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'

const board = useBoard()
const chrome = useChrome()
const { company } = storeToRefs(board)
const { goals, agents } = useWorkspace()
const dialog = ref<HTMLDialogElement | null>(null)
const title = ref('')
const description = ref('')
const goalId = ref('')
const assigneeAgentId = ref('')
const error = ref('')
const saving = ref(false)

watch(() => chrome.newTask, (open) => {
  const element = dialog.value
  if (!element) return
  if (open && !element.open) {
    error.value = ''
    element.showModal()
  }
  if (!open && element.open) element.close()
})

function close() {
  chrome.newTask = false
}

async function submit() {
  if (!company.value) return
  error.value = ''
  saving.value = true
  try {
    await api(`/api/v1/companies/${company.value.id}/tasks`, {
      method: 'POST',
      body: JSON.stringify({
        title: title.value,
        description: description.value,
        goalId: goalId.value,
        assigneeAgentId: assigneeAgentId.value,
      }),
    })
    title.value = ''
    description.value = ''
    goalId.value = ''
    assigneeAgentId.value = ''
    chrome.bump()
    close()
  } catch (reason) {
    error.value = failureMessage(reason, 'The task could not be added.')
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <dialog ref="dialog" class="sheet" @close="close">
    <form class="grid gap-3 p-5" @submit.prevent="submit">
      <div class="flex items-center justify-between gap-3">
        <h2 class="text-base font-semibold">New task</h2>
        <button class="btn btn-ghost" type="button" @click="close">Close</button>
      </div>
      <label class="label">Title
        <input v-model="title" required class="field" autofocus />
      </label>
      <label class="label">Description
        <textarea v-model="description" rows="4" class="field" />
      </label>
      <label class="label">Goal
        <select v-model="goalId" class="field">
          <option value="">No goal</option>
          <option v-for="goal in goals" :key="goal.id" :value="goal.id">{{ goal.title }}</option>
        </select>
      </label>
      <label class="label">Assignee
        <select v-model="assigneeAgentId" class="field">
          <option value="">Unassigned</option>
          <option v-for="agent in agents" :key="agent.id" :value="agent.id">{{ agent.name }}</option>
        </select>
      </label>
      <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
      <div class="flex justify-end">
        <button class="btn btn-primary" type="submit" :disabled="saving">Create task</button>
      </div>
    </form>
  </dialog>
</template>
