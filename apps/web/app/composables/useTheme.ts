export type ThemePreference = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'hore-theme'

/**
 * Light/dark/system theme (HORE_MY_MASTER_CONTEXT.md §5: "Light mode,
 * dark mode"). Persists the user's explicit choice in localStorage;
 * 'system' (the default) instead follows prefers-color-scheme live,
 * so it keeps tracking the OS setting even without a page reload.
 */
export function useTheme() {
  const preference = useState<ThemePreference>('theme-preference', () => 'system')
  const isDark = useState<boolean>('theme-is-dark', () => false)

  function apply(dark: boolean) {
    isDark.value = dark
    if (typeof document !== 'undefined') {
      document.documentElement.classList.toggle('dark', dark)
    }
  }

  function resolveSystemPrefersDark(): boolean {
    return (
      typeof window !== 'undefined' && window.matchMedia('(prefers-color-scheme: dark)').matches
    )
  }

  function setPreference(next: ThemePreference) {
    preference.value = next
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(STORAGE_KEY, next)
    }
    apply(next === 'system' ? resolveSystemPrefersDark() : next === 'dark')
  }

  function init() {
    if (typeof window === 'undefined') return

    const stored = window.localStorage.getItem(STORAGE_KEY) as ThemePreference | null
    preference.value = stored ?? 'system'
    apply(preference.value === 'system' ? resolveSystemPrefersDark() : preference.value === 'dark')

    const media = window.matchMedia('(prefers-color-scheme: dark)')
    media.addEventListener('change', (event) => {
      if (preference.value === 'system') apply(event.matches)
    })
  }

  return { preference, isDark, setPreference, init }
}
