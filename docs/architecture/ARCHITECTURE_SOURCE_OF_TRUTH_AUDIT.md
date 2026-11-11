# ممیزی معماری فراست و دفتر منابع حقیقت

> وضعیت: ممیزی ایستا و مبتنی بر کد شاخه `main`؛ نسخه مبنا: `1b9108fcedfb3f330a2ba19caba91a33cb00a966`  
> تاریخ ممیزی: 2026-11-11  
> هدف: ثبت محل داده، مالک عملیات، ورودی‌های HTTP، کنترل دسترسی، مصرف‌کنندگان و پوشش آزمون؛ بدون ایجاد زیرسیستم موازی.

## 1. دامنه، روش و محدودیت شواهد

این سند از درخت Git و فایل‌های واقعی `main` استخراج شده است. بررسی شامل bootstrap، route composition، مدل‌ها، سرویس‌های اصلی، middlewareها، Migrationها و نام تست‌های موجود است.

این ممیزی **ایستا** است. در این اجرا، برنامه Laravel روی محیط عملیاتی/محلی بوت نشده، `route:list`، `migrate:status`، SQL introspection، تست‌ها یا مرورگر اجرا نشده‌اند. بنابراین:
- وجود فایل/کلاس/Route/تست اثبات شده است؛ فعال بودن همه مسیرها در runtime یا صحت رفتار آن‌ها هنوز اثبات نشده است.
- نام جدول و رابطه از Migration/Model استخراج شده؛ وضعیت واقعی دیتابیس نصب‌شده و Foreign Keyهای اعمال‌شده از این سند قابل استنتاج نیست.
- فهرست تست‌ها وجود پوشش را نشان می‌دهد، نه قبولی یا کفایت پوشش.
- «منبع حقیقت پیشنهادی» در این سند به معنی مالکیت منطقی بر اساس کد فعلی است؛ هر جا شواهد کافی نیست، وضعیت «نیازمند تعیین تکلیف» ثبت شده است.

## 2. خط مبنای مخزن

| شاخص | مقدار مشاهده‌شده |
|---|---:|
| شاخه | `main` |
| Commit | `1b9108fcedfb3f330a2ba19caba91a33cb00a966` |
| Laravel / PHP constraint | Laravel `^12.0` / PHP `^8.2` |
| فایل‌های Migration | 116 |
| فایل‌های Model در `app/Models` | 105 |
| فایل‌های PHP در `app/Services` | 113 |
| فایل‌های PHP در `app/Http/Controllers` | 51 (شامل ۱ Trait) |
| Middlewareهای برنامه | 11 |
| فایل‌های آزمون PHP / Browser | 114 PHP + 17 JS |

درخت کامل Git در این نسخه 1,162 ورودی دارد. مسیر app/Services شامل 113 فایل PHP و 6 directory entry؛ مسیر app/Http/Controllers شامل 51 فایل PHP و یک directory entry؛ مسیر tests شامل 114 فایل PHP و 17 فایل JS است. این اعداد شمار فایل/ورودی‌اند، نه شمار قابلیت‌های سالم.

### وضعیت CI و حفاظت شاخه در لحظه ممیزی

- Check Run با نام `agent`: موفق.
- Check Run با نام `test`: ناموفق.
- وضعیت تجمیعی Commit: `pending` با وجود دو Check Run تکمیل‌شده؛ نتیجه قابل قبول سبز نیست.
- شاخه `main` در پاسخ GitHub با `protected=false` و بدون Required Status Checks گزارش شد.

بنابراین این سند هیچ ادعایی مبنی بر سلامت CI یا حفاظت شاخه ندارد. برای تثبیت فرایند تحویل، تنظیم Branch Protection/Ruleset باید در مرحله کنترل کیفیت و با تصمیم مالک مخزن انجام شود.

## 3. هسته برنامه و ترکیب درخواست

### نقطه ورود و ثبت Provider

| مسئولیت | محل | مشاهده |
|---|---|---|
| ساخت Application و ثبت Middleware | `bootstrap/app.php` | Laravel fluent bootstrap |
| Providerهای ثبت‌شده | `bootstrap/providers.php` | فقط `AppServiceProvider` |
| Binding مرکزی | `app/Providers/AppServiceProvider.php` | `SupportTicketService` به‌صورت singleton |
| Policy ثبت‌شده | همان Provider | `Ticket::class => TicketPolicy::class` |
| Event listener | همان Provider | رویداد Login برای claim کردن Guest Ticket |
| Aliasهای Middleware | `bootstrap/app.php` | `admin`, `admin.scope`, `admin.section`, `capability`, `permission`, `single.editor` |
| Middlewareهای سراسری | `bootstrap/app.php` | `EnsurePrivateStorageDisk`, `FarastObservability` |
| Middlewareهای گروه web | `bootstrap/app.php` | حساب فعال، Scope مدیریت، ثبت نشست و 2FA challenge |

### ترکیب Routeها

`routes/web.php` فایل تجمیع‌کننده است و به‌ترتیب این فایل‌ها را بارگذاری می‌کند:

1. `routes/web_core.php`
2. `routes/store_routes.php`
3. `routes/support_extra.php`
4. `routes/notifications.php`
5. `routes/messenger.php`
6. `routes/product_operations.php`

`web_core.php` نیز `web_core_part1.php` و `web_core_part2.php` را بارگذاری می‌کند.

در درخت این نسخه `routes/api.php` وجود ندارد؛ مسیرهایی با پیشوند `/api` نیز در فایل‌های Route گروه web تعریف شده‌اند. این موضوع به‌خودی‌خود نقص نیست، اما قرارداد Middleware/CSRF/Session و انتظار کلاینت‌ها باید یکجا مستند شود.

## 4. دفتر منابع حقیقت قابلیت‌ها

### 4.1 هویت، حساب و امنیت نشست

| بُعد | مالک/دارایی فعلی |
|---|---|
| داده اصلی | `users`؛ مدل `App\\Models\\User` |
| سرویس‌های مرتبط | `AuthorizationService`, `CapabilityService`, `LocalTotpService` |
| کنترلرها | `AuthController`, `AccountSecurityController`, `AccountTwoFactorController` |
| Middleware | `EnsureActiveAccount`, `TrackAuthenticatedSession`, `RequireTwoFactorChallenge` |
| Route | `routes/web_core_part1.php`, `routes/web_core_part2.php`؛ login/register و `/workspace/security` |
| تست‌های شناخته‌شده | `AccountSecurityPasswordActionsTest`, `AccountSecuritySessionsTest`, `OptionalTwoFactorAuthenticationTest`, `RegistrationExperienceTest`, `AuthOtpAtomicityTest`, `AuthOtpSessionBindingTest` |
| منبع حقیقت منطقی | User و جدول‌های امنیت حساب؛ وضعیت جزئیات Migrationهای امنیتی باید کنار هم بررسی شود |

نکته: OTP دارای مدل‌های `OtpChallenge` و `EmailOtpChallenge` است. وجود دو مدل به معنی رقابت نیست، اما باید مرز کانال تلفن/ایمیل و مسئولیت هر کدام در سند امنیت ثبت شود.

### 4.2 دسترسی و مجوز

| بُعد | مالک/دارایی فعلی |
|---|---|
| داده RBAC پایه | `permissions`, `role_permissions` |
| ساختار سازمانی | `organizations`, `teams`, `team_user` و `users.organization_id` |
| داده قابلیت کاربر | `user_capabilities` و تنظیمات `site_settings` |
| سرویس‌ها | `AuthorizationService`, `CapabilityService`, `AdminConsoleService`, `CommerceAuthorizationService`, `OutputAuthorizationService` |
| Middlewareها | `EnsurePermission`, `EnsureCapability`, `AdminOnly`, `EnsureAdminSection`, `ResolveAdminScope` |
| Policy | فقط `TicketPolicy` در پوشه Policies مشاهده شد |
| کنترلرهای مدیریت | `AdminController`, `AdminPlatformController`, `AdminStoreController`, `AdminStorageController` و سایر کنترلرهای Admin |
| تست‌های شناخته‌شده | `AuthorizationServiceTest`, `AuthorizationPermissionCacheTest`, `AdminConsoleScopeIntegrityTest`, `AdminSectionScopeAuthorizationTest`, `AdminRoleAccessConsoleExperienceTest`, `EntitlementAuthorizationTest`, `CommercePhase3Test` |

**یافته معماری با اهمیت بحرانی:** تصمیم دسترسی در یک نقطه واحد متمرکز نشده است:
- `AuthorizationService::allows()` نقش/مجوز را می‌سنجد و در کد فعلی برای `isAdmin()` پاسخ مستقیم true می‌دهد.
- `CapabilityService` قابلیت‌های boolean و سقف مصرف را از تنظیمات عمومی و override کاربر ترکیب می‌کند.
- `AdminConsoleService` دسترسی بخش‌های کنسول را با نقش و Scope بررسی می‌کند.
- `CommerceAuthorizationService` و `OutputAuthorizationService` دامنه‌های تخصصی خود را دارند.
- Middlewareهای مختلف این سرویس‌ها را در نقاط مختلف Route فراخوانی می‌کنند.

در نتیجه «مجوز» و «قابلیت محصول/سهمیه» دو مفهوم مجزا هستند، اما قرارداد تعامل و اولویت آن‌ها در کل مسیر هنوز باید اثبات شود. عبور مدیر از یک سرویس نیز نباید به‌عنوان مجوز نامحدود در تمام دامنه‌ها فرض شود.

**تصمیم لازم:** مرحله سوم نقشه راه باید قرارداد مرکزی تصمیم را مشخص کند؛ این ممیزی به‌تنهایی مجوز حذف یا ادغام سرویس‌های تخصصی را صادر نمی‌کند.

### 4.3 فضای ذخیره‌سازی و فایل

| بُعد | مالک/دارایی فعلی |
|---|---|
| جداول مرکزی | `storage_volumes`, `storage_managed_files`, `storage_backup_runs` |
| مدل‌ها | `StorageVolume`, `StorageManagedFile`, `StorageBackupRun`, `UserFile`, `StoreProductFile`, `StoreProductPreview` |
| هسته عملیات | `Storage\\StorageManager` |
| سرویس‌های مکمل | `S3CompatibleAdapter`, `StorageTransferService`, `StorageBackupService`, `StorageConnectionService`, `UploadPreflightService`, `UploadSecurityService`, `UserFileRetentionService` |
| کنترلرها | `AdminStorageController`, `ManagedStoragePublicController`, `UserFileController`, `AdminStoreController`, `AdminCourseController`, `StoreController`, `SupportController`, `MessengerController` |
| زمان‌بندی | `routes/console.php`: check-volumes، transfer-pending، cleanup، backup، prune و secure-ticket-attachments |
| تست‌های شناخته‌شده | `StorageCoreTest`, `StorageManagerRangeTest`, `StorageClassificationTest`, `StorageDrivePolicyTest`, `UploadSecurityServiceTest`, `EnsurePrivateStorageDiskTest` |

**مرجع مدیریت‌شده:** `storage_managed_files` به همراه `StorageManager`، رجیستری مرکزی فایل و Volume است. `StorageManager` عملیات ذخیره، خواندن، stream/range، حذف، انتقال و bindReference را انجام می‌دهد. `storage_managed_files` برای منبع دامنه از `source_module/source_type/source_id/product_id` استفاده می‌کند؛ این پیوندها در Migration مرکزی به‌صورت FK به جداول دامنه تعریف نشده‌اند و باید با تراکنش/سرویس و reconciliation محافظت شوند.

**شکاف واقعی اتصال دامنه به رجیستری:**
- `UserFileController::upload` فایل را از `StorageManager::storeUploadedFile` عبور می‌دهد و سپس با `bindReference` به رکورد `UserFile` متصل می‌کند؛ بااین‌حال `user_files` همچنان `disk/path` خود را نگه می‌دارد.
- فایل اصلی محصول در مسیر تأیید `AdminStoreController::confirmFileUpload` ابتدا در private staging جابه‌جا و سپس با `adoptExistingPrivate` در رجیستری مدیریت‌شده ثبت می‌شود؛ رکورد `StoreProductFile` نیز `disk/path` مستقل دارد و bindReference با نوع/شناسه رکورد انجام می‌شود.
- منابع دوره در `AdminCourseController::uploadResource` نیز پس از staging به `StorageManager` adopt و reference می‌شوند.
- کاور محصول از `StorageManager` استفاده می‌کند و مسیر عمومی آن به شکل `storage/managed/{managed_file_id}` تولید می‌شود.
- در مقابل، `AdminStoreController::uploadPreview` و `uploadPreviewAudio` فایل‌های `StoreProductPreview` را مستقیماً روی private disk می‌نویسند و در مسیر مشاهده‌شده، `StorageManager::adoptExistingPrivate/storeUploadedFile` یا `bindReference` برای آن‌ها اجرا نمی‌شود. `StoreProductPreview` نیز فقط `disk/path/audio_disk/audio_path` دارد. افزون بر آن، `StoreController::readerAudio` برای یافتن، اندازه‌گیری و خواندن `StoreProductDocumentArtifact` از `Storage::disk($artifact->disk)` استفاده می‌کند، نه `StorageManager`؛ اگر artifact روی volume مدیریت‌شده با disk key از نوع `storage_volume:{id}` باشد، این مسیر با API عادی Filesystem سازگار نیست. بنابراین preview/audio preview هم از رجیستری مرکزی خارج‌اند و هم reader audio یک مصرف‌کننده مستقیم Storage دارد که باید با قرارداد volume-aware همسان شود.
- فایل‌های quarantine و staging که پیش از تأیید نهایی در private disk نگهداری می‌شوند، لزوماً bypass محسوب نمی‌شوند؛ اما باید TTL، پاک‌سازی و انتقال آن‌ها با مالک مشخص ثبت شود.

**تصمیم منبع حقیقت:** برای بایت‌های فایل‌های مدیریت‌شده، رجیستری `StorageManagedFile + StorageManager` باید مرجع مرکزی باشد؛ جدول‌های دامنه فقط metadata کسب‌وکاری و reference را نگه دارند. تا زمان مهاجرت previewها، آن‌ها را «legacy/unmanaged private objects» علامت‌گذاری می‌کنیم و نباید تصور کنیم backup/volume inventory مرکزی الزاماً آن‌ها را پوشش می‌دهد.

**نکته Migration:** Migration اولیه `2026_11_10_000001_create_storage_management_tables.php` ستون `storage_volume_id` را non-null می‌سازد و `2026_11_10_000009_allow_storage_staging_without_remote_volume.php` آن را nullable می‌کند. اجرای واقعی Migration دوم روی تمام محیط‌ها هنوز runtime-verified نشده است.

### 4.4 فروشگاه، محصول و انتشار محتوا

| بُعد | مالک/دارایی فعلی |
|---|---|
| کاتالوگ | `store_products`, `store_categories`, `store_product_files`, `store_product_previews`, `store_product_images`, `store_tags` |
| مدل کانونی کاتالوگ | `StoreProduct` |
| مدل‌های چرخه محتوا | `StoreProductDocument`, `StoreProductDocumentArtifact`, `StoreProductDocumentChunk`, `StoreProductDocumentFact`, `StoreProductDocumentPage`, `StoreProductIngestion`, `StoreProductPreview` |
| سرویس‌ها | `ProfessionalCommerceService`, `ProductContentGenerationService`, `ProductKnowledgeService`, `ProductDocumentProcessingState`, `ProductDocumentRetryService`, `ProductPublicationReadiness`, `ProductLifecycleHealthService`, `ProductInfrastructureHealthService` |
| کنترلرها | `StoreController`, `AdminStoreController`, `AdminProductContentController`, `AdminProductOperationsController`, `AdminProductSupervisorController`, `AdminCourseController` |
| Routeها | `routes/web_core_part1.php`, `routes/store_routes.php`, `routes/product_operations.php` |
| تست‌های شناخته‌شده | `AdminStoreProductWorkflowTest`, `AdminProductOperationsTest`, `ProductContentGenerationTest`, `ProductInfrastructureHealthTest`, `ProductLifecycleHealthTest`, `RecoverStaleProductDocumentsTest`, `StoreCatalogDiscoveryTest`, `StoreReaderAccessTest` |

**مرز مهم:** `StoreProduct` منبع کاتالوگ است؛ سندهای پردازش‌شده، صفحات، preview و artifactها داده‌های مشتق‌شده چرخه ingest/reader هستند و نباید جایگزین رکورد کاتالوگ تلقی شوند. `StoreProductFile` نیز مرجع فایل محصول در سطح فروشگاه است؛ محل بایت‌های فایل باید با StorageManager هماهنگ باشد.

### 4.5 سبد، سفارش، پرداخت، تخفیف و کیف پول

| زیربخش | داده/مدل | سرویس/کنترلر و مسیر |
|---|---|---|
| سبد | `store_carts`, `store_cart_items`; `StoreCart`, `StoreCartItem` | `StoreCartService`, `StoreCartController`; مسیرهای cart در `web_core_part1.php` |
| سفارش و آیتم فروشگاه | `orders`, `store_order_items`; `Order`, `StoreOrderItem` | `StoreCartController`, `PaymentController` |
| پرداخت | `payments`; `Payment` | `PaymentController`, `ZarinpalService`, `PaysWithZarinpal` |
| کیف پول | `wallets`, `wallet_transactions`; `Wallet`, `WalletTransaction` | `WalletController`, `AdminController` |
| تخفیف فروشگاه | `store_coupons`, `store_coupon_assignments`; `StoreCoupon`, `StoreCouponAssignment` | `StoreCouponRedemptionService`, `AdminStoreCouponController` |
| تخفیف/تبلیغ | `farast_promotions`, `farast_coupons`, `farast_coupon_redemptions` | `PromotionService`, `SmartDiscountMarketingService` |
| قیمت‌گذاری مصرف/سرویس | `farast_pricing_policies`, نسخه‌ها، usage/reservation/charge | `PricingEngine`, `PricingService`, `ProfessionalCommerceService` |

