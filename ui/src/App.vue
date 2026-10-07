<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { storeToRefs } from 'pinia'
import {
  Boxes,
  ChevronDown,
  ChevronRight,
  CircleCheck,
  DollarSign,
  FolderOpen,
  History,
  Inbox,
  LayoutDashboard,
  Map,
  Menu,
  Moon,
  Network,
  Package,
  Plus,
  Repeat,
  Search,
  Settings,
  SquarePen,
  Sun,
  Target,
  Unplug,
  Users,
  type LucideIcon,
} from '@lucide/vue'
import { failureMessage } from './api'
import NewTaskDialog from './components/NewTaskDialog.vue'
import StatusMark from './components/StatusMark.vue'
import { useWorkspace } from './composables/workspace'
import LoginView from './views/LoginView.vue'
import { useBoard } from './stores/board'
import { useChrome } from './stores/chrome'

type NavItem = { to: string; label: string; icon: LucideIcon }

const board = useBoard()
const chrome = useChrome()
const router = useRouter()
const route = useRoute()
const { ready, unauthenticated, company, companies, user, mode } = storeToRefs(board)
const { tasks } = useWorkspace()
const loadError = ref('')
const companyMenu = ref(false)
const workOpen = ref(true)
const orgOpen = ref(true)
const theme = ref(document.documentElement.classList.contains('dark') ? 'dark' : 'light')

const primary: NavItem[] = [
  { to: '/search', label: 'Search', icon: Search },
  { to: '/dashboard', label: 'Dashboard', icon: LayoutDashboard },
  { to: '/floor', label: 'Floor', icon: Map },
  { to: '/inbox', label: 'Inbox', icon: Inbox },
]
const mobile = computed(() =>
  ['/dashboard', '/inbox', '/issues', '/agents'].flatMap((to) => {
    const item = [...primary, ...work, ...organization].find((entry) => entry.to === to)
    return item ? [item] : []
  }),
)
const work: NavItem[] = [
  { to: '/issues', label: 'Tasks', icon: CircleCheck },
  { to: '/projects', label: 'Projects', icon: FolderOpen },
  { to: '/routines', label: 'Routines', icon: Repeat },
  { to: '/artifacts', label: 'Artifacts', icon: Package },
  { to: '/goals', label: 'Goals', icon: Target },
]
const organization: NavItem[] = [
  { to: '/agents', label: 'Agents', icon: Users },
  { to: '/skills', label: 'Skills', icon: Boxes },
  { to: '/apps', label: 'Connectors', icon: Unplug },
  { to: '/activity', label: 'Audit', icon: History },
]

const inboxCount = computed(() => tasks.value.filter((task) => task.status === 'blocked' || task.status === 'in_review').length)
const liveCount = computed(() => tasks.value.filter((task) => task.checkoutRunId).length)
const recent = computed(() => tasks.value.slice(0, 6))
const sectionLabel = computed(() => typeof route.meta.title === 'string' ? route.meta.title : '')
const sectionTo = computed(() => typeof route.meta.section === 'string' ? route.meta.section : '')

function current(to: string) {
  if (to === '/dashboard' || to === '/search' || to === '/inbox') return route.path === to
  return route.path === to || route.path.startsWith(`${to}/`)
}

function toggleTheme() {
  theme.value = theme.value === 'dark' ? 'light' : 'dark'
  document.documentElement.classList.toggle('dark', theme.value === 'dark')
  localStorage.setItem('holder.theme', theme.value)
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', theme.value === 'dark' ? '#18181b' : '#ffffff')
}

function onKey(event: KeyboardEvent) {
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault()
    void router.push('/search')
  }
}

onMounted(async () => {
  window.addEventListener('keydown', onKey)
  try {
    await board.load()
  } catch (reason) {
    loadError.value = failureMessage(reason, 'The company could not be loaded.')
  }
  if (board.unauthenticated && route.path !== '/login') await router.push('/login')
})

onUnmounted(() => window.removeEventListener('keydown', onKey))

watch(() => route.fullPath, () => {
  chrome.sidebar = false
  chrome.crumb = ''
  companyMenu.value = false
})

