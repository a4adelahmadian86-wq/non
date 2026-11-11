# ممیزی تکمیلی معماری بر مبنای main جاری

> این سند مکمل inventoryهای معماری ادغام‌شده در PR #371 است؛ آن inventoryها snapshot تاریخی `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf` را ثبت می‌کنند.  
> خط مبنای این تکمیل: `main` در commit `7d68c678a8244578286958c4d54f5ad0194217bb`، تاریخ 2026-11-11.  
> این سند تغییرات بعد از snapshot، یافته‌های تازه و PRهای فعال مرتبط را جدا می‌کند؛ هیچ تغییری در دارایی‌های شاخه‌های همکاران اعمال نمی‌کند.

## 1. وضعیت فعلی مخزن

| شاخص | مقدار در خط مبنای جاری |
|---|---:|
| Git tree entries | 1,188 |
| Migration PHP | 116 |
| Model PHP | 105 |
| Service PHP | 113 |
| Controller PHP | 52 |
| Test PHP | 123 |

این شمارش‌ها تعداد فایل‌اند و نباید به‌عنوان تعداد قابلیت یا معیار کیفیت تعبیر شوند.

### تغییرهای اصلی پس از snapshot تکمیلی قبلی

- commit `68c61461d1065cf4e1a2a642defa959ac83d1ad3`: تکمیل stage 11 عملیاتی؛ release workflow، health/readiness، observability check، backup verification، restore کنترل‌شده و recovery ledger به main اضافه شد. checkهای `test` و `agent` روی همین commit موفق‌اند.
- commit `18e79a8b5cf9c7b0d6e5a96f3f3de870b1fa3644`: `BusinessCommerceLifecycleTest` اضافه شد تا مسیر پرداخت تا entitlement و ثبت‌نام دوره و replay idempotency را end-to-end بررسی کند.
- PR #379 و PR #365 بسته شدند اما `merged=false`؛ تغییرهای متناظر با commitهای مستقل فوق در main وجود دارند. نباید وضعیت PR بسته‌شده را با merge رسمی اشتباه گرفت.


### تغییر نسبت به snapshot ممیزی قبلی

| حوزه | snapshot قبلی | main جاری | تغییر قابل ردیابی |
|---|---:|---:|---|
| Migration | 114 | 116 | دو Migration جدید برای idempotency ایمیل و ledger پیامک |
| Model | 104 | 105 | مدل جدید `SmsLog` |
| Service | 112 | 113 | سرویس مشترک پیشرفت دوره `StoreCourseProgressService` |
| Controller | 51 | 52 | کنترلر جدید `ReadinessController` |
| Test PHP | 112 | 123 | آزمون‌های CRM course progress، email/SMS idempotency، هویت اعلان پشتیبانی، مالکیت Route اعلان، recovery/backup/readiness، چرخه کامل خرید تا ثبت‌نام دوره و observability history |

## 2. Route ownership: اصلاح ادغام‌شده

PR #371 در main ادغام شده است.

### مالکیت canonical اعلان

- Route composition: `routes/web.php` فایل `routes/notifications.php` را بارگذاری می‌کند.
- مالک HTTP: `App\\Http\\Controllers\\NotificationController`.
- سرویس ثبت/خواندن اعلان درون‌برنامه‌ای: `App\\Services\\NotificationService`.
- مدل/جدول: `FarastNotification` / `farast_notifications`.
- اطلاعیه عمومی همچنان aggregate جداگانه `Announcement` / `announcements` با `AnnouncementService` و `AnnouncementController` است.

دو Route تکراری زیر از `routes/web_core_part2.php` حذف شده‌اند تا تنها تعریف فعال در `routes/notifications.php` باقی بماند:
- `GET /workspace/notifications` → `platform.notifications`
- `POST /workspace/notifications/{id}/read` → `platform.notifications.read`

آزمون `tests/Feature/NotificationRouteOwnershipTest.php` مالکیت هشت Route اعلان را به `NotificationController` مقید می‌کند و یکتایی تمام نام‌های Route runtime را assert می‌کند. تمام checkهای commit ادغام PR #371 یعنی `c3067f69...` موفق بودند؛ checkهای main جدیدتر در بخش CI جداگانه ثبت شده‌اند.

### Route file بدون مالک اجرایی

`routes/legal.php` در مخزن وجود دارد، اما از `routes/web.php` یا فایل‌های requireشده آن بارگذاری نمی‌شود. در عین حال Routeهای `/terms`، `/refund-policy` و `/privacy` در `routes/web_core_part1.php` تعریف شده‌اند. بنابراین `routes/legal.php` در این snapshot یک فایل Route غیرفعال با تعریف‌های تکراری است، نه منبع فعال درخواست. فعلاً حذف نشده تا مصرف‌کننده احتمالی خارج از Route composition و تاریخچه انتشار بررسی شود؛ مالک اجرایی این سه مسیر همان تعریف‌های `web_core_part1.php` است.


## 3. پیشرفت دوره: یک سرویس محاسبه مشترک

### منبع داده

- `store_course_enrollments` → `StoreCourseEnrollment`
- `store_course_lesson_progress` → `StoreCourseLessonProgress`
- `store_course_lessons` / section / course → مدل‌های کاتالوگ دوره

### مالک عملیات مشترک

`App\\Services\\StoreCourseProgressService` اکنون محاسبه دسترسی drip، درس‌های منتشرشده قابل مشاهده، شمار کل/تکمیل‌شده و درصد پیشرفت را انجام می‌دهد.

### مصرف‌کنندگان

- تجربه یادگیری: `StoreCourseLearningController::show/lesson/progress`
- نمای 360 مشتری در CRM: `CrmController::show`

این اشتراک مهم است: CRM نباید درصد پیشرفت را با الگوریتم مستقل محاسبه کند؛ هر دو سطح باید از همان سرویس استفاده کنند.

### آزمون

`tests/Feature/CrmCourseProgressTimelineTest.php` سناریوی خرید دوره، entitlement/enrollment، drip release و پیشرفت را می‌سازد و برابری محاسبه سرویس مشترک را در مسیر CRM بررسی می‌کند.

