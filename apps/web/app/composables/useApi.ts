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
  /**
   * Defaults to ofetch's own content-type-based auto-parsing (JSON or
   * text) — pass `'blob'` for a binary download (e.g. the Compliance
   * Pack's ZIP), so the response bytes are never run through text
   * decoding and corrupted.
   */
  responseType?: 'json' | 'text' | 'blob'
}

/**
 * Talks to the Laravel API (ADR-0008: separate origin, Sanctum SPA
 * cookie authentication) — never a bearer token. Every mutating
 * request first hits `/sanctum/csrf-cookie` (Sanctum's own endpoint,
 * cheap and idempotent) so the browser holds a fresh `XSRF-TOKEN`
 * cookie, then echoes that token back as `X-XSRF-TOKEN`, exactly the
 * handshake a real browser performs — this composable does it
 * explicitly since there is no axios-style interceptor doing it for us.
 *
 * **The API origin's hostname is derived from the page's own
 * `window.location.hostname` at request time, never baked in at build
 * time.** Sanctum's CSRF cookie is `SameSite=Lax` — a browser only
 * sends it back on a request whose target shares the same *site*
 * (registrable domain) as the page, regardless of port. A fixed build-
 * time API base (e.g. always `localhost:8000`) silently breaks the
 * moment the page itself is reached through a different hostname —
 * confirmed as a real, reproduced bug: opening the app via a LAN IP
 * (for phone testing) while the API base stayed hardcoded to
 * `localhost` made every login fail with a 419 CSRF mismatch, because
 * `<lan-ip>:3000` and `localhost:8000` are different sites. Deriving
 * the API hostname from `window.location.hostname` — keeping only the
 * port configurable — means whichever hostname reached the frontend
 * (`localhost`, `127.0.0.1`, or a LAN IP) is exactly the same hostname
 * used to reach the API, so they are always same-site, with no
 * machine-specific override file to keep in sync.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const port = config.public.apiPort as string
  const base = `${window.location.protocol}//${window.location.hostname}:${port}`

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
      ...(options.responseType ? { responseType: options.responseType } : {}),
    })
  }

  return { request }
}
