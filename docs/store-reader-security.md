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
- DOCX is converted before delivery; XLSX is rendered as escaped HTML in a sandboxed iframe. The XLSX renderer does not execute formulas, macros, scripts, or external workbook relationships.
- The reader suppresses ordinary copy, cut, drag, context-menu, print, and save shortcuts and paints the account identity into PDF page canvases. Word PDFs and Excel HTML include server-generated identity watermarks.

## Important security boundary

A browser-based reader cannot provide absolute DRM. An authenticated user can still inspect network traffic, retrieve content delivered to their browser, take screenshots, or photograph the screen. For PDF, the account watermark is painted into the rendered page canvas in the browser; the original PDF response is **not** rewritten with a server-side watermark. The watermark therefore raises attribution friction for ordinary viewing and screenshots but does not prevent extraction of the original PDF bytes by a user who can access the authenticated endpoint.

Do not describe the reader as impossible to copy or download. Stronger PDF protection requires a separately deployed and tested server-side PDF stamping/rasterization pipeline or a dedicated DRM/document-delivery service, with explicit operational and performance requirements.

## Release checklist

1. Run the full Laravel and Playwright CI workflows.
2. Manually inspect representative PDFs and DOCX files with RTL text, mixed fonts, tables, page breaks, headers/footers, and embedded images on desktop and mobile.
3. Inspect representative XLSX files with multiple sheets, hidden sheets, dates, shared strings, cached formulas, large sparse ranges, and malformed/external relationships.
4. Verify production PHP extensions and private-disk permissions; keep preview files outside the public web root.
5. Confirm the PDF.js version and worker are pinned consistently. The current browser implementation loads the official legacy build from a version-pinned CDN; it is not self-hosted.
6. Reassess server-side PDF watermarking before making any stronger anti-extraction claims.
