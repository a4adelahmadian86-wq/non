# Protected PDF, Word and Excel samples

## Upload-time preparation

Sample documents are reduced before they are stored as reader previews. The reader never receives the uploaded source document when the access level is `sample`.

- **PDF:** FPDI imports only the first configured number of pages (the product's `preview_pages`, clamped to 1–50) into a new PDF. The original PDF is removed from quarantine after the prepared copy is stored.
- **DOCX:** the existing Word-to-PDF conversion runs during upload. Sample-level Word documents then pass through the same page-isolation step. Full-level Word documents are converted once and remain protected by the full-reader entitlement.
- **XLSX:** sample workbooks contain only the first visible worksheet and its first 50 rows. Full-level workbooks retain the supported visible sheets and row limit.
- **Images:** the uploaded image itself is the preview asset.

The preview record stores `source_page_count` and `included_page_count` for PDF/Word samples so administrators can verify the generated subset.

## Access boundaries

Full-level previews are accepted only for products whose download policy is `reader_only`. The existing reader entitlement check remains in force for every full asset. Sample assets are private and served through the reader preview route.

FPDI imports page content into a new document and does not import external links. Encrypted or malformed PDFs, unsupported compression filters, page trees above the supported limit, and documents that cannot be parsed are rejected. The system fails closed; it does not silently fall back to serving the original PDF as a sample.

## Operational notes

- Installations must provide PHP 8.2+, GD, and Zlib as required by the locked FPDF/FPDI packages.
- Sample page isolation is performed once at upload time, not during reader visits.
- A sample is a content-limited preview, not DRM. A user can still capture visible content through screenshots or external recording.
- Advanced workbook features such as charts, pivot tables and embedded images remain outside the current Excel renderer's supported scope.
