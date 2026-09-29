<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AgentCapsule from './AgentCapsule.vue'
import StatusMark from './StatusMark.vue'
import type { Agent } from '../types'

defineProps<{
  agent: Agent
  lookup: (id: string) => Agent[]
}>()
</script>

<template>
  <li class="mt-2">
    <RouterLink
      :to="`/agents/${agent.id}`"
      class="flex items-center gap-3 rounded-lg border border-border px-3 py-2 no-underline hover:bg-accent/50"
    >
      <AgentCapsule :id="agent.id" :name="agent.name" />
      <span v-if="agent.title" class="min-w-0 flex-1 truncate text-xs text-muted-foreground">{{ agent.title }}</span>
      <StatusMark :status="agent.status" />
    </RouterLink>
    <ul v-if="lookup(agent.id).length" class="org-children">
      <OrgNode v-for="child in lookup(agent.id)" :key="child.id" :agent="child" :lookup="lookup" />
    </ul>
  </li>
</template>
