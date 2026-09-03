# SRS and Reference Overrides Log

This document tracks Founder-approved corrections that supersede a specific normative statement in an authoritative source document, without editing that source document. It exists so that immutable sources (particularly the System Requirements Specification PDF) never need local editing to stay current, while the project still has one place that records what has been superseded, by whom, and why.

Each entry is narrow: it corrects one statement. It does not change product scope, UX, or architecture unless explicitly stated. See [`README.md`](README.md) for the precedence order these overrides operate under, and [`docs/adr/README.md`](../../adr/README.md) for how architecture decisions are recorded separately.

## SRS compliance assessment: Nuxt 4

- **Assessment:** No SRS override is required for the Nuxt 4 lifecycle change.
- **Basis:** The System Requirements Specification (`hore.my_Spesifikasi_Keperluan_Sistem_v1.0_BM.pdf`) does not lock a specific Nuxt major version. It states the frontend architecture generically as an installable "Nuxt PWA."
- **Conclusion:** Nuxt 4 remains fully compliant with the SRS's generic "Nuxt PWA" requirement. The SRS PDF is preserved as immutable and requires no correction, override, or annotation for this change.

## Override 001: Frontend framework version — explicit "Nuxt 3" statements superseded by Nuxt 4

- **Affected document:** Normative restatements that pinned a specific major version beyond the SRS's generic "Nuxt PWA" requirement: `HORE_MY_PROJECT_INSTRUCTIONS.txt`, `HORE_MY_MASTER_CONTEXT.md`, and [ADR-0002](../../adr/0002-technology-stack-selection.md). This override does not apply to the SRS itself — see the compliance assessment above.
- **Original statement:** The frontend technology stack is Nuxt 3, Vue 3, TypeScript, Tailwind CSS, and an installable PWA.
- **Superseding decision:** The frontend technology stack is Nuxt 4, Vue 3, TypeScript, Tailwind CSS, and an installable PWA. Vue 3, TypeScript, Tailwind CSS, and the installable PWA requirement are unchanged.
- **Authority:** Founder / Product Owner approval.
- **Effective date:** 2026-09-03.
- **Reason:** Nuxt 3 reached End-of-Life before hore.my frontend implementation began. This is a technology lifecycle correction, not a product, UX, or architecture change.
- **Note:** The SRS PDF remains unchanged and immutable, and is unaffected by this override since it never pinned Nuxt 3. This override entry, together with the amendment recorded in ADR-0002, is the authoritative record for implementation purposes. No frontend bootstrap has occurred under either the original or the superseding decision.
