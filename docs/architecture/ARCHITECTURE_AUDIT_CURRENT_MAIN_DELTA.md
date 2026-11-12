# ممیزی تکمیلی معماری بر مبنای main جاری

> این سند مکمل inventoryهای معماری ادغام‌شده در PR #371 است؛ آن inventoryها snapshot تاریخی `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf` را ثبت می‌کنند.  
> خط مبنای به‌روزشده این تکمیل: `main` در commit `638bf5fc6ed2709ad7f0cd072aff6ad826499f6c`، تاریخ 2026-11-11.  
> این سند تغییرات بعد از snapshot، یافته‌های تازه و PRهای فعال مرتبط را جدا می‌کند؛ هیچ تغییری در دارایی‌های شاخه‌های همکاران اعمال نمی‌کند.

## 1. وضعیت فعلی مخزن

| شاخص | مقدار در خط مبنای جاری |
|---|---:|
| Git tree entries | 1,212 |
| Migration PHP | 119 |
| Model PHP | 105 |
| Service PHP | 115 |
| Controller PHP | 53 |
| Test PHP | 133 |

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


### ترتیب Migrationها

در 116 Migration جاری، ده گروه timestamp تکراری وجود دارد؛ نام فایل کامل همچنان یکتا است و Laravel آن‌ها را بر اساس نام کامل مرتب می‌کند، پس تکرار timestamp به‌تنهایی خطا نیست. اما ترتیب dependency باید در fresh install ثابت بماند. گروه‌ها:
- `2026_09_12_000012`: افزودن email به users / ایجاد AI interactions
- `2026_10_03_000001`: phase5 platform layer / professional commercial engine
- `2026_10_19_000002`: support SLA / announcements
- `2026_11_07_000001`: workflow retry ledger / workspace permissions
- `2026_11_08_000001`: preview access level / product ingestion & knowledge
- `2026_11_09_000012`: reader discussions / email delivery tracking
- `2026_11_10_000001`: marketing consent / storage management
- `2026_11_10_000005`: account security / coupon-order link
- `2026_11_10_000006`: trusted devices / internal messaging
- `2026_11_10_000011`: storage file key hash / CRM incentives

هیچ‌کدام از این گروه‌ها به‌تنهایی اثبات شکست migration نیستند؛ معیار پذیرش این است که ترتیب lexical و dependency واقعی با `migrate:fresh` و upgrade از snapshot production تست شود. دو migration تکراری antivirus و دو create-table متعارض محصول که در بخش 11 ثبت شده‌اند فعلاً فقط در PRهای باز هستند و در شمار Migrationهای main نیستند.

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

### محدودیت فعلی hash-chain

`AuditEventService` checksum را از `event_type/user_id/project_id/aggregate_type/aggregate_id/source/payload_meta` می‌سازد؛ `event_id` و timestampهای ثبت در checksum وارد نمی‌شوند. بنابراین تغییر event_id یا created_at به‌تنهایی توسط `AuditIntegrityService` کشف نمی‌شود. همچنین `lockForUpdate` فقط آخرین row موجود را قفل می‌کند؛ در جدول خالی، دو insert هم‌زمان می‌توانند هر دو previous_checksum=null بگیرند و دو ریشه بسازند.

آزمون فعلی `AuditIntegrityServiceTest` فقط یک event سالم را verify می‌کند؛ chain چندرویدادی، دستکاری payload، event_id، timestamp، previous_checksum و concurrency در آن پوشش ندارد. برای ادعای tamper-evident بودن، payload canonical باید event identity و زمان/sequence را هم شامل شود، append باید با یک lock anchor پایدار serialize شود، و تست‌های tamper/concurrency اضافه شوند.



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

**حفاظت شاخه:** پاسخ GitHub برای branch جاری `main` هنوز `protected=false` و `required_status_checks.enforcement_level=off` گزارش می‌کند. بنابراین حتی با CI سبز، هیچ الزام فنی مانع push مستقیم یا merge بدون check اجباری نیست. این یک مانع اعتمادپذیری تحویل است و باید با Ruleset/Branch Protection شامل Required Status Checks و جلوگیری از force-push برطرف شود؛ وضعیت فعلی را نباید «CI اجباری» توصیف کرد.

## 13. ترتیب ادامه ممیزی/تثبیت

1. دریافت نتیجه نهایی CI روی `7d68c678...`؛ در صورت شکست، تشخیص بر اساس job و log.
2. rebase و حل تعارض PR #347 روی main جدید؛ تست‌های منفی Scope و قرارداد role/permission باید سبز شوند.
3. rebase PR #335 و انتقال صرفاً تغییرهای product artifact؛ backup code قدیمی حذف شود و audio streaming از `StorageManager::streamRange` استفاده کند.
4. یکپارچه‌سازی خوشه محصول #262/#265/#279/#318/#333 در یک pipeline و schema canonical؛ Migrationهای رقیب جداگانه merge نشوند.
5. اصلاح اشتراک/usage/pricing در PR #340 با mapping داده‌ای امن و یک منبع entitlement.
6. اجرای runtime inventory روی main: `route:list`, migration fresh install/upgrade، تست‌های منفی Scope و مالکیت فایل.
7. تکمیل call graph برای هر سرویس با مصرف‌کننده‌ها و تست‌های واقعی؛ سپس تعیین موارد retain/consolidate/deprecate.


## 14. دفتر مالکیت canonical قابلیت‌ها

این جدول مرجع عملیاتی فعلی و مرزهای حل‌نشده را خلاصه می‌کند. «canonical» به معنی مالک اصلی داده/عملیات است؛ لایه‌های مشتق‌شده یا تخصصی باید به این مالک ارجاع دهند.

