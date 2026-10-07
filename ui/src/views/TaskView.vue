<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { storeToRefs } from 'pinia'
import { api, failureMessage } from '../api'
import AgentCapsule from '../components/AgentCapsule.vue'
import ProjectPreview from '../components/ProjectPreview.vue'
import QuestionCard from '../components/QuestionCard.vue'
import StatusMark from '../components/StatusMark.vue'
import { useWorkspace } from '../composables/workspace'
import { useBoard } from '../stores/board'
import { useChrome } from '../stores/chrome'
import { displayRunStatus, modelFailure, runTranscript, shortId, statusLabel, taskStatuses } from '../lib/format'
import type { AgentQuestion, QuestionAnswer, Task } from '../types'

const route = useRoute()
const board = useBoard()
const chrome = useChrome()
const { company } = storeToRefs(board)
const { goals, agents, tasks, projects } = useWorkspace()
const task = ref<Task | null>(null)
const body = ref('')
const answering = ref(false)
const answeringQuestions = ref(false)
const loading = ref(true)
const error = ref('')
let source: EventSource | null = null

const goalTitle = computed(() => goals.value.find((goal) => goal.id === task.value?.goalId)?.title ?? 'No goal')
const project = computed(() => projects.value.find((item) => item.id === task.value?.projectId))
const assignee = computed(() => agents.value.find((agent) => agent.id === task.value?.assigneeAgentId))

function agentName(id: string | null): string {
  if (!id) return 'Unassigned'
  return agents.value.find((agent) => agent.id === id)?.name ?? 'Unassigned'
}

function authorLabel(authorType: string, authorId: string): string {
  if (authorType === 'user') return 'You'
  return agents.value.find((agent) => agent.id === authorId)?.name ?? 'Agent'
}
const blockerChoices = computed(() => {
  const current = task.value
  if (!current) return []
  const linked = new Set(current.blockerIds ?? [])
  return tasks.value.filter((item) => item.id !== current.id && !linked.has(item.id))
})
const openingQuestions = computed((): AgentQuestion[] => {
  const opening = task.value?.openingQuestion
  if (!opening) return []
  return [{ id: 'opening', prompt: opening.prompt, options: opening.options }]
})

async function load() {
  if (!company.value) {
    loading.value = false
    return
  }
  try {
    task.value = await api<Task>(`/api/v1/companies/${company.value.id}/tasks/${route.params.id}`)
    chrome.crumb = task.value.title
    error.value = ''
  } catch (reason) {
    error.value = failureMessage(reason, 'The task could not be loaded.')
  } finally {
    loading.value = false
  }
}

async function patch(payload: Record<string, unknown>) {
  if (!company.value || !task.value) return
  error.value = ''
  try {
    task.value = await api<Task>(`/api/v1/companies/${company.value.id}/tasks/${task.value.id}`, {
      method: 'PATCH',
      body: JSON.stringify(payload),
    })
    chrome.crumb = task.value.title
    chrome.bump()
  } catch (reason) {
    error.value = failureMessage(reason, 'The task could not be updated.')
  }
}

async function answerOpening(answers: QuestionAnswer[]) {
  const answer = answers[0]
  if (!company.value || !task.value || !answer) return
  error.value = ''
  answering.value = true
  try {
    task.value = await api<Task>(`/api/v1/companies/${company.value.id}/tasks/${task.value.id}/opening-answer`, {
      method: 'POST',
      body: JSON.stringify({ optionId: answer.optionId, text: answer.text }),
    })
    chrome.crumb = task.value.title
    chrome.bump()
  } catch (reason) {
    error.value = failureMessage(reason, 'The choice could not be sent.')
  } finally {
    answering.value = false
  }
}

async function answerQuestions(answers: QuestionAnswer[]) {
  const card = task.value?.agentQuestions
  if (!company.value || !task.value || !card) return
  error.value = ''
  answeringQuestions.value = true
  try {
    task.value = await api<Task>(`/api/v1/companies/${company.value.id}/tasks/${task.value.id}/question-answer`, {
      method: 'POST',
      body: JSON.stringify({ commentId: card.commentId, answers }),
    })
    chrome.crumb = task.value.title
    chrome.bump()
  } catch (reason) {
    error.value = failureMessage(reason, 'The answers could not be sent.')
  } finally {
    answeringQuestions.value = false
  }
}

function commentText(comment: { id: string; body: string }): string | null {
  const card = task.value?.agentQuestions
  if (card?.commentId !== comment.id) return comment.body
  return card.commentBody.trim() === '' ? null : card.commentBody
}

async function addBlocker(id: string, select: HTMLSelectElement) {
  select.value = ''
  if (!id || !task.value) return
  await patch({ blockerIds: [...(task.value.blockerIds ?? []), id] })
}

