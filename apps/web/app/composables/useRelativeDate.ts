/**
 * A calm, human relative date ("Today", "Yesterday", "3 days ago")
 * that falls back to a plain date once "N days ago" stops being a
 * quick read — shared by every activity/history list (Manual Entry's
 * Recent activity, the Work Queue) so they read consistently.
 */
export function formatRelativeDate(dateString: string): string {
  const date = new Date(`${dateString}T00:00:00`)
  const today = new Date()
  today.setHours(0, 0, 0, 0)
  const diffDays = Math.round((today.getTime() - date.getTime()) / 86_400_000)
  if (diffDays === 0) return 'Today'
  if (diffDays === 1) return 'Yesterday'
  if (diffDays > 1 && diffDays < 7) return `${diffDays} days ago`
  return date.toLocaleDateString('en-MY', { day: 'numeric', month: 'short', year: 'numeric' })
}