| قابلیت | منبع داده canonical | مالک عملیات | مصرف‌کننده/ورودی اصلی | وضعیت منبع حقیقت |
|---|---|---|---|---|
| هویت کاربر | `users` / `User` | Auth/security services | AuthController، account/security routes | مشخص |
| RBAC پایه | `permissions + role_permissions + users.role` | `AuthorizationService` | `EnsurePermission`, `AdminConsoleService`, Policy | در main ساده؛ مدل چندنقشی PR #347 هنوز pending و نیازمند تعیین تکلیف grantهای پایه/سفارشی |
| Scope مدیریت | `organizations/teams` + تنظیمات scope | `ResolveAdminScope` و `AdminConsoleService` | admin.section middleware | main دارای scope پایه؛ PR #347 محدودیت per-admin را اضافه می‌کند |
| feature flags | `site_settings + user_capabilities` | `CapabilityService` | Editor/AI/Voice/Commerce | مشخص؛ با entitlement اشتباه نشود |
| کاتالوگ محصول | `store_products` / `StoreProduct` | Store product domain services | StoreController، AdminStoreController | مشخص |
| intake فایل محصول | `store_product_ingestions` / `StoreProductIngestion` | upload/preflight + `ProcessProductDocument` | AdminStoreController | منبع فعلی؛ خوشه #262/#265/#279/#318/#333 هنوز pipeline رقیب می‌سازد |
| محتوای پردازش‌شده محصول | `store_product_documents` و artifact/page/chunk/factها | `ProcessProductDocument` + processing services | Reader، product operations | مشخص؛ خروجی مشتق‌شده، نه جایگزین کاتالوگ |
| آمادگی/انتشار محصول | `StoreProduct.status + published_at` + readiness state | `ProductPublicationReadiness` | AdminStoreController | review state هوشمند هنوز بین PRهای باز canonical نشده |
| فایل‌های مدیریت‌شده | `storage_managed_files + storage_volumes` | `StorageManager` | AdminStorage، Store، Support، Messenger | مالک مرکزی مشخص؛ برخی call-siteها هنوز bypass می‌کنند |
| سبد و سفارش فروشگاه | `store_carts/store_cart_items`, `orders/store_order_items` | `StoreCartService` | StoreCartController | مشخص |
| پرداخت فروشگاه | `payments` / `Payment` | PaymentController + gateway service | checkout/callback | مشخص؛ idempotency مالی در PR #340 pending |
| کیف پول | `wallets/wallet_transactions` | WalletService/WalletController | checkout/top-up/admin adjustment | مشخص؛ با wallet reservationهای FARAST یکی نیست |
| محصول تجاری پلتفرم | `farast_commercial_products` / `FarastCommercialProduct` | `ProfessionalCommerceService` | تعریف قابلیت/سرویس قابل فروش پلتفرم | aggregate جدا از `StoreProduct` فروشگاه؛ رابطه plan/product نیازمند یک canonical link |
| پلن/entitlement تجاری | `farast_commercial_plans`, `farast_entitlements` | `ProfessionalCommerceService`, `SubscriptionEntitlementService`, `CommerceAuthorizationService` | platform capabilities | مسیر جدید؛ legacy `farast_plans/ProjectBillingService` هنوز ناسازگار |
| قیمت policy نسخه‌دار | `farast_pricing_policy_versions` | `PricingEngine` + `PricingPolicyAdminService` | CommerceAuthorizationService | موتور runtime نسخه‌دار؛ write UI و fallback legacy باید یکپارچه شوند |
| free-tier quota | `free_credits` | `FreeQuotaService` | typing free allowance | مشخص به‌عنوان free tier |
| daily AI rate cap | `ai_interactions` | `AiQuotaService` | Editor AI | مشخص به‌عنوان abuse/rate cap، نه billing ledger |
| course enrollment/progress | `store_course_enrollments/store_course_lesson_progress` | `StoreCourseProgressService` | Learning + CRM | محاسبه مشترک و canonical |
| تیکت پشتیبانی | `tickets/ticket_messages` | `SupportTicketService` | SupportController | مشخص |
| مکالمه داخلی | `conversations/conversation_messages/participants` | `MessengerAccessService` | MessengerController | aggregate جدا از ticket؛ مشخص |
| اعلان شخصی | `farast_notifications` | `NotificationService` | NotificationController | Route ownership در PR #371 اصلاح و CI آن سبز شده |
| اطلاعیه عمومی | `announcements` | `AnnouncementService` | AnnouncementController | دامنه جدا از اعلان شخصی |
| workflow | `farast_workflows/runs/events/event_workflows` | `WorkflowEngine` | PlatformCompletionController + scheduler | مشخص؛ job/event ledger باید حفظ شود |
| audit domain | `farast_audit_events` | `AuditEventService` + `AuditIntegrityService` | observability/admin | hash-chain فقط برای همین ledger |
| audit legacy admin | `audit_logs` | `AuditLogService` | AdminController | ثبت عمومی بدون hash-chain؛ تضمین متفاوت |
| API/HTTP composition | `routes/web.php` و فایل‌های requireشده | Laravel router + middleware stack | صفحات و endpointها | API-prefixed endpointها در web routes هستند؛ `routes/api.php` وجود ندارد |

## 15. معیار تکمیل

این addendum وضعیت snapshot را ثبت می‌کند، نه پایان کل مرحله دوم را. مرحله دوم تنها وقتی بسته می‌شود که:
- Routeها و نام‌ها در runtime یکتا باشند؛
- هر admin mutation route، permission و Scope سمت سرور داشته باشد؛
- فایل‌های دامنه‌ای مدیریت‌شده با رجیستری مرکزی reference قابل reconciliation داشته باشند؛
- ledgerهای سفارش، پرداخت، کیف پول، invoice، charge و refund به‌طور کامل ردیابی شوند؛
- تمام فایل‌های جدید بعد از snapshot در ماتریس traceability منعکس شوند؛
- تست‌های CI و migration upgrade روی commit نهایی سبز باشند.

**وضعیت مرحله دوم: در حال انجام؛ نقشه‌های اصلی ثبت شده‌اند، اما سه شکاف امنیت، Storage و تطبیق مالی باید با PRهای فعال و شواهد runtime بسته شوند.**


## 16. تطبیق نهایی با main در commit `29d643cca59af85a15071c94423e43ef8f22cc69`