**منبع حقیقت:** رکوردهای enrollment و lesson progress داده عملیاتی‌اند؛ `StoreCourseProgressService` مالک محاسبه derived progress است. مقدار درصد پیشرفت نباید در CRM به‌عنوان داده مستقل ذخیره یا محاسبه موازی شود.

## 4. اعلان پشتیبانی و تحویل چندکاناله

سه سطح باید از هم جدا بمانند:

| سطح | مالک |
|---|---|
| اعلان داخل پنل | `NotificationService` → `FarastNotification` |
| هماهنگ‌سازی رخداد پشتیبانی | `SupportNotificationService` |
| تحویل ایمیل | `EmailService` → `EmailLog` و `DeliverFarastEmail` |
| تحویل پیامک | `SmsService` → `SmsLog` |

### تغییرهای جدید

- Migration `2026_11_11_000020_add_email_log_idempotency_key.php`: افزودن `email_logs.idempotency_key` با unique nullable index.
- Migration `2026_11_11_000021_create_sms_logs_table.php`: ایجاد `sms_logs` با کلید یکتای idempotency، نوع رخداد، hash شماره مقصد، وضعیت، تعداد تلاش و زمان پذیرش.
- مدل جدید `App\\Models\\SmsLog`.
- `EmailLog` اکنون فیلد idempotency key را در fillable دارد.
- `EmailService` برای رخدادهایی مانند پاسخ تیکت و پرداخت از کلید رخداد پایدار استفاده می‌کند.
- `SmsService::sendEvent` برای رخدادهای دارای identity، ارسال را در ledger ثبت می‌کند و امکان retry همان رخداد را بدون ایجاد رکورد دوم فراهم می‌کند.
- `SupportNotificationService` شناسه پیام تیکت را به identity اعلان/ایمیل/SMS منتقل می‌کند تا retry یک پیام با پیام جدید اشتباه نشود.

### آزمون‌های افزوده

- `EmailServiceIdempotencyTest`
- `SmsServiceIdempotencyTest`
- `SupportNotificationEventIdentityTest`

**مرز منبع حقیقت:** اعلان پنل، email delivery و SMS delivery سه ledger متفاوت‌اند. یکی‌کردن آن‌ها در یک جدول یا جایگزین کردن وضعیت تحویل ایمیل/SMS با وضعیت خوانده‌شدن اعلان اشتباه است. هماهنگ‌کننده پشتیبانی باید event identity مشترک را بسازد، ولی هر کانال وضعیت تحویل خودش را نگه دارد.

## 5. یافته امنیتی بحرانی در main و وضعیت اصلاح موازی

### یافته اثبات‌شده در main این snapshot

در `app/Http/Middleware/AdminOnly.php`، وقتی `farast_admin.multi_role_console=true` باشد، middleware فقط بررسی می‌کند نقش کاربر عضو `User::DASHBOARD_ROLES` باشد؛ یعنی ورود به گروه `/admin` را به super-admin محدود نمی‌کند.

در `config/farast_admin.php` بخش `store` به‌صورت زیر تعریف شده است:
- permission: `store.manage`
- min_scope: `global`

اما در main این snapshot:
- گروه مدیریت در `routes/store_routes.php` فقط `auth + admin` داشت و `admin.section:store` نداشت.
- گروه `routes/product_operations.php` نیز فقط `auth + admin` داشت و `admin.section:store` نداشت.
- مسیر `/admin/support-report` در `routes/support_extra.php` فاقد `admin.section:support_admin` بود؛ کنترلر نیز یک بررسی مستقیم `isAdmin()` داشت که با حالت multi-role console قرارداد یکسانی ندارد.

**اثر:** نقش‌های مدیریتی محدود که عضو `DASHBOARD_ROLES` هستند ممکن است از middleware عمومی admin عبور کنند، بدون آنکه permission و حداقل Scope بخش فروشگاه/عملیات محصول روی Route اعمال شود. این یک شکاف enforce سمت سرور است؛ مخفی بودن لینک UI جایگزین middleware نیست.

### اصلاح موجود در PR فعال #347

PR `feat(platform): scoped RBAC and transactional workflow core` همین سه مسیر را اصلاح کرده است:
- گروه admin فروشگاه: `auth + admin + admin.section:store`
- گروه عملیات محصول: `auth + admin + admin.section:store`
- گزارش پشتیبانی: `admin.section:support_admin`

همچنین `AdminSectionScopeAuthorizationTest` آزمون منفی برای دسترسی employee به `/admin/store`، `/admin/store/operations` و `/admin/support-report` اضافه کرده است.

**وضعیت:** اصلاح هنوز در main این snapshot نیست؛ PR #347 باز است. در بررسی قبلی GitHub آن را `mergeable=false / dirty` گزارش کرد؛ پس از حرکت main، metadata فعلی PR برابر `mergeable=null / unknown` است و check-run قابل اتکایی روی head فعلی وجود ندارد. مقایسه از base مشترک نشان می‌دهد main و PR هر دو شش فایل را تغییر داده‌اند: `.github/workflows/farast-ci.yml`، `.github/workflows/phase3-commerce.yml`، `bootstrap/app.php`، `routes/console.php`، `routes/web.php` و `routes/web_core_part2.php`؛ مورد آخر با اصلاح Route اعلان در PR #371 تداخل دارد و workflow/bootstrap/console نیز با stage 11 جدید main تغییر کرده‌اند. این تعارض‌ها به PR گزارش شده‌اند. فایل‌های Route و سرویس‌های Authorization در PR #347 تغییر می‌کنند، بنابراین اصلاح موازی همان فایل‌ها در شاخه دیگری انجام نمی‌شود. ادغام فقط پس از rebase روی main جاری، حل تعارض‌ها و CI کامل مجاز است.


### نکته معماری پیش از ادغام PR #347: ورودی‌های چندگانه، یک evaluator

