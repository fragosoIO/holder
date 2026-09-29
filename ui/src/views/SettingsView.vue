<script setup lang="ts">
import { ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useBoard } from '../stores/board'

const board = useBoard()
const { company } = storeToRefs(board)
const mission = ref('')
const name = ref('')
const message = ref('')
const error = ref('')

watch(company, (value) => {
  mission.value = value?.mission ?? ''
  name.value = value?.name ?? ''
}, { immediate: true })

async function save() {
  if (!company.value) return
  message.value = ''
  error.value = ''
  try {
    await api(`/api/v1/companies/${company.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ name: name.value, mission: mission.value }),
    })
    await board.load()
    message.value = 'Saved.'
  } catch (reason) {
    error.value = failureMessage(reason, 'Save failed.')
  }
}
</script>

<template>
  <form v-if="company" class="mx-auto grid max-w-xl gap-3" @submit.prevent="save">
    <label class="label">Name
      <input v-model="name" class="field" />
    </label>
    <label class="label">Mission
      <textarea v-model="mission" rows="5" class="field" />
    </label>
    <p class="break-all text-xs text-muted-foreground">Workspace {{ company.workspacePath }}</p>
    <p v-if="message" class="text-sm text-muted-foreground" role="status">{{ message }}</p>
    <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit">Save</button>
    </div>
  </form>
</template>
