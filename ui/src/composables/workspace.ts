import { onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'
import type { Agent, Goal, Task } from '../types'

export function useWorkspace() {
  const board = useBoard()
  const chrome = useChrome()
  const { company, ready } = storeToRefs(board)
  const tasks = ref<Task[]>([])
  const goals = ref<Goal[]>([])
  const agents = ref<Agent[]>([])
  const loading = ref(true)
  const error = ref('')

  async function load() {
    const current = company.value
    if (!current) {
      tasks.value = []
      goals.value = []
      agents.value = []
      loading.value = false
      return
    }
    error.value = ''
    try {
      const base = `/api/v1/companies/${current.id}`
      const [nextTasks, nextGoals, nextAgents] = await Promise.all([
        api<Task[]>(`${base}/tasks`),
        api<Goal[]>(`${base}/goals`),
        api<Agent[]>(`${base}/agents`),
      ])
      if (company.value?.id !== current.id) return
      tasks.value = nextTasks
      goals.value = nextGoals
      agents.value = nextAgents
    } catch (reason) {
      error.value = failureMessage(reason, 'The company could not be loaded.')
    } finally {
      loading.value = false
    }
  }

  watch(() => [company.value?.id, chrome.tick, ready.value] as const, () => {
    if (ready.value) void load()
  })

  onMounted(() => {
    if (ready.value) void load()
  })

  return { tasks, goals, agents, loading, error, load }
}