بازبینی کد head فعلی PR #347 نشان می‌دهد این PR **کاتالوگ مجوز جدید موازی** نمی‌سازد؛ جدول `permissions` را reuse می‌کند. اما مدل تخصیص نقش و grant چندمنبعی می‌شود:

| ورودی سیاست | جدول/مدل | معنای آن |
|---|---|---|
| نقش پایه | `users.role` + `role_permissions` | نقش‌های سیستمی/پایه و مجوزهای پیش‌فرض آن‌ها |
| نقش سفارشی | `farast_roles` + `farast_role_permissions` + `farast_user_roles` | نقش‌های قابل تعریف و انتساب با scope و زمان اعتبار |
| استثنای مستقیم | `farast_user_permission_overrides` | allow/deny صریح روی کاربر و محدوده |
| محدودیت مدیر | `farast_user_admin_scopes` | محدودسازی دامنه یک مدیر |
| کاتالوگ مشترک | `permissions` | شناسه و کلید واحد مجوزها |
| تصمیم نهایی | `AuthorizationService::allows/allowsFor` | evaluator مرکزی |

در کد PR #347 ترتیب تصمیم چنین است: مدیر سراسریِ بدون محدودیت bypass؛ سپس override مستقیم (deny بر allow و grantهای inherited مقدم است)؛ سپس grant نقش پایه legacy؛ سپس grant نقش سفارشی. در نتیجه grant قدیمی همچنان می‌تواند دسترسی بدهد حتی اگر نقش سفارشی جدید آن grant را نداشته باشد؛ برای منع آن، deny override لازم است.

این رفتار می‌تواند یک مدل سازگاری عمدی برای حفظ نقش‌های پایه در کنار نقش‌های سفارشی باشد، اما نباید به‌اشتباه «یک جدول واحد نقش/مجوز» توصیف شود. قبل از پذیرش PR #347 باید یکی از این دو قرارداد صریحاً انتخاب و در UI/تست تثبیت شود:
1. **مدل ترکیبی پایدار:** نقش پایه و نقش سفارشی دو نوع grant مستقل با union و deny precedence هستند؛ باید این قرارداد در مستندات، رابط مدیریت و آزمون‌های revoke/expiry ثبت شود.
2. **مهاجرت به منبع واحد:** نقش‌های پایه نیز به `farast_roles/farast_user_roles` منتقل شوند، سپس خواندن legacy `role_permissions` پس از reconciliation داده‌ها حذف شود.

در PR فعلی، `AdminAccessCenterController::updateBaseRole` همچنان `role_permissions` را تغییر می‌دهد، در حالی که `syncPermissions/assignRole` جداول `farast_role_permissions/farast_user_roles` را مدیریت می‌کنند. پس دو مسیر مدیریت grant واقعاً وجود دارد؛ یکسان بودن evaluator مانع نیاز به تعیین تکلیف مالکیت داده نیست.

Scope نیز دو لایه دارد: محدودیت per-admin از `farast_user_admin_scopes` و override سراسریِ صرفاً محدودکننده از `site_settings` با کلید `scope_override_key`. این دو به‌خودی‌خود رقیب نیستند، به شرط آنکه override سراسری هرگز scope را بازتر نکند و precedence فعلی با تست حفظ شود.

**وضعیت:** این توصیف مربوط به head ادغام‌نشده PR #347 است، نه main فعلی. قبل از ادغام، تست‌های migration از دیتابیس خالی و دیتابیس legacy، حذف/انقضای grant، deny precedence و cache invalidation باید در CI سبز باشند.

## 6. Storage: شکاف main و اصلاح موجود در PR فعال #335

### وضعیت main این snapshot

- مرجع فایل مدیریت‌شده: `StorageManagedFile` / `storage_managed_files`.
- هسته عملیات: `StorageManager`.
- فایل‌های محصول اصلی و کاورها/تصاویر تا حدی به رجیستری متصل‌اند.
- `AdminStoreController::uploadPreview` و `uploadPreviewAudio` در main هنوز مسیرهای private disk و metadata محلی preview را به‌کار می‌برند.
- `StoreController::readerAudio` در main هنوز خواندن artifact را با `Storage::disk($artifact->disk)` انجام می‌دهد؛ این مصرف‌کننده از قرارداد volume-aware `StorageManager` عبور نمی‌کند.

### اصلاح موجود در PR فعال #335

در head فعلی PR #335:
- preview و preview audio از `StorageManager` و `bindReference` استفاده می‌کنند.
- `StoreController::readerAudio`، `StorageManager` را دریافت می‌کند.
- `MigrateLegacyProductAssets` برای فایل محصول، artifact، preview، cover و image مسیر مهاجرت legacy به رجیستری دارد.
- `StorageProductReferenceSyncTest` همگام‌سازی reference دامنه و رجیستری را می‌آزماید.

**شکست‌های دقیق CI مشاهده‌شده برای head `cf1e6e78...` در PR #335:**
- `StorageBackupRestoreIntegrationTest` شکست خورد؛ خروجی restore: «فایل پایگاه داده در آرشیو موجود نیست.»؛ assertion در خط 160 با exit code برابر 1 متوقف شد.
- Browser regression تعداد 16 شکست داشت؛ بخش عمده خطاها timeout در تعامل Reader/toolbar و یک مورد hidden بودن `.farast-reader-studio-rail` بود. این‌ها صرفاً خطای تصادفی CI فرض نمی‌شوند؛ باید با trace و fixture مربوط بررسی شوند.
- در همان اجرا 620 تست دیگر موفق بودند؛ این عدد جایگزین حل دو دسته شکست نیست.

main اکنون پیاده‌سازی production release و disaster recovery را از commit `68c61461d1065cf4e1a2a642defa959ac83d1ad3` دارد: `StorageBackupService`, `RunStorageBackup`, `VerifyStorageBackup`, `RestoreStorageBackup`, `RecoverStaleBackupRuns`, `StorageBackupRecoveryTest`, `CheckObservability`, قفل اجرای backup، readiness/health check، release workflow و runbook. checkهای `test` و `agent` همان commit موفق بودند. PR #379 در 2026-11-11 بسته شد اما `merged=false`؛ پیاده‌سازی آن به‌صورت commit مستقیم/مستقل در main جایگزین شده و branch آن `dirty` و diverged است. بنابراین تغییرات backup در PR #335 دیگر نباید روی main بازنویسی شوند؛ فقط بخش product artifact/storage reference آن پس از rebase باید جداگانه منتقل شود.

