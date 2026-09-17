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
      updateThemeColorMeta(dark)
    }
  }

  // `theme-color` was previously hardcoded dark regardless of the
  // actual theme — iOS Safari uses it to tint its own browser chrome
  // (the status bar strip above the page), so a light-mode session
  // with a dark theme-color could show that chrome in a mismatched
  // tint after any interaction that makes Safari re-evaluate it (e.g.
  // opening the mobile nav drawer), even though the page itself never
  // visibly changed. Keeping this tag in sync with the real light/dark
  // background (main.css's own --color-bg values) removes the
  // mismatch at the source, in both directions.
  function updateThemeColorMeta(dark: boolean) {
    const meta = document.querySelector('meta[name="theme-color"]')
    if (meta) meta.setAttribute('content', dark ? '#18181b' : '#ffffff')
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
