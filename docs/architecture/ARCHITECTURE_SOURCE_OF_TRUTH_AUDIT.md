# ممیزی معماری فراست و دفتر منابع حقیقت

> وضعیت: ممیزی ایستا و مبتنی بر کد شاخه `main`؛ نسخه مبنا: `11a0427bf0a3beff9856d5790d6f97c3b1984b68`  
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
| Commit | `11a0427bf0a3beff9856d5790d6f97c3b1984b68` |
| Laravel / PHP constraint | Laravel `^12.0` / PHP `^8.2` |
| فایل‌های Migration | 114 |
| فایل‌های Model در `app/Models` | 104 |
| فایل‌های Service در `app/Services` | 118 |
| Controllerها در `app/Http/Controllers` | 52 |
| Middlewareهای برنامه | 11 |
| فایل‌های تست و Browser/E2E | 134 |

درخت کامل Git در این نسخه 1,151 ورودی داشت. اعداد بالا شمار فایل‌های مسیرهای مشخص‌اند، نه شمار قابلیت‌های سالم.

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
| جداول اصلی | `storage_volumes`, `storage_managed_files`, `storage_backup_runs` |
| مدل‌ها | `StorageVolume`, `StorageManagedFile`, `StorageBackupRun`, `UserFile`, `StoreProductFile` |
| هسته عملیات | `Storage\\StorageManager` |
| سرویس‌های مکمل | `S3CompatibleAdapter`, `StorageTransferService`, `StorageBackupService`, `StorageConnectionService`, `UploadPreflightService`, `UploadSecurityService`, `UserFileRetentionService` |
| کنترلرها | `AdminStorageController`, `ManagedStoragePublicController`, `UserFileController`, `AdminStoreController`, `SupportController`, `MessengerController` |
| Routeها | `routes/web_core_part2.php`, `routes/store_routes.php`, `routes/product_operations.php`, `routes/support_extra.php`, `routes/messenger.php` |
| زمان‌بندی | `routes/console.php`: health-check volume، انتقال pending، cleanup، backup و secure ticket attachments |
| تست‌های شناخته‌شده | `StorageCoreTest`, `StorageManagerRangeTest`, `StorageClassificationTest`, `StorageDrivePolicyTest`, `UploadSecurityServiceTest`, `EnsurePrivateStorageDiskTest` |

**منبع حقیقت پیشنهادی برای فایل‌های مدیریت‌شده:** `storage_managed_files` همراه با `StorageManager`. با این حال فایل‌های قدیمی/ویژه هنوز ممکن است در مدل‌های دامنه‌ای مثل `UserFile` یا مسیرهای مستقیم Filesystem حضور داشته باشند؛ باید تمام call-siteها بررسی شوند تا مرز فایل مرجع و metadata دامنه روشن شود.

**نکته Migration:** Migration اولیه `2026_11_10_000001_create_storage_management_tables.php` ستون `storage_volume_id` را غیر nullable می‌سازد، اما `2026_11_10_000009_allow_storage_staging_without_remote_volume.php` آن را nullable می‌کند. این با مسیر pending/staging در `StorageManager` سازگار به نظر می‌رسد، مشروط به اجرای Migration دوم. اجرای واقعی و ترتیب در دیتابیس‌های موجود هنوز بررسی نشده است.

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
| تست‌ها | `NotificationCenterTest`, `NotificationRouteOwnershipTest` |

**تداخل Route اثبات‌شده و اصلاح‌شده در شاخه ممیزی:** `web_core_part2.php` دو endpoint یعنی `GET /workspace/notifications` و `POST /workspace/notifications/{id}/read` را با همان URI و route nameهای `platform.notifications` و `platform.notifications.read` ثبت کرده بود. مقصد آن‌ها `PlatformCompletionController` بود، اما متدهای این کنترلر فقط به `NotificationController` delegate می‌کردند؛ بنابراین پیاده‌سازی منطق اعلان موازی نبود، ولی ثبت Route دوگانه و مالکیت مبهم بود. دو تعریف تکراری از `web_core_part2.php` حذف شدند و مالکیت Route به `routes/notifications.php` واگذار شد. تست `NotificationRouteOwnershipTest` اضافه شد تا Action نهایی هر دو route را به `NotificationController` مقید کند. این تست هنوز در CI اجرا و تأیید نشده است.

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
| ARCH-02 | دو تعریف Route اعلان در `web_core_part2.php` و `notifications.php` وجود داشت؛ در شاخه ممیزی تعریف قدیمی حذف و تست مالکیت اضافه شد | ثبت دوگانه Route و اتکا به ترتیب بارگذاری | اجرای CI و `route:list` پس از ادغام؛ تست `NotificationRouteOwnershipTest` |
| ARCH-03 | فروشگاه و commerce پلتفرم دو مدل تجاری مستقل دارند | اتصال مبهم order/payment/charge/refund | نمودار aggregate و تطبیق ledger و تراکنش |
| ARCH-04 | داده فایل دامنه‌ای و فایل مدیریت‌شده هر دو وجود دارند | فایل orphan یا دو منبع metadata | فهرست همه Storage facade / disk / path و طبقه‌بندی legacy |
| ARCH-05 | Migrationهای هم‌پیشوند زمانی متعدد وجود دارند | وابستگی ضمنی به ترتیب lexical و خطر upgrade | ساخت DAG وابستگی Migration و اجرای upgrade از snapshot |
| ARCH-06 | `routes/api.php` در درخت نیست و API-prefixed routeها در web route هستند | قرارداد middleware و CSRF باید صریح باشد | فهرست تمام routeها و threat model هر endpoint |
| ARCH-07 | فقط TicketPolicy در پوشه Policy مشاهده شد | احتمال پراکندگی authorization در Controller/Service | inventory تمام authorize/Gate/abort و بررسی model ownership |
| ARCH-08 | `main` در این snapshot Check Run تست ناموفق و branch protection خاموش دارد | امکان تحویل تغییر بدون gate اجباری | تکمیل مرحله اول و فعال‌سازی قواعد شاخه |

### Migrationهای دارای پیشوند زمانی مشترک

در نام فایل‌ها چند گروه دارای تاریخ و شماره ترتیبی یکسان ولی نام متفاوت دیده شد، از جمله:
- `2026_10_03_000001_*`
- `2026_10_19_000002_*`
- `2026_11_07_000001_*`
- `2026_11_08_000001_*`
- `2026_11_09_000012_*`
- `2026_11_10_000001_*`
- `2026_11_10_000005_*`
- `2026_11_10_000006_*`

این موضوع به‌تنهایی اثبات خرابی Migration نیست؛ اما ترتیب اجرای فایل‌ها با نام کامل و وابستگی واقعی باید ثبت شود. به‌ویژه migrationهای هم‌زمان که یکی به جدول ساخته‌شده در دیگری وابسته است، باید با اجرای دیتابیس خالی و ارتقای دیتابیس موجود بررسی شوند.

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
2. تست `tests/Feature/NotificationRouteOwnershipTest.php` اضافه شد تا route nameهای اعلان به Actionهای `NotificationController` متصل بمانند.
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
