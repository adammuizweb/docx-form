# DOCX Form Repository Contract

DOCX Form is a generic Jyavani CMS plugin. Keep tracked source, fixtures, and
documentation free of downstream identities, deployment paths, credentials,
and document assets. Canonical author, repository, and Store metadata are the
only product identity exceptions.

- Published template revisions and submission events are immutable.
- Store templates, source PDFs, and generated output only in private runtime storage.
- Treat DOCX/PDF uploads, descriptors, public input, receipt tokens, and callbacks as untrusted.
- Keep OTP delivery and PDF conversion behind explicit trusted callback registration.
- Every admin mutation uses POST, CSRF, transaction-time authorization, row locks,
  optimistic versions, same-transaction plugin/Core audit, rollback, and a 303 redirect.
- Add only append-only four-digit plugin migrations; never perform runtime DDL.
- Use the `dxf_` prefix for global symbols and plugin-owned storage/settings.
- Do not modify Core, commit, push, or publish unless explicitly requested.

Before release, lint every PHP file, run every standalone test, validate JSON,
build and reopen-verify the package, inspect identity leakage, and run
`git diff --check` plus `git status`.
