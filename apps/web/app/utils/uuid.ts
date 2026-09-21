/**
 * `crypto.randomUUID()` is spec-restricted to secure contexts (HTTPS,
 * or the `localhost`/`127.0.0.1` exception) — on a phone reached over
 * plain HTTP via a LAN IP (the normal way to test a local dev build on
 * a real device), it simply doesn't exist on `crypto`, so calling it
 * throws before the request is even built. Every Idempotency-Key this
 * app sends (AppComposer, invoices, payments, bank-accounts, reports,
 * task actions) needs one, so this one throw was surfacing as a
 * generic "Could not record this" with no hint of the real cause
 * (reported by the Founder: worked on a MacBook via `localhost`,
 * failed on a phone on the same LAN).
 *
 * `crypto.getRandomValues()` carries no such restriction, so it's used
 * to build an equivalent RFC 4122 v4 UUID by hand whenever
 * `randomUUID` isn't available.
 */
export function generateUuid(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }

  const bytes = crypto.getRandomValues(new Uint8Array(16))
  bytes[6] = (bytes[6]! & 0x0f) | 0x40
  bytes[8] = (bytes[8]! & 0x3f) | 0x80

  const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('')
  return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
