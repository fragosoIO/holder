<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { Bot, CircleDot, DollarSign, PauseCircle, ShieldCheck } from '@lucide/vue'
import AgentCapsule from '../components/AgentCapsule.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'
import { useChrome } from '../stores/chrome'
import { needsAttention, shortId, statusLabel, taskStatuses, timeAgo } from '../lib/format'
import type { Agent, Task } from '../types'

const chrome = useChrome()
const { tasks, agents, loading, error } = useWorkspace()

const hired = computed(() => agents.value.filter((agent) => agent.status !== 'terminated'))
const paused = computed(() => hired.value.filter((agent) => agent.status === 'paused'))
const openTasks = computed(() => tasks.value.filter((task) => task.status !== 'done' && task.status !== 'cancelled'))
const inProgress = computed(() => tasks.value.filter((task) => task.status === 'in_progress' || task.checkoutRunId))
const blocked = computed(() => tasks.value.filter((task) => task.status === 'blocked'))
const attention = computed(() => tasks.value.filter((task) => needsAttention(task.status)))
const running = computed(() => {
  const ids = new Set(tasks.value.filter((task) => task.checkoutRunId && task.assigneeAgentId).map((task) => task.assigneeAgentId))
  return hired.value.filter((agent) => ids.has(agent.id))
})
const maxStatus = computed(() => Math.max(1, ...taskStatuses.map((item) => count(item.value))))

function count(status: string) {
  return tasks.value.filter((task) => task.status === status).length
}

