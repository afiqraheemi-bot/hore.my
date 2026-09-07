function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  const value = match?.[1]
  return value ? decodeURIComponent(value) : null
}

type HttpMethod = 'GET' | 'HEAD' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'

interface RequestOptions {
  method?: HttpMethod
  body?: Record<string, unknown> | FormData
  query?: Record<string, string>
  headers?: Record<string, string>
}

/**
 * Talks to the Laravel API (ADR-0008: separate origin, Sanctum SPA
 * cookie authentication) — never a bearer token. Every mutating
 * request first hits `/sanctum/csrf-cookie` (Sanctum's own endpoint,
 * cheap and idempotent) so the browser holds a fresh `XSRF-TOKEN`
 * cookie, then echoes that token back as `X-XSRF-TOKEN`, exactly the
 * handshake a real browser performs — this composable does it
 * explicitly since there is no axios-style interceptor doing it for us.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const base = config.public.apiBase as string

  async function request<T>(path: string, options: RequestOptions = {}): Promise<T> {
    const method = options.method ?? 'GET'
    const isMutating = method !== 'GET' && method !== 'HEAD'

    if (isMutating) {
      await $fetch(`${base}/sanctum/csrf-cookie`, { credentials: 'include' })
    }

    const headers: Record<string, string> = { Accept: 'application/json', ...options.headers }
    if (isMutating) {
      const token = readCookie('XSRF-TOKEN')
      if (token) headers['X-XSRF-TOKEN'] = token
    }

    return $fetch<T>(`${base}${path}`, {
      method,
      body: options.body,
      query: options.query,
      credentials: 'include',
      headers,
    })
  }

  return { request }
}
