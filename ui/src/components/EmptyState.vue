<script setup lang="ts">
defineProps<{ message: string }>()
</script>

<template>
  <div class="flex min-h-[50vh] flex-col items-center justify-center gap-3 px-4 text-center">
    <p class="max-w-sm text-sm text-muted-foreground">{{ message }}</p>
    <slot />
  </div>
</template>
