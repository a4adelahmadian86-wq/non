# Phase 4 — Database integrity audit

Audit snapshot: 2026-11-11  
Repository: \`a4adelahmadian86-wq/non\`

## Scope and safety

This audit is repository/schema review plus automated tests. It does not claim access to a developer's local database or a production database. No live database was migrated, repaired, truncated, or otherwise modified during this work.

The local report of 12 pending migrations cannot be reconciled from GitHub source alone. The exact \`php artisan migrate:status\` output and the target database engine are required before approving an upgrade on that database.

## Migration inventory

- The inspected \`main\` snapshot contains 114 migration files.
- There are 10 pairs of files sharing a timestamp:
  - \`2026_09_12_000012\`
  - \`2026_10_03_000001\`
  - \`2026_10_19_000002\`
  - \`2026_11_07_000001\`
  - \`2026_11_08_000001\`
  - \`2026_11_09_000012\`
  - \`2026_11_10_000001\`
  - \`2026_11_10_000005\`
  - \`2026_11_10_000006\`
  - \`2026_11_10_000011\`
- The filenames themselves are distinct. Laravel's migrator orders the files by migration name, so equal timestamps are not by themselves proof of a broken migration chain.
- The clean SQLite migration path completed in CI before the new Phase 4 migrations were added. The new migrations are also exercised by the Phase 4 Agent workflow.

## Confirmed schema risks

### Financial retention and idempotency

The original core schema used cascading deletes for:
- \`wallets.user_id\`
- \`wallet_transactions.wallet_id\`
- \`orders.user_id\`
- \`payments.order_id\`

The Phase 4 financial migration adds stable unique idempotency keys to orders and payments, backfills existing rows deterministically, and refuses to create unique indexes if duplicate keys are found. It also changes financial-history deletion behavior to restrictive behavior. SQLite uses equivalent insert/update/delete triggers because its schema grammar cannot drop an existing foreign key by name.

Wallet transaction records are protected against Eloquent updates and deletes. This model-level protection does not claim to block raw SQL or query-builder writes.

### Commercial ledger references

The commercial-engine migrations define a number of indexed integer references without database foreign-key constraints. The Phase 4 reference migration covers direct ledger relationships for subscriptions, plans/products, usage events/reservations, charges, invoices, refunds, wallet reservations, price quotes, and output authorizations.

Before adding a relationship, it checks for orphan references. If any are found, the migration throws an explicit exception and does not delete or rewrite those rows. SQLite receives insert/update/delete triggers; other supported database engines receive restrictive foreign keys.

## Historical migration requiring special handling

\`2026_10_03_000004_canonicalize_farast_subscriptions.php\` has a risky data-migration sequence: it drops the legacy subscription table before the canonical copy has been fully verified, and it skips rows when a matching commercial plan cannot be resolved.

This historical migration was not rewritten as part of this change because it may already be recorded as applied in existing environments. Editing an applied migration would not repair those databases and could make fresh-install and upgrade behavior diverge. Its effect must be checked against a database snapshot or backup before any recovery decision.

## Automated coverage added

- Repeated order/payment idempotency keys resolve to one record.
- Existing financial records block cascading account, wallet, and order deletion.
- Wallet ledger records reject Eloquent updates and deletes.
- The financial migration backfills legacy rows, is repeatable, and rejects duplicate legacy keys without merging them.
- The commercial ledger migration rejects orphan references and preserves the orphaned row for manual reconciliation.
- Representative subscription, invoice-item, and wallet-reservation references are rejected when their parent row does not exist.
- Deleting a commercial plan referenced by a subscription is rejected.

## Remaining verification gates

1. Obtain the actual local \`migrate:status\` output for the 12 pending migrations.
2. Confirm the local database engine/version and compare its schema to the migration baseline.
3. Run the read-only orphan/duplicate preflight against a copy of the local database before applying the new reference migration.
4. Test the upgrade path on a sanitized copy of the upgraded database, not only on a clean SQLite database.
5. Verify the new foreign-key migration on the actual production database engine.
6. Resolve the existing Reader test failures on \`main\` separately. The Phase 3 Commerce workflow currently reports failures in \`StoreReaderAccessTest\` on both the main baseline and this branch; these are not being hidden or changed in this database-focused PR.

The Phase 4 PR remains a draft until these gates are satisfied.
