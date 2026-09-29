<script setup lang="ts">
import { computed } from 'vue'
import { statusColor, statusLabel } from '../lib/format'

const props = defineProps<{ status: string }>()
const color = computed(() => statusColor(props.status))
const done = computed(() => props.status === 'done' || props.status === 'settled')
const cancelled = computed(() => props.status === 'cancelled' || props.status === 'terminated')
const live = computed(() => props.status === 'in_progress' || props.status === 'running')
</script>

<template>
  <svg viewBox="0 0 16 16" class="h-4 w-4 shrink-0" aria-hidden="true">
    <title>{{ statusLabel(status) }}</title>
    <circle v-if="done" cx="8" cy="8" r="6" :fill="color" />
    <circle v-else cx="8" cy="8" r="5.25" fill="none" :stroke="color" stroke-width="1.75" />
    <path v-if="done" d="M5 8.2 7 10.2 11.2 6" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
    <path v-else-if="cancelled" d="M5.5 5.5 10.5 10.5 M10.5 5.5 5.5 10.5" fill="none" :stroke="color" stroke-width="1.5" stroke-linecap="round" />
    <circle v-else-if="live" cx="8" cy="8" r="2.2" :fill="color" />
  </svg>
</template>
