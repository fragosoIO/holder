export type User = { id: string; name: string; email: string }
export type Company = { id: string; name: string; mission: string; role: string; workspacePath: string }
export type PiModel = {
  provider: string
  id: string
  name: string
  thinking: string[]
}
export type PiCatalog = {
  provider: string
  model: string
  thinking: string
  models: PiModel[]
}
export type Agent = {
  id: string
  name: string
  title: string
  jobDescription: string
  managerId: string | null
  status: string
  piBinary: string
  piProvider: string
  piModel: string
  piThinking: string
  workspacePath: string
  piVersion: string | null
}
export type Goal = { id: string; parentId: string | null; title: string; description: string }
export type OpeningOption = {
  id: string
  label: string
  description: string
  freeText: boolean
}
export type OpeningQuestion = {
  prompt: string
  submitLabel: string
  options: OpeningOption[]
}
export type AgentQuestion = {
  id: string
  prompt: string
  options: OpeningOption[]
}
export type AgentQuestions = {
  commentId: string | null
  commentBody: string
  intro: string
  submitLabel: string
  questions: AgentQuestion[]
}
export type QuestionAnswer = {
  id: string
  optionId: string
  text: string
}
export type TaskBlocker = { id: string; title: string; status: string }
export type TaskChild = { id: string; title: string; status: string; assigneeAgentId: string | null }
export type TaskParent = { id: string; title: string; status: string }
export type Task = {
  id: string
  goalId: string | null
  projectId: string | null
  parentId: string | null
  parent?: TaskParent | null
  children?: TaskChild[]
  assigneeAgentId: string | null
  title: string
  description: string
  status: string
  checkoutRunId: string | null
  updatedAt?: string
  openingQuestion?: OpeningQuestion | null
  agentQuestions?: AgentQuestions | null
  labels?: string[]
  blockerIds?: string[]
  blockers?: TaskBlocker[]
  comments?: Comment[]
  runs?: Run[]
}
export type Comment = { id: string; authorType: string; authorId: string; body: string; createdAt: string }
export type Run = {
  id: string
  status: string
  prompt: string
  events: { id: number; type: string; payload: Record<string, unknown> }[]
}
