# Excel reader preview

Excel workbooks are converted to a private, sanitized HTML snapshot during upload. The reader serves that prepared snapshot; it does not parse or convert the source workbook on each visit.

## Preserved formatting

The renderer currently preserves a bounded subset of cell formatting:

- Bold and italic text, font size and explicit RGB font colors.
- Solid cell background fills.
- Thin, medium, thick, dashed, dotted, double and hairline borders.
- Horizontal and vertical alignment, plus text wrapping.
- Common numeric formats, including grouped decimals, percentages and Excel date/time serials (rendered in ISO-style date/time form).

Only validated formatting values are translated into CSS. Workbook-provided HTML, CSS, formulas, macros, external relationships and scripts are never executed or copied into the preview.

## Data and safety limits

- Formula expressions are not evaluated; only cached cell values are displayed.
- Hidden worksheets are omitted.
- External workbook relationships are rejected.
- The renderer limits archive entries, expanded archive size, sheets, rows, columns, cells and XML part sizes.
- Advanced workbook features such as charts, embedded images, pivot tables, conditional formatting and complete number-format fidelity are not yet reproduced.

## Validation

The Laravel reader feature test builds a minimal XLSX archive with a styled cell and verifies that its safe formatting is present in the prepared HTML while cached formula values remain inert.