**وضعیت checkها در آخرین snapshot:**
- PR #335: `laravel`, `test` و `reader-browser-regression` ناموفق.
- PR #357: همان سه check ناموفق.
- PR #379: بسته‌شده بدون merge؛ check `test` ناموفق و branch با main جدید ناسازگار بود. کد جایگزین در main با commit `68c614...` و CI سبز قرار دارد.

PR #335 و #357 هر دو `StoreController::readerAudio` را تغییر می‌دهند و پیاده‌سازی‌های متفاوتی دارند؛ پیشنهاد StorageManager::streamRange به هر دو گزارش شده است. PR #357 بر مبنای قدیمی `11a0427...` باقی مانده و باید rebase شود؛ اجرای مستقیم `Storage::disk()->readStream()` در آن نباید به main برگردد.

**وضعیت:** PR #335 هنوز پذیرفته‌شده تلقی نمی‌شود. backup implementation در main تثبیت شده؛ PR #335 باید روی main جدید rebase شود و تغییرهای backup/restore قدیمی خود را حذف یا با نسخه main reconcile کند. فقط product artifact/reference synchronization باید باقی بماند. تغییر streaming صوتی #357 نیز باید به همان StorageManager::streamRange متصل شود. تا آن زمان هیچ مهاجرت دستی فایل یا حذف مسیر legacy انجام نشود.


### Reader audio: StorageManager دارد streaming مرکزی، اما PR #357 آن را دور می‌زند

در main، `StorageManager` از قبل این عملیات را فراهم می‌کند:
- `exists(disk, path)`
- `size(disk, path)`
- `streamRange(disk, path, start, length, output)`

پیاده‌سازی `streamRange` برای `storage_volume:{id}` به adapter S3-compatible می‌رود و Range را در لایه مرکزی اجرا می‌کند؛ برای diskهای داخلی نیز stream را از داخل همان abstraction باز می‌کند.

اما:
- main فعلی `StoreController::readerAudio` مستقیماً `Storage::disk()->readStream()` می‌زند.
- PR #357 نیز همین bypass مستقیم را برای streaming حفظ می‌کند.
- PR #335 از `StorageManager::downloadTo` استفاده می‌کند، سپس فایل کامل را از temp محلی stream می‌کند.

**تصمیم:** یک endpoint و یک مسیر StorageManager کافی است. مسیر مطلوب، بررسی metadata با `exists/size` و ارسال byte range با `streamRange` داخل response callback است؛ این کار هم remote streaming را نگه می‌دارد، هم فایل کامل را روی دیسک موقت کپی نمی‌کند. این نکته به PRهای #335 و #357 گزارش شده است.

## 7. مرزهای تجارت که باید حفظ شوند

- `Order` در main برای خریدهای فروشگاه و جریان خدمات سند استفاده می‌شود؛ مدل دارای relation به `TypingDocument` و `StoreOrderItem` است. این یک aggregate مشترک با item/snapshotهای دامنه‌ای است، نه دو جدول سفارش مستقل.
- `Payment` به `Order` متصل است.
- `Wallet` و `WalletTransaction` دفتر کیف پول کاربرند.
- موتور تجارت FARAST علاوه بر این‌ها، usage/reservation/charge/invoice/refund/entitlementهای خودش را دارد.

این دو سطح را نباید بدون تطبیق idempotency، callback، refund و ledger با هم ادغام کرد. PR #340 مسیر تقویت idempotency و retention مالی را دنبال می‌کند و باید به‌عنوان تغییر موازی بررسی شود؛ وضعیت آن هنوز ادغام‌شده نیست.




### اشتراک و سهمیه: دو مسیر plan/usage هنوز هم‌زمان فعال‌اند

در main فعلی، دو مسیر قابل تشخیص‌اند:

| مسیر | plan catalog | subscription | quota/usage |
|---|---|---|---|
| Legacy project billing | `farast_plans` | `farast_subscriptions` | ستون‌های legacy در `farast_usage_counters` یعنی `user_id/metric/used` |
| Professional commerce | `farast_commercial_plans` | همان جدول `farast_subscriptions` | `FarastEntitlement` و `FarastUsageCounter(scope_key/capability/quantity)` |

`ProfessionalCommerceService::subscribe` از `FarastCommercialPlan` شناسه `plan_id` را در `farast_subscriptions` می‌نویسد و entitlementهای جدید می‌سازد. اما `ProjectBillingService::includedAllowance` هنوز همان `plan_id` را به `farast_plans.id` وصل می‌کند و usage را از ستون‌های legacy می‌خواند. پس یک ستون `plan_id` در دو مسیر با دو catalog ID space متفاوت تفسیر می‌شود و counterهای جدید نیز لزوماً در محاسبه سهمیه پروژه دیده نمی‌شوند.

در خود catalog جدید نیز دو نمایش برای ارتباط plan و product وجود دارد: `farast_commercial_plans.product_code` و pivot `farast_commercial_plan_products(plan_id, product_id)`. مدل `FarastCommercialPlan` در main فقط `product_code` را fillable می‌کند و relation/pivot writer ندارد؛ `ProfessionalCommerceService::createPlan` نیز pivot را sync نمی‌کند. باید مشخص شود plan دقیقاً به یک product با code وصل است یا می‌تواند چند product داشته باشد؛ فعلاً دو ساختار برای یک مفهوم در schema وجود دارد.

