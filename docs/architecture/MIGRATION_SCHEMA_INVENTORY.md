# فهرست Migration و مالکیت Schema

> مبنا: ۱۱۴ فایل `database/migrations/*.php` در `main`؛ ترتیب مطابق نام فایل‌ها. این استخراج ایستا است و فقط عملیات قابل تشخیص `Schema::create/table/rename` و FK/indexهای صریح را نشان می‌دهد. عملیات شرطی، raw SQL، morph/polymorphic و وضعیت واقعی دیتابیس باید جداگانه runtime-verify شوند.

| Migration | Creates | Alters | Renames | Explicit FK columns | Unique declarations | Index declarations | Guard/notes |
|---|---|---|---|---|---|---|
| `2026_09_12_000001_create_users_table.php` | `users` | — | — | — | `current column` | — | — |
| `2026_09_12_000002_create_typing_documents_table.php` | `typing_documents` | — | — | `user_id → Laravel convention` | — | `current column` | — |
| `2026_09_12_000003_create_pricing_rules_table.php` | `pricing_rules` | — | — | — | `current column` | — | — |
| `2026_09_12_000004_create_editor_sessions_table.php` | `editor_sessions` | — | — | `user_id → Laravel convention` | `current column` | `current column` | — |
| `2026_09_12_000005_create_tickets_tables.php` | `tickets`, `ticket_messages` | — | — | `user_id → Laravel convention`<br>`ticket_id → Laravel convention`<br>`user_id → Laravel convention` | — | — | — |
| `2026_09_12_000006_create_otp_challenges_table.php` | `otp_challenges` | — | — | — | — | `current column`<br>`current column` | — |
| `2026_09_12_000007_create_audit_logs_table.php` | `audit_logs` | — | — | `user_id → Laravel convention` | — | — | — |
| `2026_09_12_000008_create_weekly_free_pages_table.php` | `weekly_free_pages` | — | — | `user_id → Laravel convention` | `['user_id','week_start']` | — | — |
| `2026_09_12_000009_create_sessions_table.php` | `sessions` | — | — | — | — | `current column`<br>`current column` | — |
| `2026_09_12_000010_create_cache_tables.php` | `cache`, `cache_locks` | — | — | — | — | — | — |
| `2026_09_12_000011_create_jobs_tables.php` | `jobs`, `job_batches`, `failed_jobs` | — | — | — | `current column` | `current column` | — |
| `2026_09_12_000012_add_email_to_users_table.php` | — | `users`, `users` | — | — | `current column` | — | — |
| `2026_09_12_000012_create_ai_interactions_and_feedback_tables.php` | `ai_interactions`, `ai_feedback` | — | — | `user_id → Laravel convention`<br>`document_id → typing_documents`<br>`user_id → Laravel convention`<br>`document_id → typing_documents`<br>`ai_interaction_id → ai_interactions` | — | `current column`<br>`current column`<br>`current column`<br>`current column`<br>`current column`<br>`current column` | — |
| `2026_09_12_000013_create_email_otp_challenges_table.php` | `email_otp_challenges` | — | — | — | — | `current column`<br>`current column` | — |
| `2026_09_13_000001_create_site_settings_table.php` | `site_settings` | — | — | — | `current column` | — | — |