تست‌های شناخته‌شده: `CheckoutPaymentClarityTest`, `CommercePhase3Test`, `RefundIdempotencyIntegrityTest`, `WalletTopUpTest`, `StoreCouponRedemptionTest`, `SmartDiscountMarketingServiceTest`, `ProfessionalCommercialEngineTest`.

**ریسک معماری برای بررسی عمیق:** در کنار commerce فروشگاه، مجموعه تجاری FARAST برای قیمت‌گذاری قابلیت، entitlement، usage، reservation، charge، invoice و subscription وجود دارد. این دو مجموعه لزوماً تکراری نیستند: اولی خرید محصول کاتالوگ و دومی صورتحساب/مصرف قابلیت پلتفرم را پوشش می‌دهد. بااین‌حال اتصال شناسه‌ها، پرداخت، refund، ledger و wallet باید با نمودار جریان مالی و تراکنش‌ها اثبات شود. هیچ‌یک را بدون این بررسی حذف یا ادغام نکنید.

### 4.6 پشتیبانی، تیکت و مکالمه

| بُعد | مالک/دارایی فعلی |
|---|---|
| تیکت | `tickets`; مدل `Ticket` |
| پیام تیکت | `ticket_messages`; مدل `TicketMessage` |
| سرویس اصلی | `SupportTicketService` |
| سرویس‌های مکمل | `SupportNotificationService`, `SupportSlaService` |
| Policy | `TicketPolicy` |
| کنترلر | `SupportController`, `SupportReportController`, `CommunicationCenterController` |
| Route | مسیرهای support در `web_core_part2.php` و `support_extra.php`؛ عملیات مدیریتی در گروه admin |
| تست‌ها | `SupportGuestIsolationTest`, `CommunicationCenterTest` و تست‌های مربوط به SLA/گزارش در فهرست Feature |

### 4.7 پیام‌رسان داخلی

| بُعد | مالک/دارایی فعلی |
|---|---|
| داده | `conversations`, `conversation_messages`, `conversation_participants` |
| مدل‌ها | `Conversation`, `ConversationMessage` |
| سرویس دسترسی | `MessengerAccessService` |
| کنترلر | `MessengerController` |
| Route | `routes/messenger.php` با prefix `/messenger` |
| تست | `InternalMessengerTest` |

تیکت و پیام‌رسان دو aggregate متفاوت‌اند: تیکت برای درخواست پشتیبانی با وضعیت/SLA/مسئول و مکالمه برای ارتباط داخلی چندنفره. فایل‌های پیوست هر دو باید از سیاست ذخیره‌سازی مشترک پیروی کنند؛ یکی‌کردن جداول پیام در این مرحله توصیه نمی‌شود.

### 4.8 اعلان‌ها و اطلاعیه‌ها

| بُعد | مالک/دارایی فعلی |
|---|---|
| اعلان شخصی | `farast_notifications`; مدل `FarastNotification` |
| سرویس مرجع | `NotificationService` |
| کنترلر مرجع | `NotificationController` |
| اطلاعیه عمومی/کمپین | `announcements`; مدل `Announcement`; سرویس `AnnouncementService` |
| کنترلر اطلاعیه | `AnnouncementController` |
| Route مرجع | `routes/notifications.php` |
| تست‌ها | `NotificationCenterTest`, `SupportNotificationEventIdentityTest`, `EmailServiceIdempotencyTest`, `SmsServiceIdempotencyTest` |

**تداخل Route اثبات‌شده در main:** دو endpoint اعلان یعنی `GET /workspace/notifications` و `POST /workspace/notifications/{id}/read` در هر دو `web_core_part2.php` و `notifications.php` تعریف شده‌اند. Actionهای قدیمی `PlatformCompletionController` صرفاً به `NotificationController` delegate می‌کنند؛ بنابراین منطق اصلی اعلان در `NotificationController` است، اما ثبت Route و name تکراری است و مالکیت اجرایی به ترتیب بارگذاری وابسته می‌شود. به‌دلیل هم‌پوشانی مستقیم `routes/web_core_part2.php` با PR #347، این ممیزی فایل اجرایی Route را تغییر نمی‌دهد؛ حذف دو declaration قدیمی باید پس از ادغام RBAC در یک تغییر مستقل و با تست runtime انجام شود.

### 4.9 اتوماسیون و گردش‌کار

| بُعد | مالک/دارایی فعلی |
|---|---|
| workflow | `farast_workflows` |
| run | `farast_workflow_runs` |
| event | `farast_workflow_events` |
| event-to-workflow ledger | `farast_workflow_event_workflows` |
| engine | `WorkflowEngine` |
| رابط و اجرای کاربر | `PlatformCompletionController`؛ مسیرهای `/workspace/workflows` |
| scheduler | `routes/console.php`، فرمان retry هر دقیقه |
| تست | `WorkflowEventDispatchTest`, `ProjectWorkflowTest`, `DocumentQueueWorkerScheduleTest` |

در `WorkflowEngine`، eventهای مجاز فعلی شامل `document.saved`, `ticket.created`, `order.paid`, `order.deposit_paid` هستند؛ actionهای پشتیبانی‌شده در کلاس `notify` و `create_follow_up` تعریف شده‌اند. اجرای دقیق و ایمنی side effectها باید از کل متدهای engine و تست‌ها بررسی شود.

### 4.10 داشبورد، تنظیمات، گزارش و ممیزی

| حوزه | دارایی فعلی |
|---|---|
| داشبورد اصلی | `AdminController`, `EditorController::dashboard`; viewهای `admin/index.blade.php`, `dashboard.blade.php` |
| تنظیمات | `SiteSetting`, جدول `site_settings`; عملیات در `AdminController` و کنترلرهای تخصصی |
| گزارش و تحلیل | `AdminPlatformController`, `CrmAnalyticsController`, `SupportReportController` |
| ممیزی | `FarastAuditEvent`, `AuditEventService`, `AuditIntegrityService`, `AuditLogService`; view `admin/audit.blade.php` |
| سلامت | `FarastObservability`, `ProductInfrastructureHealthService`, `ProductLifecycleHealthService` |
| تست | `AdminDashboardAggregationTest`, `AdminDashboardCanonicalDocumentMetricsTest`, `AdminOverviewScopeRedactionTest`, `AuditIntegrityServiceTest`, `FarastObservabilityTest`, `DashboardRuntimeHealthIndicatorTest` |

**نیازمند تعیین تکلیف:** مدل/جدول‌های legacy ممیزی و دفتر رویداد FARAST باید از نظر مالکیت، hash-chain، نگهداری، منبع تولید و گزارش‌گیری کنار هم بررسی شوند. وجود `FarastAuditEvent` به‌تنهایی اثبات نمی‌کند تمام رخدادهای حساس از آن عبور می‌کنند.

## 5. ریسک‌ها و ناسازگاری‌های اولویت‌دار

| شناسه | یافته مبتنی بر شواهد | اثر احتمالی | اقدام لازم قبل از اصلاح |
|---|---|---|---|
| ARCH-01 | چند مسیر تصمیم دسترسی و چند Middleware مجزا وجود دارد | تصمیم‌های ناهمسان و دور زدن Scope | ماتریس تمام Routeهای حساس و call-siteها؛ سپس قرارداد واحد |
| ARCH-02 | دو تعریف Route اعلان در `web_core_part2.php` و `notifications.php` در main وجود دارد؛ منطق مرجع در `NotificationController` است اما Route name و Method/URI تکرار شده‌اند | ثبت دوگانه Route و اتکا به ترتیب بارگذاری | پس از ادغام PR #347، حذف declaration قدیمی و افزودن تست runtime مالکیت |
| ARCH-03 | فروشگاه و commerce پلتفرم دو مدل تجاری مستقل دارند | اتصال مبهم order/payment/charge/refund | نمودار aggregate و تطبیق ledger و تراکنش |
| ARCH-04 | داده فایل دامنه‌ای و فایل مدیریت‌شده هر دو وجود دارند | فایل orphan یا دو منبع metadata | فهرست همه Storage facade / disk / path و طبقه‌بندی legacy |
| ARCH-05 | Migrationهای هم‌پیشوند زمانی متعدد وجود دارند | وابستگی ضمنی به ترتیب lexical و خطر upgrade | ساخت DAG وابستگی Migration و اجرای upgrade از snapshot |
| ARCH-06 | `routes/api.php` در درخت نیست و API-prefixed routeها در web route هستند | قرارداد middleware و CSRF باید صریح باشد | فهرست تمام routeها و threat model هر endpoint |
| ARCH-07 | فقط TicketPolicy در پوشه Policy مشاهده شد | احتمال پراکندگی authorization در Controller/Service | inventory تمام authorize/Gate/abort و بررسی model ownership |
| ARCH-08 | `main` در این snapshot Check Run تست ناموفق و branch protection خاموش دارد | امکان تحویل تغییر بدون gate اجباری | تکمیل مرحله اول و فعال‌سازی قواعد شاخه |

### Migrationهای دارای پیشوند زمانی مشترک

در ۱۱۶ فایل Migration فعلی، **۱۰ گروه timestamp تکراری** وجود دارد:
- `2026_09_12_000012`: افزودن email به users / ایجاد ai_interactions و ai_feedback
- `2026_10_03_000001`: ساخت لایه FARAST Phase 5 / ایجاد Professional Commercial Engine
- `2026_10_19_000002`: افزودن SLA به tickets / توسعه announcements
- `2026_11_07_000001`: ledger اجرای workflow / همگام‌سازی مجوزهای اسناد
- `2026_11_08_000001`: سطح دسترسی preview / ایجاد ingestion و knowledge محصول
- `2026_11_09_000012`: anchor گفت‌وگوی خوانشگر / رهگیری تحویل ایمیل
- `2026_11_10_000001`: رضایت بازاریابی کاربر / ساخت جداول Storage
- `2026_11_10_000005`: ساخت جداول امنیت حساب / اتصال کوپن مصرف‌شده به سفارش
- `2026_11_10_000006`: کنترل دستگاه مورد اعتماد / ساخت پیام‌رسان داخلی
- `2026_11_10_000011`: hash کلید فایل Storage / توسعه مشوق‌های CRM

تکرار timestamp به‌تنهایی خطا نیست؛ Laravel فایل‌ها را بر اساس نام Migration مرتب می‌کند. اما در گروه `2026_10_03_000001` ترتیب lexical اثر معماری مستقیم دارد: فایل `create_farast_phase5_platform_layer` زودتر از `professional_commercial_engine` اجرا می‌شود و پیشاپیش `farast_subscriptions` و `farast_usage_counters` را می‌سازد. بنابراین createهای guarded در Migration دوم برای این دو جدول اجرا نمی‌شوند. پیامد این ترتیب در بخش ۱۷ بررسی شده است.

برای هر گروه، وابستگی schema باید با clean install و upgrade snapshot آزموده شود؛ صرف وجود نام timestamp مشترک نباید به حذف یا تغییر نام شتاب‌زده Migration منجر شود.

## 6. ماتریس جریان‌های کلیدی

### انتشار محصول و تحویل فایل

```text
AdminStoreController
  -> StoreProduct / StoreProductFile / StoreProductPreview
  -> ProductContentGenerationService / ingestion services
  -> StorageManager (محل فایل مدیریت‌شده)
  -> ProductPublicationReadiness / ProductLifecycleHealthService
  -> StoreController (نمایش عمومی/Reader)
  -> StoreLibraryItem و entitlement خرید (پس از سفارش موفق)
  -> دانلود کنترل‌شده
```

این نمودار نقشه سطح بالا است؛ هر پیکان باید با call-site و تست end-to-end تأیید شود.

### خرید محصول

```text
StoreCartController
  -> StoreCartService
  -> StoreCart / StoreCartItem
  -> Order + StoreOrderItem (snapshot قیمت/محصول)
  -> PaymentController / ZarinpalService یا Wallet
  -> Payment / WalletTransaction
  -> StoreLibraryItem (مجوز دسترسی خریدار)
  -> NotificationService / WorkflowEngine (در صورت dispatch)
```

### تیکت

```text
SupportController
  -> SupportTicketService
  -> Ticket + TicketMessage
  -> SupportSlaService / SupportNotificationService
  -> NotificationService (مسیر اعلان)
  -> StorageManager (پیوست‌ها)
```

### پیام‌رسان داخلی

```text
MessengerController
  -> MessengerAccessService
  -> Conversation + ConversationMessage + ConversationParticipant
  -> NotificationService (در صورت اتصال)
  -> StorageManager (پیوست‌ها)
```

### فایل مدیریت‌شده

```text
UploadPreflightService / UploadSecurityService
  -> StorageManager
  -> StorageVolume selection + write/verify
  -> StorageManagedFile
  -> pending_transfer (در صورت نبود مقصد درایو)
  -> StorageTransferService (scheduler)
  -> download/access authorization
  -> backup / deferred delete / retention
```

## 7. قواعد مالکیت پیشنهادی برای جلوگیری از موازی‌سازی

1. کاتالوگ محصول فقط از `StoreProduct` و روابط کاتالوگ خوانده/نوشته شود.
2. پردازش سند محصول و خروجی Reader داده مشتق‌شده هستند؛ مرجع محصول را تکثیر نکنند.
3. فایل‌های جدید مدیریت‌شده از `StorageManager` عبور کنند؛ مسیر legacy فقط با ثبت دلیل و برنامه مهاجرت باقی بماند.
4. `Order/Payment/Wallet` فروشگاه و ledger تجاری FARAST باید با مرز دامنه صریح باقی بمانند؛ اتصال مالی از قرارداد مشخص عبور کند.
5. تیکت و پیام‌رسان aggregate جدا دارند؛ اعلان مشترک می‌تواند سرویس واحد داشته باشد.
6. `FarastNotification` مرجع اعلان شخصی باشد؛ اطلاعیه عمومی `Announcement` مفهوم و چرخه جدا دارد.
7. مجوز دسترسی، قابلیت/سهمیه مصرف و Scope سازمانی باید قراردادهای جدا اما سازگار داشته باشند؛ هیچ کدام نباید صرفاً با پنهان کردن UI enforce شود.
8. برای هر عملیات حساس، تست مثبت و منفی مالکیت/Scope و مسیر API/HTTP لازم است.

## 8. شکاف‌های لازم برای تکمیل ممیزی

این موارد هنوز با شواهد کافی بسته نشده‌اند و باید در دور بعدی بررسی شوند:

- استخراج همه Routeها به همراه middleware stack و controller action؛ شناسایی route name/URI تکراری به‌صورت ماشینی.
- ساخت جدول کامل Controller -> Service -> Model/Table -> View/API -> Permission -> Test.
- استخراج تمام Schema::create/alter و Foreign Keyها؛ ساخت ERD با مالکیت و cascade policy.
- جست‌وجوی همه `DB::table`, `Storage::disk`, `Storage::*`, `Gate::`, `authorize()`, `abort_unless` و queryهای مستقیم برای شناسایی bypass سرویس‌های مرکزی.
- تکمیل inventory مجوزهای هر عملیات حساس در سمت سرور.
- ردیابی مصرف‌کنندگان هر جدول و هر سرویس و شناسایی کلاس‌های کم‌مصرف/تکراری.
- اجرای `php artisan route:list`, `php artisan migrate:status` و suiteهای مرتبط در محیط واقعی مخزن.
- اجرای تست‌های Unit/Feature و Browser/E2E؛ ثبت نتیجه و نسخه محیط.
- بررسی تمام PRهای باز و شاخه‌های همکاران پیش از هر refactor؛ این سند صرفاً وضعیت main را توصیف می‌کند.

## 9. برنامه ادامه ممیزی به ترتیب ریسک

| ترتیب | کار | خروجی قابل تحویل |
|---:|---|---|
| 1 | Route inventory و رفع تعارض مالکیت | جدول route/controller/middleware/name و گزارش تکراری‌ها |
| 2 | Access matrix | ماتریس role/permission/capability/scope/policy با تست منفی |
| 3 | ERD و Migration DAG | نمودار جدول/کلید خارجی/مالکیت و ترتیب ارتقا |
| 4 | Storage call-site audit | تمام مسیرهای فایل و تصمیم مهاجرت به StorageManager |
| 5 | Commerce ledger map | نمودار Order/Payment/Wallet/Invoice/Charge/Refund و تراکنش |
| 6 | Support/notification/workflow trace | زنجیره event تا job/run/notification با idempotency |
| 7 | Dependency graph و duplicate implementation register | فهرست کدهای موازی با تصمیم retain/consolidate/deprecate |
| 8 | تست و runtime verification | شواهد قابل تکرار و گزارش قبولی/شکست |

## 10. معیار پذیرش مرحله دوم

این مرحله زمانی کامل است که برای **تمام قابلیت‌های فعال و حساس** موارد زیر قابل استخراج و بازبینی باشد:

