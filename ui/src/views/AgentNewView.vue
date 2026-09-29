<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useWorkspace } from '../composables/workspace'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'
import type { PiCatalog, PiModel } from '../types'

const thinkingOrder = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max']
const board = useBoard()
const chrome = useChrome()
const router = useRouter()
const { company } = storeToRefs(board)
const { agents } = useWorkspace()
const error = ref('')
const modelsLoading = ref(false)
const modelsError = ref('')
const catalog = ref<PiCatalog>({ provider: '', model: '', thinking: '', models: [] })
const selectedKey = ref('')
const form = ref({
  name: '',
  title: '',
  jobDescription: '',
  managerId: '',
  piBinary: 'pi',
  piProvider: '',
  piModel: '',
  piThinking: '',
})
let modelRequest = 0
let binaryTimer = 0

const selectedModel = computed(() => catalog.value.models.find((model) => modelKey(model) === selectedKey.value))
const thinkingOptions = computed(() => selectedModel.value?.thinking ?? [])
const managers = computed(() => agents.value.filter((agent) => agent.status !== 'terminated'))

function modelKey(model: PiModel) {
  return `${encodeURIComponent(model.provider)}/${encodeURIComponent(model.id)}`
}

function clampThinking(level: string, available: string[]) {
  if (available.length === 0) return ''
  if (available.includes(level)) return level
  const requested = thinkingOrder.indexOf(level)
  if (requested === -1) return available[0]
  for (let index = requested; index < thinkingOrder.length; index += 1) {
    if (available.includes(thinkingOrder[index])) return thinkingOrder[index]
  }
  for (let index = requested - 1; index >= 0; index -= 1) {
    if (available.includes(thinkingOrder[index])) return thinkingOrder[index]
  }
  return available[0]
}

function applyModel(model: PiModel, level: string) {
  form.value.piProvider = model.provider
  form.value.piModel = model.id
  form.value.piThinking = clampThinking(level, model.thinking)
  selectedKey.value = modelKey(model)
}

async function loadModels() {
  const request = ++modelRequest
  modelsError.value = ''
  modelsLoading.value = true
  try {
    if (!company.value) return
    const binary = form.value.piBinary.trim() || 'pi'
    const result = await api<PiCatalog>(
      `/api/v1/companies/${company.value.id}/pi/models?binary=${encodeURIComponent(binary)}`,
    )
    if (request !== modelRequest) return
    catalog.value = result
    const match = result.models.find((model) => model.provider === result.provider && model.id === result.model)
    if (match) applyModel(match, result.thinking)
  } catch (reason) {
    if (request !== modelRequest) return
    catalog.value = { provider: '', model: '', thinking: '', models: [] }
    selectedKey.value = ''
    form.value.piProvider = ''
    form.value.piModel = ''
    form.value.piThinking = ''
    modelsError.value = failureMessage(reason, 'Pi models could not be loaded.')
  } finally {
    if (request === modelRequest) modelsLoading.value = false
  }
}

async function hire() {
  if (!company.value) return
  error.value = ''
  try {
    await api(`/api/v1/companies/${company.value.id}/agents`, {
      method: 'POST',
      body: JSON.stringify(form.value),
    })
    chrome.bump()
    await router.push('/agents')
  } catch (reason) {
    error.value = failureMessage(reason, 'Hire failed.')
  }
}

watch(selectedKey, (key) => {
  const model = catalog.value.models.find((item) => modelKey(item) === key)
  if (!model) return
  if (form.value.piProvider === model.provider && form.value.piModel === model.id && model.thinking.includes(form.value.piThinking)) {
    return
  }
  form.value.piProvider = model.provider
  form.value.piModel = model.id
  form.value.piThinking = clampThinking(form.value.piThinking || catalog.value.thinking, model.thinking)
})

watch(
  () => form.value.piBinary,
  () => {
    window.clearTimeout(binaryTimer)
    binaryTimer = window.setTimeout(() => {
      void loadModels()
    }, 400)
  },
)

onMounted(() => {
  chrome.crumb = 'New agent'
  void loadModels()
})

onUnmounted(() => window.clearTimeout(binaryTimer))
</script>

<template>
  <form class="mx-auto grid max-w-xl gap-3" @submit.prevent="hire">
    <p v-if="company" class="text-sm text-muted-foreground">Workspace {{ company.workspacePath }}</p>
    <label class="label">Name
      <input v-model="form.name" required class="field" />
    </label>
    <label class="label">Title
      <input v-model="form.title" class="field" />
    </label>
    <label class="label">Job
      <textarea v-model="form.jobDescription" rows="4" class="field" />
    </label>
    <label class="label">Reports to
      <select v-model="form.managerId" class="field">
        <option value="">Nobody</option>
        <option v-for="agent in managers" :key="agent.id" :value="agent.id">{{ agent.name }}</option>
      </select>
    </label>
    <label class="label">Pi binary
      <input v-model="form.piBinary" class="field" />
    </label>
    <label class="label">Model
      <select v-model="selectedKey" class="field" required :disabled="modelsLoading || catalog.models.length === 0">
        <option v-if="catalog.models.length === 0" value="" disabled>{{ modelsLoading ? 'Loading models…' : 'No models' }}</option>
        <option v-for="model in catalog.models" :key="modelKey(model)" :value="modelKey(model)">
          {{ model.name }} · {{ model.provider }}/{{ model.id }}
        </option>
      </select>
    </label>
    <label class="label">Thinking
      <select v-model="form.piThinking" class="field" required :disabled="thinkingOptions.length === 0">
        <option v-for="level in thinkingOptions" :key="level" :value="level">{{ level }}</option>
      </select>
    </label>
    <p v-if="modelsLoading" class="text-sm text-muted-foreground" role="status">Loading models from Pi…</p>
    <p v-else-if="modelsError" class="text-sm text-destructive" role="alert">{{ modelsError }}</p>
    <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit" :disabled="modelsLoading || catalog.models.length === 0">Hire</button>
    </div>
  </form>
</template>
