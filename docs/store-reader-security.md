# FARAST Store Sample Reader: Security and Support Notes

## Supported preview formats

- **PDF:** rendered in the authenticated reader with the pinned PDF.js legacy module. Page navigation, zoom, rotation, fit-width, thumbnails, outline navigation, text search, and continuous scrolling are provided where the PDF contains the corresponding data.
- **Word (.docx):** validated as an OOXML archive, converted server-side to a sanitized HTML representation and then to a watermarked PDF for paginated reading. External OOXML relationships and active HTML content are rejected.
- **Excel (.xlsx):** parsed server-side into a read-only HTML workbook. Visible worksheets and cached cell values are shown; formulas are never evaluated. External workbook relationships are rejected.

The original product file is not used as a public preview URL. Preview records are stored on the configured private disk and served through authenticated, throttled routes.

## Current resource limits

- Preview upload limit: 20 MiB.
- DOCX: at most 5,000 ZIP entries and 100 MiB expanded content; relationship XML parts are limited to 2 MiB.
- XLSX: at most 2,000 ZIP entries and 80 MiB expanded content; at most 10 total worksheets (hidden sheets are skipped), 2,000 rows per sheet, 40 columns, and 80,000 parsed cells. Individual workbook and XML parts have additional size limits in the renderer.

These are defensive ceilings, not a promise that every Office feature is reproduced. Complex Word layouts, embedded objects, advanced Excel formatting, charts, drawings, and images may not be identical to desktop Office applications.

## Access and response protections

- Reader and preview endpoints require an authenticated account; preview requests are rate-limited.
- Preview responses use private/no-store cache headers, nosniff, same-origin framing/resource policies, no-referrer, and restrictive content security policies.
- DOCX is converted once per source-content hash into a neutral PDF; XLSX is converted once per source-content hash into escaped HTML. Both neutral conversions are cached for seven days and reused across accounts. The current account identity is painted at read time (PDF canvas for PDF/DOCX and a per-response escaped identity token for XLSX), so cache entries never contain another user's identity. The XLSX renderer does not execute formulas, macros, scripts, or external workbook relationships.
- The reader suppresses ordinary copy, cut, drag, context-menu, print, and save shortcuts and paints the account identity into PDF page canvases. Word PDFs and Excel HTML include server-generated identity watermarks.

## Important security boundary

A browser-based reader cannot provide absolute DRM. An authenticated user can still inspect network traffic, retrieve content delivered to their browser, take screenshots, or photograph the screen. For PDF, the account watermark is painted into the rendered page canvas in the browser; the original PDF response is **not** rewritten with a server-side watermark. The watermark therefore raises attribution friction for ordinary viewing and screenshots but does not prevent extraction of the original PDF bytes by a user who can access the authenticated endpoint.

Do not describe the reader as impossible to copy or download. Stronger PDF protection requires a separately deployed and tested server-side PDF stamping/rasterization pipeline or a dedicated DRM/document-delivery service, with explicit operational and performance requirements.

## Automated verification status

Latest completed CI for the reader branch (commit `ce387de2a35c25071a27b57cb2af75c9dcebd7ab`, run [42407030093](https://github.com/a4adelahmadian86-wq/non/actions/runs/42407030093)) passed:

- Laravel suite: **313 tests passed, 1,526 assertions**.
- Playwright suite: **56 browser tests passed**, including a PDF.js page render from a generated PDF fixture, account watermark, text search, page controls, zoom, rotation, theme, sidebar, continuous-scroll toolbar availability, and sandboxed Excel iframe checks.
- PHP migrations, route listing, Blade view cache, JavaScript syntax checks, and the configured voice-stream smoke steps completed successfully. The optional Sherpa workflow job was skipped for this pull-request event.

These tests establish automated behavior against controlled fixtures. They do **not** replace manual visual QA against representative customer documents and real mobile/desktop browsers; no such manual QA is claimed here.

## Release checklist

1. Run the full Laravel and Playwright CI workflows.
2. Manually inspect representative PDFs and DOCX files with RTL text, mixed fonts, tables, page breaks, headers/footers, and embedded images on desktop and mobile.
3. Inspect representative XLSX files with multiple sheets, hidden sheets, dates, shared strings, cached formulas, large sparse ranges, and malformed/external relationships.
4. Verify production PHP extensions and private-disk permissions; keep preview files outside the public web root.
5. Confirm the PDF.js version and worker are pinned consistently. The current browser implementation loads the official legacy build from a version-pinned CDN; it is not self-hosted.
6. Reassess server-side PDF watermarking before making any stronger anti-extraction claims.


## Conversion reuse and resource policy

- DOCX and XLSX conversions use a content-addressed cache key (SHA-256 of the private source bytes) with a seven-day TTL. The same neutral conversion is reused across all authenticated readers; a changed source automatically receives a new cache key.
- User-specific identity is applied after the shared conversion. The cache never stores a user's mobile number or account identifier.
- Cache storage should be configured as a persistent shared Laravel cache backend (Redis or the file cache on a shared persistent volume). An in-memory/array cache only benefits a single process/request lifecycle and is not suitable for production reuse across workers.
- PDF.js renders the requested page first. Continuous scrolling and thumbnails should remain opt-in/visible-demand features; avoid eager rendering of every page or prefetching full documents.
- The conversion cache is bounded by TTL, but operators should still monitor cache disk/Redis memory and configure normal cache eviction/cleanup policies.

## Demo product

An opt-in command generates small, synthetic Persian-language PDF, DOCX, and two-sheet XLSX files on the **private** disk and creates a published, free catalog product with three preview entries:

    php artisan farast:seed-reader-demo

Rebuild the files in place with:

    php artisan farast:seed-reader-demo --refresh

The command is intentionally not part of automatic production seeding. Run it only in an environment where you want the public demo catalog item. The product page is `/store/product/farast-reader-demo`; the reader endpoint requires an authenticated account. The generated PDF is marked as the product's primary demo file, but all three preview sources remain on the private disk and are served only through authenticated preview routes.
