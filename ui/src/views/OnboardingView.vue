<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'
import type { PiCatalog, PiModel } from '../types'

const thinkingOrder = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max']
const board = useBoard()
const chrome = useChrome()
const router = useRouter()
const { company } = storeToRefs(board)
const organizationName = ref('')
const agentName = ref('')
const error = ref('')
const submitting = ref(false)
const modelsLoading = ref(false)
const modelsError = ref('')
const catalog = ref<PiCatalog>({ provider: '', model: '', thinking: '', models: [] })
const selectedKey = ref('')
const piProvider = ref('')
const piModel = ref('')
const piThinking = ref('')
let modelRequest = 0

const selectedModel = computed(() => catalog.value.models.find((model) => modelKey(model) === selectedKey.value))
const thinkingOptions = computed(() => selectedModel.value?.thinking ?? [])
const ready = computed(() => organizationName.value.trim() !== '' && agentName.value.trim() !== '' && piModel.value !== '' && piThinking.value !== '')

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
  piProvider.value = model.provider
  piModel.value = model.id
  piThinking.value = clampThinking(level, model.thinking)
  selectedKey.value = modelKey(model)
}

async function loadModels() {
  const request = ++modelRequest
  modelsError.value = ''
  modelsLoading.value = true
  try {
    if (!company.value) return
    const result = await api<PiCatalog>(`/api/v1/companies/${company.value.id}/pi/models?binary=pi`)
    if (request !== modelRequest) return
    catalog.value = result
    const match = result.models.find((model) => model.provider === result.provider && model.id === result.model)
    if (match) applyModel(match, result.thinking)
  } catch (reason) {
    if (request !== modelRequest) return
    catalog.value = { provider: '', model: '', thinking: '', models: [] }
    selectedKey.value = ''
    piProvider.value = ''
    piModel.value = ''
    piThinking.value = ''
    modelsError.value = failureMessage(reason, 'Pi models could not be loaded.')
  } finally {
    if (request === modelRequest) modelsLoading.value = false
  }
}

async function createOrganization() {
  error.value = ''
  submitting.value = true
  try {
    const result = await api<{
      company: { id: string }
      task: { id: string }
    }>('/api/v1/onboarding', {
      method: 'POST',
      body: JSON.stringify({
        name: organizationName.value.trim(),
        agentName: agentName.value.trim(),
        piBinary: 'pi',
        piProvider: piProvider.value,
        piModel: piModel.value,
        piThinking: piThinking.value,
      }),
    })
    await board.load()
    board.select(result.company.id)
    chrome.bump()
    organizationName.value = ''
    agentName.value = ''
    await router.push(`/issues/${result.task.id}`)
  } catch (reason) {
    error.value = failureMessage(reason, 'The organization could not be created.')
  } finally {
    submitting.value = false
  }
}

watch(selectedKey, (key) => {
  const model = catalog.value.models.find((item) => modelKey(item) === key)
  if (!model) return
  piProvider.value = model.provider
  piModel.value = model.id
  piThinking.value = clampThinking(piThinking.value || catalog.value.thinking, model.thinking)
})

watch(() => company.value?.id, () => {
  void loadModels()
})

onMounted(() => {
  chrome.crumb = 'New organization'
  void loadModels()
})

onUnmounted(() => {
  modelRequest += 1
})
</script>

<template>
  <form class="mx-auto grid max-w-xl gap-3" @submit.prevent="createOrganization">
    <h2 class="text-lg font-semibold">New organization</h2>
    <p class="text-sm text-muted-foreground">
      Name the organization and its first agent. That chief of staff is hired on Pi and can hire the rest of the team.
    </p>
    <label class="label">Organization
      <input v-model="organizationName" required class="field" autocomplete="organization" />
    </label>
    <label class="label">First agent
      <input v-model="agentName" required class="field" autocomplete="off" />
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
      <select v-model="piThinking" class="field" required :disabled="thinkingOptions.length === 0">
        <option v-for="level in thinkingOptions" :key="level" :value="level">{{ level }}</option>
      </select>
    </label>
    <p v-if="modelsLoading" class="text-sm text-muted-foreground" role="status">Loading models from Pi…</p>
    <p v-else-if="modelsError" class="text-sm text-destructive" role="alert">{{ modelsError }}</p>
    <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit" :disabled="submitting || modelsLoading || !ready">Create organization</button>
    </div>
  </form>
</template>
