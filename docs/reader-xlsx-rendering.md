# Excel reader preview

Excel workbooks are converted to a private, sanitized HTML snapshot during upload. The reader serves that prepared snapshot; it does not parse or convert the source workbook on each visit.

## Preserved formatting

The renderer currently preserves a bounded subset of cell formatting:

- Bold and italic text, font size and explicit RGB font colors.
- Solid cell background fills.
- Thin, medium, thick, dashed, dotted, double and hairline borders.
- Horizontal and vertical alignment, plus text wrapping.
- Merged cell ranges, custom column widths and custom row heights within safe rendering limits.
- Common numeric formats, including grouped decimals, percentages and Excel date/time serials (rendered in ISO-style date/time form).

Only validated formatting values are translated into CSS. Workbook-provided HTML, CSS, formulas, macros, external relationships and scripts are never executed or copied into the preview.

## Data and safety limits

- Formula expressions are not evaluated; only cached cell values are displayed.
- Hidden worksheets are omitted.
- Sample-level workbooks are reduced at upload time to the first visible worksheet and its first 50 rows; full-level workbooks retain all supported visible sheets and up to 2,000 rows per sheet.
- The prepared HTML stores a watermark placeholder. The private preview response injects the current viewer's escaped account identifier or guest IP on every visit, so one viewer's identity is never baked into the shared prepared snapshot.
- External workbook relationships are rejected.
- The renderer limits archive entries, expanded archive size, sheets, rows, columns, cells and XML part sizes.
- Advanced workbook features such as charts, embedded images, pivot tables, conditional formatting and complete number-format fidelity are not yet reproduced.

## Validation

The Laravel reader feature tests build minimal XLSX archives to verify safe formatting, inert cached formula values, sample sheet/row isolation, and viewer-specific watermark injection.
