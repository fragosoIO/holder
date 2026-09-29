<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { failureMessage } from '../api'
import { useBoard } from '../stores/board'

const email = ref('')
const password = ref('')
const error = ref('')
const board = useBoard()
const router = useRouter()

async function submit() {
  error.value = ''
  try {
    await board.login(email.value, password.value)
    await router.push('/dashboard')
  } catch (reason) {
    error.value = failureMessage(reason, 'Sign-in failed.')
  }
}
</script>

<template>
  <form class="card grid w-full max-w-sm gap-3 p-6" @submit.prevent="submit">
    <p class="text-lg font-semibold tracking-tight">Holder</p>
    <h1 class="text-sm text-muted-foreground">Sign in</h1>
    <label class="label">Email
      <input v-model="email" type="email" required autocomplete="username" class="field" />
    </label>
    <label class="label">Password
      <input v-model="password" type="password" required autocomplete="current-password" class="field" />
    </label>
    <p v-if="error" class="text-sm text-destructive" role="alert">{{ error }}</p>
    <button class="btn btn-primary" type="submit">Sign in</button>
  </form>
</template>