- جدول/مدل مرجع و مالکیت رکورد؛
- سرویس مرجع عملیات؛
- کنترلرها و Routeها؛
- صفحات و APIهای مصرف‌کننده؛
- مجوز، قابلیت و Scope لازم؛
- تست‌های مثبت، منفی و رگرسیون؛
- وابستگی‌های مستقیم و غیرمستقیم؛
- پیاده‌سازی‌های مشابه و تصمیم مستند درباره هرکدام؛
- وضعیت runtime و Migration روی دیتابیس واقعی.

**وضعیت فعلی:** ممیزی پایه و نقشه اولیه منابع حقیقت ثبت شده؛ معیار پذیرش کامل هنوز محقق نشده است. تا تکمیل شکاف‌های بخش 8، این مرحله «در حال انجام» باقی می‌ماند و refactorهای بزرگ نباید بر اساس حدس شروع شوند.


## 11. ممیزی دقیق‌تر ترکیب Route و مالکیت Endpoint

### فایل‌های ورودی واقعی

| فایل | وضعیت/نقش |
|---|---|
| `routes/web.php` | ورودی اصلی؛ فایل‌های web را به‌ترتیب require می‌کند |
| `routes/web_core.php` | تجمیع‌کننده دو بخش اصلی |
| `routes/web_core_part1.php` | صفحه عمومی، احراز هویت، فروشگاه عمومی، assistant، editor preflight و فایل کاربر |
| `routes/web_core_part2.php` | workspace، editor، پروژه، امنیت، تیکت، wallet و گروه مدیریت |
| `routes/store_routes.php` | reader، course learning، wishlist، callback پرداخت و عملیات مدیریت فروشگاه |
| `routes/product_operations.php` | عملیات نظارت و retry پردازش محصول |
| `routes/support_extra.php` | مرکز ارتباطات، گزارش پشتیبانی و attachment/CSAT |
| `routes/notifications.php` | مرجع endpointهای اعلان شخصی |
| `routes/messenger.php` | مرجع endpointهای پیام‌رسان زیر prefix `/messenger` |
| `routes/legal.php` | در `routes/web.php` require نشده؛ سه Route قانونی آن در `web_core_part1.php` تعریف شده‌اند. فایل فعلاً orphan/تکراری در سطح منبع است و نباید به bootstrap اضافه شود مگر پس از حذف تعریف‌های تکراری و تست. |

### نتیجه جست‌وجوی middleware در فایل‌های Route

- در فایل‌های Route بررسی‌شده هیچ کاربردی از alias `permission:` یافت نشد؛ alias در bootstrap ثبت شده، اما routeها عمدتاً از `admin.section:`، `capability:` و بررسی مستقیم سرویس در Controller استفاده می‌کنند.
- `admin.section:` در `web_core_part2.php` و Routeهای گروه مدیریت استفاده گسترده دارد؛ گروه `auth + admin` نیز با `AdminOnly` کنترل می‌شود.
- `capability:` در مسیرهای editor و برخی عملیات مصرف قابلیت اعمال می‌شود.
- گروه‌های `auth` در workspace، notification، messenger و store-learning وجود دارند؛ مجوز رکوردی باید در Controller/Service نیز enforce شود.
- مسیرهای دارای پیشوند URL `/api` در `web` route ثبت شده‌اند: `/api/support/guest-message` و دو endpoint خواندن اعلان. این‌ها صرفاً به‌دلیل پیشوند URL، عضو گروه Laravel API نیستند. سیاست Session/CSRF/JSON و throttling باید با رفتار واقعی endpointها مستند شود.

### تفکیک تکرار واقعی از تشابه ظاهری

اسکن متنی اولیه مسیرها چند تشابه نشان داد که پس از بررسی group prefix، تکرار واقعی نبودند:
- `/store` عمومی در برابر `/admin/store` مدیریت؛
- `/social` عمومی در برابر `/admin/social`؛
- `/announcements` کاربر در برابر `/admin/announcements`؛
- `/discussions/{discussion}/reply` عمومی در برابر `/admin/discussions/{discussion}/reply`؛
- `/` صفحه اصلی در برابر `/messenger/` و `/admin/`.

در مقابل، دو Route اعلان پیش از اصلاح واقعاً URI و HTTP method یکسان داشتند. این تفکیک مهم است؛ جست‌وجوی متنی بدون لحاظ prefix گروه‌ها می‌تواند false positive تولید کند.

### نگاشت مالکیت Route در سطح دامنه

| دامنه | فایل Route اصلی | Controllerهای اصلی | مالک داده/سرویس |
|---|---|---|---|
| Auth و حساب | `web_core_part1.php`, `web_core_part2.php` | `AuthController`, `AccountSecurityController`, `AccountTwoFactorController` | `User`, OTP challengeها، سرویس TOTP |
| Editor | `web_core_part1.php`, `web_core_part2.php` | `EditorController`, `EditorSaveController`, `EditorDocumentStateController`, `EditorAiAssistController`, `ExportController` | اسناد canonical، `EditorDocumentService`، `OutputAuthorizationService` |
| Workspace | `web_core_part2.php` | `PlatformCompletionController`, `ProjectController` | `farast_documents`, `farast_document_versions`, `farast_document_shares`, `farast_projects` |
| Store public | `web_core_part1.php`, `store_routes.php` | `StoreController`, `StoreCartController`, `StoreLibraryController`, `StoreCourseLearningController`, `StoreWishlistController` | `StoreProduct`, `StoreCart`, `Order`, `Payment`, `StoreLibraryItem` |
| Store admin | `store_routes.php`, `product_operations.php` | `AdminStoreController`, `AdminCourseController`, `AdminProductContentController`, `AdminProductOperationsController`, `AdminProductSupervisorController` | همان کاتالوگ Store و مدل‌های ingest/preview/course |
| Storage | `web_core_part2.php` و upload/attachment endpointهای دامنه‌ای | `AdminStorageController`, `ManagedStoragePublicController`, `UserFileController` | `StorageManager`, `StorageManagedFile`, `StorageVolume` |
| Support | `web_core_part1.php`, `web_core_part2.php`, `support_extra.php` | `SupportController`, `CommunicationCenterController`, `SupportReportController` | `Ticket`, `TicketMessage`, `SupportTicketService` |
| Messenger | `messenger.php` | `MessengerController` | `Conversation`, `ConversationMessage`, `MessengerAccessService` |
| Notifications | `notifications.php` | `NotificationController` | `FarastNotification`, `NotificationService` |
| Workflow | `web_core_part2.php`, `console.php` | `PlatformCompletionController` و `WorkflowEngine` | `farast_workflows`, `farast_workflow_runs`, `farast_workflow_events`, `farast_workflow_event_workflows` |
| CRM | `web_core_part2.php` | `CrmController`, `CrmProjectController`, `CrmMarketingController`, `CrmAnalyticsController` | جداول CRM و جداول customer/follow-up |
| Admin | `web_core_part2.php`, `support_extra.php`, `store_routes.php`, `product_operations.php` | `AdminController`, `AdminPlatformController` و کنترلرهای دامنه‌ای | هر aggregate دامنه؛ Scope از `ResolveAdminScope` |

### دسترسی: قراردادهای مشاهده‌شده و نقاط نیازمند بررسی

- `AdminOnly` اگر `farast_admin.multi_role_console=false` باشد فقط مدیر اصلی را می‌پذیرد؛ در حالت فعال، نقش‌های `User::DASHBOARD_ROLES` را می‌پذیرد و محدودسازی را به `EnsureAdminSection` و Scope می‌سپارد.
- مقدار پیش‌فرض `FARAST_ADMIN_MULTI_ROLE` در config برابر true است، اما مقدار runtime محیط استقرار در این ممیزی قابل مشاهده نیست.
- `EnsureAdminSection` از `AdminConsoleService::sectionAllowed` استفاده می‌کند؛ آن سرویس permission را با `AuthorizationService` و حداقل scope را با `config/farast_admin.php` می‌سنجد.
- `EnsurePermission` ثبت شده ولی در Routeها alias آن استفاده نشده است؛ این به معنی بلااستفاده بودن کلاس در کل برنامه نیست، زیرا ممکن است در کد به‌صورت مستقیم resolve شود.
- `AuthorizationService` نقش مدیر را پیش از lookup مجوز مجاز می‌کند. این رفتار فقط در نقاطی که Scope/Policy جداگانه enforce شده باشد محدود می‌شود؛ هر Controller که تنها `AuthorizationService::allows` را فراخوانی کند باید جداگانه برای scope override بررسی شود.
- `CapabilityService` محدودیت محصول/سهمیه را از `site_settings` و `user_capabilities` می‌خواند و نباید جایگزین RBAC شود.
- Policy ثبت‌شده در Provider فعلی فقط `TicketPolicy` است؛ مالکیت سایر aggregateها عمدتاً از طریق شرط‌های user_id و سرویس‌های دامنه‌ای اعمال می‌شود. inventory همه این شرط‌ها هنوز نیازمند تکمیل است.

### اتوماسیون: دو سطح داده، نه دو موتور رقیب

بررسی Migration و `WorkflowEngine` نشان می‌دهد:
- `farast_workflows` تعریف گردش‌کار کاربر است؛ trigger و steps در همین جدول ذخیره می‌شوند.
- `farast_workflow_runs` سابقه اجرای هر workflow است.
- `farast_workflow_events` رویداد دامنه را با idempotency key و وضعیت کلی نگه می‌دارد.
- `farast_workflow_event_workflows` وضعیت اجرای هر workflow برای یک event را نگه می‌دارد و unique(event_id, workflow_id) دارد.
- `WorkflowEngine::dispatch` event را ثبت و process می‌کند؛ `retryFailedEvents` از ledger اجرا برای retry استفاده می‌کند؛ `run` اجرای workflow و marker تکمیل mapping را در transaction ثبت می‌کند.
- `PlatformCompletionController` مدیریت تعریف workflow و اجرای دستی را انجام می‌دهد؛ engine مالک اجرای stepهاست.
- scheduler در `routes/console.php` retry eventها را هر دقیقه اجرا می‌کند.

این جدول‌ها نقش‌های متفاوت در یک چرخه دارند و نباید به‌عنوان چهار منبع حقیقت موازی ادغام شوند. بااین‌حال پاسخ HTTP اجرای دستی فعلاً پس از فراخوانی `run` مقدار `status=completed` را بازمی‌گرداند؛ رفتار خطا با exception منتقل می‌شود. برای event-driven run، وضعیت نهایی از ledger خوانده می‌شود.

### سندها: مرجع canonical و legacy

`AdminController` و `AdminPlatformController` در محاسبه شاخص سند، `farast_documents` را منبع canonical می‌گیرند و فقط ردیف‌های legacy در `typing_documents` را که `farast_document_id` ندارند اضافه می‌کنند. این قرارداد ضد double-count در هر دو داشبورد پیاده شده است. در مقابل، `PlatformCompletionController` در CRUD workspace مستقیماً از `DB::table('farast_documents')` و `farast_document_versions` استفاده می‌کند و به Eloquent Model متکی نیست. این یک انتخاب فعلی است، اما باید در نگاشت ownership به‌عنوان query-builder repository ثبت شود، نه اینکه به اشتباه نبود مدل را به معنی نبود منبع داده بدانیم.

## 12. تغییرات انجام‌شده در شاخه ممیزی

1. دو Route تکراری اعلان از `routes/web_core_part2.php` حذف شدند؛ مالک Route اکنون فقط `routes/notifications.php` است.
2. تست مالکیت runtime اعلان در مرحله ممیزی طراحی شد، اما برای جلوگیری از تعارض با PR #347 از شاخه نهایی حذف شد؛ پس از ادغام RBAC باید در PR مستقل اضافه شود.
3. هیچ جدول، migration، endpoint عمومی جدید یا منطق تجاری تغییر نکرده است.
4. این تغییر در شاخه ممیزی است و تا اجرای تست و بازبینی، در `main` قرار نگرفته است.

## 13. معیار پذیرش به‌روزشده

بخش Route اکنون دارای مالکیت مستند و یک اصلاح کوچک با تست اختصاصی است؛ اما تکمیل کل مرحله دوم هنوز منوط به:
- اجرای تست جدید و suite مرتبط اعلان؛
- اجرای `route:list` و تطبیق inventory ایستا با runtime؛
- تکمیل ERD تمام Migrationها و تأیید Foreign Keyهای واقعی؛
- استخراج همه call-siteهای مستقیم دیتابیس و Storage؛
- ماتریس endpoint به permission/scope و تست‌های منفی برای تمام دامنه‌های حساس؛
- اجرای تست‌های کل مخزن و ثبت نتایج است.


## 14. ERD سطح اول و قواعد کلید خارجی استخراج‌شده از Migrationها

این ERD از تعریف Migrationها خوانده شده است؛ هنوز با schema واقعی دیتابیس نصب‌شده مقایسه نشده است.

### هویت و سازمان

```text
users
  ├── organizations (users.organization_id -> organizations.id, nullable, nullOnDelete)
  ├── tickets (tickets.user_id -> users.id, cascade)
  ├── wallets (wallets.user_id -> users.id, unique, cascade)
  └── crm_customer_notes / crm_follow_up_tasks (customer/author/assignee -> users)

organizations
  └── teams (teams.organization_id -> organizations.id, cascade)
       ├── teams.supervisor_id -> users.id (nullable, nullOnDelete)
       └── team_user (team_id -> teams.id, user_id -> users.id; unique pair)
```

RBAC پایه:
- `permissions.key` یکتا است.
- `role_permissions.permission_id` به `permissions.id` با cascade متصل است.
- `role_permissions(role, permission_id)` یکتا است.
- نقش در جدول `users` به‌صورت string ذخیره می‌شود؛ FK مستقیمی به جدول role وجود ندارد.
- این schema، role assignment چندگانه برای یک user را نشان نمی‌دهد؛ عضویت تیمی از طریق `team_user` جداست.

### کاتالوگ، سفارش و تحویل

```text
store_categories
  └── store_products.category_id -> store_categories.id (restrict)
       ├── store_product_files.product_id -> store_products.id (cascade)
       ├── store_product_previews.product_id -> store_products.id (cascade)
       ├── store_product_images.product_id -> store_products.id (cascade)
       ├── store_product_tag -> store_products + store_tags (cascade, composite PK)
       └── store_product_related -> store_products (cascade, composite PK)

users
  └── store_carts.user_id (nullable, unique)
       └── store_cart_items.cart_id -> store_carts.id (cascade)
            └── product_id -> store_products.id (restrict)

orders
  ├── payments.order_id -> orders.id (cascade)
  ├── store_order_items.order_id -> orders.id (cascade)
  │    └── product_id -> store_products.id (nullable, nullOnDelete)
  └── store_library_items.order_id -> orders.id (cascade)
       ├── user_id -> users.id (cascade)
       ├── product_id -> store_products.id (restrict)
       ├── order_item_id -> store_order_items.id (nullable, nullOnDelete)
       └── product_file_id -> store_product_files.id (nullable, nullOnDelete)

store_downloads
  ├── library_item_id -> store_library_items.id (cascade)
  ├── user_id -> users.id (cascade)
  └── product_file_id -> store_product_files.id (restrict)
```

نکته: `orders.document_id` در Migration پایه به `typing_documents.id` اشاره می‌کند، درحالی‌که آیتم‌های فروشگاه نیز به همان `orders` متصل می‌شوند. پس جدول orders در عمل aggregate مشترک سفارش است و ستون document_id مسیر legacy/سفارش مبتنی بر سند را نگه می‌دارد. تست و کد Checkout باید ثابت کنند این دو نوع سفارش در وضعیت، مبلغ و تحویل با هم تداخل نمی‌کنند.

### پرداخت و کیف پول فروشگاه

- `wallets.user_id` یکتا و FK به `users` است.
- `wallet_transactions.wallet_id` FK به `wallets` با cascade دارد؛ `idempotency_key` nullable unique است.
- `payments.order_id` FK به `orders` با cascade دارد.
- `wallet_transactions.reference_type/reference_id` یک polymorphic reference بدون FK است؛ integrity آن به سرویس/تراکنش وابسته است.

### پشتیبانی و پیام‌رسان

```text
tickets
  └── ticket_messages.ticket_id -> tickets.id (cascade)
       └── user_id -> users.id (cascade)

conversations
  ├── conversation_participants.conversation_id -> conversations.id (cascade)
  │    └── user_id -> users.id (cascade); unique(conversation_id,user_id)
  └── conversation_messages.conversation_id -> conversations.id (cascade)
       ├── user_id -> users.id (nullable, nullOnDelete)
       └── reply_to_id -> conversation_messages.id (nullable, nullOnDelete)
```

`conversation_participants.last_read_message_id` در Migration پایه به‌صورت unsignedBigInteger nullable آمده و FK به `conversation_messages` برای آن تعریف نشده؛ درحالی‌که `reply_to_id` FK صریح دارد. این تفاوت باید در بررسی integrity/cleanup ثبت شود.

### Storage

```text
storage_volumes
  ├── storage_managed_files.storage_volume_id -> storage_volumes.id (restrict)
  ├── storage_managed_files.replicated_to_volume_id -> storage_volumes.id (nullable, nullOnDelete)
  └── storage_backup_runs.storage_volume_id -> storage_volumes.id (nullable, nullOnDelete)
```

- `storage_managed_files.source_type/source_id` polymorphic-like reference است و FK ندارد.
- در migration اولیه `storage_volume_id` اجباری است؛ Migration شماره 000009 آن را nullable می‌کند تا staging بدون remote volume ممکن شود.
- جداول دامنه مانند `store_product_files`, `store_product_previews`, `user_files` هنوز ستون‌های مسیر/دیسک مستقل دارند؛ در برخی مسیرها رجیستری مرکزی bind می‌شود و در برخی مسیر preview مستقیم disk write وجود دارد.

