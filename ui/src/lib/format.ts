const capsules = [
  ['#f7cfdc', '#1f7a3a'],
  ['#c9a9e8', '#ee79a1'],
  ['#28164b', '#7a1530'],
  ['#f3e6c4', '#e3a21a'],
  ['#1f4dd6', '#3aa35c'],
  ['#e94b27', '#5a1122'],
  ['#7eb6e3', '#ee79a1'],
  ['#9ce8a7', '#bd7ff0'],
  ['#f3b49e', '#1f4ed4'],
  ['#f2d95f', '#4fbcba'],
]

export const taskStatuses = [
  { value: 'todo', label: 'Todo', color: '#a8aeb2' },
  { value: 'in_progress', label: 'In progress', color: '#2563eb' },
  { value: 'blocked', label: 'Blocked', color: '#dc2626' },
  { value: 'in_review', label: 'In review', color: '#7c3aed' },
  { value: 'done', label: 'Done', color: '#16a34a' },
  { value: 'cancelled', label: 'Cancelled', color: '#a8aeb2' },
] as const

export function agentGradient(id: string): string {
  let hash = 0
  for (const char of id) hash = (hash * 33 + char.charCodeAt(0)) >>> 0
  const pair = capsules[hash % capsules.length]
  return `linear-gradient(180deg, ${pair[0]}, ${pair[1]})`
}

export function shortId(id: string): string {
  return id.slice(0, 8)
}

export function timeAgo(value: string | undefined): string {
  if (!value) return ''
  const then = Date.parse(value.includes('T') ? value : value.replace(' ', 'T'))
  if (Number.isNaN(then)) return ''
  const seconds = Math.max(0, (Date.now() - then) / 1000)
  if (seconds < 45) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  if (seconds < 86400 * 14) return `${Math.floor(seconds / 86400)}d ago`
  return new Date(then).toLocaleDateString()
}

export function statusColor(status: string): string {
  const known = taskStatuses.find((item) => item.value === status)
  if (known) return known.color
  if (status === 'paused') return '#f59e0b'
  if (status === 'failed' || status === 'error') return '#dc2626'
  if (status === 'active' || status === 'running' || status === 'settled') return '#16a34a'
  return '#a8aeb2'
}

export function statusLabel(status: string): string {
  return taskStatuses.find((item) => item.value === status)?.label ?? status.replaceAll('_', ' ')
}

export function needsAttention(status: string): boolean {
  return status === 'blocked' || status === 'in_review'
}

type RunEvent = { type: string; payload: Record<string, unknown> }

function summarizeModelError(message: string): string {
  const description = message.match(/"error_description"\s*:\s*"([^"]+)"/)
  if (description) {
    const provider = message.match(/\bfor ([A-Za-z0-9._-]+)\b/)
    return provider ? `${provider[1]}: ${description[1]}` : description[1]
  }
  const line = message.split(/\r?\n/)[0]?.split('; stack=')[0] ?? message
  return line.length > 280 ? `${line.slice(0, 279)}…` : line
}

function messageError(message: unknown): string {
  if (!message || typeof message !== 'object') return ''
  const record = message as { role?: string; stopReason?: string; errorMessage?: string }
  if (record.role && record.role !== 'assistant') return ''
  const error = record.errorMessage
  if (record.stopReason === 'error' || (typeof error === 'string' && error !== '')) {
    return summarizeModelError(typeof error === 'string' && error !== '' ? error : 'The model returned an error.')
  }
  return ''
}

export function modelFailure(events: RunEvent[]): string {
  const seen = new Set<string>()
  const lines: string[] = []
  for (const event of events) {
    const candidates: unknown[] = []
    if (event.type === 'holder.error' && typeof event.payload.message === 'string') candidates.push(event.payload.message)
    if (event.payload.message && typeof event.payload.message === 'object') candidates.push(event.payload.message)
    if (Array.isArray(event.payload.messages)) candidates.push(...event.payload.messages)
    for (const candidate of candidates) {
      const text = typeof candidate === 'string' ? summarizeModelError(candidate) : messageError(candidate)
      if (!text || seen.has(text)) continue
      seen.add(text)
      lines.push(text)
    }
  }
  return lines.join('\n')
}

function contentText(content: unknown): string {
  if (typeof content === 'string') return content.trim()
  if (!Array.isArray(content)) return ''
  const parts: string[] = []
  for (const part of content) {
    if (!part || typeof part !== 'object') continue
    const item = part as { type?: string; text?: string; name?: string }
    if (item.text) parts.push(item.text)
    else if (item.type === 'toolCall' && item.name) parts.push(`\n[${item.name}]\n`)
  }
  return parts.join('').trim()
}

function toolLine(payload: Record<string, unknown>): string {
  const name = typeof payload.toolName === 'string' ? payload.toolName : 'tool'
  const args = payload.args
  if (!args || typeof args !== 'object') return name
  const record = args as { path?: unknown; command?: unknown }
  if (typeof record.path === 'string' && record.path !== '') return `${name} ${record.path}`
  if (typeof record.command === 'string' && record.command !== '') {
    const command = record.command.replace(/\s+/g, ' ')
    return `${name} ${command.length > 80 ? `${command.slice(0, 79)}…` : command}`
  }
  return name
}

export function runTranscript(events: RunEvent[]): string {
  let streamed = ''
  let settled = ''
  const tools: string[] = []
  for (const event of events) {
    const update = event.payload.assistantMessageEvent as { type?: string; delta?: string } | undefined
    if (update?.type === 'text_delta' && update.delta) streamed += update.delta
    if (event.type === 'tool_execution_start') tools.push(toolLine(event.payload))
    if (streamed !== '' || (event.type !== 'message_end' && event.type !== 'turn_end')) continue
    const message = event.payload.message as { role?: string; content?: unknown } | undefined
    if (message?.role !== 'assistant') continue
    const text = contentText(message.content)
    if (text) settled = text
  }
  const parts = [(streamed || settled).trim(), tools.join('\n')].filter((part) => part !== '')
  return parts.join('\n\n')
}

export function displayRunStatus(status: string, events: RunEvent[]): string {
  if (status === 'running' || status === 'cancelled') return status
  if (modelFailure(events)) return 'failed'
  return status
}
