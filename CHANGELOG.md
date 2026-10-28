# Changelog

## 0.4.2 - 2026-10-28

- Allowed Site Owners to delegate every DOCX Form permission to dedicated
  least-privilege custom roles without granting general site administration.
- Advanced unchanged managed-import definitions between package releases
  without creating duplicate immutable template revisions or storage copies.

## 0.4.1 - 2026-10-28

- Fixed the nested audit route and added bounded server-side event pagination.
- Aligned the admin workspace with Core light/dark tokens and corrected linked
  button hover, focus, text-color, and underline states.

## 0.4.0 - 2026-10-28

- Added a dashboard preview-mode toggle and ephemeral rendered-DOCX previews
  for immediate-download forms, with partial typed validation, bounded request
  rates, private-stage cleanup, local pinned browser dependencies, and shadow
  fallback.

## 0.3.1 - 2026-10-28

- Expanded the public form workspace into a responsive one-quarter form and
  three-quarter live document preview, with descriptor-driven columns and a
  mobile single-column fallback.

## 0.3.0 - 2026-10-28

- Added immutable per-revision operator-review and immediate-DOCX workflows,
  with transactional server-side generation, a distinct `ready` status,
  append-only events, receipt revocation, and retention parity.
- Added backward-compatible managed import projections, retry-safe workflow
  upgrade and verification migrations, localized UI, and release contracts.

## 0.2.0 - 2026-10-27

- Added a generic, versioned batch-import API with exact source integrity,
  read-only planning, namespace locking, immutable revisions, Core audit, and
  a plugin-owned ownership ledger that rejects unmanaged or edited templates.
- Added append-only import-ledger migrations, complete schema verification,
  transactional private-file staging, and rollback-safe cleanup.

## 0.1.0 - 2026-10-27

- Initial MVP with immutable DOCX form revisions, typed descriptors, private
  storage, email OTP or self-declared identity, pending review, approved DOCX,
  optional trusted PDF conversion, source PDF controls, print views, domain
  events, local translations, shortcode integration, and verified packaging.
- Hardened DOCX/XML and PDF validation, cookie-bound expiring and revocable
  capabilities, transactional staging, canonical integrity hashes, bounded
  retention, stable GET/HEAD streaming, and complete schema verification.
