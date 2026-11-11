# Product document queue recovery

The farast:recover-product-documents scheduler command repairs only abandoned queue leases:

- Only processing documents older than 25 minutes are candidates. Old pending rows are deliberately left alone because a queue backend may already contain their job; redispatching them blindly could create duplicate work.
- The source file must still exist on the private disk, remain active, have a clean upload-scan result, and match both the document and file SHA-256 values.
- The command re-checks eligibility under a row lock, resets the state to pending, writes a system audit event, and dispatches after the transaction commits.
- Failed, stale, awaiting-provider, inactive, missing, or checksum-mismatched documents are never automatically retried. They require diagnosis or an explicit operator action.
- Each run inspects at most 50 documents by default (hard cap 200). Existing artifacts are not deleted; the document job owns processing and artifact reuse.

The processing lease threshold exceeds the current 10-minute job timeout. If the worker timeout changes, keep the recovery threshold comfortably above it.

## Workflow event contracts and health gates

The central workflow outbox accepts only these server-originated event contracts:

| Event | Required immutable context |
| --- | --- |
| `document.saved` | positive `document_id` and `revision` |
| `ticket.created` | positive `ticket_id` and non-empty `ticket_code` |
| `order.paid` | positive `order_id`, non-negative `amount_rials`, `order_kind`, and status `paid` |
| `order.deposit_paid` | positive `order_id`, non-negative `amount_rials`, `order_kind`, and status `deposit_paid` |

Unknown context fields, malformed identifiers, mismatched payment status, and missing event-ledger schema fail closed. A valid domain operation must not silently commit after its required outbox write has been discarded.

Run `php artisan farast:workflows:health` from the application environment. The command exits non-zero and logs a warning when sync mode leaves pending events, terminal failures exist, processing leases are stale, failed queue jobs exist, or queue/outbox latency reaches five minutes. A healthy queue exits zero.

## Queue isolation and deployment

- General document/ticket workflow events use the `automation` queue.
- Financial events (`order.paid` and `order.deposit_paid`) use the isolated `automation-critical` queue and a separate scheduled worker, so general workflow backlog cannot delay payment-related automation.
- Heavy document/audio processing and backup jobs remain on their own `documents`, `documents-audio`, and `backups` queues.
- The scheduled recovery command also marks abandoned manual workflow runs as failed and preserves their run history. Event-backed runs are linked to their lease through `run_id`; stale lease recovery marks the interrupted run before a retry or terminal failure.

For a background-processing environment, configure `QUEUE_CONNECTION=database`, keep `QUEUE_SCHEDULED_WORKER=true`, apply the Laravel queue-table migrations, and ensure the Laravel scheduler is invoked every minute:

```cron
* * * * * cd /path/to/farast && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler starts separate workers for `automation` and `automation-critical`. Confirm the deployment with `php artisan farast:workflows:health`; a non-zero exit code is a failed health check, not a healthy empty queue.
