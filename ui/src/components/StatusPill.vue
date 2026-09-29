<script setup lang="ts">
defineProps<{ status: string }>()

const tone: Record<string, string> = {
  active: 'pill-running',
  running: 'pill-running',
  settled: 'pill-settled',
  done: 'pill-settled',
  paused: 'pill-paused',
  todo: 'pill-todo',
  in_progress: 'pill-todo',
  blocked: 'pill-blocked',
  in_review: 'pill-review',
  failed: 'pill-failed',
  cancelled: 'pill-cancelled',
  terminated: 'pill-terminated',
  queued: 'pill-settled',
  'over-budget': 'pill-budget',
  'awaiting-approval': 'pill-approval',
}
</script>

<template>
  <span class="pill" :class="tone[status] ?? 'pill-settled'">
    {{ status.replaceAll('_', ' ') }}
  </span>
</template>
