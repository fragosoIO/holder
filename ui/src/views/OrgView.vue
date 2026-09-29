<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import EmptyState from '../components/EmptyState.vue'
import OrgNode from '../components/OrgNode.vue'
import { useWorkspace } from '../composables/workspace'
import type { Agent } from '../types'

const { agents, loading, error } = useWorkspace()
const roots = computed(() => agents.value.filter((agent) => !agent.managerId))

function children(id: string): Agent[] {
  return agents.value.filter((agent) => agent.managerId === id)
}
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading the org…</p>
  <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <EmptyState v-else-if="agents.length === 0" message="No agents yet.">
    <RouterLink to="/agents/new" class="btn btn-primary">New agent</RouterLink>
  </EmptyState>
  <div v-else class="space-y-3">
    <div class="flex justify-end">
      <RouterLink to="/agents/new" class="btn btn-primary">New agent</RouterLink>
    </div>
    <ul class="org-branch">
      <OrgNode v-for="agent in roots" :key="agent.id" :agent="agent" :lookup="children" />
    </ul>
  </div>
</template>
