/**
 * The install affordance browsers hide or never surface consistently
 * (a Chromium address-bar icon most users never notice; no automatic
 * prompt at all on iOS Safari or Firefox) — this composable exposes
 * Chromium's own deferred `beforeinstallprompt` event (captured once,
 * app-wide, by `app/plugins/pwa.client.ts`) so a real, visible
 * "Install app" button (AppSidebar) can trigger it on demand. On a
 * browser that never fires the event (Firefox, Safari, or an
 * already-installed app), `canInstall` simply stays `false` and no
 * button renders — never a fabricated affordance that does nothing.
 */
export interface BeforeInstallPromptEvent extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}

export function useInstallPrompt() {
  const deferredEvent = useState<BeforeInstallPromptEvent | null>('pwa-install-event', () => null)
  const installed = useState<boolean>('pwa-installed', () => false)

  const canInstall = computed(() => !installed.value && deferredEvent.value !== null)

  async function promptInstall(): Promise<void> {
    const event = deferredEvent.value
    if (!event) return

    await event.prompt()
    const { outcome } = await event.userChoice
    if (outcome === 'accepted') installed.value = true
    deferredEvent.value = null
  }

  return { canInstall, deferredEvent, installed, promptInstall }
}
