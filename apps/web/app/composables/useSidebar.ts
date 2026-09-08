const STORAGE_KEY = 'hore-sidebar-collapsed'

/**
 * The sidebar's own open/collapsed state (HORE_MY_MASTER_CONTEXT.md
 * §5: "Sidebar minimal dan boleh ditutup") — persisted so a user's
 * choice survives a reload, shared as one `useState` so every
 * consumer (the sidebar itself, its toggle button in the top bar)
 * stays in sync.
 *
 * Two independent states for two different breakpoints (fully
 * responsive, 2026-09-08): `collapsed` is the desktop icon-only mode
 * (persisted); `mobileOpen` is the small-screen drawer's own
 * open/closed state (never persisted — a drawer always starts closed
 * on a fresh mobile load, exactly like every native mobile nav
 * pattern).
 */
export function useSidebar() {
  const collapsed = useState<boolean>('sidebar-collapsed', () => false)
  const mobileOpen = useState<boolean>('sidebar-mobile-open', () => false)

  function init() {
    if (typeof window === 'undefined') return
    collapsed.value = window.localStorage.getItem(STORAGE_KEY) === '1'
  }

  function toggle() {
    collapsed.value = !collapsed.value
    if (typeof window !== 'undefined') {
      window.localStorage.setItem(STORAGE_KEY, collapsed.value ? '1' : '0')
    }
  }

  function openMobile() {
    mobileOpen.value = true
  }

  function closeMobile() {
    mobileOpen.value = false
  }

  return { collapsed, toggle, init, mobileOpen, openMobile, closeMobile }
}
