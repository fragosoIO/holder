<script setup lang="ts">
import { onUnmounted, ref, watch } from 'vue'
import { api, failureMessage } from '../api'

const props = defineProps<{
  companyId: string
  taskId: string
}>()

type PreviewState = 'ready' | 'no_page' | 'no_checkout'

type Preview = {
  state: PreviewState
  revision: string
  url: string | null
  expiresAt: number
}

const preview = ref<Preview | null>(null)
const error = ref('')
const paused = ref(false)
const loading = ref(true)
const src = ref('')
let shownRevision = ''
let shownExpiresAt = 0
let refreshCount = 0
let generation = 0
let timer = 0
let abort: AbortController | null = null

function emptyCopy(state: PreviewState): string {
  if (state === 'no_checkout') return 'This branch is not checked out.'
  return 'No page yet. The preview looks for index.html in the project folder, site/, public/, or dist/.'
}

function apply(data: Preview, force: boolean) {
  preview.value = data
  error.value = ''
  paused.value = false
  loading.value = false
  if (data.state !== 'ready' || !data.url) {
    src.value = ''
    shownRevision = ''
    shownExpiresAt = 0
    return
  }
  const now = Math.floor(Date.now() / 1000)
  const nearExpiry = shownExpiresAt > 0 && shownExpiresAt - now < 3600
  if (!force && src.value !== '' && data.revision === shownRevision && !nearExpiry) return
  let next = `${data.url}?r=${encodeURIComponent(data.revision)}`
  if (force) {
    refreshCount += 1
    next += `&n=${refreshCount}`
  }
  src.value = next
  shownRevision = data.revision
  shownExpiresAt = data.expiresAt
}

async function poll(force = false) {
  const mine = ++generation
  abort?.abort()
  const controller = new AbortController()
  abort = controller
  try {
    const data = await api<Preview>(
      `/api/v1/companies/${props.companyId}/tasks/${props.taskId}/preview`,
      { signal: controller.signal },
    )
    if (mine !== generation) return
    apply(data, force)
  } catch (reason) {
    if (controller.signal.aborted || mine !== generation) return
    loading.value = false
    if (src.value !== '') {
      paused.value = true
      return
    }
    error.value = failureMessage(reason, 'The preview could not be loaded.')
    preview.value = null
  }
}

function refresh() {
  void poll(true)
}

function start() {
  window.clearInterval(timer)
  abort?.abort()
  generation += 1
  preview.value = null
  error.value = ''
  paused.value = false
  loading.value = true
  src.value = ''
  shownRevision = ''
  shownExpiresAt = 0
  refreshCount = 0
  void poll()
  timer = window.setInterval(() => {
    void poll()
  }, 2000)
}

watch(() => [props.companyId, props.taskId] as const, start, { immediate: true })

onUnmounted(() => {
  generation += 1
  window.clearInterval(timer)
  abort?.abort()
})
</script>

<template>
  <section
    class="card flex min-h-0 flex-col overflow-hidden lg:sticky lg:top-0 lg:h-[calc(100dvh-6.75rem)] max-lg:h-[70vh]"
    aria-label="Preview"
  >
    <header class="flex shrink-0 items-center gap-2 border-b border-border px-3 py-2">
      <h3 class="text-sm font-medium">Preview</h3>
      <p v-if="paused" class="text-xs text-muted-foreground" role="status">Preview paused</p>
      <button class="btn btn-ghost ml-auto px-2 py-1 text-xs" type="button" @click="refresh">Refresh</button>
    </header>
    <div class="relative min-h-0 flex-1">
      <p v-if="loading" class="p-4 text-sm text-muted-foreground" role="status">Loading preview…</p>
      <p v-else-if="error" class="p-4 text-sm text-destructive" role="alert">{{ error }}</p>
      <iframe
        v-else-if="src"
        :src="src"
        title="Project preview"
        class="absolute inset-0 h-full w-full border-0 bg-white"
        sandbox="allow-scripts allow-forms allow-popups allow-popups-to-escape-sandbox"
      />
      <p v-else-if="preview" class="p-4 text-sm text-muted-foreground" role="status">{{ emptyCopy(preview.state) }}</p>
    </div>
  </section>
</template>
