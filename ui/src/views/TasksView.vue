<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useBoard } from '../stores/board'
import StatusPill from '../components/StatusPill.vue'
import type { Agent, Goal, Task } from '../types'

const board = useBoard()
const { company } = storeToRefs(board)
const tasks = ref<Task[]>([])
const goals = ref<Goal[]>([])
const agents = ref<Agent[]>([])
const title = ref('')
const description = ref('')
const goalId = ref('')
const assigneeAgentId = ref('')
const loading = ref(true)
const error = ref('')

async function load() {
  error.value = ''
  try {
    if (!company.value) return
    const base = `/api/v1/companies/${company.value.id}`
    ;[tasks.value, goals.value, agents.value] = await Promise.all([
      api<Task[]>(`${base}/tasks`),
      api<Goal[]>(`${base}/goals`),
      api<Agent[]>(`${base}/agents`),
    ])
  } catch (reason) {
    error.value = failureMessage(reason, 'Tasks could not be loaded.')
  } finally {
    loading.value = false
  }
}

async function create() {
  if (!company.value) return
  error.value = ''
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
    await load()
  } catch (reason) {
    error.value = failureMessage(reason, 'The task could not be added.')
  }
}

function agentName(id: string | null) {
  return agents.value.find((agent) => agent.id === id)?.name ?? 'Unassigned'
}

onMounted(load)
</script>

<template>
  <div class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="stack">
      <p v-if="loading" class="status status-loading" role="status">Loading tasks…</p>
      <p v-else-if="error && tasks.length === 0" class="status status-error" role="alert">{{ error }}</p>
      <ul v-else class="stack plain-list">
        <li v-for="task in tasks" :key="task.id" class="surface stack">
          <div class="row-title">
            <RouterLink :to="`/tasks/${task.id}`" class="name-link section-title">{{ task.title }}</RouterLink>
            <StatusPill :status="task.status" />
          </div>
          <p class="meta-text">{{ agentName(task.assigneeAgentId) }}</p>
        </li>
        <li v-if="tasks.length === 0">
          <p class="status status-empty" role="status">No tasks yet.</p>
        </li>
      </ul>
    </div>
    <form class="surface stack" @submit.prevent="create">
      <h2 class="section-title">New task</h2>
      <label class="label">Title
        <input v-model="title" required placeholder="Title" class="field" />
      </label>
      <label class="label">Description
        <textarea v-model="description" rows="4" placeholder="What done looks like" class="field" />
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
      <p v-if="error && tasks.length > 0" class="status status-error" role="alert">{{ error }}</p>
      <button class="btn btn-primary" type="submit">Add task</button>
    </form>
  </div>
</template>
