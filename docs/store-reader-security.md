# FARAST Store Reader: Security and Support Notes

## Reader responsibility

The reader is a delivery and display layer only. It must never convert an uploaded source document, generate a new preview file, or persist a visitor-specific copy during a read request.

Prepared artifacts are created once in the administrator upload/preparation flow and stored on the private disk:
- PDF and image uploads are stored as private preview assets.
- DOCX uploads are validated and converted to a neutral, paginated PDF before the preview record is created.
- XLSX uploads are parsed into safe, read-only HTML before the preview record is created. Formulas are not evaluated.
- The reader serves only these stored artifacts. Legacy raw DOCX/XLSX preview records are rejected (HTTP 409) until re-prepared. The one-time `farast:prepare-reader-previews` command migrates existing records to stored PDF/HTML artifacts; run it with `--dry-run` first.
- Future AI sample-page selection belongs in the upload/preparation pipeline. It should save selected page assets and metadata once; it is not reader functionality.

## Supported preview formats and limits

- **PDF:** PDF.js renders prepared PDFs. Page navigation, zoom, rotation, fit-width, thumbnails, outline navigation, text search, and continuous scrolling are provided where the PDF contains the corresponding data.
- **Word (.docx):** prepared during upload into a neutral PDF. Embedded images, tables, explicit page breaks, and section page settings are preserved where PHPWord/Dompdf can represent them. External OOXML relationships and unsafe embedded HTML are rejected.
- **Excel (.xlsx):** prepared during upload into read-only HTML. Visible worksheets and cached cell values are shown; formulas are never evaluated. External workbook relationships are rejected.
- **Images:** stored privately and displayed as non-selectable preview content.

Current defensive ceilings:
- Preview upload limit: 20 MiB.
- DOCX: at most 5,000 ZIP entries and 100 MiB expanded content; relationship XML parts are limited to 2 MiB.
- XLSX: at most 2,000 ZIP entries and 80 MiB expanded content; at most 10 total worksheets (hidden sheets are skipped), 2,000 rows per sheet, 40 columns, and 80,000 parsed cells.

These are defensive ceilings, not a promise that every Office feature is reproduced. Complex Word layouts, embedded objects, advanced Excel formatting, charts, drawings, and images may differ from desktop Office applications.

## Visitor identity watermark

- Authenticated visitor: the mobile number on their account.
- Guest visitor: the request IP address. Production proxy configuration must trust only known reverse proxies so the address cannot be spoofed through arbitrary forwarded headers.
- PDF identity is painted into the browser-rendered page canvas. Prepared workbook HTML and image assets are streamed unchanged; the visitor identity is drawn as a visual overlay in the reader. No visitor identity is inserted into or persisted with the prepared workbook.
- The stored neutral PDF/HTML/image artifact is not rewritten for each visitor. The reader does not generate a watermark-specific file or mutate the delivered HTML artifact.
- Ordinary selection, copy, cut, drag, context-menu, print, and save shortcuts are suppressed where practical. These browser controls are deterrents, not DRM; screenshots, photography, developer tools, and extraction of delivered network content remain possible.

## Product access policy

Products have a download policy:
- `signed`: the existing signed-download flow remains available after purchase.
- `reader_only`: buyers receive access through the reader, but download-link issuance and the final stream endpoint both deny downloads.

Administrators can upload prepared preview assets with access level `sample` or `full`:
- Sample assets are available to guests and carry an IP watermark.
- Full assets are accepted only for `reader_only` products and are served only to authenticated visitors with an active library entitlement for that product.
- Revoked or absent library entitlements do not unlock full assets.

## Response protections

Preview and reader responses use private/no-store cache headers, nosniff, same-origin framing/resource policies, no-referrer, and restrictive content security policies. Preview assets remain on the private disk and are delivered through throttled application routes; no public storage URL is used.

The reader self-hosts the pinned PDF.js legacy module, worker, viewer styles, CMaps, standard fonts, and optional WASM assets under `public/vendor/pdfjs/`. Run `bash scripts/prepare-reader-assets.sh` during deployment (Node.js/npm required); CI runs the same preparation step before browser tests. The generated distribution is version-pinned to `6.5.438` and its upstream license is copied alongside the assets. The reader no longer depends on a third-party CDN at runtime.

## Automated verification and release checks

CI should cover:
1. Laravel feature tests for guest IP watermark, authenticated mobile watermark, prepared-only Office delivery, full-content entitlement, revocation, and reader-only download denial.
2. Playwright tests for PDF.js rendering and watermark visibility, text search, page controls, zoom/rotation, lazy thumbnail loading, and copy/context-menu suppression.
3. Migration, route, Blade cache, and JavaScript syntax checks.

For an existing installation, first run `bash scripts/prepare-reader-assets.sh` to install the pinned, same-origin PDF.js assets. Then run `php artisan farast:prepare-reader-previews --dry-run`, review the listed records, and run `php artisan farast:prepare-reader-previews` during a controlled maintenance window before enabling the new reader routes. New uploads are prepared automatically in the admin upload flow.

Automated fixtures do not replace manual visual QA against representative customer PDFs, DOCX, and XLSX files on desktop and mobile browsers. Production deployment, migrations, trusted-proxy configuration, private-disk permissions, and actual uploaded sample content must be verified in the target environment.

## Demo product

The opt-in command creates synthetic source files and their prepared reader artifacts on the private disk, then registers three prepared preview entries:

    php artisan farast:seed-reader-demo

Rebuild the fixture files in place with:

    php artisan farast:seed-reader-demo --refresh

This command is a test/demo fixture generator only. It is not called by the reader and is not part of automatic production seeding. The generated PDF, Word-to-PDF, and Excel-to-HTML assets are stored once and then served by the reader.
