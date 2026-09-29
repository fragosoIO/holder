<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import AgentCapsule from '../components/AgentCapsule.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'
import { statusLabel } from '../lib/format'
import type { Agent, PiCatalog, PiModel } from '../types'

const thinkingOrder = ['off', 'minimal', 'low', 'medium', 'high', 'xhigh', 'max']
const route = useRoute()
const router = useRouter()
const board = useBoard()
const chrome = useChrome()
const { company } = storeToRefs(board)
const { agents } = useWorkspace()
const agent = ref<Agent | null>(null)
const error = ref('')
const message = ref('')
const loading = ref(true)
const saving = ref(false)
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
let filling = false

const editable = computed(() => agent.value !== null && agent.value.status !== 'terminated')
const modelChoices = computed(() => {
  const choices = catalog.value.models.slice()
  const provider = form.value.piProvider
  const id = form.value.piModel
  if (provider && id && !choices.some((model) => model.provider === provider && model.id === id)) {
    choices.unshift({
      provider,
      id,
      name: id,
      thinking: form.value.piThinking ? [form.value.piThinking] : [],
    })
  }
  return choices
})
const selectedModel = computed(() => modelChoices.value.find((model) => modelKey(model) === selectedKey.value))
const thinkingOptions = computed(() => selectedModel.value?.thinking ?? [])
const managers = computed(() => {
  const currentId = agent.value?.id
  const open = agents.value.filter((item) => item.id !== currentId && item.status !== 'terminated')
  const managerId = form.value.managerId
  if (managerId && !open.some((item) => item.id === managerId)) {
    const current = agents.value.find((item) => item.id === managerId)
    if (current) open.unshift(current)
  }
  return open
})
const dirty = computed(() => {
  const current = agent.value
  if (!current) return false
  return form.value.name !== current.name
    || form.value.title !== current.title
    || form.value.jobDescription !== current.jobDescription
    || form.value.managerId !== (current.managerId ?? '')
    || (form.value.piBinary.trim() || 'pi') !== (current.piBinary || 'pi')
    || form.value.piProvider !== current.piProvider
    || form.value.piModel !== current.piModel
    || form.value.piThinking !== current.piThinking
})
const canSave = computed(() => editable.value
  && dirty.value
  && !saving.value
  && form.value.name.trim() !== ''
  && form.value.piModel !== ''
  && form.value.piThinking !== '')

function modelKey(model: Pick<PiModel, 'provider' | 'id'>) {
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

function fill(current: Agent) {
  filling = true
  form.value = {
    name: current.name,
    title: current.title,
    jobDescription: current.jobDescription,
    managerId: current.managerId ?? '',
    piBinary: current.piBinary || 'pi',
    piProvider: current.piProvider,
    piModel: current.piModel,
    piThinking: current.piThinking,
  }
  if (current.piProvider && current.piModel) {
    selectedKey.value = modelKey({ provider: current.piProvider, id: current.piModel })
  }
}

async function load() {
  if (!company.value) return
  loading.value = true
  try {
    const current = await api<Agent>(`/api/v1/companies/${company.value.id}/agents/${route.params.id}`)
    agent.value = current
    fill(current)
    chrome.crumb = current.name
    error.value = ''
    void loadModels()
  } catch (reason) {
    error.value = failureMessage(reason, 'The agent could not be loaded.')
  } finally {
    loading.value = false
  }
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
    const match = result.models.find((model) => model.provider === form.value.piProvider && model.id === form.value.piModel)
    if (match) {
      form.value.piThinking = clampThinking(form.value.piThinking, match.thinking)
      selectedKey.value = modelKey(match)
    }
  } catch (reason) {
    if (request !== modelRequest) return
    catalog.value = { provider: '', model: '', thinking: '', models: [] }
    modelsError.value = failureMessage(reason, 'Pi models could not be loaded.')
  } finally {
    if (request === modelRequest) modelsLoading.value = false
  }
}