این بخش، وضعیت‌های قدیمی‌تر بخش‌های 12 و 13 را برای خط مبنای جدید جایگزین می‌کند. مقایسه Git از `7d68c678a8244578286958c4d54f5ad0194217bb` تا HEAD فعلی main سه commit و هشت فایل تغییر‌یافته را نشان می‌دهد. تغییرها شامل تکمیل readiness/recovery، اصلاحات release workflow و افزودن job مستقل MySQL برای آزمون Audit Ledger است. تعداد فایل‌های inventoryشده تغییر نکرده است: 1,188 ورودی Git tree، 116 Migration، 105 Model، 113 Service، 52 Controller و 123 فایل تست PHP.

### 16.1 شواهد CI روی main جاری

نتیجه Check Runها برای commit بالا:

| Check | نتیجه | تفسیر |
|---|---|---|
| `agent` | موفق | بررسی Agent روی همین commit |
| `voice-service-contract-tests` | موفق | قرارداد سرویس صوت |
| `document-audio-smoke` | موفق | Smoke سرویس صوت سند |
| `reader-browser-regression` | موفق | رگرسیون مرورگر Reader |
| `voice-model-smoke` | skipped | این workflow برای شاخه دیگری شرطی است |
| `laravel` | ناموفق | ۵ شکست در `AuditEventObservabilityTest`؛ 635 تست دیگر موفق |
| `FARAST Phase 3 Commerce Tests` | ناموفق | همان ۵ شکست کلاس `AuditEventObservabilityTest`؛ شکست مستقل از سناریوهای Commerce گزارش نشده |
| `audit-mysql-integrity` | ناموفق | اجرای Migration روی MySQL 8.4 پیش از رسیدن به تست‌ها متوقف شد |

بنابراین CI خط مبنای جاری سبز نیست. موفقیت Browser، Agent و سرویس‌های صوتی، شکست‌های Laravel و MySQL را جبران نمی‌کند.

### 16.2 شکست واقعی Migration در MySQL

لاگ job `audit-mysql-integrity` نشان می‌دهد clean install روی MySQL 8.4 در Migration زیر متوقف می‌شود:

`database/migrations/2026_09_30_000004_add_feedback_trust_and_audit_ledger.php`

خطای دقیق:

`SQLSTATE[42S22]: Column not found: 1054 Unknown column 'confidence' in 'farast_feedback_evidence'`

Migration می‌خواهد ستون `frequency` را با `after('confidence')` اضافه کند؛ اما ساختار اولیه‌ای که Migration قبلی `2026_09_30_000003_create_farast_platform_registry.php` برای `farast_feedback_evidence` می‌سازد فقط این ستون‌ها را دارد: `id, user_id, source_type, source_id, kind, body, payload, created_at, updated_at`. ستون `confidence` در آن تعریف وجود ندارد.

ناهماهنگی فقط به همین ستون محدود نیست. مدل `FarastFeedbackEvidence` فیلدهای `feedback_id`, `project_id`, `source`, `tool_id`, `tool_version_id`, `evidence_type`, `input_meta`, `output_meta`, `confidence`, `frequency`, `independent_confirmations`, `status`, `reviewer_notes` و `checksum` را قابل‌نوشتن اعلام می‌کند؛ اما Migrationهای بررسی‌شده همه این ستون‌ها را ایجاد نمی‌کنند. `FeedbackPipelineService` نیز همین قرارداد را در insertها مصرف می‌کند.

همین الگوی drift در aggregateهای مرتبط هم دیده می‌شود:
- `farast_review_cases` در Migration اولیه فقط `code/status/payload` دارد، درحالی‌که `FeedbackPipelineService` فیلدهای `evidence_id, reviewer_id, decision, reviewed_at, notes` را می‌نویسد.
- `farast_approved_knowledge` در Migration اولیه `code/title/body/metadata` دارد، درحالی‌که سرویس فیلدهای `review_case_id, capability_code, knowledge_version, content, status, approved_by, approved_at, checksum` را می‌نویسد.
- `farast_evaluation_datasets` و `farast_evaluation_cases` نیز در تعریف اولیه با فیلدهایی که سرویس برای version/status/input/expected/tool references مصرف می‌کند تطابق کامل ندارند.

**نتیجه معماری:** برای این خوشه، منبع حقیقت Schema بین Migration، Model و Service هم‌راستا نیست. این نقص باید با Migration افزایشی و سازگار با دیتابیس‌های نصب‌شده اصلاح شود؛ تغییر کور Migration قدیمی به‌تنهایی برای محیط‌هایی که آن Migration را قبلاً اجرا کرده‌اند کافی نیست. تا زمانی که clean install و upgrade روی MySQL هر دو سبز نشده‌اند، مرحله دوم از منظر schema/runtime بسته نیست.

### 16.3 شکست‌های Audit Observability روی main

در همان HEAD، `laravel` و `Phase 3 Commerce Tests` پنج شکست از کلاس `AuditEventObservabilityTest` گزارش کرده‌اند:
- شناسه‌های trace در metadata رخداد ممیزی ثبت نشده‌اند؛
- کلاس‌های `FarastTraceContext` و `FarastObservabilityService` در main وجود ندارند؛
- تست ترتیب قفل append پایدار دفترکل را پیدا نمی‌کند.

این موارد در PR #353 پیاده‌سازی شده‌اند، اما تا زمان merge شدن PR، بخشی از main نیستند و نباید به‌عنوان قابلیت تکمیل‌شده main ثبت شوند.

### 16.4 وضعیت فعلی PRهای اثرگذار

| PR | وضعیت مشاهده‌شده | تصمیم |
|---:|---|---|
| #347 | باز، `mergeable=false` | هسته RBAC/Workflow هنوز با main جاری قابل ادغام مستقیم نیست؛ ابتدا تعارض‌ها و قرارداد grant/scope حل شوند. |
| #335 | باز، `mergeable=false` | تغییرهای Storage/Product artifact با main همگام نیست؛ ادغام مستقل ممنوع تا قرارداد Storage و backup یکپارچه شود. |
| #340 | باز، `mergeable=false` | تغییرهای مالی نیازمند rebase و آزمون upgrade/idempotency روی آخرین main هستند. |
| #353 | باز، `mergeable=true`؛ head برابر `231b11138bb2ca5eb9fbd66db9b9c4b09597c666` | پیاده‌سازی observability و اصلاح Trace Context در شاخه موجود است؛ `agent` و `Phase 3 test` روی head مشاهده‌شده موفق‌اند، اما `laravel` و `audit-mysql-integrity` در حال اجرا هستند. تا پایان همه checkها merge نشود. |
| #409 | همین addendum مستنداتی | باید با main در `29d643cca59af85a15071c94423e43ef8f22cc69` همگام شود؛ snapshot قبلی `7d68c678...` دیگر خط مبنای جاری نیست. |

