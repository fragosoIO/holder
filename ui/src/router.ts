import { createRouter, createWebHistory } from 'vue-router'
import DashboardView from './views/DashboardView.vue'
import InboxView from './views/InboxView.vue'
import SearchView from './views/SearchView.vue'
import IssuesView from './views/IssuesView.vue'
import TaskView from './views/TaskView.vue'
import GoalsView from './views/GoalsView.vue'
import AgentsView from './views/AgentsView.vue'
import AgentNewView from './views/AgentNewView.vue'
import AgentView from './views/AgentView.vue'
import OrgView from './views/OrgView.vue'
import SettingsView from './views/SettingsView.vue'
import ProjectsView from './views/ProjectsView.vue'
import EmptyView from './views/EmptyView.vue'
import LoginView from './views/LoginView.vue'
import OnboardingView from './views/OnboardingView.vue'

export const router = createRouter({
  history: createWebHistory(),
  routes: [
    { path: '/login', component: LoginView, meta: { title: 'Sign in' } },
    { path: '/onboarding', component: OnboardingView, meta: { title: 'New organization' } },
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', component: DashboardView, meta: { title: 'Dashboard' } },
    { path: '/inbox', component: InboxView, meta: { title: 'Inbox' } },
    { path: '/search', component: SearchView, meta: { title: 'Search' } },
    { path: '/issues', component: IssuesView, meta: { title: 'Tasks' } },
    { path: '/issues/:id', component: TaskView, meta: { title: 'Tasks', section: '/issues' } },
    { path: '/tasks', redirect: '/issues' },
    { path: '/tasks/:id', redirect: (to) => `/issues/${to.params.id}` },
    { path: '/projects', component: ProjectsView, meta: { title: 'Projects' } },
    { path: '/routines', component: EmptyView, props: { message: 'No routines yet.' }, meta: { title: 'Routines' } },
    { path: '/artifacts', component: EmptyView, props: { message: 'No artifacts yet.' }, meta: { title: 'Artifacts' } },
    { path: '/goals', component: GoalsView, meta: { title: 'Goals' } },
    { path: '/agents', component: AgentsView, meta: { title: 'Agents' } },
    { path: '/agents/new', component: AgentNewView, meta: { title: 'Agents', section: '/agents' } },
    { path: '/agents/:id', component: AgentView, meta: { title: 'Agents', section: '/agents' } },
    { path: '/org', component: OrgView, meta: { title: 'Org' } },
    { path: '/skills', component: EmptyView, props: { message: 'No skills yet.' }, meta: { title: 'Skills' } },
    { path: '/apps', component: EmptyView, props: { message: 'No connectors yet.' }, meta: { title: 'Connectors' } },
    { path: '/activity', component: EmptyView, props: { message: 'No audit events yet.' }, meta: { title: 'Audit' } },
    { path: '/costs', component: EmptyView, props: { message: 'No spend recorded. Model bills stay with Pi.' }, meta: { title: 'Costs' } },
    { path: '/company/settings', component: SettingsView, meta: { title: 'Settings' } },
  ],
})

router.afterEach((to) => {
  const title = typeof to.meta.title === 'string' ? to.meta.title : 'Holder'
  document.title = title === 'Holder' ? 'Holder' : `${title} · Holder`
})