همچنین `SubscriptionEntitlementService::sync` هر دو کلید `typing_pages` و `pages` را به capability یکسان `document.editing` map می‌کند. اگر هر دو در `quotas` یک plan باشند، iteration دوم entitlement اول را با status=`replaced` می‌بندد و entitlement دوم را می‌سازد؛ در مقابل `ProjectBillingService` مقدار `typing_pages` را با اولویت بر `pages` می‌خواند. بنابراین در حضور هر دو کلید، entitlement تجاری و project billing می‌توانند مقدار متفاوتی برای یک quota ببینند. این aliasها باید پیش از sync به یک مقدار canonical normalize شوند و تست conflict داشته باشند.

PR #340 Migration `2026_10_03_000004_canonicalize_farast_subscriptions.php` را برای انتقال subscriptionها به catalog جدید اضافه می‌کند، اما در head بررسی‌شده:
- جدول قدیمی را قبل از اثبات کامل mapping حذف می‌کند؛
- اگر عدد `plan_id` تصادفاً در catalog جدید موجود باشد، بدون تطبیق `code` پذیرفته می‌شود؛
- اگر mapping پیدا نشود، رکورد با `continue` حذف می‌شود؛
- `ProjectBillingService` همچنان join قدیمی `farast_plans` و ستون‌های usage قدیمی را نگه می‌دارد.

این خطر به PR #340 گزارش شده است. **تا زمانی که mapping plan ID و مصرف quota در هر دو مسیر canonical نشده، Migration ادغام اشتراک نباید اجرا شود.** آزمون upgrade باید IDهای متفاوت/متصادم، plan code، رکورد بدون mapping، تعداد subscriptionها، entitlementها و مقدار usage را تطبیق دهد.

## 8. قیمت‌گذاری: سه منبع داده با precedence متفاوت

بازبینی کد main نشان می‌دهد «قیمت» در یک محل واحد نگهداری نمی‌شود. این‌ها دامنه‌های قابل تفکیک‌اند، اما قرارداد precedence فعلی باید به‌عنوان ریسک ثبت شود.

| منبع | مدل/جدول | مصرف‌کننده و نقش |
|---|---|---|
| قواعد قدیمی | `PricingRule` / `pricing_rules` | تنظیمات ساده تایپ؛ `AdminController::updatePricing` فقط این جدول را می‌نویسد |
| سیاست قدیمی تجاری | `FarastPricingPolicy` / `farast_pricing_policies` | `PricingService::calculate` مستقیم از آن می‌خواند؛ Migration موتور تجاری، نسخه‌های فعال آن را به نسخه 1 منتقل می‌کند |
| سیاست نسخه‌دار | `FarastPricingPolicyVersion` / `farast_pricing_policy_versions` | `PricingEngine::resolve/quote`؛ immutable به‌جز پنجره اعتبار؛ `CommerceAuthorizationService` از آن استفاده می‌کند |

### مسیرهای واقعی

- `PricingService::ruleValue(key)` ابتدا `PricingEngine::resolve(key)` را می‌خواند؛ در نبود نسخه، به `FarastPricingPolicy` و سپس `PricingRule` fallback می‌کند.
- `PricingService::projectQuote`, `quote` و `estimateByPages` از همین precedence استفاده می‌کنند.
- `PricingService::calculate` مستقیماً `FarastPricingPolicy` را می‌خواند و از نسخه‌دار بودن `PricingEngine` عبور می‌کند.
- `ProjectBillingService` برای quote پروژه از `PricingService::projectQuote` استفاده می‌کند.
- `CommerceAuthorizationService` مستقیماً `PricingEngine` را برای quote/reservation/charge مصرف می‌کند.
- Migration `2026_10_02_000001_create_farast_commerce_engine.php` در صورت وجود `farast_pricing_policies`، سیاست‌های active را به `farast_pricing_policy_versions` با version=1 منتقل می‌کند.
- `PricingPolicyAdminService` قابلیت create/schedule/retire/activate نسخه‌ها را دارد، اما در Controller/Route inventory مصرف‌کننده HTTP برای آن ثبت نشده است.
- `AdminController::updatePricing` فقط `PricingRule::updateOrCreate` را اجرا می‌کند؛ پس اگر کلید در `FarastPricingPolicyVersion` یا `FarastPricingPolicy` موجود باشد، تغییر `pricing_rules` در مسیرهای دارای precedence بالاتر اثر نمی‌کند.

### شکاف تست و مالکیت

- `tests/Unit/PricingPolicyTest.php` مسیر legacy `PricingService::calculate` را با `FarastPricingPolicy` آزمایش می‌کند.
- `CommercePhase3Test` موتور تجاری نسخه‌دار را پوشش می‌دهد.
- در inventory فعلی، تستی که ثابت کند تغییر قیمت از UI ادمین همان quote نهایی مصرف‌شده در checkout/typing را تغییر می‌دهد، شناسایی نشد.
- وجود دو موتور لزوماً تکرار یک دامنه نیست: قیمت تایپ پروژه و قیمت مصرف capability می‌توانند policyهای متفاوت داشته باشند؛ اما نباید برای یک `policy_code` چند منبع فعال با precedence پنهان وجود داشته باشد.

**تصمیم لازم:** `FarastPricingPolicyVersion + PricingEngine` باید منبع canonical برای policyهای versioned باشد. `PricingPolicyAdminService` باید write API رسمی و audit‌شده آن باشد. قواعد `PricingRule` و `FarastPricingPolicy` باید برای هر key طبقه‌بندی شوند: یا به‌عنوان migration source فقط خواندنی باقی بمانند، یا برای کلیدهای مشخص به‌صورت صریح owner باشند. Admin UI نباید صرفاً `PricingRule` را به‌روزرسانی کند درحالی‌که runtime نسخه بالاتری را می‌خواند. پیش از حذف legacy، کلیدهای typing و policyهای فعلی باید migrate و quoteهای قبل/بعد regression-tested شوند.


## 9. قابلیت، سهمیه و مصرف: لایه‌های جدا با مرزهای لازم

در main چند کنترل مجزا وجود دارد که باید در نقشه دسترسی/تجارت به‌عنوان قراردادهای متفاوت ثبت شوند:

