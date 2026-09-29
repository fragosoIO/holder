<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AgentCapsule from '../components/AgentCapsule.vue'
import EmptyState from '../components/EmptyState.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'
import { statusLabel } from '../lib/format'

const { agents, loading, error } = useWorkspace()
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading agents…</p>
  <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <div v-else class="space-y-3">
    <div class="flex items-center justify-between">
      <p class="text-xs text-muted-foreground">{{ agents.length }} agents</p>
      <RouterLink to="/agents/new" class="btn btn-primary">New agent</RouterLink>
    </div>
    <EmptyState v-if="agents.length === 0" message="No agents yet.">
      <RouterLink to="/agents/new" class="btn btn-primary">New agent</RouterLink>
    </EmptyState>
    <div v-else class="card divide-y divide-border overflow-hidden">
      <RouterLink
        v-for="agent in agents"
        :key="agent.id"
        :to="`/agents/${agent.id}`"
        class="flex items-center gap-3 px-3 py-2.5 no-underline hover:bg-accent/50"
      >
        <AgentCapsule :id="agent.id" :name="agent.name" />
        <span class="min-w-0 flex-1">
          <span class="block truncate text-sm">{{ agent.title || 'Agent' }}</span>
          <span class="block truncate text-xs text-muted-foreground">{{ agent.piProvider || 'pi' }} / {{ agent.piModel || 'default' }}</span>
        </span>
        <span class="hidden text-xs text-muted-foreground sm:inline">{{ statusLabel(agent.status) }}</span>
        <StatusMark :status="agent.status" />
      </RouterLink>
    </div>
  </div>
</template>
