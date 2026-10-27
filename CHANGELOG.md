# Changelog

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