| مفهوم | منبع داده | سرویس تصمیم/عملیات | معنی |
|---|---|---|---|
| feature flag | `site_settings` + `user_capabilities` / `UserCapability` | `CapabilityService` | فعال بودن قابلیت‌هایی مانند `can_type/can_ai/can_voice` و سقف‌های پیکربندی‌شده |
| free typing allowance | `free_credits` / `FreeCredit` | `FreeQuotaService` | اعتبار هفتگی صفحات، با `mobile_hash + week_start` و قفل تراکنشی |
| daily AI abuse/rate cap | `ai_interactions` / `AiInteraction` | `AiQuotaService` | محدودیت تعداد درخواست AI در روز بر اساس `daily_ai_requests` |
| subscription/PAYG entitlement | `farast_entitlements`, reservations, usage events, charges, wallet reservations | `CommerceAuthorizationService` | رزرو، commit/release، محاسبه هزینه و settlement |
| legacy project subscription | `farast_subscriptions + farast_plans + farast_usage_counters` و `farast_projects.billing_state` | `ProjectBillingService` | محاسبه allowance و قفل/بازکردن خروجی پروژه؛ مسیر قدیمی که در بخش اشتراک ثبت شد |

این لایه‌ها الزاماً رقیب نیستند: feature flag، free tier، abuse limit و billable entitlement می‌توانند نقش متفاوت داشته باشند. مشکل وقتی رخ می‌دهد که یک عملیات بدون قرارداد مشخص فقط یکی از آن‌ها را بررسی کند یا quota را در چند ledger مستقل کم کند.

### مسیرهای مشاهده‌شده

- `EditorController`, `TypingPreflightController`, `PaymentController` و `UserFileController` مصرف‌کننده‌های `PricingService/FreeQuotaService` هستند.
- `AiQuotaService` سقف روزانه را از شمارش `AiInteraction` اعمال می‌کند.
- `EditorAiAssistService` رزرو و commit واقعی AI را از `CommerceAuthorizationService` انجام می‌دهد؛ در عین حال `EntitlementService` را در constructor تزریق می‌کند، اما در متد `assist` از آن استفاده نمی‌کند.
- `EntitlementService` یک مسیر مستقل `authorize/consume/grant` دارد که می‌تواند `FarastEntitlement.used_quantity` را مستقیم افزایش دهد، بدون ایجاد `FarastUsageReservation/FarastUsageEvent/FarastCharge`. در inventory فعلی، callsite عملیاتی قطعی برای این مسیر به‌جز تزریق بلااستفاده فوق شناسایی نشد.
- `OutputAuthorizationService` از `CommerceAuthorizationService` استفاده می‌کند و قبل از آن، پرداخت سفارش یا `FarastProject.output_state=unlocked` را هم می‌پذیرد.

### تصمیم لازم

1. `CapabilityService` فقط مالک feature flags و حدود پیکربندی باشد؛ نباید ledger مصرف یا charge را خودش انجام دهد.
2. `FreeQuotaService` فقط free-tier allowance مشخص را مدیریت کند؛ همه مصرف‌های billable باید به CommerceAuthorizationService برسند.
3. `AiQuotaService` فقط rate/abuse cap روزانه باشد و با usage billing اشتباه نشود.
4. `CommerceAuthorizationService` مرجع واحد reserve/commit/release/charge/refund باشد؛ مسیر `EntitlementService::consume` یا باید به آن delegate شود یا بعد از اثبات نبود مصرف‌کننده حذف/محدود شود.
5. `ProjectBillingService` نباید هم‌زمان subscription و usage legacy را جداگانه از منبع تجاری جدید بخواند؛ باید با migration اشتراک و quota یکپارچه شود.
6. برای هر endpoint حساس، ماتریس تست مشخص کند کدام لایه‌ها بررسی و کدام ledgerها تغییر می‌کنند؛ تست منفی باید ثابت کند free flag به‌تنهایی امکان دور زدن سقف یا charge را نمی‌دهد.


## 10. ممیزی و تاریخچه: ledgerهای جدا با تضمین‌های متفاوت

در main دو مسیر اصلی audit وجود دارد:

| هدف | سرویس | جدول | تضمین |
|---|---|---|---|
| ثبت عملیات مدیریتی/legacy | `AuditLogService::record` | `audit_logs` | ثبت action، actor، IP و meta؛ hash-chain در این سرویس وجود ندارد |
| رویداد دامنه‌ای FARAST | `AuditEventService::record` | `farast_audit_events` | `event_id`، payload checksum و previous checksum؛ `AuditIntegrityService` زنجیره را verify می‌کند |

`AuditIntegrityService` فقط `farast_audit_events` را بررسی می‌کند؛ سبز بودن integrity آن به معنی tamper-evident بودن `audit_logs` نیست. این دو ledger می‌توانند برای دو دامنه متفاوت باقی بمانند، اما UI/گزارش نباید آن‌ها را یک تاریخچه واحد با تضمین امنیتی یکسان معرفی کند.

در head ادغام‌نشده PR #347 دو ledger تخصصی دیگر نیز اضافه شده‌اند:
- `farast_access_audit_logs` برای تغییرهای نقش/مجوز/Scope و عملیات مرکز دسترسی.
- `farast_workflow_audit_logs` برای تغییر/اجرای workflow و وضعیت‌های اجرایی.

این تفکیک می‌تواند معتبر باشد، مشروط بر آنکه قرارداد retention، actor identity، correlation/event id، دسترسی خواندن و انتقال رخداد به تاریخچه مرکزی روشن باشد. پیش از ادغام PR #347 باید مشخص شود access/workflow logها ledger تخصصی‌اند یا باید event متناظر در `farast_audit_events` نیز ثبت شود؛ در هر حالت نباید ادعای hash-chain برای جدول‌هایی که آن را ندارند مطرح شود.

آزمون `AuditEventObservabilityTest` در main جدید، هویت incident را در تاریخچه observability بررسی می‌کند؛ این آزمون پوشش نمایش/trace است و جایگزین تست integrity زنجیره یا ثبت تمام عملیات حساس نیست.

## 11. تعارض معماری قطعی در خوشه توسعه محصول

