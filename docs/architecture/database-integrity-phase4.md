# Phase 4 — Financial and commerce database integrity

## Scope

This change hardens the existing financial schema. It does not create a second ledger, replace the existing payment/order services, or rewrite historical rows.

The migrations are deliberately timestamped after the current migration inventory:

- `2026_11_16_235951_harden_financial_idempotency_and_retention`
- `2026_11_16_235952_enforce_farast_commerce_ledger_references`
- `2026_11_16_235953_make_wallet_ledger_immutable`

## Guarantees added

### Orders and payments

- Add `idempotency_key` when it is missing.
- Backfill null, empty, and whitespace-only legacy values deterministically using the row ID.
- Reject duplicate non-empty keys before changing the schema.
- Enforce unique order and payment idempotency keys.
- Reject null or blank keys at the database boundary.
- Enforce unique `(gateway, authority)` and `(gateway, transaction_id)` pairs.
- Reject authority or transaction identifiers that have no gateway.
- Preserve payment/order history by preventing deletion of referenced financial parents.

The application models already generate idempotency keys and protect settled records. These migrations add database-level uniqueness and retention enforcement so query-builder and direct SQL paths cannot silently bypass the relevant constraints.

### Wallet ledger

Wallet transaction rows are append-only at the database layer. Update and delete attempts are rejected even when they bypass Eloquent model events.

### Commercial ledger

Restrictive references are added for the existing commercial tables: subscriptions, plans/products, usage events/reservations, charges, invoices, refunds, wallet reservations, price quotes, and output authorizations.

Before applying constraints, the migration checks the complete relation set for missing tables/columns and orphan references. It does not delete, null, or rewrite orphaned records. A failed preflight stops the migration and requires explicit reconciliation.

## Database-driver behavior

- SQLite: trigger-based enforcement where an existing foreign key cannot be safely altered in place.
- MySQL: restrictive foreign keys, unique indexes, check constraints, and wallet-ledger triggers.
- PostgreSQL: restrictive foreign keys, unique indexes, check constraints, and wallet-ledger triggers.

The migrations are intentionally non-destructive on rollback. A rollback must not silently restore cascading financial-history deletion or mutable wallet ledger entries.

## Validation

Automated coverage includes:

- Deterministic legacy idempotency-key backfill and repeatable migration execution.
- Rejection of null/blank and duplicate idempotency keys.
- Rejection of duplicate gateway authorities and transaction IDs.
- Restrictive deletion of users, wallets, and orders with financial history.
- Wallet-ledger immutability through Eloquent and query-builder operations.
- Rejection of orphan commercial references and preservation of the orphan row for manual reconciliation.
- MySQL 8.4 execution of the financial retention and commercial foreign-key tests.

## Production upgrade gate

No production or developer-local database is migrated by this change. Before applying it to an existing database:

1. Record the database engine/version and exact `php artisan migrate:status` output.
2. Take and verify a restorable backup.
3. Run the migration against a sanitized copy of the target database.
4. Review duplicate keys, duplicate gateway references, and orphan reports.
5. Reconcile invalid legacy rows explicitly; do not disable preflight or silently discard records.
6. Apply the migrations in a controlled deployment window and verify the constraints afterward.

A clean CI migration proves the schema path on an empty database. It does not prove that an existing production database contains no legacy duplicates or orphan references.