### 16.5 گیت‌های باقی‌مانده برای پذیرش مرحله دوم

1. هم‌راستاسازی Schema خوشه Feedback/Review/Evaluation با Model و Service، با Migration افزایشی برای دیتابیس‌های موجود و اصلاح مسیر clean install.
2. اجرای مجدد clean install و upgrade روی MySQL 8.4 و SQLite؛ آزمون باید ستون‌ها، indexها و Foreign Keyها را پس از Migration introspect کند، نه اینکه صرفاً exit code Migration را بسنجد.
3. تکمیل CI روی head نهایی PR #353 و تعیین تکلیف شکست‌های Audit Observability؛ سپس ادغام کنترل‌شده آن.
4. rebase و بازبینی مجدد PRهای #347، #335 و #340 روی main جاری؛ وضعیت قبلی آن‌ها قابل انتقال به head جدید نیست.
5. اجرای `route:list` و تست یکتایی نام Routeها در CI حفظ شود؛ تست `NotificationRouteOwnershipTest` پس از PR #371 مالکیت هشت endpoint اعلان را کنترل می‌کند.
6. inventory Storage و مالی فقط وقتی بسته شود که مسیرهای bypass و منبع داده موازی با تست‌های منفی/مثبت و آزمون reconciliation تعیین تکلیف شده باشند.

**وضعیت مرحله دوم در خط مبنای `29d643c`:** inventoryهای ایستا و traceability ثبت شده‌اند و مالکیت Route اعلان با تست runtime تثبیت شده است؛ اما ناسازگاری Schema در clean install MySQL و چند شکاف اجرایی در RBAC، Storage، مالی و Audit هنوز باز هستند. این مرحله برای پذیرش نهایی بسته نیست.


## 17. جمع‌بندی نهایی ممیزی مرحله دوم روی main در commit `638bf5fc6ed2709ad7f0cd072aff6ad826499f6c`

این بخش نتیجه بخش 16 را با وضعیت واقعی main پس از ادغام PRهای #412 و #442 جایگزین می‌کند. شمارش ماشینی Git tree برای همین main انجام شده است: 1,212 ورودی، 119 Migration، 105 Model، 115 Service، 53 Controller و 133 فایل تست PHP.

### 17.1 رفع یافته‌های Schema که در CI واقعی آشکار شدند

**Feedback Evidence و Audit Ledger — PR #412، ادغام‌شده**

- Migration `2026_09_30_000004_add_feedback_trust_and_audit_ledger.php` دیگر به ترتیب فیزیکی ستون‌های ناموجود وابسته نیست و قرارداد Feedback Evidence را با ستون‌های لازم هم‌راستا می‌کند.
- Migration `2026_11_11_230000_reconcile_farast_feedback_evidence_schema.php` برای ارتقای دیتابیس‌های قبلی و ایجاد indexهای ترکیبی افزایشی است.
- آزمون `FarastFeedbackEvidenceSchemaTest` وجود ستون‌ها و indexهای لازم را assert می‌کند و در job مستقل MySQL اجرا می‌شود.
- هسته Audit Ledger در PR #412 با checksum نسخه ۲، canonicalization سازگار با JSON دیتابیس، حفظ مسیر legacy v1، append serialization و verification جریانی تکمیل شد.

**Review / Approved Knowledge / Evaluation — PR #442، ادغام‌شده**

- Migration `2026_11_11_230200_reconcile_feedback_review_evaluation_schema.php` ستون‌ها و indexهای مصرف‌شده توسط `FeedbackPipelineService` را در جدول‌های موجود تکمیل می‌کند؛ جدول یا موتور موازی ایجاد نشده است.
- Migration افزایشی است و rollback آن داده‌های review و knowledge را حذف نمی‌کند.
- `FeedbackPipelineSchemaTest` از مسیر واقعی سرویس، ثبت feedback، queue review، approve/reject، ذخیره دانش تأییدشده، ساخت evaluation case و promotion نسخه ابزار را اجرا می‌کند.
- همین تست در job MySQL 8.4 قرار گرفته است؛ پس قرارداد فقط با SQLite سنجیده نمی‌شود.

### 17.2 شواهد Runtime و CI

روی head نهایی PR #442 برابر `cba8311e62a42a477dbf74412666f06970b788dc` همه checkهای لازم موفق شدند:

| Check | نتیجه |
|---|---|
| Laravel کامل، شامل تست‌ها و Playwright | موفق |
| MySQL 8.4 migration و audit/schema tests | موفق |
| Phase 3 Commerce | موفق |
| Phase 4 Agent | موفق |
| Voice service contract | موفق |
| Document audio smoke | موفق |
| Agent workflow | موفق |
| Reader browser regression | skipped، چون Laravel موفق بود |
| Voice model smoke | skipped، چون شرط شاخه مخصوص آن برقرار نبود |

سه فایل اجرایی PR #442 در main جاری با همان blobهای تأییدشده ادغام‌شده‌اند: workflow، Migration جدید و تست سرتاسری. بنابراین شواهد CI مربوط به همان محتوای اجرایی است که اکنون در main قرار دارد. PR #412 نیز پیش‌تر با FARAST CI، MySQL 8.4، Phase 3 و Phase 4 سبز ادغام شده است.

### 17.3 وضعیت فعلی PRهای هم‌پوشان

