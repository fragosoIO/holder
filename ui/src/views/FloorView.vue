<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import Phaser from 'phaser'
import { api, failureMessage } from '../api'
import { OfficeScene, setFloorHandlers } from '../floor/OfficeScene'
import { useBoard } from '../stores/board'
import type { FloorAgent, FloorSnapshot } from '../types'

const router = useRouter()
const { company } = storeToRefs(useBoard())
const host = ref<HTMLDivElement | null>(null)
const agents = ref<FloorAgent[]>([])
const hovered = ref<FloorAgent | null>(null)
const seen = ref(false)
const error = ref('')
const stalled = ref(false)

let game: Phaser.Game | null = null
let timer = 0
let abort: AbortController | null = null
let generation = 0

function linkFor(agent: FloorAgent): string {
  return agent.task ? `/issues/${agent.task.id}` : `/agents/${agent.id}`
}

function publish(snapshot: FloorSnapshot) {
  game?.registry.set('snapshot', snapshot)
  const scene = game?.scene.getScene('office')
  if (scene instanceof OfficeScene) scene.apply(snapshot)
}

async function poll() {
  const current = company.value
  if (!current) return
  const mine = ++generation
  abort?.abort()
  const controller = new AbortController()
  abort = controller
  try {
    const data = await api<FloorSnapshot>(`/api/v1/companies/${current.id}/floor`, { signal: controller.signal })
    if (mine !== generation) return
    seen.value = true
    error.value = ''
    stalled.value = false
    agents.value = data.agents
    publish(data)
  } catch (reason) {
    if (controller.signal.aborted || mine !== generation) return
    if (!seen.value) error.value = failureMessage(reason, 'The office could not be loaded.')
    else stalled.value = true
  }
}

onMounted(() => {
  setFloorHandlers({
    open: (agent) => {
      void router.push(linkFor(agent))
    },
    hover: (agent) => {
      hovered.value = agent
    },
  })
  if (host.value) {
    game = new Phaser.Game({
      type: Phaser.AUTO,
      parent: host.value,
      width: host.value.clientWidth || 640,
      height: 448,
      pixelArt: true,
      backgroundColor: '#1c1915',
      banner: false,
      scale: { mode: Phaser.Scale.RESIZE },
      scene: [OfficeScene],
    })
  }
  void poll()
  timer = window.setInterval(() => {
    void poll()
  }, 2000)
})

onUnmounted(() => {
  window.clearInterval(timer)
  abort?.abort()
  game?.destroy(true)
  game = null
})

watch(() => company.value?.id, () => {
  seen.value = false
  error.value = ''
  stalled.value = false
  agents.value = []
  hovered.value = null
  publish({ agents: [] })
  void poll()
})
</script>

<template>
  <div class="space-y-4">
    <p v-if="!seen && !error" class="text-sm text-muted-foreground" role="status">Loading the office…</p>
    <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <p v-if="stalled" class="text-sm text-muted-foreground" role="status">Live updates paused</p>
    <div
      v-if="seen && agents.length === 0"
      class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
    >
      <p class="text-sm">You have no agents.</p>
      <RouterLink to="/agents/new" class="text-sm font-medium underline underline-offset-2">Create one here</RouterLink>
    </div>
    <div class="relative">
      <div ref="host" class="h-[28rem] w-full overflow-hidden rounded-xl border border-border" />
      <p v-if="hovered" class="absolute left-2 top-2 z-10 max-w-sm rounded-lg border border-border bg-card px-3 py-2 text-sm shadow">
        <span class="block font-medium">{{ hovered.name }}</span>
        <span class="block text-muted-foreground">{{ hovered.task?.title ?? 'No task' }}</span>
        <span class="block">{{ hovered.step }}</span>
      </p>
    </div>
    <ul v-if="agents.length > 0" class="divide-y divide-border overflow-hidden rounded-xl border border-border">
      <li v-for="agent in agents" :key="agent.id">
        <RouterLink
          :to="linkFor(agent)"
          class="flex items-center justify-between gap-3 px-3 py-2 text-sm no-underline hover:bg-accent/50"
        >
          <span>{{ agent.name }}</span>
          <span class="min-w-0 truncate text-muted-foreground">{{ agent.step }}</span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
