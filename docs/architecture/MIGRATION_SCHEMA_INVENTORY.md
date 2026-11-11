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

| `2026_09_13_000002_create_user_capabilities_table.php` | `user_capabilities` | — | — | `user_id → Laravel convention` | `current column` | — | — |
| `2026_09_13_000003_bootstrap_master_admin.php` | — | — | — | — | — | — | — |
| `2026_09_13_000004_create_announcements_table.php` | `announcements` | — | — | `created_by → users` | — | `current column` | — |
| `2026_09_13_000005_create_wallets_table.php` | `wallets` | — | — | `user_id → Laravel convention` | `current column` | — | — |
| `2026_09_13_000006_create_wallet_transactions_table.php` | `wallet_transactions` | — | — | `wallet_id → Laravel convention` | `current column` | `['reference_type','reference_id']` | — |
| `2026_09_13_000007_create_orders_table.php` | `orders` | — | — | `user_id → Laravel convention`<br>`document_id → typing_documents` | — | `['user_id','status']` | — |
| `2026_09_13_000008_create_payments_table.php` | `payments` | — | — | `order_id → Laravel convention` | — | `['gateway','authority']`<br>`'transaction_id'` | — |
| `2026_09_13_000009_create_free_credits_table.php` | `free_credits` | — | — | `user_id → Laravel convention` | `['mobile_hash','week_start']` | `['user_id','week_start']` | — |
| `2026_09_13_000010_add_content_hash_to_orders.php` | — | `orders`, `orders` | — | — | — | `current column` | — |
| `2026_09_13_000011_create_email_logs_table.php` | `email_logs` | — | — | `user_id → Laravel convention` | — | `current column`<br>`current column`<br>`current column` | — |
| `2026_09_13_000012_ensure_master_admin.php` | — | — | — | — | — | — | — |
| `2026_09_13_000013_create_store_catalog_tables.php` | `store_categories`, `store_products`, `store_product_files`, `store_product_previews`, `store_product_images`, `store_tags`, `store_product_tag`, `store_product_related`, `store_product_reviews`, `store_licenses` | — | — | `parent_id → store_categories`<br>`category_id → store_categories`<br>`seller_id → users`<br>`product_id → store_products`<br>`product_id → store_products`<br>`product_id → store_products`<br>`product_id → store_products`<br>`tag_id → store_tags`<br>`product_id → store_products`<br>`related_product_id → store_products`<br>`product_id → store_products`<br>`user_id → users`<br>`product_id → store_products` | `current column`<br>`current column`<br>`current column`<br>`current column`<br>`['product_id', 'user_id']`<br>`current column` | `['parent_id', 'is_active', 'sort_order']`<br>`['category_id', 'status', 'published_at']`<br>`['featured', 'status', 'sort_order']`<br>`['seller_id', 'status']`<br>`['product_id', 'is_active']`<br>`'sha256'`<br>`['product_id', 'is_active', 'sort_order']`<br>`['product_id', 'is_active', 'sort_order']`<br>`['product_id', 'status']` | — |
| `2026_09_13_000014_create_store_cart_order_library_tables.php` | `store_carts`, `store_cart_items`, `store_order_items`, `store_library_items`, `store_downloads` | — | — | `user_id → users`<br>`cart_id → store_carts`<br>`product_id → store_products`<br>`order_id → orders`<br>`product_id → store_products`<br>`user_id → users`<br>`product_id → store_products`<br>`order_id → orders`<br>`order_item_id → store_order_items`<br>`product_file_id → store_product_files`<br>`library_item_id → store_library_items`<br>`user_id → users`<br>`product_file_id → store_product_files` | `current column`<br>`current column`<br>`['cart_id', 'product_id']`<br>`['user_id', 'product_id', 'order_id']`<br>`current column` | `'expires_at'`<br>`'product_id'`<br>`['order_id', 'product_id']`<br>`['user_id', 'revoked_at']`<br>`['library_item_id', 'expires_at']`<br>`['user_id', 'created_at']` | — |
| `2026_09_14_000020_create_user_files_table.php` | `user_files` | — | — | `user_id → Laravel convention` | — | `['user_id', 'status']`<br>`'local_expires_at'`<br>`'remote_expires_at'` | — |
| `2026_09_15_000001_add_account_profile_fields_to_users.php` | — | `users`, `users` | — | — | — | — | — |