| PR | وضعیت | مسیر بعدی |
|---:|---|---|
| #371 | merged | مالکیت Route اعلان و یکتایی نام Routeها در main تثبیت شده است. |
| #412 | merged | مرحله ۹ مشاهده‌پذیری و Audit Ledger در main است. |
| #442 | merged | قرارداد Schema و اجرای Feedback/Review/Evaluation در main است. |
| #347 | open، `mergeable=false` | کار مرحله ۳ RBAC؛ نیازمند rebase و حل تعارض روی main جدید. |
| #335 | open، `mergeable=false` | کار مرحله ۵ Storage؛ نیازمند rebase و آزمون یکپارچه با چرخه فایل. |
| #340 | open، `mergeable=false` | کار مالی؛ نیازمند rebase و آزمون idempotency/reconciliation. |
| #438 | closed، not merged | superseded؛ اصلاح Feedback Evidence در #412 و بخش Review/Evaluation در #442 جایگزین شده است. |
| #353 و #426 | closed، not merged | superseded by #412؛ شاخه‌های قدیمی observability نباید جداگانه ادغام شوند. |

### 17.4 نتیجه و مرز اعتبار ممیزی

**خروجی ممیزی مرحله دوم برای مخزن و main تکمیل شد.** دفتر منابع حقیقت قابلیت‌ها، ماتریس traceability، Route ownership، inventory مدل/سرویس/کنترلر/Migration، call-siteها، ERD سطح اول، جریان‌های بین‌ماژولی و دفتر هم‌پوشانی شاخه‌ها در مستندات معماری ثبت شده‌اند. دو شکاف Schema که از اجرای MySQL آشکار شدند نیز با Migration و تست روی main تعیین تکلیف شدند.

این نتیجه به معنی «رفع تمام ریسک‌های معماری» یا «تأیید Production» نیست. یافته‌های RBAC، Storage، مالی، pricing/entitlement، product intake و workflow همچنان به‌عنوان کارهای اجرایی مراحل بعدی باز هستند و نباید با قابلیت‌های ادغام‌نشده اشتباه گرفته شوند.

**محدودیت شواهد:** دسترسی مستقیم به دیتابیس Production یا snapshot قابل‌بازیابی آن در این ممیزی وجود نداشت. بنابراین clean install و قراردادهای Schema در CI روی SQLite و MySQL 8.4 اثبات شده‌اند؛ اما upgrade روی clone واقعی Production، تطبیق داده‌های موجود، و آزمون restore عملیاتی باید در مرحله استقرار انجام شوند.

**تصمیم نهایی مرحله دوم:** ممیزی منبع حقیقت در سطح کد و CI بسته شد؛ موارد باقی‌مانده به مراحل اجرایی مربوط خود منتقل شدند. هیچ قابلیت جدید یا زیرسیستم موازی برای بستن این مرحله ساخته نشد.


## 18. تطبیق تغییرات پس از بسته‌شدن ممیزی مرحله دوم؛ main در 2026-11-12

> خط مبنای این تطبیق: `main` در commit `d6050242449e4d0869cf39daff0d5ada69e258ba`.  
> این بخش وضعیت‌های CI و مصرف‌کنندگان را که در snapshot بخش 17 قدیمی شده‌اند به‌روزرسانی می‌کند. بخش 17 همچنان نتیجه ممیزی مرحله دوم روی commit `638bf5fc6ed2709ad7f0cd072aff6ad826499f6c` را ثبت می‌کند.

### 18.1 تغییرات main پس از snapshot مرحله دوم

مقایسه Git از `638bf5fc6ed2709ad7f0cd072aff6ad826499f6c` تا HEAD فعلی main شش commit و 17 فایل تغییر‌یافته را نشان می‌دهد:

| Commit | تغییر | اثر بر منبع حقیقت |
|---|---|---|
| `c006cddf84fc9cf6c3a8c7df304cbc41595df087` | نهایی‌سازی سند ممیزی منابع حقیقت | مستندات؛ بدون تغییر runtime |
| `df4538644072e750d6ef027f4e0101151b4f722c` | بازیابی اتصال IndexedDB و پایداری ذخیره‌سازی Reader | فقط ذخیره‌سازی client-side؛ منبع داده و مجوز سمت سرور را تغییر نمی‌دهد |
| `ceb9d332aa0c942433d6d021397241edfbdc757b` | انتقال صوت preview با stream/range از StorageManager | مصرف‌کننده فایل به هسته Storage متصل‌تر شده؛ جدول/مالک فایل جدیدی ایجاد نشده |
| `a60e7091af315a04773a13ea4c13986bd53b3931` | تکمیل تست‌های Reader، وضعیت‌های صوت، E2E محصول و support | افزایش شواهد و تست؛ تغییر محدود در StoreController و ProcessProductDocument |
| `acc4e893733425c09d62f383b45194e2dff5f6b2` | تفکیک تست‌های PHP و قراردادهای Product Supervisor در Composer/CI | زیرساخت تست؛ بدون تغییر schema یا مالکیت دامنه |
| `d6050242449e4d0869cf39daff0d5ada69e258ba` | self-host و اعتبارسنجی کامل assetهای PDF.js | دارایی‌های viewer؛ بدون تغییر منبع داده محصول یا فایل |

در این بازه هیچ Migration، Model، Service یا فایل Route جدیدی به main اضافه نشده است. تغییرهای backend محدود به `StoreController` و `ProcessProductDocument` بوده‌اند؛ بنابراین ERD، Route ownership و دفتر مالکیت دامنه در بخش 17 همچنان مبنای معتبرند، با تکمیل جریان Reader در ادامه.

### 18.2 منبع حقیقت Reader و چرخه صوت سند

| موضوع | منبع/مالک جاری | مصرف‌کننده |
|---|---|---|
| محصول و مجوز Reader | `StoreProduct`, `StoreLibraryItem`, سفارش پرداخت‌شده و entitlement فروشگاه | `StoreController` |
| سند پردازش‌شده | `StoreProductDocument` و وضعیت `processing_status` | `ProcessProductDocument`, `StoreController` |
| خروجی واقعی صوت | `StoreProductDocumentArtifact` با `artifact_type=audio_full_wav`، نسخه، مسیر، اندازه و checksum | `GenerateProductDocumentAudio`, `StoreController::readerAudio` |
| وضعیت آماده‌سازی صوت | `StoreProductDocument.structure_json.audio.status` | Job پردازش، صفحه Reader و polling client |
| فایل preview محصول | `StoreProductPreview` و فیلدهای `disk/path` و `audio_disk/audio_path` | `StoreController::preview/previewAudio` با `StorageManager` |
| ذخیره‌سازی آفلاین مرورگر | IndexedDB در `farast-reader-storage.js` | فقط client Reader؛ نه مرجع مجوز، مالکیت یا بایت canonical سرور |