function choose(id: string) {
  board.select(id)
  companyMenu.value = false
}

async function signOut() {
  await board.logout()
  await router.push('/login')
}
</script>

<template>
  <div v-if="unauthenticated || route.path === '/login'" class="grid min-h-dvh place-items-center bg-background p-4 text-foreground">
    <LoginView />
  </div>
  <div v-else-if="!ready" class="grid min-h-dvh place-items-center bg-background text-sm text-muted-foreground">
    Loading…
  </div>
  <div v-else class="flex h-dvh overflow-hidden bg-background text-foreground">
    <button
      v-if="chrome.sidebar"
      class="fixed inset-0 z-40 bg-black/50 md:hidden"
      type="button"
      aria-label="Close sidebar"
      @click="chrome.sidebar = false"
    />
    <aside
      class="z-50 w-60 shrink-0 flex-col border-r border-sidebar-border bg-sidebar"
      :class="chrome.sidebar ? 'fixed inset-y-0 left-0 flex md:static' : 'hidden md:flex'"
    >
      <div class="relative z-30 flex h-[60px] shrink-0 items-center px-2">
        <button
          type="button"
          class="flex min-w-0 flex-1 items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm font-medium hover:bg-sidebar-accent"
          aria-haspopup="menu"
          :aria-expanded="companyMenu"
          @click="companyMenu = !companyMenu"
        >
          <span class="truncate">{{ company?.name ?? 'Holder' }}</span>
          <ChevronDown class="h-4 w-4 shrink-0 text-muted-foreground" />
        </button>
        <div v-if="companyMenu" class="card absolute top-14 left-2 z-20 w-56 p-1 shadow-lg" role="menu">
          <button
            v-for="item in companies"
            :key="item.id"
            type="button"
            class="block w-full rounded-md px-2 py-1.5 text-left text-sm hover:bg-accent"
            :class="item.id === company?.id ? 'font-medium' : ''"
            role="menuitem"
            @click="choose(item.id)"
          >
            {{ item.name }}
          </button>
          <div class="my-1 border-t border-border" />
          <RouterLink to="/onboarding" class="block rounded-md px-2 py-1.5 text-sm no-underline hover:bg-accent" role="menuitem">
            <Plus class="mr-2 inline h-4 w-4" />New organization
          </RouterLink>
          <RouterLink to="/org" class="block rounded-md px-2 py-1.5 text-sm no-underline hover:bg-accent" role="menuitem">
            <Network class="mr-2 inline h-4 w-4" />Org
          </RouterLink>
          <RouterLink to="/costs" class="block rounded-md px-2 py-1.5 text-sm no-underline hover:bg-accent" role="menuitem">
            <DollarSign class="mr-2 inline h-4 w-4" />Costs
          </RouterLink>
          <RouterLink to="/company/settings" class="block rounded-md px-2 py-1.5 text-sm no-underline hover:bg-accent" role="menuitem">
            <Settings class="mr-2 inline h-4 w-4" />Settings
          </RouterLink>
        </div>
      </div>

      <nav class="flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-0 py-2" aria-label="Sections">
        <button class="nav-row" type="button" @click="chrome.openTask()">
          <SquarePen class="h-4 w-4 shrink-0" />
          New task
        </button>
        <RouterLink
          v-for="item in primary"
          :key="item.to"
          :to="item.to"
          class="nav-row"
          :aria-current="current(item.to) ? 'page' : undefined"
        >
          <component :is="item.icon" class="h-4 w-4 shrink-0" />
          <span class="truncate">{{ item.label }}</span>
          <span v-if="item.to === '/dashboard' && liveCount" class="ml-auto h-2 w-2 rounded-full bg-blue-500" :title="`${liveCount} live`" />
          <span v-if="item.to === '/inbox' && inboxCount" class="ml-auto rounded-full bg-red-600 px-1.5 text-[10px] text-white">{{ inboxCount }}</span>
        </RouterLink>

        <button class="section-label" type="button" @click="workOpen = !workOpen">
          <ChevronRight class="h-3 w-3 transition-transform" :class="workOpen ? 'rotate-90' : ''" />
          Work
        </button>
        <template v-if="workOpen">
          <RouterLink
            v-for="item in work"
            :key="item.to"
            :to="item.to"
            class="nav-row"
            :aria-current="current(item.to) ? 'page' : undefined"
          >
            <component :is="item.icon" class="h-4 w-4 shrink-0" />
            {{ item.label }}
          </RouterLink>
        </template>

        <button class="section-label" type="button" @click="orgOpen = !orgOpen">
          <ChevronRight class="h-3 w-3 transition-transform" :class="orgOpen ? 'rotate-90' : ''" />
          Org
        </button>
        <template v-if="orgOpen">
          <RouterLink
            v-for="item in organization"
            :key="item.to"
            :to="item.to"
            class="nav-row"
            :aria-current="current(item.to) ? 'page' : undefined"
          >
            <component :is="item.icon" class="h-4 w-4 shrink-0" />
            {{ item.label }}
          </RouterLink>
        </template>

        <p class="section-label">Recent</p>
        <RouterLink
          v-for="task in recent"
          :key="task.id"
          :to="`/issues/${task.id}`"
          class="nav-row"
          :aria-current="route.params.id === task.id ? 'page' : undefined"
        >
          <StatusMark :status="task.status" />
          <span class="truncate">{{ task.title }}</span>
        </RouterLink>
      </nav>

      <div class="flex shrink-0 items-center gap-2 border-t border-sidebar-border px-3 py-2">
        <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-muted text-xs font-medium">
          {{ (user?.name || 'H').slice(0, 1).toUpperCase() }}
        </span>
        <span class="min-w-0 flex-1 truncate text-sm">{{ user?.name || 'Board' }}</span>
        <button class="btn btn-ghost px-2" type="button" :aria-label="theme === 'dark' ? 'Use light theme' : 'Use dark theme'" @click="toggleTheme">
          <Sun v-if="theme === 'dark'" class="h-4 w-4" />
          <Moon v-else class="h-4 w-4" />
        </button>
        <button v-if="mode === 'authenticated'" class="btn btn-ghost px-2 text-xs" type="button" @click="signOut">Sign out</button>
      </div>
    </aside>

    <div class="flex min-w-0 flex-1 flex-col">
      <header class="flex h-[60px] shrink-0 items-center gap-2 border-b border-border px-4">
        <button class="btn btn-ghost px-2 md:hidden" type="button" aria-label="Open sidebar" @click="chrome.sidebar = true">
          <Menu class="h-4 w-4" />
        </button>
        <nav class="flex min-w-0 items-center gap-1 text-sm" aria-label="Breadcrumb">
          <RouterLink
            v-if="chrome.crumb && sectionTo"
            :to="sectionTo"
            class="truncate text-muted-foreground no-underline hover:text-foreground"
          >
            {{ sectionLabel }}
          </RouterLink>
          <span v-else class="truncate font-medium">{{ sectionLabel }}</span>
          <template v-if="chrome.crumb">
            <ChevronRight class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span class="truncate font-medium">{{ chrome.crumb }}</span>
          </template>
        </nav>
      </header>
      <main
        id="main-content"
        class="min-h-0 flex-1 p-4 pb-20 md:p-6 md:pb-6"
        :class="route.path === '/floor' ? 'flex flex-col overflow-hidden' : 'overflow-auto'"
      >
        <p v-if="loadError" class="mb-4 text-sm text-destructive" role="alert">{{ loadError }}</p>
        <p v-else-if="!company" class="text-sm text-muted-foreground">No company yet.</p>
        <RouterView v-else />
      </main>
      <nav class="fixed inset-x-0 bottom-0 z-30 flex border-t border-border bg-background md:hidden" aria-label="Mobile">
        <RouterLink v-for="item in mobile" :key="item.to" :to="item.to" class="flex flex-1 flex-col items-center gap-1 py-2 text-[10px] no-underline" :class="current(item.to) ? 'text-foreground' : 'text-muted-foreground'">
          <component :is="item.icon" class="h-4 w-4" />
          {{ item.label }}
        </RouterLink>
      </nav>
    </div>
    <NewTaskDialog />
  </div>
</template>
