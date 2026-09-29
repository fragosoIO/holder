<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AgentCapsule from '../components/AgentCapsule.vue'
import EmptyState from '../components/EmptyState.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'
import { useChrome } from '../stores/chrome'
import { shortId, taskStatuses, timeAgo } from '../lib/format'

const chrome = useChrome()
const { tasks, agents, loading, error } = useWorkspace()
const query = ref('')
const status = ref('')

const filtered = computed(() => {
  const needle = query.value.trim().toLowerCase()
  return tasks.value.filter((task) => {
    if (status.value && task.status !== status.value) return false
    if (!needle) return true
    return task.title.toLowerCase().includes(needle) || task.description.toLowerCase().includes(needle)
  })
})

function assignee(id: string | null) {
  return agents.value.find((agent) => agent.id === id)
}
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading tasks…</p>
  <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <div v-else class="space-y-3">
    <div class="flex flex-wrap items-center gap-2">
      <input v-model="query" class="field mt-0 max-w-sm" placeholder="Filter tasks" aria-label="Filter tasks" />
      <select v-model="status" class="field mt-0 w-auto" aria-label="Status">
        <option value="">All statuses</option>
        <option v-for="item in taskStatuses" :key="item.value" :value="item.value">{{ item.label }}</option>
      </select>
      <span class="text-xs text-muted-foreground">{{ filtered.length }}</span>
      <button class="btn btn-primary ml-auto" type="button" @click="chrome.openTask()">New task</button>
    </div>
    <EmptyState v-if="tasks.length === 0" message="No tasks yet.">
      <button class="btn btn-primary" type="button" @click="chrome.openTask()">New task</button>
    </EmptyState>
    <p v-else-if="filtered.length === 0" class="text-sm text-muted-foreground">No tasks match that filter.</p>
    <div v-else class="card divide-y divide-border overflow-hidden">
      <RouterLink
        v-for="task in filtered"
        :key="task.id"
        :to="`/issues/${task.id}`"
        class="flex items-center gap-3 px-3 py-2.5 no-underline hover:bg-accent/50"
      >
        <StatusMark :status="task.status" />
        <span class="min-w-0 flex-1 truncate text-sm">{{ task.title }}</span>
        <span v-if="task.parentId" class="shrink-0 text-[11px] uppercase tracking-wide text-muted-foreground">Subtask</span>
        <AgentCapsule v-if="assignee(task.assigneeAgentId)" :id="assignee(task.assigneeAgentId)!.id" :name="assignee(task.assigneeAgentId)!.name" />
        <span class="hidden w-16 shrink-0 text-right font-mono text-[11px] text-muted-foreground sm:inline">{{ shortId(task.id) }}</span>
        <span class="hidden w-16 shrink-0 text-right text-xs text-muted-foreground md:inline">{{ timeAgo(task.updatedAt) }}</span>
      </RouterLink>
    </div>
  </div>
</template>