بازبینی diff شاخه‌های باز #262، #265، #279، #318 و #333 نشان می‌دهد این‌ها PRهای مستقل و قابل ادغام جداگانه نیستند؛ چند فایل و حتی قرارداد Schema مشترک دارند.

### 11.1 دو تعریف ناسازگار برای Publication Review

| مورد | PR #262 | PR #333 |
|---|---|---|
| Migration | `2026_11_10_000010_create_store_product_publication_reviews.php` | `2026_11_11_000012_create_store_product_publication_reviews.php` |
| جدول | `store_product_publication_reviews` | همان جدول |
| مدل | `StoreProductPublicationReview` | همان نام کلاس |
| مالک درخواست | `requested_by` | `submitted_by` |
| وضعیت/نتیجه | `status, verdict, score` | `status, ai_verdict, ai_score` |
| snapshot | `snapshot_hash, reviewed_snapshot_hash, snapshot_json, findings_json, corrections_json` | `intake_session_id, ai_review_json` |
| تأیید مدیر | `manager_approved_by, manager_approved_at` | `manager_reviewed_by, manager_reviewed_at, manager_notes` |
| cardinality محصول | index روی product/status؛ چند review برای محصول ممکن | `product_id` unique؛ یک review برای هر محصول |

هر دو Migration با `Schema::create('store_product_publication_reviews')` شروع می‌شوند و هر دو همان نام مدل PHP را تعریف می‌کنند، اما schema و state machine آن‌ها متفاوت است. ادغام هر دو به‌صورت مستقل باعث شکست migration دوم و overwrite معنای مدل/فیلدها می‌شود.

### 11.2 دو قرارداد intake و antivirus روی ingestion

- PR #262 فایل‌محور است و `StoreProductIngestion` را با `product_id` nullable و `relative_path` گسترش می‌دهد.
- PR #333 یک aggregate جدید `StoreProductIntakeSession` و `StoreProductIntakeFile` ایجاد می‌کند؛ این‌ها staging، نوع محصول، فایل‌های intake و supervisor review را نگه می‌دارند.
- PR #265 Migration با نام `2026_11_10_235959_add_optional_antivirus_to_product_ingestions.php` را اضافه می‌کند.
- PR #318 Migration با نام `2026_11_11_000001_add_optional_antivirus_to_product_ingestions.php` را اضافه می‌کند.

دو Migration antivirus در هر دو، همین پنج ستون را به `store_product_ingestions` اضافه می‌کنند: `antivirus_requested`, `antivirus_status`, `antivirus_engine`, `antivirus_version`, `antivirus_scanned_at`. هر دو مجوز `store.security_scan` را نیز در کاتالوگ ثبت می‌کنند؛ PR #318 علاوه بر آن `store.publish_products` را اضافه می‌کند. اجرای هر دو Migration روی یک دیتابیس به افزودن مجدد ستون‌ها می‌رسد.

### 11.3 هم‌پوشانی اجرایی

| PR | نقش پیشنهادی در خوشه | فایل‌های مشترک/حساس |
|---|---|---|
| #262 | AI-supervised publication روی ingestion موجود | `AdminStoreController`, `StoreProduct`, `StoreProductIngestion`, `StoreProductPublicationReview`, `ProductSupervisorClient`, `routes/store_routes.php` |
| #265 | پایه اسکن آنتی‌ویروس اختیاری | `AdminStoreController`, `StoreProductIngestion`, `OptionalAntivirusScanner`, `product-ingestion.js` |
| #279 | مجوز و bypass اسکن در publication | `AdminStoreController`, `StoreController`, `ReviewProductForPublication`, `ProductPublicationReadiness`, `ProductSupervisorSnapshotBuilder`, `product-ingestion.js` |
| #318 | file-first intake و review asset/publication | `AdminStoreController`, `ReviewProductForPublication`, `ProductPublicationReadiness`, `ProductSupervisorClient`, `store_routes.php`, scanner |
| #333 | type-first intake با session/file aggregate جدید | `AdminStoreController`, `StoreProduct`, `StoreProductPublicationReview`, `store_routes.php`, `form/index/course.blade.php` |

این خوشه در کنار PR #335 قرار می‌گیرد که همان `AdminStoreController` و `StoreController` را برای centralize کردن artifact storage تغییر می‌دهد. بنابراین انتخاب intake و publication state machine باید قبل از rebase نهایی storage انجام شود؛ در غیر این صورت یک کنترلر بزرگ با چند مسیر موازی upload/review/publish ساخته خواهد شد.

**تعارض درون خود PR #333:** این PR علاوه بر `StoreProductIntakeSession/StoreProductIntakeFile`، متدهای قدیمی مبتنی بر `StoreProductIngestion` را نیز در همان `AdminStoreController` نگه می‌دارد. مدل جدید فایل، فیلدهایی مانند `staging_disk/path`, `managed_file_id`, `original_name`, `mime`, `size_bytes`, `sha256`, `inspection_json`, `is_primary` و `expires_at` دارد که بخش زیادی از آن‌ها در `StoreProductIngestion` فعلی هم وجود دارند؛ اما رابطه مستقیم `ingestion_id` بین دو مدل تعریف نشده است. پس در head این PR دو مسیر فعال staging/upload وجود دارد، نه یک مسیر جایگزین‌شده.

**تعدد jobهای publication/review در شاخه‌های باز:**
- #262: `RunProductPublicationReview`
- #318/#279: `ReviewProductForPublication` و `ReviewProductFileAsset`
- #333: `ReviewProductPublicationRequest` و `ReviewProductIntakeFile`

این jobها به یک supervisor و یک محصول اشاره می‌کنند، اما ورودی، رکورد state و قرارداد خروجی یکسانی ندارند. `ProcessProductDocument` باید همان job پردازش سند باقی بماند؛ AI review فایل و publication نباید آن را با pipeline مستقل دیگری جایگزین کنند.

### 11.4 تصمیم معماری لازم

