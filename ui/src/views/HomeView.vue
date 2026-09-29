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
  <section v-if="company" class="stack measure">
    <p class="lede">What is happening, whether it needs you, and what to do next lives in the org, goals, and tasks.</p>
    <dl class="meta">
      <dt>Workspace</dt>
      <dd>{{ company.workspacePath }}</dd>
    </dl>
    <form class="surface stack" @submit.prevent="save">
      <label class="label">Name
        <input v-model="name" class="field" />
      </label>
      <label class="label">Mission
        <textarea v-model="mission" rows="4" class="field" />
      </label>
      <div class="actions">
        <button class="btn btn-primary" type="submit">Save mission</button>
      </div>
      <p v-if="message" class="status status-ok" role="status">{{ message }}</p>
      <p v-if="error" class="status status-error" role="alert">{{ error }}</p>
    </form>
  </section>
</template>
