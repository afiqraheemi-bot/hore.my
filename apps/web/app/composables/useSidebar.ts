const STORAGE_KEY = 'hore-sidebar-collapsed'

/**
 * The sidebar's own open/collapsed state (HORE_MY_MASTER_CONTEXT.md
 * §5: "Sidebar minimal dan boleh ditutup") — persisted so a user's
 * choice survives a reload, shared as one `useState` so every
 * consumer (the sidebar itself, its toggle button in the top bar)
 * stays in sync.
 */
export function useSidebar() {
  const collapsed = useState<boolean>('sidebar-collapsed', () => false)

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

  return { collapsed, toggle, init }
}