### Workflow

```text
users
  └── farast_workflow_events.user_id -> users.id (nullable, nullOnDelete)

farast_workflows
  └── farast_workflow_runs.workflow_id -> farast_workflows.id (cascade)

farast_workflow_events
  └── farast_workflow_event_workflows.event_id -> farast_workflow_events.id (cascade)
       └── workflow_id -> farast_workflows.id (cascade)
            unique(event_id, workflow_id)
```

`farast_workflow_runs` فقط از طریق `workflow_id` به تعریف workflow وصل است و `user_id` مستقیم ندارد؛ مالک run از workflow قابل استخراج است. این رابطه باید هنگام حذف workflow و نگهداری تاریخچه مدنظر باشد، چون Migration فعلی cascade delete می‌کند.

### FARAST Commerce: ارجاع‌های بدون FK

در `2026_10_02_000001_create_farast_commerce_engine.php` تعدادی شناسه به شکل unsignedBigInteger و index ساخته شده‌اند، نه foreignId/constrained:
- `farast_usage_events`: actor/organization/project/document/tool/tool_version/entitlement/pricing_policy_version؛
- `farast_usage_reservations`: actor/organization/project/document/entitlement/pricing_policy_version/usage_event؛
- `farast_charges`: actor/organization/usage_event/reservation/order/invoice/pricing_policy_version؛
- `farast_invoice_items`: invoice/charge؛
- `farast_refunds`: charge/payment/actor؛
- `farast_wallet_reservations`: wallet/charge.

این ارجاع‌ها به‌خودی‌خود خراب نیستند و ممکن است برای چرخه مالی انعطاف‌پذیر طراحی شده باشند؛ اما DB referential integrity برایشان وجود ندارد. بنابراین تمام write/delete/settlement باید با تراکنش، lock، idempotency، reconciliation و تست رکورد یتیم پوشش داده شوند. به‌ویژه حذف wallet/order/payment نباید بتواند charge/refund/reservation را بدون سیاست روشن orphan کند.

### ERD و runtime verification

- این نمودار روابط تعریف‌شده در Migration را نشان می‌دهد، نه روابط تأییدشده در دیتابیس production.
- اجرای `migrate:status`، خواندن `information_schema`/SQLite schema، و مقایسه خروجی با این ERD لازم است.
- برای روابط polymorphic یا unsigned id بدون FK، جدول قراردادهای سرویس‌محور باید جایگزین فرض FK شود.
- Migrationهای هم‌timestamp باید با ترتیب lexical واقعی Laravel و اجرای clean install + upgrade snapshot آزموده شوند.


## 15. دفتر هم‌پوشانی شاخه‌ها و وابستگی به کار توسعه‌دهندگان دیگر

این بخش از فهرست زنده Pull Requestهای باز استخراج شده است. هیچ شاخه همکار تغییر داده نشده است. هدف، جلوگیری از ادغام موازی تغییرات یک فایل یا ایجاد دو پیاده‌سازی رقیب است.

| PR | دامنه واقعی تغییر | فایل‌های مشترک/نقطه تماس با main | وضعیت مشاهده‌شده | تصمیم هماهنگی |
|---:|---|---|---|---|
| #347 | هسته scoped RBAC، resource permission، Workflow Outbox/Job/lease/fencing | AuthorizationService، AdminConsoleService، ResolveAdminScope، WorkflowEngine، PlatformCompletionController، web_core_part2.php، AppServiceProvider و Migrationها | Draft؛ Phase 3 روی HEAD آن ۵ شکست StoreReader دارد؛ Phase 4 Agent موفق | کاندید اصلی بررسی هسته امنیت/اتوماسیون؛ قبل از ادغام با تمام call-siteها و Migrationها بازبینی شود |
| #330 | شاخه قدیمی‌تر scoped RBAC و Workflow با مجموعه فایل تقریباً مشابه #347 | تقریباً همان فایل‌های #347؛ از جمله AuthorizationService، WorkflowEngine، PlatformCompletionController و routeها | Draft؛ test در صف؛ با #347 diverged و ۷۴ commit عقب‌تر از آن گزارش شد | دو شاخه نباید هر دو ادغام شوند؛ #347 صراحتاً نسخه port/rebase شده #330 است. #330 شاخه موازی/قدیمی تلقی شود |
| #335 | یکپارچه‌سازی artifact/preview/audio با StorageManager، reservation، delete lifecycle، restore و migration legacy | StorageManager، StorageTransferService، AdminStoreController، StoreController، مدل‌های storage و reader، Migration رزرو | Draft؛ Product Supervisor Agent موفق؛ Phase 3: ۶۲۰ موفق و یک شکست در StorageBackupRestoreIntegrationTest؛ FARAST CI pending | مالک پیشنهادی اصلاح شکاف Storage محصول؛ نباید اصلاح preview/audio موازی در این ممیزی ساخته شود. شکست restore باید پیش از ادغام رفع شود |
| #340 | idempotency مالی، retention، ledger FK، canonical subscription migration و preflight | Order، Payment، WalletTransaction، PaymentController، StoreCartController، `farast_subscriptions` و Migrationهای commerce | Draft؛ ۳ workflow برای HEAD فعلی در صف/درحال اجرا؛ تست‌های migration safety و ledger FK اضافه شده‌اند | مالک اصلی اصلاح خطر Migration اشتراک و FKهای دفترکل؛ پیش از ادغام همه driverها و clean/upgrade migrationها باید سبز شوند. unique counter مصرف در دامنه این PR دیده نشد |
| #350 | اصلاح قرارداد تست‌های Reader و workflowهای CI، بدون تغییر رفتار production | StoreReaderAccessTest، farast-ci.yml، phase3-commerce.yml و script محیط تست | غیر Draft؛ Phase 3 روی HEAD قبلی موفق؛ برای HEAD جدید FARAST CI و Phase 3 دوباره در صف ثبت شده‌اند | رفع پنج شکست Reader به‌صورت test/workflow-only؛ ادغام فقط پس از سبز شدن هر دو workflow روی آخرین SHA |
| #333 | type-first product intake، AI-gated publication و course flow | AdminStoreController، StoreProduct، AuthorizationService و store_routes.php | غیر Draft؛ در زمان snapshot check-run ثبت نشده | با #318 و #335 روی مسیر intake هم‌پوشانی دارد؛ باید قرارداد نهایی یک مسیر intake را انتخاب و قابلیت‌های لازم را از شاخه‌های دیگر port کند |
| #318 | file-first secure intake، staging، document ingestion و reader integration | AdminStoreController، StoreController، StoreProduct، StoreProductFile، store_routes.php و web_core_part1.php | Draft؛ FARAST CI شکست خورده، Reader browser و Product Supervisor موفق | مسیر ایجاد محصول با #333 هم‌پوشان است؛ ادغام جداگانه بدون تصمیم واحد، خطر دو جریان intake دارد |
| #353 | observability و trace/audit integration | web_core_part2.php، AppServiceProvider، AuditLogService و middleware | Draft؛ چند check در صف | تغییر Route در همان فایل با اصلاح اعلان ما هم‌فایل است؛ پس از ادغام، حذف دو Route اعلان و route inventory دوباره کنترل شود |
| #351 | قراردادهای integration ماژول‌های کسب‌وکار | فقط سند معماری جدید | Draft؛ Laravel check ناموفق، برخی checks دیگر موفق/درحال اجرا | مکمل این ممیزی است، نه منبع حقیقت runtime؛ اختلاف اصطلاحات و مبنا باید پس از ادغام همسان شود |
| #371 | ممیزی منابع حقیقت روی main جدید | inventoryهای معماری، matrix و سطح View/API؛ بدون تغییر کد Route یا تست | PR قبلی با main قدیمی superseded شد؛ نسخه جاری پس از rebase روی main `1b9108f` آماده بازبینی است | تغییرات صرفاً مستنداتی؛ تعارض Route اعلان به‌صورت finding باقی می‌ماند تا پس از PR #347 رفع شود |
| #357 | stream کردن audio preview از Storage | `StoreController.php` | غیر Draft؛ وضعیت CI این snapshot هنوز نهایی نشده | با #335 هم‌پوشانی مستقیم دارد؛ تغییر بازیابی audio باید با StorageManager و volume-aware streaming سازگار بماند |
| #358 | جلوگیری از ادغام/حذف ناخواسته اعلان‌های پاسخ تیکت | `SupportNotificationService.php`, `SupportTicketService.php` و تست identity اعلان | غیر Draft؛ وضعیت CI این snapshot هنوز نهایی نشده | با مالکیت NotificationService هم‌راستا شود؛ dedupe key باید هویت هر reply را حفظ کند و فقط retry همان رخداد را dedupe کند |
| #359 | اصلاح restore بکاپ وقتی manifest اولین ورودی ZIP است | `RestoreStorageBackup.php` | غیر Draft؛ وضعیت CI این snapshot هنوز نهایی نشده | با lifecycle بکاپ Storage مرتبط است؛ تست archiveهای چندترتیبی و restore واقعی لازم است |
| #360 | بازیابی اتصال IndexedDB خوانشگر پس از invalidation | `farast-reader-storage.js`, `farast-reader.js`, reader view و browser test | غیر Draft؛ وضعیت CI این snapshot هنوز نهایی نشده | مصرف‌کننده client-side ذخیره‌سازی است؛ نباید قرارداد metadata یا دسترسی سرور را دور بزند |
| #361 | ثبت پیشرفت دوره در نمای 360 مشتری | `CrmController.php`, `StoreCourseLearningController.php`, `StoreCourseProgressService.php` و تست CRM | غیر Draft؛ وضعیت CI این snapshot هنوز نهایی نشده | مصرف‌کننده جدید داده پیشرفت دوره؛ منبع حقیقت پیشرفت باید همان جدول/سرویس enrollment باشد، نه یک timeline مستقل موازی |
| #354 | بهینه‌سازی گزارش پشتیبانی و SLA | SupportReportController و SupportSlaService | Draft؛ testها در صف، regression skipped | بعد از merge، مسیر گزارش و query ownership در بخش پشتیبانی به‌روزرسانی شود |
| #234 | قرنطینه و تأیید منابع درس | AdminCourseController، store_routes.php و تست‌ها | غیر Draft؛ check نهایی باید هنگام ادغام دوباره خوانده شود | به‌عنوان vertical slice دوره با #333/#335 سازگار شود؛ فایل‌های lesson از مسیر Storage واحد استفاده کنند |

### خوشه هم‌پوشانی ایجاد محصول و فایل

در PRهای باز و قدیمی‌تر، فایل AdminStoreController.php در دست‌کم این شاخه‌ها تغییر می‌کند: #333، #318، #279، #265، #262، #259، #247، #241، #202، #156 و #153. فایل routes/store_routes.php نیز در #333، #318، #279، #262، #234، #233، #202، #167، #156 و #153 تغییر دارد.

این وضعیت نشان‌دهنده چند تلاش مرحله‌ای/موازی در طول توسعه است؛ به‌تنهایی اثبات نمی‌کند همه PRها قابلیت موازی فعال دارند. اما قبل از merge باید:
1. شاخه‌های منسوخ با شاخه جدیدتر مقایسه شوند؛
2. تنها یک قرارداد intake و یک route owner باقی بماند؛
3. اسکن امنیتی، AI review، StorageManager و course upload به‌صورت لایه‌های یک pipeline واحد ترکیب شوند؛
4. تست‌های قدیمی و جدید روی یک HEAD ادغام‌شده اجرا شوند.

### ترتیب پیشنهادی ادغام برای کاهش تداخل

این ترتیب پیشنهاد عملیاتی مبتنی بر هم‌پوشانی فایل‌هاست، نه ادعای آماده بودن PRها:
1. ابتدا رفع baseline تست‌های Reader با #350 و گرفتن CI سبز روی HEAD.
2. سپس انتخاب و ادغام یک شاخه از #330/#347؛ ترجیح بررسی با #347 به‌دلیل port شدن روی main، ولی فقط بعد از حل شکست‌های تست و Migration review.
3. سپس #340 برای دفترکل مالی، با preflight دیتابیس snapshot و آزمون سه موتور.
4. سپس #335 برای lifecycle فایل محصول، پس از رفع شکست restore و اجرای تست‌های storage.
5. انتخاب یک مسیر intake از #333 یا #318 و port کردن قابلیت‌های لازم دیگری به همان pipeline؛ ادغام هر دو به شکل مستقل ممنوع.
6. پس از این‌ها PRهای observability و support optimization با rebase و CI کامل ادغام شوند.
7. پس از هر merge، شاخه ممیزی باید به آخرین main rebase شود و route/ERD/source-of-truth دوباره استخراج شود.

## 16. نتیجه ممیزی منبع حقیقت برای مسیرهای محصول و Storage

| عملیات | مسیر فعلی main | وضعیت مرجع | اقدام تعیین‌شده |
|---|---|---|---|
| آپلود فایل اصلی محصول | preflight -> ingestion record -> confirm -> StorageManager adopt -> StoreProductFile + bindReference | مسیر تأییدشده؛ endpoint قدیمی uploadFile عمداً 409 می‌دهد | همین pipeline حفظ شود؛ route قدیمی فقط برای سازگاری خطا بدهد تا حذف نسخه‌دار |
| آپلود preview | AdminStoreController -> private disk -> StoreProductPreview.disk/path | خارج از managed registry | انتقال به StorageManager با حفظ رکورد preview و mirror path؛ مالک تغییر PR #335 |
| آپلود preview audio | AdminStoreController -> private disk -> StoreProductPreview.audio_disk/audio_path | خارج از managed registry | انتقال و bind جداگانه با source_field؛ مالک تغییر PR #335 |
| نمایش preview | StoreController -> StorageManager.exists/get/downloadTo | مصرف‌کننده volume-aware | حفظ |
| پخش preview audio | StoreController -> StorageManager.exists/downloadTo/stream | مصرف‌کننده volume-aware | حفظ |
| خواندن audio artifact خوانشگر | StoreController.readerAudio -> Storage::disk مستقیم | ناسازگار با volume disk key | تغییر به StorageManager و range/stream؛ مالک تغییر PR #335 |
| فایل کاربر | UserFileController -> StorageManager + bindReference؛ UserFile نیز disk/path mirror دارد | رجیستری managed مرجع بایت، UserFile مرجع مالکیت/metadata | reconcile مرجع‌ها و جلوگیری از حذف orphan |
| منابع درس دوره | AdminCourseController -> quarantine -> StorageManager adopt + bindReference | pipeline مدیریت‌شده | با #234 و #335 همگام شود |
| پیوست تیکت/پیام‌رسان | StorageManager برای ثبت نهایی، private staging برای preflight | managed registry برای فایل نهایی | کنترل orphan و ownership reference در تست‌ها |

**نتیجه:** شکاف preview/audio در main شناسایی شده و مالک تغییر آن در شاخه موجود #335 است؛ در این ممیزی pipeline موازی ایجاد نمی‌شود. مسیر StoreController.readerAudio نیز باید در همان PR به StorageManager منتقل شود تا ذخیره و بازیابی یک قرارداد واحد داشته باشند.


## 17. یافته‌های عمیق امنیت، اشتراک، مالی و ممیزی

این بخش یافته‌هایی را ثبت می‌کند که از تطبیق مستقیم Service، Controller، Middleware، Migration و call-site به دست آمده‌اند. اولویت‌ها بر اساس اثر احتمالی بر جداسازی tenant، مجوز نوشتن، اعتبار مالی و قابلیت بازیابی داده تعیین شده‌اند.

### ARCH-09 — قرارداد نقش تیم با Scope resolver هم‌خوان نیست

**شواهد main:**
- PlatformCompletionController::addTeamMember مقدارهای owner، supervisor و employee را برای team_user.team_role می‌پذیرد.
- ResolveAdminScope::hasManagedTeams و teamUserIds فقط team_role = manager را به‌عنوان عضویت مدیریتی می‌شناسند؛ AdminConsoleService::visibleTeamIds نیز همین مقدار را می‌خواند.
- در نتیجه supervisor/owner که از UI فعلی به pivot اضافه می‌شود، در Scope تیمی شناخته نمی‌شود؛ مسیر دوم teams.supervisor_id مستقل از pivot کار می‌کند.
- queryهای pivot در ResolveAdminScope و AdminConsoleService شرط teams.organization_id = users.organization_id ندارند.
- Migration جدول team_user فقط FKهای team و user را تضمین می‌کند؛ قید هم‌سازمانی user/team ندارد.
- endpointهای مدیریت عضویت تیم در PlatformCompletionController فقط isAdmin() را کنترل می‌کنند و تطبیق organization_id کاربر و تیم را enforce نمی‌کنند.

**اثر:** ناسازگاری نقش supervisor/owner با Scope؛ و در صورت وجود عضویت manager بین‌سازمانی، امکان گسترش دامنه تیمی به کاربران سازمان دیگر. این یک ریسک جداسازی tenant است و باید با داده واقعی نیز preflight شود.

**پوشش شاخه #347:** تست‌های AdvancedAccessControlTest شامل مرز cross-organization و رفتار team authority هستند؛ شاخه #347 هم roleهای manager/owner/supervisor را یکپارچه و هم organization boundary را در queryهای Scope اعمال می‌کند. این شاخه هنوز در main نیست.

### ARCH-10 — مجوز مشاهده به‌جای مجوز عملیات نوشتاری استفاده می‌شود

