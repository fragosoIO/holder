<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useBoard } from '../stores/board'
import type { Project } from '../types'

const board = useBoard()
const { company } = storeToRefs(board)
const projects = ref<Project[]>([])
const name = ref('')
const repoUrl = ref('')
const loading = ref(true)
const saving = ref(false)
const cloning = ref(false)
const error = ref('')

const needsToken = computed(() => repoUrl.value.trim() !== '' && !company.value?.githubConnected)

async function load() {
  const current = company.value
  if (!current) {
    projects.value = []
    loading.value = false
    return
  }
  error.value = ''
  try {
    const next = await api<Project[]>(`/api/v1/companies/${current.id}/projects`)
    if (company.value?.id !== current.id) return
    projects.value = next
  } catch (reason) {
    error.value = failureMessage(reason, 'Projects could not be loaded.')
  } finally {
    loading.value = false
  }
}

async function create() {
  if (!company.value || needsToken.value || saving.value) return
  const url = repoUrl.value.trim()
  cloning.value = url !== ''
  saving.value = true
  error.value = ''
  try {
    await api(`/api/v1/companies/${company.value.id}/projects`, {
      method: 'POST',
      body: JSON.stringify(url !== '' ? { name: name.value, repoUrl: url } : { name: name.value, workspacePath: '' }),
    })
    name.value = ''
    repoUrl.value = ''
    await load()
  } catch (reason) {
    error.value = failureMessage(reason, 'The project could not be saved.')
  } finally {
    saving.value = false
  }
}

watch(() => company.value?.id, () => {
  void load()
})

onMounted(() => {
  void load()
})
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading projects…</p>
  <div v-else class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <div class="min-w-0">
      <p v-if="projects.length === 0" class="text-sm text-muted-foreground">No projects yet.</p>
      <div v-else class="card divide-y divide-border overflow-hidden">
        <article v-for="project in projects" :key="project.id" class="space-y-1 px-4 py-3">
          <h2 class="text-sm font-medium">{{ project.name }}</h2>
          <p v-if="project.repoUrl" class="flex flex-wrap items-baseline gap-x-2 break-all text-xs text-muted-foreground">
            <a :href="project.repoUrl" target="_blank" rel="noopener" class="underline">{{ project.repoUrl }}</a>
            <span class="font-mono">{{ project.defaultBranch }}</span>
          </p>
          <p v-else class="text-xs text-muted-foreground">Company folder</p>
        </article>
      </div>
    </div>
    <form class="card grid gap-3 p-4" @submit.prevent="create">
      <h2 class="text-sm font-medium">New project</h2>
      <label class="label">Name
        <input v-model="name" required class="field" />
      </label>
      <label class="label">GitHub URL
        <input v-model="repoUrl" class="field" placeholder="https://github.com/owner/repo" />
      </label>
      <p v-if="needsToken" class="text-sm">
        <RouterLink to="/company/settings" class="text-muted-foreground underline">Add a GitHub token in Settings first.</RouterLink>
      </p>
      <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
      <div class="flex justify-end">
        <button class="btn btn-primary" type="submit" :disabled="saving || needsToken">
          {{ saving ? (cloning ? 'Cloning…' : 'Saving…') : 'Save' }}
        </button>
      </div>
    </form>
  </div>
</template>