async function removeBlocker(id: string) {
  if (!task.value) return
  await patch({ blockerIds: (task.value.blockerIds ?? []).filter((item) => item !== id) })
}

async function comment() {
  if (!company.value || !task.value) return
  error.value = ''
  try {
    await api(`/api/v1/companies/${company.value.id}/tasks/${task.value.id}/comments`, {
      method: 'POST',
      body: JSON.stringify({ body: body.value }),
    })
    body.value = ''
    await load()
  } catch (reason) {
    error.value = failureMessage(reason, 'The comment could not be sent.')
  }
}

async function cancel() {
  if (!company.value || !task.value) return
  error.value = ''
  try {
    await api(`/api/v1/companies/${company.value.id}/tasks/${task.value.id}/cancel`, { method: 'POST' })
    await load()
  } catch (reason) {
    error.value = failureMessage(reason, 'The run could not be cancelled.')
  }
}

function connect() {
  source?.close()
  if (!company.value || !task.value) return
  source = new EventSource(`/api/v1/companies/${company.value.id}/tasks/${task.value.id}/stream`)
  source.onmessage = async (event) => {
    const data = JSON.parse(event.data) as { type?: string; payload?: { assistantMessageEvent?: { type?: string } } }
    if (data.type === 'holder.stream_end') return
    if (data.type === 'message_update' && data.payload?.assistantMessageEvent?.type !== 'text_delta') return
    await load()
  }
}

onMounted(async () => {
  await load()
  connect()
})

watch(() => route.params.id, async () => {
  loading.value = true
  await load()
  connect()
})

onUnmounted(() => source?.close())
</script>