**شواهد main:**
- AdminConsoleService::authorizeAction(User, section, targetUserId) فقط sectionAllowed و مالکیت/Scope رکورد را می‌سنجد؛ ورودی برای permission اختصاصی عملیات ندارد.
- بخش users در config/farast_admin.php با users.view تعریف شده است.
- AdminController::toggleUser و updateUserCapabilities هر دو فقط authorizeAction(..., 'users', targetUserId) را صدا می‌زنند.
- AuthorizationService مجوزهایی مانند users.edit و users.suspend را می‌شناسد، اما این دو مسیر آن‌ها را مطالبه نمی‌کنند؛ در نتیجه نقش دارای users.view و Scope مناسب می‌تواند عملیات suspend یا تغییر capability override انجام دهد.
- تغییر capability شامل can_ai، can_type، can_voice، export و quota override است؛ این عملیات صرفاً read نیست.

**اثر:** نقض اصل least privilege و تبدیل مجوز مشاهده کاربران به مجوز تغییر وضعیت/قابلیت آن‌ها. کنترل Scope، جایگزین مجوز عملیاتی نیست.

**پوشش شاخه #347:** AdminConsoleService::authorizeAction پارامتر required permission می‌گیرد؛ کنترلرها برای suspend و capability management مجوزهای اختصاصی مطالبه می‌کنند؛ تست view_permission_cannot_be_used_as_a_record_write_permission این مرز را پوشش می‌دهد. در اجرای Phase 3 شاخه #347، AdvancedAccessControlTest با ۲۷ تست موفق گزارش شده است؛ همان suite به‌علت پنج شکست مستقل StoreReader در مجموع قرمز مانده است.

### ARCH-11 — دو تصمیم‌گیر entitlement با ترتیب انتخاب متفاوت

**شواهد main:**
- EntitlementService::allows/consume و CommerceAuthorizationService::findEntitlement/reserve/commit هر دو از FarastEntitlement و fallbackهای CapabilityService استفاده می‌کنند.
- EntitlementService entitlement را با specificity (project/user) انتخاب می‌کند، اما CommerceAuthorizationService ابتدا priority و سپس specificity را مرتب می‌کند؛ برای چند entitlement هم‌پوشان ممکن است دو سرویس دو رکورد متفاوت را انتخاب کنند.
- EntitlementService::allows مقدار used_quantity را می‌سنجد، اما reservationهای باز را در دسترس باقی‌مانده لحاظ نمی‌کند؛ CommerceAuthorizationService مقدار reserved را نیز کم می‌کند.
- در EditorController::analyze ابتدا EntitlementService::allows اجرا می‌شود و سپس CommerceAuthorizationService::reserve با allow_payg اجرا می‌شود. بنابراین gate اولیه می‌تواند قبل از مسیر PAYG تصمیم deny بدهد.
- EntitlementService::consume مسیر افزایش مستقیم used_quantity دارد؛ مسیر جدید Commerce از reservation/commit و event/charge استفاده می‌کند.

**اثر:** اختلاف در انتخاب entitlement، سهمیه باقی‌مانده و امکان PAYG؛ دو منبع تصمیم برای یک capability. باید یک تصمیم‌گیر واحد یا قرارداد روشن feature gate در برابر billing authorization تعریف شود و انتخاب entitlement در هر دو مسیر یکسان باشد.

### ARCH-12 — مسیر اشتراک و سهمیه پروژه هنوز بخشی از schema legacy را می‌خواند

**شواهد main:**
- Migration 2026_09_29_000006_create_farast_completion_tables.php ابتدا farast_plans و farast_subscriptions را با FK قدیمی plan_id -> farast_plans می‌سازد.
- Migrationهای 2026_10_03_000001 و 000003 کاتالوگ جدید farast_commercial_plans و ساختار جدید farast_usage_counters را اضافه/نرمال می‌کنند.
- Migration 2026_10_03_000004_canonicalize_farast_subscriptions.php جدول اشتراک را بازسازی می‌کند؛ schema canonical فعلی plan_id را برای commercial plan به کار می‌گیرد و FK قدیمی را حذف می‌کند.
- PlatformCompletionController::billing/subscribe/adminPlans/savePlan و ProfessionalCommerceService از farast_commercial_plans استفاده می‌کنند؛ SubscriptionEntitlementService از همین کاتالوگ برای ساخت FarastEntitlement استفاده می‌کند.
- بااین‌حال ProjectBillingService::includedAllowance همچنان farast_subscriptions را به farast_plans join می‌کند و usage را با ستون‌های legacy user_id/metric/used از farast_usage_counters جمع می‌زند.
- مدل FarastUsageCounter و ProfessionalCommerceService::recordUsageCounter قرارداد جدید scope_key/capability/unit/quantity را استفاده می‌کنند.
- PaymentController::createForDocument و payWithWallet برای اسناد دارای project، فعالانه ProjectBillingService::quote را فراخوانی می‌کنند؛ پس این مسیر legacy dead code نیست.

**اثر:** تغییر plan از پنل مدیریت در کاتالوگ commercial ممکن است در project billing منعکس نشود؛ usage جدید با schema canonical ممکن است در جمع legacy دیده نشود؛ quota/price پروژه می‌تواند stale یا نادرست باشد. این مسیر باید به commercial plan و usage source واحد منتقل شود یا legacy بودن آن با یک adapter رسمی و تست‌شده حفظ شود.

### ARCH-13 — Migration استانداردسازی اشتراک در main fail-open و غیرقابل rollback است

**شواهد main:** 2026_10_03_000004_canonicalize_farast_subscriptions.php جدول قدیمی را rename می‌کند، سپس آن را drop می‌کند و بعد جدول canonical را می‌سازد و رکوردها را insert می‌کند. اگر plan قابل حل نباشد، بعضی ردیف‌ها با continue حذف می‌شوند؛ down() نیز عملاً کاری نمی‌کند. نگاشت plan ابتدا بر اساس numeric id است و در صورت نبود، فقط اگر ستون plan_code در خود subscription row وجود داشته باشد از code استفاده می‌کند؛ schema legacy اولیه چنین ستون plan_codeی ندارد.

**اثر:** خطر حذف/جاافتادن رکورد اشتراک در migration و احتمال نگاشت plan به رکورد هم‌شماره اما متفاوت. اجرای این Migration روی snapshot واقعی بدون تطبیق plan IDs ایمن نیست.

**پوشش شاخه #340:** Migration به staging copy، row-count verification و orphan preflight تغییر کرده و در صورت plan حل‌نشده منبع را حفظ می‌کند. بااین‌حال تست آن یک ستون plan_code در schema آزمایشی می‌سازد که در Migration اولیه main وجود ندارد. لازم است تستی با schema واقعی legacy (FK به farast_plans و بدون plan_code) اضافه شود و mapping از طریق join به farast_plans.code انجام شود؛ صرف تطبیق numeric id کافی نیست. #340 همچنین Migration FK را به farast_commercial_plans اضافه می‌کند، اما ProjectBillingService در فایل‌های تغییرکرده آن PR اصلاح نشده و همچنان reader legacy است.

### ARCH-14 — debitهای legacy کیف پول، holdهای Commerce را لحاظ نمی‌کنند

**شواهد main:**
- CommerceAuthorizationService::canAfford مانده قابل خرج را از wallet.balance_rials - SUM(farast_wallet_reservations.status=reserved) محاسبه می‌کند.
- reserve یک FarastWalletReservation ایجاد می‌کند و موجودی اصلی Wallet را تا زمان commit کم نمی‌کند.
- PaymentController::payWithWallet، مسیر wallet برای Store order و مسیر deposit، Wallet را lock می‌کنند اما فقط total > wallet.balance_rials را می‌سنجند؛ مجموع holdهای باز را از موجودی قابل خرج کم نمی‌کنند.
- CommerceAuthorizationService::commitWalletHold بعداً hold را lock می‌کند و فقط balance فعلی را با مبلغ همان hold می‌سنجد؛ debit قدیمی می‌تواند منابع لازم برای holdهای باز دیگر را مصرف کرده باشد.
- در PR #340 نسخه PaymentController همچنان شرط balance مستقیم دارد و بررسی FarastWalletReservation در debitهای legacy دیده نمی‌شود.

**اثر:** پرداخت قدیمی می‌تواند اعتبار رزروشده برای مصرف PAYG را خرج کند و commit رزرو بعدی را به insufficient_credit برساند؛ lock کردن Wallet به‌تنهایی مشکل را حل نمی‌کند.

**قاعده مالکیت:** تمام debitهای Wallet باید از یک سرویس مشترک available balance = balance - active holds استفاده کنند؛ هیچ Controller نباید مستقلاً balance را قابل خرج فرض کند.

### ARCH-15 — دو دفتر ممیزی با سطح یکپارچگی متفاوت

| دفتر | جدول | سرویس نویسنده | ویژگی |
|---|---|---|---|
| ممیزی عملیاتی | audit_logs | AuditLogService | action، user، IP، metadata؛ بدون checksum chain |
| ممیزی رویدادی FARAST | farast_audit_events | AuditEventService | event_id یکتا، payload_checksum و previous_checksum؛ قابل verify با AuditIntegrityService |

- AdminPlatformController صفحه audit را از audit_logs می‌خواند؛ این صفحه به‌تنهایی زنجیره farast_audit_events را نمایش نمی‌دهد.
- AuditIntegrityService فقط زنجیره FarastAuditEvent را verify می‌کند؛ صحت audit_logs را از نظر tamper-evidence بررسی نمی‌کند.
- پوشش رخدادهای حساس بین این دو دفتر تقسیم شده و شناسه correlation واحد در قرارداد مشاهده‌شده وجود ندارد.

**ریسک concurrency در زنجیره:** AuditEventService::record آخرین checksum را با lockForUpdate()->latest('id') می‌خواند. وقتی جدول خالی است هیچ row موجودی برای قفل وجود ندارد؛ دو writer هم‌زمان می‌توانند هر دو previous_checksum=null بگیرند و زنجیره fork شود. تست فعلی فقط یک رویداد اول را ثبت می‌کند و concurrency اولین writerها را نمی‌آزماید. راهکار پایدار نیازمند chain-head row/singleton lock یا advisory lock و تست چندنویسنده است.

### ARCH-16 — Workflow فعلی synchronous و فاقد fencing token است

**شواهد main:**
- WorkflowEngine::dispatch رویداد را ثبت می‌کند و همان درخواست، processEvent را اجرا می‌کند؛ Job مستقل برای مصرف event در main وجود ندارد.
- retry با schedule هر دقیقه در routes/console.php انجام می‌شود.
- mapping اجرا پس از گذشت بازه stale دوباره claim می‌شود، اما در schema فعلی lease token یا fencing token برای تشخیص مالک اجرای قدیمی وجود ندارد.
- run اجرای stepها را در transaction انجام می‌دهد؛ ولی در رقابت worker قدیمی و retry جدید، شرط وضعیت running به‌تنهایی اثبات نمی‌کند همان worker مالک lease است.
- workflow definitions و runs عمدتاً با DB query builder در WorkflowEngine و PlatformCompletionController خوانده/نوشته می‌شوند؛ مدل Eloquent مستقل برای workflow و run در main وجود ندارد.

**اثر:** اجرای طولانی‌تر از stale window می‌تواند با retry هم‌پوشانی پیدا کند و side effectهای غیر idempotent مانند create_follow_up تکرار شوند؛ اجرای event نیز در latency درخواست کاربر دخیل است.

**پوشش شاخه #347:** Job/outbox، lease و fencing در آن شاخه اضافه شده‌اند؛ این بخش باید همراه با تست crash/retry/lease-expiry و نه صرفاً تست happy path بررسی شود.

### ARCH-17 — Controllerهای مرکزی مرز Service را می‌شکنند

اسکن ایستای main روی فایل‌های اصلی این شمارش را نشان داد (شمارش source-level، نه پیچیدگی runtime):

| Controller | تعداد method | DB::table call | DB::transaction call | برداشت |
|---|---:|---:|---:|---|
| PlatformCompletionController | 31 | 47 | 5 | چند دامنه: document/workspace، workflow، CMS، billing، security، OCR، team |
| AdminController | 20 | 23 | 2 | dashboard، users/capability، settings، pricing، wallet و گزارش |
| AdminPlatformController | 8 | 7 | 1 | analytics، audit و مدیریت platform |
| AdminStoreController | 24 | 9 | 11 | catalog، upload، preview، content workflow |
| CrmController | 7 | 7 | 0 | query و mutation مستقیم CRM |
| MessengerController | 13 | 7 | 2 | access و persistence مکالمه |
| StoreCartController | 9 | 0 | 1 | عمدتاً delegation به StoreCartService |
| PaymentController | 11 | 0 | 3 | orchestration مدل/سرویس و تراکنش مالی |
| SupportController | 19 | 0 | 0 | delegation غالب به SupportTicketService |

PlatformCompletionController پرریسک‌ترین hotspot است: تعریف و اجرای workflow، CRUD سند، share، folder، billing، subscription، team membership و برخی عملیات admin در یک Controller جمع شده‌اند. این مسئله با ساختن Controllerهای موازی حل نمی‌شود؛ هر استخراج آینده باید از همین endpointها و جدول‌های مرجع به Serviceهای مالک منتقل شود و Route/response قرارداد فعلی را حفظ کند.

### ARCH-18 — Pricing/Entitlement چند لایه دارد و باید دامنه هر لایه ثابت شود

- PricingService::quote/projectQuote برای خدمات تایپ و پروژه استفاده می‌شود؛ ProjectBillingService آن را با subscription و counter legacy ترکیب می‌کند.
- PricingEngine از FarastPricingPolicyVersion نسخه‌دار برای capability usage استفاده می‌کند؛ ProfessionalCommerceService و CommerceAuthorizationService به این مسیر متصل‌اند.
- PricingService::calculate همچنان مدل/جدول legacy FarastPricingPolicy را می‌خواند؛ در Controllerهای پرداخت و Editor که بررسی شدند مسیر اصلی از quote/projectQuote است، نه calculate.
- PricingRule برای تعرفه تایپ با FarastPricingPolicyVersion یکی نیست؛ این دو باید به‌عنوان دو دامنه قیمت‌گذاری جدا مستند شوند، نه اینکه نام کلی Pricing باعث یکی فرض کردنشان شود.
- Migration professional_commercial_engine جدول‌های farast_price_quotes و farast_output_authorizations را می‌سازد؛ در سرویس‌های اصلی بررسی‌شده writer/reader قطعی برای farast_output_authorizations و مدل متناظر پیدا نشد. این دو جدول باید با جست‌وجوی کامل call-site در کل repository تعیین تکلیف شوند؛ فعلاً orphan-schema candidate هستند.



## 18. تکمیل نقشه داده، مدل و Migration

### 18.1 منبع حقیقت تجاری اشتراک

زنجیره واقعی Migration و کد مصرف‌کننده نشان می‌دهد دو نسل schema وجود دارد:

1. `2026_09_29_000006_create_farast_completion_tables.php` ابتدا `farast_plans`، `farast_subscriptions` و `farast_usage_counters` را ایجاد می‌کند. در این نسل، `farast_subscriptions.plan_id` به `farast_plans.id` وصل است.
2. `2026_10_03_000001_professional_commercial_engine.php` جدول `farast_commercial_plans` را می‌سازد؛ اما چون جدول اشتراک و شمارنده مصرف از قبل وجود دارد، بلوک‌های `if (! Schema::hasTable(...))` برای آن دو جدول اجرا نمی‌شوند.
3. `2026_10_03_000003_normalize_commercial_runtime.php` ردیف‌های `farast_plans` را به `farast_commercial_plans` منتقل می‌کند و schema اشتراک/شمارنده را توسعه می‌دهد.
4. نسخه موجود در `main` از `2026_10_03_000004_canonicalize_farast_subscriptions.php` جدول اشتراک قدیمی را rename و سپس drop می‌کند؛ جدول canonical را بدون FK برای `plan_id` می‌سازد و در صورت resolve نشدن plan بعضی ردیف‌ها را از کپی کنار می‌گذارد. این رفتار خطر از دست رفتن تاریخچه اشتراک یا اتصال اشتباه plan در دیتابیس upgrade‌شده را ایجاد می‌کند.

مصرف‌کننده‌های فعال مشاهده‌شده:
- `ProfessionalCommerceService` با `FarastCommercialPlan` و `FarastSubscription` اشتراک می‌سازد.
- `SubscriptionEntitlementService` quota را از `FarastCommercialPlan` می‌خواند.
- `PlatformCompletionController` برای billing، `farast_subscriptions` را به `farast_commercial_plans` join می‌کند.
- `FarastSubscription` مدل جدول `farast_subscriptions` است؛ مدل فعال `FarastPlan` در مسیر مدل‌ها مشاهده نشد.

**مالکیت canonical در main هنوز یکپارچه نیست:** `farast_commercial_plans` منبع کاتالوگ برای `ProfessionalCommerceService`، `SubscriptionEntitlementService` و مسیر billing در `PlatformCompletionController` است؛ اما `ProjectBillingService::includedAllowance()` همچنان `farast_subscriptions` را به `farast_plans` join می‌کند و سهمیه را از `farast_plans.quotas` می‌خواند. همین سرویس شمارنده legacy را با `user_id/metric/used` می‌خواند. این مسیر dead code نیست: `PaymentController::createForDocument()` و مسیر پرداخت سند پروژه‌ای، `ProjectBillingService::quote()` را فراخوانی می‌کنند. بنابراین در main، کاتالوگ اشتراک و محاسبه billing پروژه دو reader با قرارداد متفاوت دارند و هنوز یک منبع حقیقت واحد برای تمام billing وجود ندارد.

