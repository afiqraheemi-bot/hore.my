export interface AuthUser {
  id: string
  name: string
  email: string
}

export interface AuthTenant {
  id: string
}

interface MeResponse {
  user: AuthUser
  tenant: AuthTenant | null
}

/**
 * Session state for the current browser tab — backed by the Laravel
 * API's session cookie (ADR-0008), not a token this app stores itself.
 * `useState` (not a module-level ref) so it stays correctly scoped per
 * Nuxt app instance.
 */
export function useAuth() {
  const user = useState<AuthUser | null>('auth-user', () => null)
  const tenant = useState<AuthTenant | null>('auth-tenant', () => null)
  const { request } = useApi()

  async function fetchMe(): Promise<void> {
    try {
      const data = await request<MeResponse>('/api/v1/me')
      user.value = data.user
      tenant.value = data.tenant
    } catch {
      user.value = null
      tenant.value = null
    }
  }

  async function login(email: string, password: string): Promise<void> {
    const data = await request<MeResponse>('/api/v1/login', {
      method: 'POST',
      body: { email, password },
    })
    user.value = data.user
    tenant.value = data.tenant
  }

  async function register(
    name: string,
    email: string,
    password: string,
    passwordConfirmation: string,
    termsAccepted: boolean,
  ): Promise<void> {
    const data = await request<MeResponse>('/api/v1/register', {
      method: 'POST',
      body: {
        name,
        email,
        password,
        password_confirmation: passwordConfirmation,
        terms_accepted: termsAccepted,
      },
    })
    user.value = data.user
    tenant.value = data.tenant
  }

  async function logout(): Promise<void> {
    await request('/api/v1/logout', { method: 'POST' })
    user.value = null
    tenant.value = null
  }

  return { user, tenant, fetchMe, login, register, logout }
}
