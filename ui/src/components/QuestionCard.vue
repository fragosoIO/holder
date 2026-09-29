<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { AgentQuestion, QuestionAnswer } from '../types'

const props = defineProps<{
  label: string
  intro?: string
  questions: AgentQuestion[]
  submitLabel: string
  placeholder: string
  answering?: boolean
}>()

const emit = defineEmits<{
  submit: [answers: QuestionAnswer[]]
}>()

const selected = ref<Record<string, string>>({})
const text = ref<Record<string, string>>({})

watch(
  () => props.questions.map((question) => question.id).join(','),
  () => {
    selected.value = {}
    text.value = {}
  },
)

const canContinue = computed(() => {
  if (props.answering) return false
  return props.questions.every((question) => {
    if (question.options.length === 0) return (text.value[question.id] ?? '').trim() !== ''
    const option = question.options.find((item) => item.id === selected.value[question.id])
    if (!option) return false
    if (option.freeText) return (text.value[question.id] ?? '').trim() !== ''
    return true
  })
})

function submit() {
  if (!canContinue.value) return
  emit(
    'submit',
    props.questions.map((question) => {
      const option = question.options.find((item) => item.id === selected.value[question.id])
      const free = question.options.length === 0 || option?.freeText === true
      return {
        id: question.id,
        optionId: question.options.length === 0 ? '' : (selected.value[question.id] ?? ''),
        text: free ? (text.value[question.id] ?? '').trim() : '',
      }
    }),
  )
}
</script>

<template>
  <section class="card space-y-3 p-4" :aria-label="label">
    <p v-if="intro" class="text-sm text-muted-foreground">{{ intro }}</p>
    <div v-for="question in questions" :key="question.id" class="space-y-2">
      <p :id="`question-${question.id}`" class="font-medium">{{ question.prompt }}</p>
      <div
        v-if="question.options.length"
        class="space-y-2"
        role="radiogroup"
        :aria-labelledby="`question-${question.id}`"
      >
        <div v-for="option in question.options" :key="option.id" class="space-y-2">
          <button
            type="button"
            role="radio"
            :aria-checked="selected[question.id] === option.id"
            class="w-full rounded-md border px-3 py-2 text-left"
            :class="selected[question.id] === option.id ? 'border-foreground bg-accent' : 'border-border hover:bg-accent/50'"
            @click="selected[question.id] = option.id"
          >
            <span class="block">{{ option.label }}</span>
            <span v-if="option.description" class="block text-sm text-muted-foreground">{{ option.description }}</span>
          </button>
          <textarea
            v-if="option.freeText && selected[question.id] === option.id"
            v-model="text[question.id]"
            rows="3"
            class="field"
            :placeholder="placeholder"
            :aria-label="`Describe your answer for ${question.prompt}`"
          />
        </div>
      </div>
      <textarea
        v-else
        v-model="text[question.id]"
        rows="3"
        class="field"
        :placeholder="placeholder"
        :aria-label="question.prompt"
      />
    </div>
    <button class="btn btn-primary" type="button" :disabled="!canContinue" @click="submit">
      {{ answering ? 'Sending…' : submitLabel }}
    </button>
  </section>
</template>
