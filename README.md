# DOCX Form

DOCX Form is a generic Jyavani CMS plugin for collecting structured values,
reviewing submissions, and producing approved documents from validated DOCX
templates. Version `0.2.0` requires Jyavani `2.3.187+`, PHP `8.1+`, MariaDB
`10.6+` or MySQL `8.0.16+`, and the PHP extensions declared in `plugin.json`.

## Workflow

1. An authorized operator creates a template with a `docx-form/v1` descriptor,
   a DOCX containing matching plain-text placeholders, and optional source PDF.
2. Every save creates a new immutable template revision. Existing submissions
   retain their original title, description, descriptor, controls, and binary
   revision even if the current template metadata changes later.
3. A public user submits values under either email OTP or explicitly
   self-declared identity. New submissions enter `pending`.
4. An operator approves or rejects the submission. Approval generates the
   authoritative DOCX server-side when an enabled output requires it.
5. A non-secret receipt UUID plus its scoped browser capability cookie can
   retrieve approved enabled output until the configured receipt expiry.

## Descriptor

```json
{
  "schema": "docx-form/v1",
  "document_filename": "completed-document.docx",
  "fields": [
    {
      "key": "person.full_name",
      "label": "Full name",
      "type": "text",
      "required": true,
      "max_length": 160,
      "token": "{{person.full_name}}",
      "identity": "name"
    }
  ],
  "print": {"title": "Completed document", "columns": 1, "show_description": true}
}
```

Supported field types are `text`, `email`, `tel`, `number`, `date`,
`textarea`, `select`, `radio`, and `checkbox`. Identity bindings are `name`,
`email`, and `reference`; each may occur once. Email OTP templates require an
`email` field bound to `email`. Values are always plain text.

DOCX placeholders may cross Word runs. Generation modifies only Word text
nodes and lets DOM serialization escape values, so submitted text is never
interpreted as XML or HTML. The validator rejects encryption, macros, external
relationships, active embedded content, traversal, case-colliding entries,
unsafe compression, non-UTF-8 or entity-bearing XML, Word fields and
instructions, external data bindings, malformed XML, and packages over fixed
limits.

## Trusted Integrations

The plugin intentionally sends no mail and executes no converter process.
Another trusted plugin may register one callback per request after DOCX Form
loads:

```php
dxf_register_otp_sender(function (string $email, string $code, array $context): bool {
    // Deliver through a trusted site mail service.
    return true;
});

dxf_register_pdf_converter(function (string $docxPath, string $pdfStage, array $context): bool {
    // Invoke a deployment-owned, fixed converter outside user control.
    return true;
});

dxf_register_pdf_validator(function (string $sourcePath, string $sanitizedStage, array $context): bool {
    // Validate and rewrite the PDF to the exact deployment-owned stage path.
    return true;
});
```

PDF callbacks must create the exact supplied stage path. DOCX Form then applies
its conservative bounded parser, which rejects active actions, forms,
attachments, external links, and compressed object streams. Source PDF download
can use that built-in policy; inline viewing additionally requires a registered
validator/sanitizer and a revision produced by it. Generated PDFs remain
downloadable after their converter callback is no longer registered. Do not pass
user-controlled shell commands or converter options.

Use `[[widget:docx_form slug="your-template"]]` to render a link card. The
shortcode emits only its narrowly scoped plugin stylesheet when the card is
actually rendered; it does not globally load the full form frontend assets.
The public route is `/document-forms/`. Installation fails closed if that path
is already owned by a configured Core collection or authentication route.

## Storage and Privacy

Runtime files live below Core's private files tree in
`private_files/plugins/docx-form` with random names and SHA-256 metadata. That
directory is not packaged. Uploads and generated artifacts are bounded in a
same-filesystem private staging directory and atomically published only after
transaction-time authorization and row locking. Receipt and OTP capabilities
are kept in `Secure`, `HttpOnly`, `SameSite=Strict` scoped cookies and stored
server-side only as hashes; they never appear in URLs. Events retain UTC
timestamps, an available actor user ID, and
HMAC-based IP/user-agent hashes; they do not retain raw network identifiers.
`SESSION_SECRET` must contain at least 32 bytes. Public forms exclusively use
Core stateless CSRF tokens with a two-hour (`7200` second) TTL, including when
the browser also has an authenticated session. Missing stateless CSRF APIs or
an insufficient secret produce a controlled public `503` response. Settings explicitly bound OTP
resends, receipt lifetime, pending/rejected submissions, generated outputs,
events, and stale staging files. Cleanup runs in small request-time batches.

Complete uninstall removes plugin tables, settings, and the validated private
storage tree. Ordinary disable or keep-data deletion follows Core behavior and
preserves data while routes fail closed because the plugin is not loaded.

## Migrations

Migrations are append-only and retry-safe after MySQL/MariaDB partial DDL:
`0001-foundation.sql` creates tables, `0002-current-version-foreign-key.php`
idempotently installs the circular current-version foreign key, and
`0003-verify-schema.php` fails closed on incompatible columns, indexes, checks,
JSON validity constraints, or owned foreign keys. `0004-template-import-ledger.sql`
adds generic managed-import ownership, and `0005-verify-import-ledger.php`
verifies that extension. No request path performs DDL.

## Managed Imports

Trusted deployment tooling may call `dxf_import_templates()` with a versioned,
fully hash-bound package. The API validates every descriptor and source file,
plans without mutation by default, and applies under a namespace advisory lock,
transaction-time permission check, immutable revisions, Core audit, and a
plugin-owned ledger. It never adopts an existing unmanaged slug or overwrites
operator changes. Imported inline PDF viewing remains disabled because offline
validation cannot stand in for the runtime trusted sanitizer callback.

## Development

```sh
for file in $(git ls-files '*.php'); do php -l "$file"; done
for test in tests/*.php; do php "$test"; done
php tools/build-package.php /tmp/docx-form-0.2.0.zip
```

No Composer or runtime framework is required.