**مالک اصلاح در شاخه‌های موجود:** PR #340 نسخه Migration را به staging و preflight غیرمخرب تغییر داده، در صورت plan حل‌نشده پیش از حذف منبع fail می‌کند و Migration بعدی FK اشتراک به `farast_commercial_plans` را با orphan preflight اضافه می‌کند. تست‌های `CommercialSubscriptionMigrationSafetyTest` و `CommerceLedgerForeignKeyTest` نیز اضافه شده‌اند. بااین‌حال `ProjectBillingService.php` در diff فعلی #340 نیست؛ پس قبل از canonicalize کردن plan_id، مصرف‌کننده billing پروژه باید به catalog و usage canonical منتقل شود یا adapter سازگار رسمی داشته باشد. این وابستگی به‌صورت blocker در PR #340 ثبت شده است. اصلاح Migration هنوز در main نیست؛ وضعیت آن باید با CI همان SHA و سپس merge کنترل‌شده تأیید شود.

### 18.2 شکاف باقی‌مانده در شمارنده مصرف تجاری

این مورد در diff فعلی PR #340 نیز پوشش داده نشده است:

- schema اولیه `farast_usage_counters` در Migration پایه دارای `user_id`, `metric`, `used` و unique قدیمی `(user_id, metric, period_start)` است.
- Migration `professional_commercial_engine` یک schema جدید با unique روی `(scope_key, capability, unit, period_start)` تعریف می‌کند، اما به علت وجود جدول قبلی، create آن skip می‌شود.
- `normalize_commercial_runtime` ستون‌های `scope_key`, `capability`, `unit`, `quantity` را nullable اضافه و داده legacy را backfill می‌کند، ولی unique constraint متناظر با کلید جدید را اضافه نمی‌کند.
- `FarastUsageCounter` و `ProfessionalCommerceService::recordUsageCounter` از کلید جدید استفاده می‌کنند و با `firstOrCreate` سپس `increment` شمارنده را به‌روزرسانی می‌کنند.

**ریسک:** دو درخواست هم‌زمان می‌توانند قبل از وجود ردیف، هر دو آن را ایجاد کنند؛ چون unique index روی کلید جدید در schema فعلی تعریف نشده است، حفاظت دیتابیس در برابر duplicate counter وجود ندارد. unique قدیمی بر ستون‌های legacy این رقابت را برای کلید جدید متوقف نمی‌کند.

**اقدام لازم:** preflight تکرار روی tuple جدید، سپس Migration forward برای unique index و تست رقابت/تکرار روی SQLite و MySQL/PostgreSQL. تا اجرای این کار، این مورد به‌عنوان شکاف یکپارچگی مصرف باقی می‌ماند؛ ایجاد counter موازی یا جدول جدید ممنوع است.

### 18.3 تکمیل ARCH-15: سه دفتر رخداد با سه مسئولیت متفاوت

ARCH-15 دو دفتر `audit_logs` و `farast_audit_events` را از هم جدا می‌کند. ردیابی حساب امنیتی، دفتر سوم را نیز آشکار می‌کند؛ این سه جدول نباید در مستندات یا داشبورد به‌عنوان یک دفتر واحد فرض شوند:

| جدول | مالک فعلی مشاهده‌شده | مصرف‌کننده | نقش |
|---|---|---|---|
| `audit_logs` | `AuditLogService` | `AdminController`, `AdminPlatformController::audit` | ثبت رویدادهای عملیاتی مدیر و منبع فعلی صفحه ممیزی مدیریت |
| `farast_audit_events` | `FarastAuditEvent`, `AuditEventService`, `AuditIntegrityService` | سرویس ثبت و verifier زنجیره checksum | دفتر رویداد دامنه با زنجیره checksum |
| `farast_security_events` | queryهای مستقیم `DB::table` در `AccountSecurityController` و `TrackAuthenticatedSession` | مرکز امنیت حساب و middleware نشست | رخدادهای امنیتی حساب/دستگاه |

نکته مهم: `AdminPlatformController::audit` و شاخص audit داشبورد از `audit_logs` می‌خوانند؛ این صفحه در مسیر مشاهده‌شده `farast_audit_events` را verify یا نمایش نمی‌دهد. `AuditIntegrityService` زنجیره `farast_audit_events` را بررسی می‌کند، اما اتصال عملیاتی آن به صفحه ممیزی مدیریت در مسیرهای بررسی‌شده اثبات نشد. بنابراین «ممیزی مدیریتی قابل مشاهده» و «دفتر checksumدار دامنه» فعلاً دو قابلیت با دو منبع داده‌اند. قبل از یکپارچه‌سازی، call-siteهای کامل `AuditEventService` و سیاست پوشش رخدادهای حساس باید استخراج شود.

### 18.4 قرارداد واقعی جدول رخداد امنیتی

`farast_security_events` نخست در Migration `2026_09_29_000006` ایجاد می‌شود. Migration امنیت حساب `2026_11_10_000005` نیز create-if-missing برای همین نام جدول دارد؛ اما در نصب معمول که جدول از قبل وجود دارد، آن create اجرا نمی‌شود. Migration تعمیر `2026_11_10_000012` هم در صورت وجود جدول، ستون‌های آن را reconcile نمی‌کند.

در کد فعلی، `AccountSecurityController` و `TrackAuthenticatedSession` با Query Builder مستقیماً روی این جدول کار می‌کنند و مدل Eloquent اختصاصی برای آن مشاهده نشد. schema اولیه ستون‌های لازم این مسیرها را دارد؛ بنابراین این مورد فعلاً «مالکیت و تکامل schema چندپاره» است، نه اثبات خطای runtime. مالکیت آینده باید در یک Migration forward واحد تثبیت شود و تست upgrade از دیتابیس قدیمی داشته باشد.

### 18.5 Route ownership: نتیجه تست در CI

تست runtime مالکیت Route در این PR اجرا نمی‌شود، زیرا تعارض Route با PR #347 در فایل مشترک است و برای جلوگیری از تداخل با شاخه همکار، اصلاح و تست آن به commit مستقل پس از ادغام RBAC موکول شده است.

همان اجرای suite در مجموع **۶۰۴ تست موفق و ۵ تست ناموفق** داشته؛ هر پنج شکست در `StoreReaderAccessTest` بوده‌اند (قرارداد streamed response، audio status، PDF preview، CSP و محتوای transcript). بنابراین:
- تست اختصاصی اصلاح Route اعلان موفق است.
- suite کامل Laravel هنوز سبز نیست.
- مراحل بعدی همان job که بعد از `php artisan test` قرار دارند، از جمله `route:list`، در آن run اجرا نشده‌اند.
- PR #350 برای هم‌راستاسازی تست‌های Reader ایجاد شده؛ روی HEAD جدید آن، FARAST CI و Phase 3 دوباره در صف‌اند و تا نتیجه نهایی نباید آن را سبز فرض کرد.

### 18.6 فهرست Routeها

فهرست ۳۳۶ declaration خام در main (شامل دو جفت Route متعارض)، به تفکیک فایل، همراه با method، URI نهایی پس از prefix، route name، action، middleware گروه و middleware صریح در فایل زیر ثبت شده است:

- `docs/architecture/ROUTE_OWNERSHIP_INVENTORY.md`

### 18.7 فهرست کامل مدل‌ها

برای ۱۰۵ فایل مدل Eloquent، نام کلاس، table override صریح، primary key، connection و روابط قابل استخراج ثبت شده است:

- `docs/architecture/MODEL_OWNERSHIP_INVENTORY.md`

مدل‌هایی که `$table` صریح ندارند عمداً با برچسب «قرارداد Eloquent؛ runtime تأیید نشده» آمده‌اند؛ نام جدول آن‌ها تا اجرای runtime قطعی تلقی نمی‌شود.

### 18.8 فهرست کامل Migrationها

برای تمام ۱۱۶ Migration، جدول‌های create/alter/rename، FKهای صریح، unique/indexهای قابل استخراج و guardهای create-if-missing ثبت شده است:

- `docs/architecture/MIGRATION_SCHEMA_INVENTORY.md`

این فهرست عملیات source را نشان می‌دهد، نه schema واقعی محیط نصب‌شده؛ raw SQL، morph/polymorphic و وضعیت واقعی FKها نیازمند runtime introspection هستند.

این فایل از Routeهای بارگذاری‌شده توسط `routes/web.php` استخراج شده و `routes/legal.php` orphan را عمداً وارد inventory فعال نمی‌کند. ۴۹ action controller استخراج‌شده همگی با فایل‌های controller inventory تطبیق دارند؛ دو فایل بدون route action، کلاس پایه `Controller` و trait `PaysWithZarinpal` هستند. route inventory ایستا است؛ پس از عبور CI از `php artisan route:list` باید با خروجی runtime تطبیق داده شود.

### 18.9 هم‌پوشانی محل تعریف جدول‌ها در Migrationها

استخراج ایستای تمام ۱۱۶ Migration این آمار را به دست می‌دهد:

- ۱۵۲ عملیات `Schema::create` روی ۱۴۴ نام جدول متمایز؛
- ۱۳۱ عملیات `Schema::table` روی ۳۷ جدول متمایز؛
- ۱۸۱ ارجاع FK صریح قابل استخراج از macroهای شناخته‌شده؛
- ۶ نام جدول دارای بیش از یک محل create.

| جدول | محل‌های create | تفسیر مالکیت |
|---|---|---|
| `farast_notifications` | `2026_09_29_000006`, `2026_11_09_000005` | create-if-missing دوم نقش ensure دارد؛ schema نهایی را Migrationهای بعدی توسعه می‌دهند |
| `farast_subscriptions` | `2026_09_29_000006`, `2026_10_03_000001`, `2026_10_03_000004` | ابتدا schema legacy؛ create guarded نسل تجاری به علت وجود جدول skip می‌شود؛ Migration چهارم آن را بازسازی می‌کند. این حساس‌ترین مورد است و به #340 وابسته است |
| `farast_usage_counters` | `2026_09_29_000006`, `2026_10_03_000001` | schema نسل جدید به دلیل جدول موجود skip می‌شود؛ فقط normalize ستون‌ها انجام می‌شود و unique جدید جا می‌ماند |
| `farast_security_events` | `2026_09_29_000006`, `2026_11_10_000005`, `2026_11_10_000012` | دو create بعدی در نصب معمول به علت وجود جدول skip می‌شوند؛ Migration تعمیر schema موجود را کامل reconcile نمی‌کند |
| `farast_account_sessions` | `2026_11_10_000005`, `2026_11_10_000012` | Migration دوم مسیر repair است و فقط در نبود جدول ایجاد می‌کند؛ ستون device hash در مسیر موجود جداگانه افزوده می‌شود |
| `farast_blocked_devices` | `2026_11_10_000006`, `2026_11_10_000012` | Migration دوم مسیر repair/create-if-missing است؛ مالکیت schema باید به یک migration forward مرجع ختم شود |

این آمار نشان‌دهنده شش «محل تعریف» تکراری است، نه شش جدول فیزیکی هم‌زمان. بااین‌حال create-if-missing نباید جایگزین schema reconciliation شود: اگر جدول موجود اما ناقص باشد، guard آن را بدون اصلاح ستون‌ها رد می‌کند. تمام این موارد باید در clean install و upgrade از snapshot واقعی تست شوند.

### 18.11 Call-site سرویس‌ها و کنترلرها

دو inventory مکمل برای اتصال Route → Controller → Service → Model/Table اضافه شده‌اند:

- `docs/architecture/CONTROLLER_CALLSITE_INVENTORY.md`: ۵۱ کنترلر؛ importهای Service/Model، جدول‌های literal در `DB::table`، توکن‌های authorization قابل تشخیص، actionهای public و تعداد call مستقیم DB/Storage.
- `docs/architecture/SERVICE_DEPENDENCY_INVENTORY.md`: ۱۱۳ سرویس؛ dependencyهای import/constructor/app-resolve، مدل‌ها، جدول‌های literal، diskهای Storage، متدهای public و تعداد call مستقیم DB/Storage.

شمارش استاتیک فعلی:
- در Controllerها: ۲۸ فایل دارای call مستقیم DB با مجموع ۲۴۴ call؛ ۱۱ فایل دارای call مستقیم Storage با مجموع ۵۱ call.
- در Serviceها: ۲۸ فایل دارای call مستقیم DB با مجموع ۱۱۲ call؛ ۸ فایل دارای call مستقیم Storage با مجموع ۲۱ call.

این اعداد شمار source-level callهای facade هستند؛ queryهای Eloquent، repositoryهای dynamic، `StorageManager` injection، helperهای سفارشی و دسترسی از Job/Command/Listener در این شمارش مستقیم نیستند. وجود call مستقیم نیز به‌تنهایی bypass اثبات نمی‌کند؛ هر مورد باید با مالکیت دامنه و قرارداد امنیتی سنجیده شود. این دو inventory برای اولویت‌بندی call-site audit استفاده می‌شوند، نه برای حذف مکانیکی DB/Storage از Controllerها.
### 18.12 Hotspotهای مستقیم برای مراحل بعد

با شمارش همه فراخوانی‌های `DB::...` و `Storage::...` (نه فقط `DB::table`) در دو inventory، بیشترین نقاط تماس مستقیم چنین‌اند:

| فایل | DB facade calls | Storage facade calls | نقش/اهمیت معماری |
|---|---:|---:|---|
| `PlatformCompletionController` | 52 | 0 | چند دامنه در یک controller؛ workflow، workspace، billing، team و عملیات پلتفرم |
| `AdminStoreController` | 20 | 21 | مسیر مدیریتی محصول و فایل؛ نقطه حساس اتصال به StorageManager |
| `AdminController` | 26 | 1 | کاربران، capability override، تنظیمات، قیمت‌گذاری و wallet |
| `CrmMarketingController` | 21 | 0 | query/mutation مستقیم CRM و کمپین |
| `AccountSecurityController` | 18 | 0 | عملیات نشست و رخداد امنیتی با Query Builder |
| `WorkflowEngine` | 28 | 0 | persistence و lifecycle گردش‌کار؛ مالک execution باید در مرحله اتوماسیون تثبیت شود |
| `FeedbackPipelineService` | 16 | 0 | pipeline و persistence بازخورد؛ باید مالک جدول‌ها و مسیر async آن روشن باشد |
| `StorageManager` | 3 | 8 | هسته ذخیره‌سازی؛ دسترسی مستقیم به disk در خود adapter/core قابل انتظار است |
| `StorageTransferService` | 6 | 1 | انتقال بین Volumeها و ثبت وضعیت انتقال |
| `ProjectBillingService` | 2 | 0 | تعداد call کم اما اثر زیاد؛ reader legacy فعال برای billing سند پروژه‌ای |

این شمارش صرفاً اولویت بررسی را مشخص می‌کند. تعداد بیشتر به‌تنهایی نشانه بد بودن کلاس نیست؛ معیار اصلاح، مالکیت دامنه، تراکنش، authorization و idempotency هر call-site است. به‌ویژه `AdminStoreController` نباید با یک Storage abstraction موازی جایگزین شود؛ callهای مستقیم آن باید به‌صورت تدریجی با `StorageManager` canonical تطبیق داده شوند.
### 18.10 محدودیت inventory مدل‌ها

از ۱۰۵ فایل مدل:
- ۴۰ مدل `$table` صریح دارند؛
- ۶۴ مدل به قرارداد نام‌گذاری Eloquent متکی‌اند و نام نهایی جدول آن‌ها در این inventory به‌صورت قطعی حدس زده نشده؛
- ۱۶۵ رابطه مستقیم از الگوی استاتیک قابل استخراج بوده است.

برای بستن این شکاف، در runtime باید برای همه مدل‌ها `getTable()`، `getConnectionName()`، `getKeyName()` و relation metadata با reflection/test استخراج شود. این کار باید روی همان نسخه کد و connection واقعی اجرا شود؛ نام‌گذاری convention جایگزین شواهد runtime نیست.

## 19. به‌روزرسانی مالکیت و هم‌پوشانی شاخه‌های جدید

از آخرین snapshot زنده PRهای باز، نقاط تماس جدید زیر نیز به نقشه وابستگی افزوده شدند:

- #357 مالک تغییر stream صوت preview در `StoreController` است و با #335 در Storage overlap دارد.
- #358 هویت اعلان‌های reply پشتیبانی را در `SupportNotificationService` و `SupportTicketService` اصلاح می‌کند؛ dedupe باید فقط retry همان رخداد را یکی کند، نه دو پاسخ مجزا را.
- #359 یک اصلاح محدود در `RestoreStorageBackup` برای ترتیب ورودی ZIP دارد و باید در تست restore ذخیره‌سازی دیده شود.
- #360 مصرف‌کننده client-side ذخیره‌سازی Reader را در IndexedDB اصلاح می‌کند؛ نباید منبع مجوز یا metadata سرور را تغییر دهد.
- #361 پیشرفت دوره را به نمای CRM اضافه می‌کند و `StoreCourseProgressService` را معرفی می‌کند؛ منبع پیشرفت باید همان enrollment/progress فعلی باشد، نه جدول timeline مستقل.
- #352 تست‌های E2E واقعی محصول/پشتیبانی را هدف می‌گیرد؛ پس از تثبیت مسیرهای intake و پشتیبانی باید به‌عنوان تست پذیرش یکپارچه استفاده شود.

