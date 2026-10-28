# DOCX Form

DOCX Form is a generic Jyavani CMS plugin for collecting structured values and
producing reviewed or immediate documents from validated DOCX templates.
Version `0.4.1` requires Jyavani `2.3.187+`, PHP `8.1+`, MariaDB
`10.6+` or MySQL `8.0.16+`, and the PHP extensions declared in `plugin.json`.

## Workflow

1. An authorized operator creates a template with a `docx-form/v1` descriptor,
   a DOCX containing matching plain-text placeholders, and optional source PDF.
2. Every save creates a new immutable template revision. Existing submissions
   retain their original title, description, descriptor, controls, and binary
   revision even if the current template metadata changes later.
3. A public user submits values under either email OTP or explicitly
   self-declared identity. `operator_review` revisions enter `pending`; an
   operator can approve or reject them.
4. `immediate_download` revisions generate enabled output in the submission
   transaction and enter `ready` without review metadata. Immediate mode always
   requires generated DOCX download to be enabled.
5. A non-secret receipt UUID plus its scoped browser capability cookie can
   retrieve approved or ready enabled output until the configured receipt expiry.

The Settings page can switch public previews between the fast descriptor-based
shadow view and a rendered DOCX view. Rendered previews are available only for
immediate-download templates. They generate a validated temporary DOCX from
the current partial values, render that actual package in the browser with
pinned local dependencies, and remove the private staging file without creating
a submission or retained output. Email-OTP previews still require a verified
challenge. Browser rendering is a preview; the downloaded DOCX remains the
authoritative document for Word-compatible pagination.

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
transaction-time permission or published-template checks and row locking. Receipt and OTP capabilities
are kept in `Secure`, `HttpOnly`, `SameSite=Strict` scoped cookies and stored
server-side only as hashes; they never appear in URLs. Events retain UTC
timestamps, an available actor user ID, and
HMAC-based IP/user-agent hashes; they do not retain raw network identifiers.
`SESSION_SECRET` must contain at least 32 bytes. Public forms exclusively use
Core stateless CSRF tokens with a two-hour (`7200` second) TTL, including when
the browser also has an authenticated session. Missing stateless CSRF APIs or
an insufficient secret produce a controlled public `503` response. Settings explicitly bound OTP
resends, receipt lifetime, pending/rejected/approved-or-ready submissions, generated outputs,
events, and stale staging files. Cleanup runs in small request-time batches.

Complete uninstall removes plugin tables, settings, and the validated private
storage tree. Ordinary disable or keep-data deletion follows Core behavior and
preserves data while routes fail closed because the plugin is not loaded.

## Migrations

Migrations are append-only and retry-safe after MySQL/MariaDB partial DDL.
Applied migrations `0001` through `0005` remain byte-for-byte immutable:
`0001-foundation.sql` creates the baseline tables,
`0002-current-version-foreign-key.php` installs the circular current-version
foreign key, `0003-verify-schema.php` verifies that baseline, and `0004`/`0005`
add and verify managed-import ownership. On both fresh and upgraded databases,
`0006-workflow-mode.php` retry-safely adds immutable workflow modes and the
`ready` status after the baseline migrations;
`0007-verify-workflow-schema.php` verifies the complete upgraded schema and
workflow invariants. No request path performs DDL.

## Managed Imports

Trusted deployment tooling may call `dxf_import_templates()` with a versioned,
fully hash-bound package. The API validates every descriptor and source file,
plans without mutation by default, and applies under a namespace advisory lock,
transaction-time permission check, immutable revisions, Core audit, and a
plugin-owned ledger. It never adopts an existing unmanaged slug or overwrites
operator changes. `workflow_mode` is optional and defaults to `operator_review`
without changing import API version 1; immediate imports must enable DOCX
download. Imported inline PDF viewing remains disabled because offline
validation cannot stand in for the runtime trusted sanitizer callback.

## Development

```sh
for file in $(git ls-files '*.php'); do php -l "$file"; done
for test in tests/*.php; do php "$test"; done
php tools/build-package.php /tmp/opencode/docx-form-0.4.1.zip
```

No Composer or runtime framework is required.
