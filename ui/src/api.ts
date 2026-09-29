export class ApiError extends Error {
  status: number
  code: string

  constructor(message: string, status: number, code: string) {
    super(message)
    this.status = status
    this.code = code
  }
}

type Envelope<T> = {
  status: string
  data: T
  error_message?: string
  error_data?: { code?: string }
}

export async function api<T>(path: string, init: RequestInit = {}): Promise<T> {
  const headers = new Headers(init.headers)
  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }
  const response = await fetch(path, { ...init, headers, credentials: 'include' })
  let payload: Envelope<T>
  try {
    payload = (await response.json()) as Envelope<T>
  } catch {
    throw new ApiError('The request failed.', response.status, 'invalid-response')
  }
  if (!response.ok || payload.status === 'failed') {
    throw new ApiError(
      payload.error_message ?? response.statusText,
      response.status,
      payload.error_data?.code ?? 'error',
    )
  }
  return payload.data
}

export function failureMessage(reason: unknown, fallback: string): string {
  if (reason instanceof ApiError && reason.code !== 'invalid-response' && reason.message) {
    return reason.message
  }
  return fallback
}