**تغییر مهم مصرف‌کننده Storage:** `StoreController::previewAudio()` اکنون از `StorageManager::exists/size/streamRange` برای پاسخ range و stream استفاده می‌کند؛ بنابراین مسیر صوت preview دیگر مستقیماً فایل را در حافظه PHP materialize نمی‌کند. این تغییر، PR/commit stream صوت را در main تثبیت کرده و شکاف مصرف‌کننده‌ای را که در inventory قبلی ثبت شده بود می‌بندد.

**مسیر منبع سند در Job:** `ProcessProductDocument` برای فایل‌هایی که disk آن‌ها به Volume مدیریت‌شده اشاره می‌کند از `StorageManager::downloadTo()` استفاده می‌کند. برای diskهای legacy، مسیر fallback از adapter دیسک موجود و `readStream()` استفاده می‌کند. این fallback را نباید به‌عنوان StorageManager دوم تعبیر کرد؛ آن یک سازگاری legacy است که باید با مهاجرت رکوردهای قدیمی و تست باقی بماند.

**قرارداد وضعیت صوت:** وضعیت‌های `pending/queued/processing/upgrading/failed/awaiting_provider/not_applicable` در metadata سند ثبت می‌شوند؛ وجود artifact معتبر مرجع آماده‌بودن خروجی است. Reader با endpoint واحد `store.reader.audio` وضعیت را poll می‌کند: 202 برای انتظار، 200/206 برای محتوای آماده، 409 برای شکست آماده‌سازی و 410 برای غیرقابل‌اعمال/عدم پیکربندی. client فقط این قرارداد را نمایش می‌دهد و حق صدور مجوز یا تغییر وضعیت canonical را ندارد.

### 18.3 شواهد جدید E2E و مرز آن‌ها

فایل `tests/browser/admin-product-real-workflow.spec.js` اکنون سناریوهای واقعی زیر را پوشش می‌دهد:

- ایجاد محصول از UI واقعی، مشاهده خطای validation و ذخیره پیش‌نویس خصوصی؛
- ایجاد تیکت، ارسال پاسخ، بستن و ثبت CSAT؛
- تغییر permission نقش از کنسول و بررسی ماندگاری؛
- بازشدن مقصدهای sidebar مدیریت بدون خطای قابل مشاهده؛
- رد دسترسی employee، organization manager و team supervisor به کنسول نقش؛
- خرید رایگان storefront و ایجاد entitlement کتابخانه/دانلود کنترل‌شده؛
- تبادل پیام واقعی بین مدیر و کارمند در پیام‌رسان داخلی؛
- رفتار editor محصول در viewport موبایل و دسترسی صفحه‌کلید.

`BrowserE2ESeeder` حساب‌ها و سازمان مخصوص E2E را با رمز متغیر مبتنی بر `GITHUB_RUN_ID` می‌سازد. این داده‌ها فقط در دیتابیس آزمون seed می‌شوند و منبع داده production نیستند.

این تست‌ها شواهد سرتاسری ارزشمندند، اما آزمون کامل RBAC مرحله سوم محسوب نمی‌شوند: سناریوی کنسول نقش، رفتار فعلی main را می‌آزماید؛ ماتریس کامل permission × scope × owner/non-owner و همه mutationهای حساس هنوز باید با هسته RBAC canonical و تست‌های منفی آن تکمیل شود.

### 18.4 وضعیت CI روی main در commit d605

Check Runهای همان SHA چنین بودند:

| Check | نتیجه | علت/تفسیر |
|---|---|---|
| `test` / FARAST Phase 3 | موفق | 663 تست موفق و 3,668 assertion |
| `audit-mysql-integrity` | موفق | اجرای job یکپارچگی MySQL 8.4 موفق |
| `laravel` | ناموفق | 67 Unit و 596 Feature موفق؛ سپس Playwright 1.55 نصب Chromium را برای Ubuntu 26.04 پشتیبانی نکرد |
| `admin-product-e2e` | ناموفق | Playwright 1.55 روی `ubuntu26.04-x64` پشتیبانی نشده |
| `reader-browser-regression` | ناموفق | همان ناسازگاری Playwright با Ubuntu 26.04 |
| `phase4-agent` | ناموفق | همان ناسازگاری Playwright با Ubuntu 26.04 |
| `document-audio-smoke` | ناموفق | `actions/setup-python` نسخه 3.12.11 را برای Ubuntu 26.04 x64 پیدا نکرد |
| `product-supervisor-contract-tests` | ناموفق | همان خطای نبود Python 3.12.11 |
| `voice-service-contract-tests` | ناموفق | همان خطای نبود Python 3.12.11 |
| `voice-model-smoke` | skipped | شرط اجرای workflow برقرار نبود |

در job لاراول، clean-install Migration روی SQLite و دو دسته تست PHP کامل شده بودند؛ همچنین route:list برای مسیرهای messenger، communications، admin/storage، storage/managed، admin/store، store/preview و my-courses اجرا و موفق شد. شکست این job بعد از این گام‌ها و در نصب Chromium رخ داد؛ بنابراین نباید آن را به‌عنوان شکست تست‌های PHP یا Migration گزارش کرد.

### 18.5 اصلاح محیط اجرای CI

PR #475 runner پنج workflow وابسته به Python/Playwright را از `ubuntu-latest` به `ubuntu-24.04` pin کرد و یک گام runtime route inventory/unique-name assertion به FARAST CI افزود:

- `.github/workflows/farast-ci.yml`
- `.github/workflows/phase4-agent.yml`
- `.github/workflows/assistant-browser-tests.yml`
- `.github/workflows/product-ai-content.yml`
- `.github/workflows/product-supervisor.yml`