async function save() {
  if (!company.value || !agent.value || !canSave.value) return
  error.value = ''
  message.value = ''
  saving.value = true
  try {
    const saved = await api<Agent>(`/api/v1/companies/${company.value.id}/agents/${agent.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify({
        name: form.value.name.trim(),
        title: form.value.title.trim(),
        jobDescription: form.value.jobDescription.trim(),
        managerId: form.value.managerId,
        piBinary: form.value.piBinary.trim() || 'pi',
        piProvider: form.value.piProvider,
        piModel: form.value.piModel,
        piThinking: form.value.piThinking,
      }),
    })
    agent.value = saved
    fill(saved)
    chrome.crumb = saved.name
    chrome.bump()
    message.value = 'Saved.'
  } catch (reason) {
    error.value = failureMessage(reason, 'The agent could not be saved.')
  } finally {
    saving.value = false
  }
}

async function act(action: 'pause' | 'resume' | 'terminate') {
  if (!company.value || !agent.value) return
  error.value = ''
  try {
    agent.value = await api<Agent>(`/api/v1/companies/${company.value.id}/agents/${agent.value.id}/${action}`, { method: 'POST' })
    chrome.bump()
    if (action === 'terminate') await router.push('/agents')
  } catch (reason) {
    error.value = failureMessage(reason, 'Action failed.')
  }
}

watch(selectedKey, (key) => {
  const model = modelChoices.value.find((item) => modelKey(item) === key)
  if (!model) return
  if (form.value.piProvider === model.provider && form.value.piModel === model.id && model.thinking.includes(form.value.piThinking)) {
    return
  }
  form.value.piProvider = model.provider
  form.value.piModel = model.id
  form.value.piThinking = clampThinking(form.value.piThinking, model.thinking)
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

watch(form, () => {
  if (filling) {
    filling = false
    return
  }
  message.value = ''
}, { deep: true })

onMounted(load)
watch(() => [route.params.id, company.value?.id], () => {
  void load()
})
onUnmounted(() => {
  window.clearTimeout(binaryTimer)
  modelRequest += 1
})
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading the agent…</p>
  <p v-else-if="error && !agent" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <article v-else-if="agent" class="mx-auto grid max-w-2xl gap-4">
    <div class="flex flex-wrap items-center gap-3">
      <AgentCapsule :id="agent.id" :name="agent.name" />
      <h2 class="text-lg font-semibold">{{ agent.name }}</h2>
      <StatusMark :status="agent.status" />
      <span class="text-xs text-muted-foreground">{{ statusLabel(agent.status) }}</span>
    </div>

    <form v-if="editable" class="grid gap-3" @submit.prevent="save">
      <label class="label">Name
        <input v-model="form.name" required class="field" autocomplete="off" />
      </label>
      <label class="label">Title
        <input v-model="form.title" class="field" />
      </label>
      <label class="label">Job
        <textarea v-model="form.jobDescription" rows="6" class="field" />
      </label>
      <label class="label">Reports to
        <select v-model="form.managerId" class="field">
          <option value="">Nobody</option>
          <option v-for="manager in managers" :key="manager.id" :value="manager.id">
            {{ manager.name }}{{ manager.status === 'terminated' ? ' (terminated)' : '' }}
          </option>
        </select>
      </label>
      <label class="label">Pi binary
        <input v-model="form.piBinary" class="field" />
      </label>
      <label class="label">Model
        <select v-model="selectedKey" class="field" required :disabled="modelChoices.length === 0">
          <option v-if="modelChoices.length === 0" value="" disabled>{{ modelsLoading ? 'Loading models…' : 'No models' }}</option>
          <option v-for="model in modelChoices" :key="modelKey(model)" :value="modelKey(model)">
            {{ model.name }} · {{ model.provider }}/{{ model.id }}
          </option>
        </select>
      </label>
      <label class="label">Thinking
        <select v-model="form.piThinking" class="field" required :disabled="thinkingOptions.length === 0">
          <option v-for="level in thinkingOptions" :key="level" :value="level">{{ level }}</option>
        </select>
      </label>
      <p class="break-all text-xs text-muted-foreground">
        Workspace {{ agent.workspacePath }}
        <span v-if="agent.piVersion"> · Pi {{ agent.piVersion }}</span>
      </p>
      <p v-if="modelsLoading" class="text-sm text-muted-foreground" role="status">Loading models from Pi…</p>
      <p v-else-if="modelsError" class="text-sm text-destructive" role="alert">{{ modelsError }}</p>
      <p v-if="message" class="text-sm text-muted-foreground" role="status">{{ message }}</p>
      <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
      <div class="flex flex-wrap items-center justify-between gap-2">
        <div class="flex flex-wrap gap-2">
          <button v-if="agent.status === 'active'" class="btn btn-secondary" type="button" @click="act('pause')">Pause</button>
          <button v-if="agent.status === 'paused'" class="btn btn-secondary" type="button" @click="act('resume')">Resume</button>
          <button class="btn btn-danger" type="button" @click="act('terminate')">Terminate</button>
        </div>
        <button class="btn btn-primary" type="submit" :disabled="!canSave">{{ saving ? 'Saving…' : 'Save' }}</button>
      </div>
    </form>

    <template v-else>
      <p v-if="agent.title" class="text-sm text-muted-foreground">{{ agent.title }}</p>
      <p class="whitespace-pre-wrap text-sm">{{ agent.jobDescription }}</p>
      <dl class="card grid grid-cols-[8rem_1fr] gap-2 p-4 font-mono text-xs">
        <dt class="text-muted-foreground">Pi</dt>
        <dd>{{ agent.piBinary }} {{ agent.piVersion }}</dd>
        <dt class="text-muted-foreground">Model</dt>
        <dd>{{ agent.piProvider || 'default' }} / {{ agent.piModel || 'default' }}</dd>
        <dt class="text-muted-foreground">Thinking</dt>
        <dd>{{ agent.piThinking || 'default' }}</dd>
        <dt class="text-muted-foreground">Workspace</dt>
        <dd class="break-all">{{ agent.workspacePath }}</dd>
      </dl>
      <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    </template>
  </article>
  <p v-else class="text-sm text-muted-foreground">This agent is not available.</p>
</template>
