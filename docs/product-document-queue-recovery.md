# Product document queue recovery

The farast:recover-product-documents scheduler command repairs only abandoned queue leases:

- Only processing documents older than 25 minutes are candidates. Old pending rows are deliberately left alone because a queue backend may already contain their job; redispatching them blindly could create duplicate work.
- The source file must still exist on the private disk, remain active, have a clean upload-scan result, and match both the document and file SHA-256 values.
- The command re-checks eligibility under a row lock, resets the state to pending, writes a system audit event, and dispatches after the transaction commits.
- Failed, stale, awaiting-provider, inactive, missing, or checksum-mismatched documents are never automatically retried. They require diagnosis or an explicit operator action.
- Each run inspects at most 50 documents by default (hard cap 200). Existing artifacts are not deleted; the document job owns processing and artifact reuse.

The processing lease threshold exceeds the current 10-minute job timeout. If the worker timeout changes, keep the recovery threshold comfortably above it.