<template>
  <p v-if="loading" class="text-sm text-muted-foreground" role="status">Loading the task…</p>
  <p v-else-if="error && !task" class="text-sm text-destructive" role="alert">{{ error }}</p>
  <div v-else-if="task" class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_16rem]">
    <section class="min-w-0 space-y-4">
      <div class="flex items-start gap-2">
        <StatusMark :status="task.status" class="mt-1" />
        <div class="min-w-0">
          <h2 class="text-lg font-semibold">{{ task.title }}</h2>
          <RouterLink
            v-if="task.parent"
            :to="`/issues/${task.parent.id}`"
            class="text-xs text-muted-foreground no-underline hover:underline"
          >
            Subtask of {{ task.parent.title }}
          </RouterLink>
        </div>
      </div>
      <p class="whitespace-pre-wrap text-sm text-muted-foreground">{{ task.description || 'No description.' }}</p>
      <QuestionCard
        v-if="task.openingQuestion"
        label="Opening question"
        :questions="openingQuestions"
        :submit-label="task.openingQuestion.submitLabel"
        placeholder="Describe the task"
        :answering="answering"
        @submit="answerOpening"
      />
      <div class="space-y-3">
        <template v-for="comment in task.comments" :key="comment.id">
          <article
            v-if="commentText(comment) !== null"
            class="max-w-[85%] rounded-2xl border border-border px-3 py-2 text-sm"
            :class="comment.authorType === 'user' ? 'ml-auto bg-secondary' : 'bg-background'"
          >
            <p class="mb-1 text-[11px] uppercase tracking-wide text-muted-foreground">{{ authorLabel(comment.authorType, comment.authorId) }}</p>
            <p class="whitespace-pre-wrap">{{ commentText(comment) }}</p>
          </article>
          <QuestionCard
            v-if="task.agentQuestions?.commentId === comment.id"
            label="Questions"
            :questions="task.agentQuestions.questions"
            :submit-label="task.agentQuestions.submitLabel"
            placeholder="Your answer"
            :answering="answeringQuestions"
            @submit="answerQuestions"
          />
        </template>
        <QuestionCard
          v-if="task.agentQuestions && !task.agentQuestions.commentId"
          label="Questions"
          :intro="task.agentQuestions.intro"
          :questions="task.agentQuestions.questions"
          :submit-label="task.agentQuestions.submitLabel"
          placeholder="Your answer"
          :answering="answeringQuestions"
          @submit="answerQuestions"
        />
        <p v-if="!task.comments?.length && !task.agentQuestions" class="text-sm text-muted-foreground">No comments yet.</p>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="comment">
          <label class="label min-w-0 flex-1">Comment
            <input v-model="body" required placeholder="Steer the agent" class="field" />
          </label>
          <button class="btn btn-primary" type="submit">Send</button>
        </form>
      </div>
      <section class="space-y-2">
        <h3 class="font-mono text-[10px] font-medium uppercase tracking-widest text-muted-foreground">Runs</h3>
        <article v-for="run in task.runs" :key="run.id" class="card p-3">
          <div class="mb-2 flex items-center gap-2 text-xs text-muted-foreground">
            <StatusMark :status="displayRunStatus(run.status, run.events)" />
            <span class="font-mono">{{ run.id.slice(0, 8) }}</span>
          </div>
          <pre v-if="runTranscript(run.events)" class="max-h-80 overflow-auto whitespace-pre-wrap font-mono text-xs">{{ runTranscript(run.events) }}</pre>
          <p v-if="modelFailure(run.events)" class="whitespace-pre-wrap font-mono text-xs text-destructive">{{ modelFailure(run.events) }}</p>
          <p v-else-if="!runTranscript(run.events)" class="font-mono text-xs text-muted-foreground">{{ run.status === 'running' ? 'Waiting for the agent…' : 'No agent output.' }}</p>
        </article>
        <p v-if="!task.runs?.length" class="text-sm text-muted-foreground">No run yet. Assigning the task queues a heartbeat.</p>
      </section>
    </section>
    <ProjectPreview v-if="company" :company-id="company.id" :task-id="task.id" />
    <aside class="card h-fit space-y-3 p-4 lg:self-start">
      <label class="label">Status
        <select class="field" :value="task.status" @change="patch({ status: ($event.target as HTMLSelectElement).value })">
          <option v-for="item in taskStatuses" :key="item.value" :value="item.value">{{ item.label }}</option>
        </select>
      </label>
      <div class="space-y-2">
        <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Blocked by</p>
        <div v-for="blocker in task.blockers ?? []" :key="blocker.id" class="flex items-center gap-2">
          <StatusMark :status="blocker.status" />
          <RouterLink :to="`/issues/${blocker.id}`" class="min-w-0 flex-1 truncate text-sm no-underline hover:underline">{{ blocker.title }}</RouterLink>
          <span class="shrink-0 text-xs text-muted-foreground">{{ statusLabel(blocker.status) }}</span>
          <button class="shrink-0 text-xs text-muted-foreground" type="button" @click="removeBlocker(blocker.id)">Remove</button>
        </div>
        <p v-if="!(task.blockers?.length)" class="text-sm text-muted-foreground">No blocker linked.</p>
        <p v-if="task.status === 'blocked' && task.blockers?.length" class="text-xs text-muted-foreground">This task returns to Todo when every blocker is done.</p>
        <select class="field" aria-label="Add a blocker" @change="addBlocker(($event.target as HTMLSelectElement).value, $event.target as HTMLSelectElement)">
          <option value="">Add a blocker</option>
          <option v-for="candidate in blockerChoices" :key="candidate.id" :value="candidate.id">{{ candidate.title }}</option>
        </select>
      </div>
      <div v-if="task.children?.length" class="space-y-2">
        <p class="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">Subtasks</p>
        <div v-for="child in task.children" :key="child.id" class="flex items-center gap-2">
          <StatusMark :status="child.status" />
          <RouterLink :to="`/issues/${child.id}`" class="min-w-0 flex-1 truncate text-sm no-underline hover:underline">{{ child.title }}</RouterLink>
          <span class="shrink-0 text-xs text-muted-foreground">{{ agentName(child.assigneeAgentId) }}</span>
        </div>
        <p class="text-xs text-muted-foreground">When a subtask is done, that agent reports here and the assignee checks the work.</p>
      </div>
      <label class="label">Assignee
        <select class="field" :value="task.assigneeAgentId ?? ''" @change="patch({ assigneeAgentId: ($event.target as HTMLSelectElement).value })">
          <option value="">Unassigned</option>
          <option v-for="agent in agents" :key="agent.id" :value="agent.id">{{ agent.name }}</option>
        </select>
      </label>
      <div v-if="assignee" class="pt-1">
        <AgentCapsule :id="assignee.id" :name="assignee.name" />
      </div>
      <p class="text-xs text-muted-foreground">Goal · {{ goalTitle }}</p>
      <template v-if="project">
        <p class="text-xs text-muted-foreground">Project · {{ project.name }}</p>
        <template v-if="project.repoUrl">
          <a :href="project.repoUrl" target="_blank" rel="noopener" class="block break-all text-xs underline">{{ project.repoUrl }}</a>
          <p class="font-mono text-xs text-muted-foreground">holder/{{ task.id }}</p>
        </template>
      </template>
      <p class="font-mono text-xs text-muted-foreground">{{ shortId(task.id) }}</p>
      <button v-if="task.checkoutRunId" class="btn btn-danger w-full" type="button" @click="cancel">Cancel run</button>
      <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    </aside>
  </div>
  <p v-else class="text-sm text-muted-foreground">This task is not available.</p>
</template>
