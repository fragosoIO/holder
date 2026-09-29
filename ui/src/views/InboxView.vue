<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import EmptyState from '../components/EmptyState.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'
import { needsAttention, statusLabel, timeAgo } from '../lib/format'

const { tasks, loading, error } = useWorkspace()
const items = computed(() => tasks.value.filter((task) => needsAttention(task.status)))
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading the inbox…</p>
  <p v-else-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <EmptyState v-else-if="items.length === 0" message="You're all caught up." />
  <div v-else class="card divide-y divide-border overflow-hidden">
    <RouterLink
      v-for="task in items"
      :key="task.id"
      :to="`/issues/${task.id}`"
      class="flex items-center gap-3 px-3 py-2.5 no-underline hover:bg-accent/50"
    >
      <StatusMark :status="task.status" />
      <span class="min-w-0 flex-1 truncate text-sm">{{ task.title }}</span>
      <span v-if="task.parentId" class="shrink-0 text-[11px] uppercase tracking-wide text-muted-foreground">Subtask</span>
      <span class="text-xs text-muted-foreground">{{ statusLabel(task.status) }}</span>
      <span class="hidden text-xs text-muted-foreground sm:inline">{{ timeAgo(task.updatedAt) }}</span>
    </RouterLink>
  </div>
</template>