**منبع حقیقت فعلی در main:**
- کاتالوگ و هویت محصول: `StoreProduct`.
- ingest فایل فعلی: `StoreProductIngestion`.
- پردازش محتوا و خروجی‌های مشتق‌شده: `StoreProductDocument` و artifact/page/chunk/factهای مرتبط.
- آمادگی انتشار: `ProductPublicationReadiness`.
- هماهنگی supervisor: `ProductSupervisorClient` و snapshot builder.
- فایل‌های مدیریت‌شده: `StorageManagedFile + StorageManager`؛ با شکاف‌های ثبت‌شده در بخش Storage.

**قاعده ادغام پیشنهادی:**
1. فقط یک state machine برای intake انتخاب شود؛ اگر session چندمرحله‌ای لازم است، باید مالکیت آن نسبت به `StoreProductIngestion` روشن باشد و دو جدول staging موازی با status مستقل نسازند.
2. فقط یک `StoreProductPublicationReview` با schema و lifecycle واحد باقی بماند.
3. antivirus یک قرارداد مشترک و یک Migration idempotent داشته باشد؛ scanner اختیاری و policy bypass باید به همان نتیجه canonical وصل باشند.
4. همه uploadهای اصلی، preview، audio، cover و image از StorageManager عبور کنند؛ staging/quarantine استثنای موقت با TTL و مالک مشخص است.
5. publication فقط از یک سرویس readiness/review استفاده کند؛ کنترلرها صرفاً orchestrate کنند و state machine مستقل نسازند.
6. Migrationها از روی دیتابیس خالی و دیتابیس upgradeشده اجرا شوند؛ تست باید اثبات کند هر table/column فقط یک‌بار ساخته می‌شود.
7. `AdminStoreProductWorkflowTest` نباید به‌تنهایی پوشش چند PR را تضمین کند؛ تست قرارداد end-to-end باید یک مسیر واحد را از type selection تا secure upload، review، publish، entitlement و download طی کند.

**وضعیت checkها در آخرین snapshot:**
- PR #333: `mergeable=false / dirty` و check-run ندارد.
- PR #318: `laravel` ناموفق؛ تست عمومی و Reader در checkهای مشاهده‌شده موفق بودند.
- PR #262: `laravel`, `test` و `agent` ناموفق.
- PR #279: `laravel` ناموفق؛ `test` و Reader در checkهای مشاهده‌شده موفق بودند.
- PR #265: check-run قابل مشاهده ندارد.

**وضعیت:** هیچ‌یک از PRهای #262/#265/#279/#318/#333 را نمی‌توان صرفاً به‌دلیل سبز بودن مستقل، جداگانه ادغام کرد. لازم است یک PR ادغام‌شده و rebased روی main جاری ساخته شود که schema نهایی را تثبیت و فایل‌های هم‌پوشان را یک‌بار تغییر دهد.


## 12. وضعیت CI خط مبنای جدید

- commit ممیزی Routeها `c3067f69...`: `laravel`, `voice-service-contract-tests`, `document-audio-smoke`, `test`, `agent` موفق؛ `reader-browser-regression` و `voice-model-smoke` skipped.
- commit عملیات Storage `68c61461...`: `test` و `agent` موفق.
- commit `18e79a8b...`: `voice-model-smoke` skipped؛ checkهای `voice-service-contract-tests`, `document-audio-smoke`, `laravel`, `agent`, `test` در snapshot قبلی queued بودند.
- آخرین commit main یعنی `7d68c678...`: `agent` و `test` queued؛ بنابراین آخرین main هنوز CI نهایی سبز ندارد و نباید آن را pass اعلام کرد.

commit `18e79a8b...` آزمون `BusinessCommerceLifecycleTest` را اضافه می‌کند که مسیر catalog → cart → order → wallet payment → entitlement → course enrollment → notification و replay idempotency را end-to-end بررسی می‌کند. PR #365 بسته شد اما merged نبود؛ آزمون در main با commit مستقیم ثبت شده است.

## 13. ترتیب ادامه ممیزی/تثبیت

1. دریافت نتیجه نهایی CI روی `7d68c678...`؛ در صورت شکست، تشخیص بر اساس job و log.
2. rebase و حل تعارض PR #347 روی main جدید؛ تست‌های منفی Scope و قرارداد role/permission باید سبز شوند.
3. rebase PR #335 و انتقال صرفاً تغییرهای product artifact؛ backup code قدیمی حذف شود و audio streaming از `StorageManager::streamRange` استفاده کند.
4. یکپارچه‌سازی خوشه محصول #262/#265/#279/#318/#333 در یک pipeline و schema canonical؛ Migrationهای رقیب جداگانه merge نشوند.
5. اصلاح اشتراک/usage/pricing در PR #340 با mapping داده‌ای امن و یک منبع entitlement.
6. اجرای runtime inventory روی main: `route:list`, migration fresh install/upgrade، تست‌های منفی Scope و مالکیت فایل.
7. تکمیل call graph برای هر سرویس با مصرف‌کننده‌ها و تست‌های واقعی؛ سپس تعیین موارد retain/consolidate/deprecate.

## 14. معیار تکمیل

این addendum وضعیت snapshot را ثبت می‌کند، نه پایان کل مرحله دوم را. مرحله دوم تنها وقتی بسته می‌شود که:
- Routeها و نام‌ها در runtime یکتا باشند؛
- هر admin mutation route، permission و Scope سمت سرور داشته باشد؛
- فایل‌های دامنه‌ای مدیریت‌شده با رجیستری مرکزی reference قابل reconciliation داشته باشند؛
- ledgerهای سفارش، پرداخت، کیف پول، invoice، charge و refund به‌طور کامل ردیابی شوند؛
- تمام فایل‌های جدید بعد از snapshot در ماتریس traceability منعکس شوند؛
- تست‌های CI و migration upgrade روی commit نهایی سبز باشند.

**وضعیت مرحله دوم: در حال انجام؛ نقشه‌های اصلی ثبت شده‌اند، اما سه شکاف امنیت، Storage و تطبیق مالی باید با PRهای فعال و شواهد runtime بسته شوند.**
