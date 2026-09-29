<script setup lang="ts">
import EmptyState from '../components/EmptyState.vue'

defineProps<{ message: string }>()
</script>

<template>
  <EmptyState :message="message" />
</template>