function agentName(task: Task): Agent | undefined {
  return agents.value.find((agent) => agent.id === task.assigneeAgentId)
}
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading the dashboard…</p>
  <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <div v-else class="space-y-6">
    <div
      v-if="hired.length > 0 && paused.length === hired.length"
      class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
    >
      <div class="flex items-center gap-2 text-sm">
        <PauseCircle class="h-4 w-4 shrink-0" />
        All agents in this organization are paused. Nothing will run.
      </div>
      <RouterLink to="/agents" class="text-sm font-medium underline underline-offset-2">Review agents</RouterLink>
    </div>
    <div
      v-if="hired.length === 0"
      class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
    >
      <div class="flex items-center gap-2 text-sm">
        <Bot class="h-4 w-4 shrink-0" />
        You have no agents.
      </div>
      <RouterLink to="/agents/new" class="text-sm font-medium underline underline-offset-2">Create one here</RouterLink>
    </div>

    <section v-if="running.length" class="card px-4 py-3">
      <h2 class="mb-2 font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Running</h2>
      <ul class="grid gap-2">
        <li v-for="agent in running" :key="agent.id">
          <RouterLink :to="`/agents/${agent.id}`" class="flex items-center gap-2 no-underline">
            <StatusMark status="running" />
            <AgentCapsule :id="agent.id" :name="agent.name" />
          </RouterLink>
        </li>
      </ul>
    </section>

    <div class="grid grid-cols-2 gap-2 xl:grid-cols-4">
      <RouterLink to="/agents" class="card block p-4 no-underline hover:bg-accent/40">
        <div class="flex items-center justify-between text-muted-foreground">
          <span class="text-xs font-medium uppercase tracking-wide">Agents</span>
          <Bot class="h-4 w-4" />
        </div>
        <p class="mt-2 text-2xl font-semibold text-foreground">{{ hired.length }}</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ paused.length }} paused</p>
      </RouterLink>
      <RouterLink to="/issues" class="card block p-4 no-underline hover:bg-accent/40">
        <div class="flex items-center justify-between text-muted-foreground">
          <span class="text-xs font-medium uppercase tracking-wide">In progress</span>
          <CircleDot class="h-4 w-4" />
        </div>
        <p class="mt-2 text-2xl font-semibold text-foreground">{{ inProgress.length }}</p>
        <p class="mt-1 text-xs text-muted-foreground">{{ openTasks.length }} open, {{ blocked.length }} blocked</p>
      </RouterLink>
      <RouterLink to="/costs" class="card block p-4 no-underline hover:bg-accent/40">
        <div class="flex items-center justify-between text-muted-foreground">
          <span class="text-xs font-medium uppercase tracking-wide">Month spend</span>
          <DollarSign class="h-4 w-4" />
        </div>
        <p class="mt-2 text-2xl font-semibold text-foreground">—</p>
        <p class="mt-1 text-xs text-muted-foreground">Pi bills the provider</p>
      </RouterLink>
      <RouterLink to="/inbox" class="card block p-4 no-underline hover:bg-accent/40">
        <div class="flex items-center justify-between text-muted-foreground">
          <span class="text-xs font-medium uppercase tracking-wide">Needs you</span>
          <ShieldCheck class="h-4 w-4" />
        </div>
        <p class="mt-2 text-2xl font-semibold text-foreground">{{ attention.length }}</p>
        <p class="mt-1 text-xs text-muted-foreground">Blocked or in review</p>
      </RouterLink>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
      <section>
        <h2 class="mb-3 font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Tasks by status</h2>
        <div class="card grid gap-2 p-4">
          <div v-for="item in taskStatuses" :key="item.value" class="grid grid-cols-[6.5rem_1fr_2rem] items-center gap-2 text-xs">
            <span class="text-muted-foreground">{{ item.label }}</span>
            <span class="h-1.5 overflow-hidden rounded-full bg-muted">
              <span
                class="block h-full rounded-full"
                :style="{ width: `${(count(item.value) / maxStatus) * 100}%`, background: item.color }"
              />
            </span>
            <span class="text-right tabular-nums">{{ count(item.value) }}</span>
          </div>
        </div>
      </section>
      <section>
        <h2 class="mb-3 font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Needs you</h2>
        <div v-if="attention.length === 0" class="card p-4 text-sm text-muted-foreground">Nothing is waiting on you.</div>
        <div v-else class="card divide-y divide-border overflow-hidden">
          <RouterLink
            v-for="task in attention.slice(0, 8)"
            :key="task.id"
            :to="`/issues/${task.id}`"
            class="flex items-center gap-2 px-3 py-2 text-sm no-underline hover:bg-accent/50"
          >
            <StatusMark :status="task.status" />
            <span class="min-w-0 flex-1 truncate">{{ task.title }}</span>
            <span class="text-xs text-muted-foreground">{{ statusLabel(task.status) }}</span>
          </RouterLink>
        </div>
      </section>
    </div>

    <section>
      <div class="mb-3 flex items-center justify-between">
        <h2 class="font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Recent tasks</h2>
        <button class="btn btn-ghost" type="button" @click="chrome.openTask()">New task</button>
      </div>
      <div v-if="tasks.length === 0" class="card p-4 text-sm text-muted-foreground">No tasks yet.</div>
      <div v-else class="card divide-y divide-border overflow-hidden">
        <RouterLink
          v-for="task in tasks.slice(0, 10)"
          :key="task.id"
          :to="`/issues/${task.id}`"
          class="flex items-center gap-2 px-3 py-2 text-sm no-underline hover:bg-accent/50"
        >
          <StatusMark :status="task.status" />
          <span class="min-w-0 flex-1 truncate">{{ task.title }}</span>
          <span v-if="task.parentId" class="shrink-0 text-[11px] uppercase tracking-wide text-muted-foreground">Subtask</span>
          <AgentCapsule v-if="agentName(task)" :id="agentName(task)!.id" :name="agentName(task)!.name" />
          <span class="hidden w-16 shrink-0 text-right font-mono text-[11px] text-muted-foreground sm:inline">{{ shortId(task.id) }}</span>
          <span class="hidden w-16 shrink-0 text-right text-xs text-muted-foreground md:inline">{{ timeAgo(task.updatedAt) }}</span>
        </RouterLink>
      </div>
    </section>
  </div>
</template>