روی HEAD نهایی PR #475 (`f0d2a12c9590b7450e098141233b1ee773801af5`) همه checkهای لازم موفق شدند؛ 9 check موفق و 3 check به‌صورت شرطی skipped بودند. در job لاراول، 67 Unit، 596 Feature و 117 Playwright browser test موفق شدند؛ clean-install SQLite، route inventory، و job مستقل MySQL 8.4 نیز موفق بودند. Document Audio Smoke، Product Supervisor Contract Tests، Admin Product E2E، Voice Service Contract Tests، Phase 4 Agent، Assistant Browser Regression و Product Supervisor Service نیز موفق شدند. PR #475 در 2026-11-12T16:01:34Z با commit `ed5d36d8f6dc6b6a6c5cdd283a987e57a7552651` در main ادغام شد.

این اصلاح به‌جای ارتقای بی‌دلیل Playwright یا تغییر نسخه Python، runner سازگار با toolchain قفل‌شده فعلی را انتخاب می‌کند. هیچ کد محصول، dependency، Migration یا داده‌ای در این PR تغییر نکرده است.

### 18.6 وضعیت شاخه و Pull Requestهای اثرگذار

**ادغام‌شده در main:**
- PR #310 (Storage lifecycle), #351 (business module contracts), #358 (support notification identity), #368 (CRM history), #371 (Route ownership), #412 (Audit/Observability), #442 (Feedback/Review/Evaluation schema), #473 (PDF.js assets) و #475 (runner pin + runtime route-name gate).
- PR #311 بسته شد و merge نشد؛ پیاده‌سازی RBAC/Workflow آن جزو main نیست.

**شاخه‌های باز و هم‌پوشان که نباید کورکورانه ادغام شوند:**
- PR #318 و #333 هر دو برای product intake/publication workflow باز هستند و `mergeable=false` دارند؛ دو قرارداد رقیب‌اند و باید قبل از هر ادغام یک state machine canonical انتخاب شود.
- PR #335 (storage/product artifacts) باز، draft و `mergeable=false` است. PR #310 بخش مهمی از چرخه Storage را قبلاً به main آورده؛ PR #335 باید فقط از نظر delta واقعی و تست‌های باقی‌مانده با main تطبیق داده شود.
- PR #340 (financial idempotency/retention) باز، draft و `mergeable=false` است؛ ledgerهای Order/Payment/Wallet/Charge/Refund باید قبل از ادغام با منبع مالی canonical reconciliation شوند.
- PR #347 (scoped RBAC/workflow) باز و `mergeable=false` است؛ تا ادغام کنترل‌شده، نقش/Scopeهای پیشنهادی آن main محسوب نمی‌شوند.
- PR #361 (CRM course progress) باز و `mergeable=false` است. مقایسه فایل‌ها نشان می‌دهد `StoreCourseProgressService` و `StoreCourseLearningController` آن با main یکسان‌اند؛ `CrmController` و `CrmCustomerWorkspaceTest` هنوز تفاوت دارند. بخش‌های یکتای آن باید با مسیر جاری CRM reconciliation شوند؛ افزودن مجدد سرویس/محاسبه progress موازی ممنوع است.

در لحظه این تطبیق، API شاخه `main` مقدار `protected=false` و Required Status Checks خاموش را گزارش می‌کند. بنابراین سبز شدن workflowها به‌تنهایی gate اجباری سمت GitHub ایجاد نمی‌کند؛ فعال‌سازی Branch Protection/Ruleset باید به‌عنوان اقدام عملیاتی مستقل انجام شود و در این ممیزی بدون مجوز مالک مخزن تغییر داده نشده است.

### 18.7 نتیجه مرحله دوم پس از تطبیق آخرین main

تغییرهای شش commit پس از `638bf5f` منبع حقیقت سرور را در دامنه‌های اصلی تغییر نداده‌اند؛ آن‌ها عمدتاً چرخه Reader، مصرف StorageManager و کیفیت آزمون را تقویت کرده‌اند. نقشه Route، Model، Migration، Service، Controller، ERD و traceability ثبت‌شده در بخش 17 همچنان معتبر است و جریان صوت Reader اکنون به آن اضافه شده است.

**مرحله دوم از نظر ممیزی کد، تعیین مالکیت و ثبت نقشه وابستگی‌ها تکمیل‌شده باقی می‌ماند.** گیت باز این snapshot، سبزشدن CI پس از pin کردن runner و سپس اجرای مجدد checkها روی main است. محدودیت قبلی نیز برقرار است: دیتابیس Production یا snapshot قابل‌بازیابی آن در دسترس ممیزی نبوده؛ بنابراین نتیجه CI، جایگزین upgrade و reconciliation روی clone واقعی Production نیست.


### 18.8 به‌روزرسانی inventoryهای مکمل تا HEAD جاری

مقایسه درخت Git از snapshot فایل‌های inventory در `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf` تا main جاری، این deltaهای واقعی را نشان می‌دهد:

| حوزه | snapshot قبلی | main جاری | افزوده‌شده |
|---|---:|---:|---|
| Migration | 114 | 119 | 5 فایل |
| Model | 104 | 105 | `SmsLog` |
| Service | 112 | 115 | `FarastObservabilityService`, `FarastTraceContext`, `StoreCourseProgressService` |
| Controller | 51 | 53 | `Admin\ObservabilityController`, `ReadinessController` |
| تست PHP و Browser | 111 PHP + 17 Browser در snapshot قبلی | 133 فایل PHP + تست E2E محصول | 23 فایل تست جدید در مقایسه درختی |

**Migrationهای جدید و مالکیت داده:**
- `2026_11_11_000020_add_email_log_idempotency_key.php`: کلید یکتای nullable روی `email_logs` برای جلوگیری از ارسال تکراری همان رخداد.
- `2026_11_11_000021_create_sms_logs_table.php`: جدول `sms_logs` با idempotency key یکتا، event/status، hash شماره مقصد و شمار تلاش؛ شماره خام مقصد در log ذخیره نمی‌شود.
- `2026_11_11_230000_reconcile_farast_feedback_evidence_schema.php`: تکمیل افزایشی ستون‌ها، FKهای nullable و indexهای `farast_feedback_evidence`.
- `2026_11_11_230100_add_farast_storage_object_path_hash.php`: backfill مقدار SHA-256 و قید یکتایی `(disk,path_hash)` در `farast_storage_objects`.
- `2026_11_11_230200_reconcile_feedback_review_evaluation_schema.php`: هم‌راستاسازی schema برای review cases، approved knowledge و evaluation datasets/cases.

