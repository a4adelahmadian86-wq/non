# ممیزی تکمیلی معماری بر مبنای main جاری

> این سند مکمل inventoryهای معماری ادغام‌شده در PR #371 است؛ آن inventoryها snapshot تاریخی `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf` را ثبت می‌کنند.  
> خط مبنای این تکمیل: `main` در commit `c3067f69da7d29b874c26438e804e4067a078bab`، تاریخ 2026-11-11.  
> این سند تغییرات بعد از snapshot، یافته‌های تازه و PRهای فعال مرتبط را جدا می‌کند؛ هیچ تغییری در دارایی‌های شاخه‌های همکاران اعمال نمی‌کند.

## 1. وضعیت فعلی مخزن

| شاخص | مقدار در خط مبنای جاری |
|---|---:|
| Git tree entries | 1,171 |
| Migration PHP | 116 |
| Model PHP | 105 |
| Service PHP | 113 |
| Controller PHP | 51 |
| Test PHP | 116 |

این شمارش‌ها تعداد فایل‌اند و نباید به‌عنوان تعداد قابلیت یا معیار کیفیت تعبیر شوند.

### تغییر نسبت به snapshot ممیزی قبلی

| حوزه | snapshot قبلی | main جاری | تغییر قابل ردیابی |
|---|---:|---:|---|
| Migration | 114 | 116 | دو Migration جدید برای idempotency ایمیل و ledger پیامک |
| Model | 104 | 105 | مدل جدید `SmsLog` |
| Service | 112 | 113 | سرویس مشترک پیشرفت دوره `StoreCourseProgressService` |
| Controller | 51 | 51 | تغییر شمارشی ندارد |
| Test PHP | 112 | 116 | آزمون‌های CRM course progress، email/SMS idempotency و پایداری هویت اعلان پشتیبانی؛ به‌علاوه آزمون مالکیت Route اعلان که در PR ممیزی اضافه شد |

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

آزمون `tests/Feature/NotificationRouteOwnershipTest.php` مالکیت هشت Route اعلان را به `NotificationController` مقید می‌کند و یکتایی تمام نام‌های Route runtime را assert می‌کند. این آزمون در PR ممیزی موفق بود؛ اجرای مجدد CI روی merge commit جاری هنوز در حال اجراست و باید نتیجه نهایی همان commit بررسی شود.

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

**وضعیت:** اصلاح هنوز در main این snapshot نیست؛ PR #347 باز است. فایل‌های Route و سرویس‌های Authorization در PR #347 تغییر می‌کنند، بنابراین اصلاح موازی همان فایل‌ها در شاخه دیگری انجام نمی‌شود. ادغام فقط پس از rebase/بررسی تعارض و CI کامل مجاز است.

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

**وضعیت:** PR #335 در آخرین snapshot بررسی‌شده چند check ناموفق داشت؛ بنابراین این اصلاح هنوز پذیرفته‌شده تلقی نمی‌شود. تا رفع شکست‌ها و rebase، هیچ مهاجرت دستی فایل یا حذف مسیر legacy انجام نشود.

## 7. مرزهای تجارت که باید حفظ شوند

- `Order` در main برای خریدهای فروشگاه و جریان خدمات سند استفاده می‌شود؛ مدل دارای relation به `TypingDocument` و `StoreOrderItem` است. این یک aggregate مشترک با item/snapshotهای دامنه‌ای است، نه دو جدول سفارش مستقل.
- `Payment` به `Order` متصل است.
- `Wallet` و `WalletTransaction` دفتر کیف پول کاربرند.
- موتور تجارت FARAST علاوه بر این‌ها، usage/reservation/charge/invoice/refund/entitlementهای خودش را دارد.

این دو سطح را نباید بدون تطبیق idempotency، callback، refund و ledger با هم ادغام کرد. PR #340 مسیر تقویت idempotency و retention مالی را دنبال می‌کند و باید به‌عنوان تغییر موازی بررسی شود؛ وضعیت آن هنوز ادغام‌شده نیست.

## 8. وضعیت CI خط مبنای جدید

پس از ادغام PR #371، checkهای commit `c3067f69da7d29b874c26438e804e4067a078bab` در لحظه ثبت این سند هنوز در حال اجرا بودند:
- `laravel`: in progress
- `voice-service-contract-tests`: in progress
- `document-audio-smoke`: in progress
- `test`: in progress
- `agent`: queued
- `voice-model-smoke`: skipped

بنابراین نتیجه این commit هنوز pass اعلام نمی‌شود. checkهای سبز PR #371 روی head آن PR، جایگزین checkهای merge commit نیستند.

## 9. ترتیب ادامه ممیزی/تثبیت

1. دریافت نتیجه نهایی CI روی `c3067f69...`؛ در صورت شکست، تشخیص بر اساس job و log.
2. بررسی و ادغام امن PR #347 پس از rebase و CI؛ این مسیر شکاف authorization سطح Route را می‌بندد.
3. بررسی PR #335 و تکمیل Storage reference synchronization و reader audio؛ تا سبز شدن تست‌ها هیچ پاک‌سازی legacy انجام نشود.
4. بررسی PR #340 و تطبیق تمام ledgerهای مالی و callbackهای پرداخت.
5. اجرای runtime inventory روی main پس از ادغام این تغییرها: `route:list`, migration fresh install/upgrade، تست‌های منفی Scope و مالکیت فایل.
6. تکمیل call graph برای هر سرویس با مصرف‌کننده‌ها و تست‌های واقعی؛ سپس تعیین موارد retain/consolidate/deprecate.

## 10. معیار تکمیل

این addendum وضعیت snapshot را ثبت می‌کند، نه پایان کل مرحله دوم را. مرحله دوم تنها وقتی بسته می‌شود که:
- Routeها و نام‌ها در runtime یکتا باشند؛
- هر admin mutation route، permission و Scope سمت سرور داشته باشد؛
- فایل‌های دامنه‌ای مدیریت‌شده با رجیستری مرکزی reference قابل reconciliation داشته باشند؛
- ledgerهای سفارش، پرداخت، کیف پول، invoice، charge و refund به‌طور کامل ردیابی شوند؛
- تمام فایل‌های جدید بعد از snapshot در ماتریس traceability منعکس شوند؛
- تست‌های CI و migration upgrade روی commit نهایی سبز باشند.

**وضعیت مرحله دوم: در حال انجام؛ نقشه‌های اصلی ثبت شده‌اند، اما سه شکاف امنیت، Storage و تطبیق مالی باید با PRهای فعال و شواهد runtime بسته شوند.**
