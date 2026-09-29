<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import AgentCapsule from '../components/AgentCapsule.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'

const { tasks, agents, goals, loading, error } = useWorkspace()
const query = ref('')
const needle = computed(() => query.value.trim().toLowerCase())

function hit(values: string[]) {
  if (!needle.value) return false
  return values.some((value) => value.toLowerCase().includes(needle.value))
}

const taskHits = computed(() => tasks.value.filter((task) => hit([task.title, task.description])))
const agentHits = computed(() => agents.value.filter((agent) => hit([agent.name, agent.title, agent.jobDescription])))
const goalHits = computed(() => goals.value.filter((goal) => hit([goal.title, goal.description])))
const empty = computed(() => needle.value && taskHits.value.length + agentHits.value.length + goalHits.value.length === 0)
</script>

<template>
  <div class="mx-auto grid max-w-2xl gap-6">
    <input v-model="query" class="field mt-0" placeholder="Search tasks, agents, and goals" aria-label="Search" autofocus />
    <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading…</p>
    <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <p v-else-if="!needle" class="text-sm text-muted-foreground">Search tasks, agents, and goals. Ctrl+K or ⌘K opens this page.</p>
    <p v-else-if="empty" class="text-sm text-muted-foreground">No matches.</p>
    <template v-else>
      <section v-if="taskHits.length">
        <h2 class="mb-2 font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Tasks</h2>
        <div class="card divide-y divide-border overflow-hidden">
          <RouterLink
            v-for="task in taskHits"
            :key="task.id"
            :to="`/issues/${task.id}`"
            class="flex items-center gap-2 px-3 py-2 text-sm no-underline hover:bg-accent/50"
          >
            <StatusMark :status="task.status" />
            <span class="min-w-0 flex-1 truncate">{{ task.title }}</span>
            <span v-if="task.parentId" class="shrink-0 text-[11px] uppercase tracking-wide text-muted-foreground">Subtask</span>
          </RouterLink>
        </div>
      </section>
      <section v-if="agentHits.length">
        <h2 class="mb-2 font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Agents</h2>
        <div class="card divide-y divide-border overflow-hidden">
          <RouterLink
            v-for="agent in agentHits"
            :key="agent.id"
            :to="`/agents/${agent.id}`"
            class="flex items-center gap-2 px-3 py-2 no-underline hover:bg-accent/50"
          >
            <AgentCapsule :id="agent.id" :name="agent.name" />
            <span class="truncate text-sm">{{ agent.title }}</span>
          </RouterLink>
        </div>
      </section>
      <section v-if="goalHits.length">
        <h2 class="mb-2 font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Goals</h2>
        <div class="card divide-y divide-border overflow-hidden">
          <RouterLink
            v-for="goal in goalHits"
            :key="goal.id"
            to="/goals"
            class="block px-3 py-2 text-sm no-underline hover:bg-accent/50"
          >
            {{ goal.title }}
          </RouterLink>
        </div>
      </section>
    </template>
  </div>
</template>
