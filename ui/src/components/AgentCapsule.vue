<script setup lang="ts">
import { agentGradient } from '../lib/format'

defineProps<{ name: string; id?: string }>()
</script>

<template>
  <span class="inline-flex min-w-0 max-w-40 items-center gap-1.5">
    <span
      class="h-4 w-3.5 shrink-0 rounded-full"
      :style="{ background: agentGradient(id || name) }"
      aria-hidden="true"
    />
    <span class="truncate text-xs text-muted-foreground">{{ name }}</span>
  </span>
</template>
