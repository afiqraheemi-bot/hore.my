/**
 * Normalizes whatever a user typed into an amount field (e.g. "390",
 * "390.5", " 12.3 ") into the exact `\d+\.\d{2}` decimal-string shape
 * every backend Money-accepting endpoint requires (AETS-003) — without
 * this, a perfectly reasonable "390" submits as-is, the backend's
 * strict format validation rejects it, and the user sees only a
 * generic "Failed to..." message with no indication that adding
 * ".00" would have worked.
 *
 * Returns the input unchanged if it isn't a valid non-negative number
 * — never invents a value for genuinely empty or garbage input; the
 * existing `required`/format validation (client- or server-side)
 * still catches that case on its own terms.
 */
export function normalizeMoney(value: string): string {
  const trimmed = value.trim()
  const asNumber = Number(trimmed)

  if (trimmed === '' || !Number.isFinite(asNumber) || asNumber < 0) {
    return trimmed
  }

  return asNumber.toFixed(2)
}