**مالکیت سرویس/کنترلر جدید:**
- `SmsLog` مدل جدول `sms_logs` است؛ `SmsService::sendEvent` با idempotency key پایدار رکورد تلاش را claim/retry می‌کند. `EmailService` نیز `email_logs.idempotency_key` را برای dedupe همان event مصرف می‌کند.
- `FarastTraceContext` در `AppServiceProvider` به‌صورت singleton ثبت شده و request/correlation id را برای هم‌بستگی رخدادها نگه می‌دارد؛ خودش ledger نیست.
- `FarastObservabilityService` منبع تاریخچه incident را `farast_audit_events` قرار می‌دهد و health snapshot را از DB/queue/storage/product services می‌سازد؛ جدول incident موازی ندارد.
- `Admin\ObservabilityController@index` از route `admin.observability.index` با middlewareهای `auth`, `admin`, `admin.section:audit` استفاده می‌کند.
- `ReadinessController::__invoke` روی `GET /ready` فقط اتصال DB را با `SELECT 1` می‌سنجد؛ این endpoint readiness کامل queue/storage/provider نیست.
- `StoreCourseProgressService` تنها محاسبه shared برای drip availability، progress summary و sync completion است؛ هم `StoreCourseLearningController` و هم `CrmController@show` از آن استفاده می‌کنند. داده مرجع همان `StoreCourseEnrollment` و `StoreCourseLessonProgress` باقی می‌ماند.

**Storage backup ownership gap:** Migration `2026_10_02_000001_create_farast_commerce_engine.php` جدول `farast_backup_manifests` را ایجاد کرده، اما در tree فعلی Model و call-site مستقیم در سرویس‌ها/Job/Commandهای backup پیدا نشد. مسیر فعال backup از `StorageBackupRun`/`storage_backup_runs` و `StorageBackupService`/`RunStorageBackupJob` استفاده می‌کند. این جدول orphan/legacy candidate با مالکیت حل‌نشده است؛ حذف آن بدون بررسی داده واقعی ممنوع و استفاده جدید از آن تا تعیین مالکیت متوقف بماند.

**Route و scheduler delta:**
- `bootstrap/app.php` مسیر عمومی `/ready` را خارج از web middleware و health route داخلی `/up` را ثبت می‌کند.
- `routes/web_core_part2.php` مسیر `/admin/observability` را در admin group و با gate بخش audit ثبت می‌کند.
- `routes/console.php` پایش `farast:observability:check` و بازیابی `storage:recover-stale-backup-runs` را به scheduler اضافه کرده است.
- Route file inventory پایه 334 declaration را پوشش می‌داد؛ routeهای bootstrap و observability بعداً اضافه شده‌اند. PR #475 این گام را به CI اضافه و در اجرای موفق آن روی همان کد برنامه ثبت کرد: `route:list --json` تعداد 337 route، 336 route نام‌گذاری‌شده و صفر نام تکراری گزارش کرد.

**تست‌های جدید مرتبط:** `FarastStorageObjectIntegrityTest`, `EmailServiceIdempotencyTest`, `SmsServiceIdempotencyTest`, `FarastFeedbackEvidenceSchemaTest`, `FeedbackPipelineSchemaTest`, `CrmCourseProgressTimelineTest`, `FarastObservabilityServiceTest`, `ReadinessCheckTest`, `NotificationRouteOwnershipTest` و `admin-product-real-workflow.spec.js`.

فهرست کامل پنج Migration، مدل جدید، سه سرویس و دو Controller در inventoryهای مکمل همین پوشه ثبت شده است؛ این جدول فقط delta نسبت به snapshot قبلی را نشان می‌دهد.


## 19. بازاجرای CI روی main پس از ادغام اصلاح runner

- HEAD جدید main: `ed5d36d8f6dc6b6a6c5cdd283a987e57a7552651` (merge squash PR #475).
- تغییر این commit فقط workflowهای CI است؛ کد برنامه و schema همان کد تأییدشده در PR #475 باقی مانده‌اند.
- بازاجرای push روی main برای FARAST CI، Phase 3 Commerce، Phase 4 Agent، Assistant Browser Regression و Product Supervisor Service کامل شد.
- همه checkهای اجرایی روی SHA `ed5d36d8f6dc6b6a6c5cdd283a987e57a7552651` موفق‌اند؛ فقط `voice-model-smoke` و `reader-browser-regression` به‌صورت شرطی skipped شدند.
- job لاراول: 67 Unit و 596 Feature test موفق؛ 117 Playwright browser test نیز موفق.
- Phase 3 Commerce: 663 test و 3,668 assertion موفق.
- MySQL 8.4 audit integrity: 12 test و 106 assertion موفق.
- Document Audio Smoke، Product Supervisor Contract Tests، Admin Product E2E، Voice Service Contract Tests، Phase 4 Agent، Assistant Browser Regression و Product Supervisor Service همگی موفق‌اند.
- Route inventory runtime: 337 route ثبت‌شده، 336 route نام‌گذاری‌شده و بدون نام تکراری؛ این گام اکنون در FARAST CI اجرا می‌شود.
- تنظیمات GitHub همچنان `main.protected=false` و Required Status Checks خاموش را گزارش می‌کند؛ فعال‌سازی Branch Protection/Ruleset اقدام عملیاتی جداگانه‌ای است و در این مرحله تغییر داده نشده است.

**وضعیت پذیرش پس از PR #475:** ناسازگاری runner با Python 3.12.11 و Playwright 1.55 اصلاح شد؛ PR و بازاجرای کامل روی main سبز هستند. مرحله دوم از نظر inventory، traceability، runtime route ownership و شواهد CI تکمیل‌شده است. محدودیت باقیمانده فقط تأیید upgrade/reconciliation روی clone واقعی دیتابیس Production است که در دسترس این ممیزی نبوده است.
