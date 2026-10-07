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
let observer: ResizeObserver | null = null

function fitCanvas(target: HTMLElement, instance: Phaser.Game) {
  const width = target.clientWidth
  const height = target.clientHeight
  if (width < 1 || height < 1) return
  const ratio = window.devicePixelRatio || 1
  const bufferWidth = Math.round(width * ratio)
  const bufferHeight = Math.round(height * ratio)
  const canvas = instance.canvas
  // Style first. Scale.NONE reads the CSS box while resizing and does not
  // refresh displayScale again when only the style changes.
  canvas.style.width = `${width}px`
  canvas.style.height = `${height}px`
  canvas.style.display = 'block'
  if (instance.scale.width !== bufferWidth || instance.scale.height !== bufferHeight) {
    instance.scale.resize(bufferWidth, bufferHeight)
  }
  instance.scale.updateBounds()
  const bounds = instance.scale.canvasBounds
  if (bounds.width > 0 && bounds.height > 0) {
    instance.scale.displayScale.set(bufferWidth / bounds.width, bufferHeight / bounds.height)
  }
}

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
    const width = Math.max(1, host.value.clientWidth)
    const height = Math.max(1, host.value.clientHeight)
    const ratio = window.devicePixelRatio || 1
    const parent = host.value
    game = new Phaser.Game({
      type: Phaser.AUTO,
      parent,
      width: Math.round(width * ratio),
      height: Math.round(height * ratio),
      pixelArt: true,
      backgroundColor: '#2a2622',
      banner: false,
      scale: { mode: Phaser.Scale.NONE, autoRound: true, autoCenter: Phaser.Scale.NO_CENTER },
      render: { antialias: false, pixelArt: true, roundPixels: true },
      scene: [OfficeScene],
    })
    fitCanvas(parent, game)
    ;(game.canvas as HTMLCanvasElement & { __holderGame?: Phaser.Game }).__holderGame = game
    observer = new ResizeObserver(() => {
      if (game) fitCanvas(parent, game)
    })
    observer.observe(parent)
  }
  void poll()
  timer = window.setInterval(() => {
    void poll()
  }, 2000)
})

onUnmounted(() => {
  window.clearInterval(timer)
  abort?.abort()
  observer?.disconnect()
  observer = null
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
  <div class="flex min-h-0 w-full flex-1 flex-col gap-3">
    <p v-if="!seen && !error" class="shrink-0 text-sm text-muted-foreground" role="status">Loading the office…</p>
    <p v-if="error" class="shrink-0 text-sm text-destructive" role="alert">{{ error }}</p>
    <p v-if="stalled" class="shrink-0 text-sm text-muted-foreground" role="status">Live updates paused</p>
    <div
      v-if="seen && agents.length === 0"
      class="flex shrink-0 flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-500/30 bg-amber-500/10 px-4 py-3"
    >
      <RouterLink to="/agents/new" class="text-sm font-medium text-foreground underline underline-offset-2">You have no agents</RouterLink>
    </div>
    <div class="relative min-h-0 flex-1">
      <div ref="host" class="absolute inset-0 overflow-hidden rounded-xl border border-border" />
      <div
        v-if="hovered"
        class="card pointer-events-none absolute top-3 left-3 z-10 max-h-[40%] max-w-sm overflow-auto px-3 py-2 text-sm text-card-foreground shadow-lg"
      >
        <p class="font-medium">{{ hovered.name }}</p>
        <p class="text-muted-foreground">{{ hovered.task?.title ?? 'No task' }}</p>
        <p class="whitespace-pre-wrap break-words">{{ hovered.step }}</p>
      </div>
    </div>
    <ul v-if="agents.length > 0" class="card max-h-36 shrink-0 divide-y divide-border overflow-auto">
      <li v-for="agent in agents" :key="agent.id">
        <RouterLink
          :to="linkFor(agent)"
          class="flex flex-col gap-0.5 px-3 py-2 text-sm no-underline hover:bg-accent/50"
        >
          <span class="font-medium">{{ agent.name }}</span>
          <span class="whitespace-pre-wrap break-words text-muted-foreground">{{ agent.step }}</span>
        </RouterLink>
      </li>
    </ul>
  </div>
</template>