این شاخه‌ها تغییر داده نشده‌اند. ترتیب و مالکیت فوق برای جلوگیری از ویرایش هم‌زمان فایل‌های `StoreController`, `SupportTicketService`, `StoreCourseLearningController` و `RestoreStorageBackup` ثبت شده است.

## 20. وضعیت پذیرش مرحله دوم پس از ممیزی عمیق‌تر

مواردی که اکنون شواهد قابل استناد دارند:
- ترکیب ورودی و Providerها؛
- inventory ایستای Routeهای فعال و تعارض‌های واقعی؛
- مالکیت اولیه جدول/مدل/سرویس/کنترلر در تمام دامنه‌های اصلی؛
- ERD سطح اول و روابط FK صریح/ارجاع‌های بدون FK؛
- جریان‌های محصول، خرید، Storage، پشتیبانی، پیام‌رسان و Workflow؛
- تفکیک سه دفتر audit/security؛
- ریسک‌های migration و شاخه‌های باز هم‌پوشان.

مواردی که هنوز معیار «پایان» را می‌بندند:
1. اجرای `php artisan route:list` روی HEAD نهایی و تطبیق با inventory؛
2. اجرای `migrate:status` و مقایسه schema واقعی SQLite/MySQL/PostgreSQL با ERD؛
3. اجرای suite کامل پس از رفع شکست‌های Reader؛
4. تکمیل call-site inventory برای دسترسی مستقیم `DB::table`, `Storage::disk`, `Gate/authorize` و سرویس‌های audit؛
5. تعیین تکلیف unique index شمارنده‌های مصرف؛
6. تأیید آخرین PRهای دارای migration و سپس به‌روزرسانی این سند روی آخرین main.

**وضعیت این مرحله در این snapshot: نقشه ایستای ۳۳۶ declaration خام Route، ۱۰۵ Model و ۱۱۶ Migration به‌همراه جریان‌های اصلی و تعارض‌های منبع حقیقت ثبت شده است؛ اما پذیرش runtime هنوز باز است. به‌ویژه مصرف‌کننده legacy در `ProjectBillingService` و unique key شمارنده مصرف باید پیش از canonicalization مالی تعیین تکلیف شوند. هیچ refactor گسترده‌ای بر مبنای حدس مجاز نیست؛ ابتدا گیت‌های بالا باید با نتیجه واقعی بسته شوند.**


## 18. Findings in active implementation and candidate branches

### ARCH-19 — Store administration routes in main lack section-level authorization

**main evidence:**
- routes/store_routes.php opens the complete admin store group with only auth + admin middleware.
- routes/product_operations.php uses the same auth + admin-only group.
- config/farast_admin.php already defines section store with permission store.manage and min_scope global, but the current main route groups do not apply admin.section:store.
- AdminOnly in multi_role_console mode admits all User::DASHBOARD_ROLES, not only the platform administrator.
- AdminStoreController main has no direct isAdmin, AuthorizationService, authorizeAction or Policy checks in its CRUD/upload methods; AdminCourseController routes inherit the same group.

**Effect:** when multi-role console is enabled, dashboard roles can reach product catalog CRUD, bulk archive, upload, preview, course sections/lessons/resources and product operations without the store.manage section gate. This is a critical route authorization gap.

**Candidate fix:** PR #347 changes both groups to auth + admin + admin.section:store. Since store section has global minimum scope, the route boundary becomes explicit. The PR must be rechecked after merge with a negative test for each dashboard role and every store mutation route; do not assume one index-page test covers all endpoints.

### ARCH-20 — Backup restore misreads the first ZIP entry as missing

**main evidence:** RestoreStorageBackup::handle checks the result of ZipArchive::locateName('database/database.sql') using a boolean negation. ZipArchive::locateName returns integer 0 when the requested file is the first entry; zero is falsey in PHP. The new StorageBackupRestoreIntegrationTest in PR #335 places database/database.sql as the first ZIP entry, so the command incorrectly reports that the database file is absent.

**Effect:** valid backups whose database dump is the first archive entry cannot be restored. This is especially likely for a backup with no other managed files. The correct existence check is strict comparison with false, not boolean negation.

**Coordination:** this exact cause was posted on PR #335. The backup integration test should remain; the restore command needs the strict false check and the test must pass before the Storage lifecycle PR is accepted.

### ARCH-21 — Product intake proposals define competing pre-product state machines

- Current main already has StoreProductIngestion for the preflight/confirm flow of files attached to an existing StoreProduct.
- PR #318 adds a new-product file-first path that creates a draft StoreProduct immediately and uses StoreProductIngestion as the staging state.
- PR #333 adds StoreProductIntakeSession and StoreProductIntakeFile before a StoreProduct exists; it supports multi-file and course intake, then creates StoreProduct and StoreProductFile at finalize.
- Both proposals change AdminStoreController and store_routes.php and introduce separate review/antivirus lifecycle code.

**Decision proposed:** use StoreProductIntakeSession/File as the sole pre-product intake aggregate; keep StoreProductIngestion for attaching files to an already existing product and downstream ingestion. Port any valuable scanner/review adapter from #318 into the #333 session pipeline, but do not merge both new-product intake routes or two publication-review engines. This decision and the exact route split were posted on PR #318 and PR #333.

**Additional access requirement:** PR #333's reviewer lookup uses role + store.products.publish permission without an organization/team resource scope on the intake session. If a non-global role is ever granted that permission, it can inspect/review sessions across organizations. Reviewer access must be resource-scoped before the feature is enabled for organization/team roles.

### ARCH-22 — Candidate Workflow queue branch must define sync-driver behavior

PR #347 moves active event call-sites to WorkflowEngine::enqueue and dispatches ProcessWorkflowEvent after commit. In that branch queueEvent returns immediately when queue.default is sync, and dispatchPendingEvents also returns 0 for the sync driver. In the supplied environment context, queue.default is sync. Therefore an enqueue-only caller can persist a pending event that no worker or scheduler will process.

The branch needs one explicit contract:
- inline dispatchSync/processEvent after commit for sync driver; or
- fail loudly on sync driver and make health checks prevent activation; or
- require a real database/Redis worker in deployment and verify it end-to-end.

This finding was posted on PR #347. Do not accept silent pending events as a valid sync-mode behavior.

### وضعیت اجرایی Pull Requestهای وابسته

آخرین snapshot checkها باید هنگام ادغام دوباره خوانده شود؛ وضعیت مشاهده‌شده در زمان این ممیزی:
- PR #348 قدیمی به‌عنوان superseded بسته شد؛ PR جایگزین روی main جدیدتر ایجاد می‌شود. تست اختصاصی مالکیت اعلان در این PR باقی نمانده تا پس از ادغام #347 و حذف تعارض Routeها اضافه شود.
- PR #350: Phase 3 در HEAD آن سبز شد و 608 تست موفق گزارش شد؛ Laravel check هنوز باید نهایی شود.
- PR #335: Phase 3 دارای 620 موفق و یک شکست StorageBackupRestoreIntegrationTest بود؛ علت دقیق locateName index 0 شناسایی و در PR comment ثبت شد.
- PR #340: Phase 3 دارای 614 موفق و 12 شکست بود؛ هفت شکست در setup تست FinancialIdempotencyMigrationUpgradeTest به‌علت duplicate transaction_id و پنج شکست StoreReader. تست‌های سه موتور دیتابیس در صف بودند. ایراد schema واقعی legacy و wallet holds نیز در PR comment ثبت شد.
- PR #347: در اجرای قبلی 27 تست AdvancedAccessControlTest موفق بودند؛ Phase 3 به پنج شکست StoreReader رسیده بود. HEAD جدید آن در زمان آخرین بررسی هنوز checkهای کامل نداشت.
- PR #318: تست اصلی موفق، اما Laravel check ناموفق؛ PR #333 هنوز check-run قابل اتکا نداشت.
- PR #353 و #354: checkها در صف/در حال اجرا بودند.



## 19. ممیزی سطح دسترسی Store Admin و زنجیره Intake

### مالکیت فعلی در main

- routes/store_routes.php گروه مدیریت فروشگاه را با auth + admin باز می‌کند؛ admin.section:store در گروه وجود ندارد.
- routes/product_operations.php نیز گروه auth + admin دارد و admin.section:store ندارد.
- config/farast_admin.php از قبل بخش store را با store.manage و min_scope=global تعریف کرده، اما دو فایل Route از این قرارداد استفاده نمی‌کنند.
- AdminOnly در حالت multi_role_console فعال، تمام DASHBOARD_ROLES را می‌پذیرد.
- AdminStoreController، AdminCourseController، AdminProductContentController، AdminProductSupervisorController و AdminProductOperationsController در مسیرهای اصلی بررسی‌شده guard داخلی مشترک isAdmin/AuthorizationService/Policy ندارند.

**اثر:** نقش‌های dashboard غیر از مدیر اصلی می‌توانند به عملیات catalog، course، upload، preview، AI content generation، AI supervisor review و retry عملیات محصول برسند، مگر آنکه مسیر خاصی middleware جدا داشته باشد. این یک نقص route authorization در main است.

### تفاوت branchهای فعال

| مسیر | Gate فعلی در branch | شکاف باقی‌مانده |
|---|---|---|
| main | auth + admin | فاقد admin.section:store |
| PR #318 | auth + permission:store.manage | permission به‌تنهایی scope سراسری را enforce نمی‌کند |
| PR #333 | auth + admin؛ فقط برخی intake endpoints permission دارند | CRUD و عملیات موجود فروشگاه همچنان به section gate سراسری نیاز دارند |
| PR #335 | auth + admin | اصلاح Storage نباید با group بدون section ادغام شود |
| PR #347 | auth + admin + admin.section:store در store_routes و product_operations | مسیر کاندید اصلاح؛ باید تست منفی roleهای غیر global داشته باشد |

**قاعده ادغام:** تغییرهای محصول و Storage نباید قبل از اضافه‌شدن admin.section:store به گروه‌های کامل مدیریت فروشگاه ادغام شوند. این وابستگی در PRهای #318، #333 و #335 به صاحبان آن‌ها اعلام شده است.

### Intake جدید و مرز دسترسی reviewer

در PR #333، owner بودن session به user_id کنترل می‌شود؛ اما reviewer با permission store.products.publish و role dashboard می‌تواند sessionهای پیش از ایجاد product را مشاهده/بررسی کند. چون session هنوز organization_id/team_id ندارد و reviewer path به AdminConsoleService resource scope متصل نیست، در صورت اعطای permission به نقش غیر global، reviewer می‌تواند session سازمان‌های دیگر را ببیند. permission باید با resource scope یا tenant ownership روی submitter اعمال شود، نه صرفاً با role permission.



## 21. تطبیق خط مبنا با آخرین main در زمان انتقال ممیزی

این نسخه برای جلوگیری از ثبت snapshot منقضی، با HEAD فعلی `main` یعنی `1b9108fcedfb3f330a2ba19caba91a33cb00a966` تطبیق شد. مقایسه Git بین snapshot قبلی `11a0427bf0a3beff9856d5790d6f97c3b1984b68` و این HEAD دقیقاً دو commit را نشان می‌دهد:

- `90ee46c` تغییرهای Reader/Browser و تست‌های مربوط؛ خارج از مسیرهای Route، Model، Migration و Service که inventoryهای این سند را تشکیل می‌دهند.
- `5a9323e` افزودن قراردادهای یکپارچگی کسب‌وکار در `docs/architecture/business-module-integration-phase7.md`؛ تغییر مستنداتی، بدون تغییر کد اجرایی دامنه‌های inventoryشده.

در نتیجه inventoryهای Route، Model، Migration، Service و Controller در این انتقال از نظر فایل‌های منبع با HEAD جدید تطبیق دارند؛ بااین‌حال Route inventory عمداً canonical candidate پس از حذف دو declaration متعارض اعلان را نشان می‌دهد، نه وضعیت خام main پیش از این PR. تغییر Route هنوز باید در این شاخه merge و سپس در runtime با `route:list` تأیید شود.

توجه: commit جدید main همچنان شامل دو Route متعارض اعلان است؛ این شاخه فقط دو تعریف متعلق به `PlatformCompletionController` را حذف می‌کند و سایر مسیرها را دست‌نخورده نگه می‌دارد.


## 22. دفتر هم‌پوشانی زنده PRها و ترتیب امن ادغام

این بخش، وضعیت snapshot ساعت 12:59 UTC در 2026-11-11 را جایگزین فهرست‌های قدیمی‌تر بخش‌های 15 و 19 می‌کند. وضعیت PRها و فایل‌های آن‌ها از GitHub خوانده شده است؛ باز بودن یا mergeable بودن به معنی قبولی CI یا صحت طراحی نیست.

| PR | وضعیت | فایل‌های حساس مشترک/مرز اثر | تصمیم معماری و ترتیب |
|---:|---|---|---|
| #330 | باز، غیر Draft؛ base قدیمی‌تر | هسته RBAC/Workflow: `AuthorizationService`, `AdminConsoleService`, `ResolveAdminScope`, `SupportTicketService`, `WorkflowEngine`, `PlatformCompletionController`, `bootstrap/app.php`, migrationها و Routeهای مدیریت | با #347 هم‌پوشانی گسترده دارد. هر دو را با هم ادغام نکنید؛ ابتدا یک خط مبنای واحد و انتخاب یک implementation مرجع لازم است. |
| #347 | باز، غیر Draft؛ base روی main فعلی | همان هسته RBAC/Workflow به‌علاوه Messenger permissions، admin scope و Workflow lease migration | از نظر base به‌روزتر از #330 است، اما هنوز به بررسی sync queue، scope تیم/سازمان و تست منفی همه Routeهای Store نیاز دارد. مرجع candidate برای مقایسه است، نه تأیید merge. |
| #340 | باز، Draft | `PaymentController`, `PaysWithZarinpal`, `StoreCartController`, `WalletController`, مدل‌های مالی و Migrationهای subscription/ledger | قبل از هر تغییر در checkout، callback، wallet یا commerce ledger باید با main و #330/#347 تطبیق شود؛ وضعیت قبلی آن شامل شکست migration-upgrade بوده است. |
| #318 | باز، Draft | `AdminStoreController`, `StoreProductIngestion`, `AuthorizationService`, `routes/store_routes.php`, intake JS و migration antivirus | مسیر file-first که محصول draft را زودتر ایجاد می‌کند؛ با #333 در state machine محصول جدید رقیب است. فقط اجزای scanner قابل انتقال پس از انتخاب مالک pipeline هستند. |
| #333 | باز، غیر Draft | `AdminStoreController`, `AdminCourseController`, `StoreProduct`, `AuthorizationService`, `routes/store_routes.php`, intake session/file models و migrationها | مسیر type-first با session قبل از ایجاد محصول. در طراحی پیشنهادی، pre-product intake مرجع واحد باشد و StoreProductIngestion فقط فایل محصول موجود را مدیریت کند. reviewer باید resource scope داشته باشد. |
| #335 | باز، Draft؛ mergeable=false در snapshot | `StorageManager`, `StorageBackupService`, `StorageTransferService`, `AdminStoreController`, `StoreController`, migration reservation و restore tests | بزرگ‌ترین تغییر چرخه عمر فایل محصول است؛ با #357 در StoreController overlap دارد. merge order باید با #369 و #357 هماهنگ شود. |
| #357 | باز، غیر Draft | فقط `StoreController.php` | تغییر محدود stream صوت preview؛ با #335 روی همان controller تداخل مستقیم دارد. ابتدا قرارداد StorageManager و مالک نهایی readerAudio/previewAudio مشخص شود؛ cherry-pick کور ممنوع. |
| #358 | merged در main | `SupportNotificationService`, `SupportTicketService`, `EmailService`, `SmsService`, `SmsLog`, migrationهای idempotency | تغییر در منبع فعلی main است؛ هویت هر reply مستقل و retry همان رخداد dedupe می‌شود. |
| #361 | باز، غیر Draft | `CrmController`, `StoreCourseLearningController`, `StoreCourseProgressService` | با #368 در همان سه فایل overlap دارد؛ هر دو یک مسیر progress timeline پیشنهاد می‌کنند. فقط یک پیاده‌سازی سرویس و یک مالک CRM باقی بماند. |
| #368 | merged در main | `CrmController`, `StoreCourseLearningController`, `StoreCourseProgressService`, `CrmCourseProgressTimelineTest` | منبع canonical محاسبه پیشرفت در main است؛ #361 قدیمی باید با این پیاده‌سازی reconcile شود. |
| #365 | باز، غیر Draft | تست `BusinessCommerceLifecycleTest` | تست سرتاسری ارزشمند؛ باید پس از تثبیت قرارداد Order/Payment/Library/Enrollment با تغییرهای #340 و #333 همگام شود. |
| #352 | باز، غیر Draft | `StoreController`, `ProcessProductDocument`, Reader Blade/JS، seeder و browser E2E | تست پذیرش UI واقعی؛ با #335/#357 و #333 روی product/reader/file flow تداخل دارد. بعد از تثبیت implementation اجرا و rebased شود. |
| #353 | باز، غیر Draft | `AuditEventService`, `AuditLogService`, `AppServiceProvider`, `bootstrap/app.php`, `routes/console.php`, middleware و tests | با قرارداد سه دفتر audit و مرحله دسترسی overlap دارد. writerهای موجود را بدون طرح migration/dual-write و تست integrity جایگزین نکند. |
| #354 | باز، Draft | `SupportReportController`, `SupportSlaService`, `routes/notifications.php`, `routes/store_routes.php`, `routes/support_extra.php` | با route ownership این ممیزی و #358 هم‌پوشانی دارد. قبل از ادغام، inventory route و permission باید مجدداً تولید شود. |
| #367 | merged در main | `.github/workflows/farast-ci.yml`, workflowهای تست، `scripts/verify-test-environment.php` | baseline مشترک محیط PHP در main است؛ تمام نتایج CI بعدی باید با verifier جدید سنجیده شوند. |
| #369 | باز، غیر Draft | backup/restore commands، `StorageBackupService`, `AdminStorageController`, `routes/console.php`, readiness و workflow release | با #335 روی backup/storage overlap دارد. ابتدا lock/recovery contract و restore strictness را با #335 تطبیق دهید، سپس E2E backup/restore اجرا شود. |

### ترتیب ادغام پیشنهادی از روی هم‌پوشانی فایل‌ها

1. **گیت ابزار تست:** #367؛ تا baseline محیط PHP/SQLite و workflow قابل اعتماد نشود، نتیجه تست‌های سایر PRها قابل قیاس نیست.
2. **هسته دسترسی و Workflow:** انتخاب یک شاخه مرجع میان #330 و #347؛ ابتدا route section gate، team/org resource scope، permission separation و sync-driver contract بسته شود.
3. **Storage و backup:** هم‌ترازسازی #335 و #369، سپس cherry-pick/port محدود #357 روی همان implementation نهایی StorageManager؛ نه merge موازی سه نسخه StoreController.
4. **Intake محصول:** انتخاب #333 به‌عنوان pre-product session state machine و انتقال اجزای لازم از #318؛ StoreProductIngestion برای فایل محصول موجود حفظ شود.
5. **مالی:** #340 پس از بررسی migration upgrade و تطبیق Wallet holds با debit path؛ بدون دست‌کاری تاریخچه ledger.
6. **پشتیبانی و اعلان:** #358 با NotificationService/Workflow و #353 از نظر idempotency، transaction و audit correlation هماهنگ شود.
7. **CRM و یادگیری:** #361 و #368 به یک candidate تبدیل شوند؛ سپس #365 و #352 به‌عنوان پذیرش سرتاسری اجرا شوند.
8. **گزارش و عملکرد:** #354 پس از تثبیت route ownership و query contract ادغام شود.
9. **Release:** #369 پس از سبز بودن گیت‌های بالا و اثبات backup/restore واقعی.

### قاعده حفاظت از شاخه همکاران

این ممیزی فقط PRها و فایل‌هایشان را مشاهده کرده است. هیچ branch یا PR همکار rebase، force-push، merge یا ویرایش نشده است. اگر یک PR به‌روزتر باشد، فقط آن را candidate ترجیحی برای بررسی معرفی می‌کنیم؛ تصمیم ادغام منوط به CI و بازبینی محتوای نهایی است.

### وضعیت اجرای تست این PR

در HEAD قبلی شاخه، Phase 3 روی یک snapshot پیشین موفق شده بود؛ این نتیجه به main فعلی قابل انتقال نیست. این PR اکنون فاقد تست Route جدید است و پذیرش آن باید بر اساس اجرای تازه FARAST CI/Phase 3/Phase 4 روی HEAD نهایی انجام شود.


## 23. تکمیل ردیابی صفحه، API و تست

برای بستن شکاف بین Route و سطح مصرف‌کننده، دو سند مکمل اضافه شدند:

- `docs/architecture/CAPABILITY_TRACEABILITY_MATRIX.md`: ۱۳ حوزه اصلی را به جدول/مدل، سرویس، Controller/Route، Gate، تست‌های موجود و شکاف‌های اثبات‌نشده وصل می‌کند.
- `docs/architecture/CONTROLLER_VIEW_API_SURFACE_INVENTORY.md`: ۵۱ فایل Controller/Trait را به Viewهای مستقیم، JSON/stream/download/redirect/back response surface متصل می‌کند.

خط مبنای تعداد فایل‌های تست در HEAD فعلی main:
- ۱۱۴ فایل PHP در Feature/Unit؛
- ۱۷ فایل JavaScript در Browser؛
- جمعاً ۱۲۷ فایل آزمون در مسیرهای بررسی‌شده.

این عدد شمار فایل است، نه تعداد test method و نه نتیجه قبولی. ماتریس traceability عمداً شکاف‌های بدون تست منفی را باز نگه می‌دارد و ادعای پوشش کامل ۳۳۶ declaration Route ندارد.

## 24. اصلاح دقت استخراج Schema و Model

در بازبینی مجدد inventoryها، دو نقص در خود سند شناسایی و اصلاح شد:

1. ۴۷ ردیف Migration در نسخه قبلی برای index/unique ستونی عبارت مبهم `current column` داشتند. هر ۴۷ ردیف با خواندن Migration source بازبینی شدند و نام جدول/ستون یا مجموعه ستون‌ها ثبت شد؛ مورد dynamic loop در `2026_10_03_000001_create_farast_phase5_platform_layer.php` نیز به‌صورت صریح با فهرست ۱۱ جدول دارای `stable_id` یکتا ثبت شد. اکنون در inventory هیچ placeholder با عبارت `current column` باقی نمانده است.
2. رابطه `Conversation::participants()` در inventory قبلی به علت طول فیلد بریده شده بود. فهرست کامل pivot columns شامل `role`, `last_read_message_id`, `read_at`, `muted_at`, `archived_at`, `joined_at`, `last_active_at`, `typing_until` و `withTimestamps` ثبت شد؛ فیلتر soft-delete در رابطه `messages()` نیز ثبت شد.

این اصلاحات source inventory را دقیق‌تر می‌کنند، اما جایگزین introspection runtime نیستند.

## 25. وضعیت اعتبارسنجی آخرین HEAD ممیزی

پس از اضافه‌شدن matrix قابلیت و inventory سطح View/API، CI باید روی HEAD نهایی re-run شود؛ هیچ تست جدید Route در این PR وجود ندارد:
- Phase 3 Commerce Tests: در snapshot فعلی در حالت pending/در صف؛
- FARAST CI: jobهای Laravel و smoke در صف؛
- Phase 4 Agent: در صف.

تا تکمیل این checkها، نتیجه تست جدید را موفق اعلام نکنید. در اجرای قبلی همین شاخه، Phase 3 موفق بود؛ اما آن نتیجه مربوط به HEAD قبلی است و برای HEAD جدید قابل انتقال نیست.

**وضعیت مرحله دوم پس از این تکمیل:** inventoryهای ایستا اکنون شامل ۳۳۶ declaration Route، ۱۰۵ Model، ۱۱۶ Migration، ۱۱۳ Service، ۵۱ Controller/Trait، ماتریس ۱۳ حوزه قابلیت و سطح View/API هستند. گیت‌های runtime، schema واقعی و CI همچنان بازند؛ بنابراین مرحله برای پذیرش نهایی هنوز بسته نشده است.


## 26. یافته جدید: Route گزارش پشتیبانی از قرارداد بخش مدیریتی پیروی نمی‌کند

**شواهد main:**
- `routes/support_extra.php` گروه `/admin` را با `auth + admin` باز می‌کند.
- `GET /admin/communications` صریحاً `admin.section:support_admin` دارد.
- `GET /admin/support-report` با نام `admin.support.report` همان گروه را به ارث می‌برد، اما middleware بخش روی آن ثبت نشده است.
- `config/farast_admin.php` بخش `support_admin` را با `support.manage` و `min_scope=global` تعریف کرده است.
- `SupportReportController::__invoke` علاوه بر middleware، مستقیماً `auth()->user()?->isAdmin()` را enforce می‌کند.

**تفسیر:** این مورد در main فعلاً به معنی دسترسی گسترده به همه dashboard roles نیست، چون Controller یک guard مستقیم super-admin دارد؛ اما این guard مسیر RBAC/section مرکزی را دور می‌زند و با قرارداد تعریف‌شده `support_admin` ناسازگار است. در نتیجه کاربر دارای مجوز و Scope معتبر ممکن است بی‌جهت رد شود و audit مسیر دسترسی در دو محل متفاوت بماند.

**اقدام مرحله بعد:** پس از تثبیت هسته Authorization، middleware route باید `admin.section:support_admin` داشته باشد و guard داخلی Controller به قرارداد مرکزی منتقل شود؛ تست مثبت برای support manager global و تست منفی برای role فاقد مجوز و scope محدود لازم است. در این مرحله فقط یافته ثبت شد؛ کد همکار/عملیاتی تغییر داده نشد.

## 27. شمارش ماشینی Routeهای مدیریتی فاقد section gate

از ۳۳۶ ردیف Route inventory، **۴۰ Route** با middleware گروهی `auth, admin` و بدون `admin.section:*` شناسایی شدند:
- ۳۹ Route در سطح Store Admin شامل catalog CRUD، فایل، preview، AI supervisor، AI content، course sections/lessons/resources و product operations؛
- ۱ Route گزارش پشتیبانی با نام `admin.support.report`.

برای Store Admin، نبود section gate با نبود guard داخلی یکپارچه در Controllerهای CRUD/review همراه است و ریسک دسترسی واقعی دارد. برای گزارش پشتیبانی، guard داخلی `isAdmin()` وجود دارد اما با RBAC مرکزی هم‌راستا نیست. این دو مورد باید جداگانه و با آزمون منفی/مثبت رفع شوند؛ یک اصلاح گروهی بدون تست route-level کافی نیست.


## 28. شواهد Runtime از اجرای CI و اصلاح شکست تست

در اجرای GitHub Actions روی HEAD قبلی این شاخه (`708f84abb12331a322b8f56b4bd0ee6b1282b721`):

- نصب Composer و آماده‌سازی SQLite موفق بود.
- فرمان `php artisan migrate --force` از ابتدای تاریخچه Migration تا آخرین Migration موجود، بدون شکست و با وضعیت `DONE` برای همه Migrationها اجرا شد.
- job مرحله چهارم در گام PHPUnit متوقف شد؛ علت، syntax error در فایل جدید `NotificationRouteOwnershipTest.php` بود: namespace و useها به‌اشتباه با دو backslash نوشته شده بودند.
- این خطا در commit `315831edec7e825c1930f5f28ba515cf3dbfb534` با بازگرداندن namespace separator استاندارد PHP اصلاح شد. این اصلاح فقط فایل تست را تغییر داده و به کد اجرایی یا schema دست نزده است.

**نتیجه معتبر فعلی:** clean-install migration روی SQLite در آن HEAD موفق بوده است. این نتیجه هنوز upgrade از دیتابیس قدیمی، MySQL/PostgreSQL، آزمون‌های کامل و route ownership test روی HEAD اصلاح‌شده را ثابت نمی‌کند. CI باید روی HEAD پس از اصلاح دوباره اجرا شود؛ failure قبلی نباید به‌عنوان شکست migration ثبت شود.


## 29. تطبیق مجدد با main پس از ادغام تغییرهای همکاران

در `main` اکنون HEAD برابر `1b9108fcedfb3f330a2ba19caba91a33cb00a966` است. مقایسه Git با خط مبنای قبلی `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf`، ۱۶ commit جدید را نشان می‌دهد که بخش مهم آن‌ها شامل ادغام #358 (هویت رویداد و idempotency اعلان‌های پشتیبانی)، #368 (سرویس مشترک پیشرفت دوره در CRM و یادگیری) و #367 (baseline تست PHP) است. دو تغییر نخست مستقیماً منبع حقیقت پشتیبانی و یادگیری را تغییر داده‌اند و inventoryهای Service/Controller/Model/Migration/Test در این نسخه بر اساس آن‌ها به‌روزرسانی می‌شوند.

تعدادهای جدید در HEAD فعلی: ۱۱۶ Migration، ۱۰۵ Model، ۱۱۳ Service، ۵۱ Controller/Trait، ۱۱۴ فایل PHP تست و ۱۷ فایل Browser JS. Route inventory شامل ۳۳۶ declaration خام main است؛ دو جفت اعلان متعارض در آن حفظ شده‌اند و مالک candidate آن‌ها `NotificationController` است، اما در این ممیزی کد Route تغییر نکرده است.


## 30. وضعیت معتبر پس از ادغام #358، #368 و #367 در main

این بخش وضعیت بخش 22 را supersede می‌کند. سه تغییر زیر اکنون در main هستند و باید به‌عنوان کد موجود، نه PR باز، در تحلیل‌های بعدی در نظر گرفته شوند:

- **#358 merged:** `SupportNotificationService` برای replyهای مجزا از `messageId/eventIdentity` استفاده می‌کند؛ `EmailService` و `SmsService` idempotency key دارند؛ `SmsLog` و `email_logs.idempotency_key` دفتر ارسال را پایدار می‌کنند. تست‌های `SupportNotificationEventIdentityTest`, `EmailServiceIdempotencyTest`, `SmsServiceIdempotencyTest` در main موجودند.
- **#368 merged:** `StoreCourseProgressService` مالک محاسبه مشترک available/completed/progress است و هم `CrmController` و هم `StoreCourseLearningController` از آن استفاده می‌کنند. این یک منبع حقیقت مشترک برای پیشرفت دوره است؛ جدول timeline موازی ساخته نشده است. `CrmCourseProgressTimelineTest` در main موجود است.
- **#367 merged:** `scripts/verify-test-environment.php` و تغییر workflowها baseline محیط تست PHP را یکپارچه می‌کنند؛ CI فعلی باید با همین verifier تفسیر شود.

### وضعیت PRهای باز و وابستگی‌های واقعی در HEAD `1b9108f`

| PR | وضعیت در snapshot | وابستگی/تعارض |
|---:|---|---|
| #377 | باز، غیر Draft، base روی main فعلی | E2E و reader failure-state؛ فایل‌های `StoreController`, `ProcessProductDocument`, reader JS، seed و workflow را تغییر می‌دهد. با #335/#357 و #375 در چرخه فایل/reader هماهنگ شود. |
| #375 | باز، غیر Draft، mergeable=false | release و disaster recovery؛ `StorageBackupService`, `AdminStorageController`, `routes/console.php`, workflowها و restore tests. با #335 روی storage backup overlap مستقیم دارد. |
| #365 | باز، غیر Draft | تست lifecycle خرید catalog تا course؛ باید بعد از تثبیت #333 و قرارداد پرداخت #340 اجرا شود. |
| #361 | باز، غیر Draft | همان Controllerهای CRM/course progress که #368 در main اصلاح کرده است؛ باید با implementation ادغام‌شده #368 reconcile شود و نباید نسخه قدیمی progress را دوباره وارد کند. |
| #360 | باز، غیر Draft | IndexedDB reader invalidation؛ با #377 روی reader JS/Browser tests هماهنگ شود. |
| #357 | باز، غیر Draft | stream مستقیم preview audio در `StoreController`; با #335/#375 در storage contract و با #377 در reader playback هماهنگ شود. |
| #354 | باز، Draft | گزارش، file processing و resilience؛ routeها و سرویس‌های support/store را لمس می‌کند. با Route inventory و scope gateها تطبیق شود. |
| #353 | باز، غیر Draft | observability/audit writers؛ با سه دفتر audit و integrity chain هماهنگ شود. |
| #347 | باز، غیر Draft، mergeable=false | RBAC و Workflow core؛ فایل `routes/web_core_part2.php` و چند route group مشترک را تغییر می‌دهد. اصلاح دو Route اعلان در این ممیزی عمداً تا ادغام/تثبیت این PR عقب افتاد. |
| #340 | باز، Draft | financial idempotency/retention؛ migration upgrade و ledger/wallet reservation باید قبل از هر تغییر checkout تأیید شود. |
| #336 | باز، غیر Draft | voice API contract tests؛ در بررسی route/Service voice به‌عنوان گیت آزمون لحاظ شود. |
| #335 | باز، Draft | StorageManager و lifecycle فایل محصول؛ با #375 backup/restore و #357 preview audio overlap دارد. |
| #333 | باز، غیر Draft | type-first product intake؛ با #318 رقیب state machine intake است. |
| #330 | باز، غیر Draft، base قدیمی | RBAC/Workflow قدیمی‌تر و هم‌پوشان با #347؛ هر دو نباید مستقل merge شوند. |
| #318 | باز، Draft | file-first product intake؛ با #333 رقیب state machine است. |

### ترتیب به‌روزشده ادغام

1. #367 اکنون در main است؛ verifier آن باید gate مشترک تمام PRهای باقی‌مانده باشد.
2. یک شاخه canonical از RBAC میان #330 و #347 انتخاب شود؛ #347 candidate به‌روزتر است اما mergeable=false و نیازمند حل conflict است.
3. پس از تثبیت route file در #347، یک PR کوچک و مستقل برای حذف دو declaration اعلان از `web_core_part2.php` و تست مالکیت runtime ثبت شود.
4. #335 و #375 به یک storage/backup contract تبدیل شوند؛ #357 و #377 پس از آن بر همان قرارداد reader سوار شوند.
5. #333 یا #318 فقط یکی را به‌عنوان pre-product intake state machine نگه دارد؛ اجزای مفید دیگر به‌صورت port محدود منتقل شوند.
6. #340 پس از migration-upgrade و ledger review؛ #353 و #354 پس از route/permission map؛ #365 و #377 پس از تثبیت چرخه محصول.

**قاعده:** این PR صرفاً مستندات را تغییر می‌دهد. هیچ شاخه همکار force-push، rebase، merge یا ویرایش نشده است.
