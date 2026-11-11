# استقرار Production فراست

این راهنما برای استقرار برنامه اصلی Laravel است؛ سرویس گفتار آنلاین در مخزن مستقل Voice-typing مدیریت می‌شود و نباید با سرویس‌های کمکی همین مخزن یکی فرض شود.

## پیش‌نیازها

- PHP 8.2+ با افزونه‌های موردنیاز Composer و ZipArchive
- Composer
- MySQL یا PostgreSQL؛ SQLite فقط برای تست و محیط‌های سبک
- وب‌سرور با document root دقیقاً روی پوشه `public/`
- دسترسی نوشتن محدود برای `storage/` و `bootstrap/cache/`
- یک Secret Manager برای `APP_KEY`، رمز دیتابیس، کلید بکاپ و کلیدهای ارائه‌دهندگان
- Cron برای اجرای Scheduler لاراول
- Worker مدیریت‌شده یا اجرای Worker زمان‌بندی‌شده (یکی از این دو روش، نه هر دو)

## آماده‌سازی محیط

1. فایل `.env.example` را به `.env` کپی کنید.
2. همه placeholderها را در Secret Manager/Environment میزبان تکمیل کنید؛ فایل واقعی `.env` را در Git قرار ندهید.
3. `APP_ENV=production`، `APP_DEBUG=false` و `APP_URL=https://...` را بررسی کنید.
4. `APP_KEY` را فقط یک بار برای این محیط تولید کنید (مثلاً با `php artisan key:generate --show` در محیط امن)، در Secret Manager نگه دارید و هنگام Deployهای بعدی regenerate نکنید.
5. `FARAST_BACKUP_ENCRYPTION_KEY` را جداگانه و تصادفی تولید کنید؛ آن را در همان محل یا همان حسابی که بکاپ‌ها ذخیره می‌شوند نگه ندارید.
6. `FARAST_VOICE_TOKEN` باید با مقدار متناظر در سرویس Voice-typing یکسان باشد. کلیدهای Gemini، Groq، Kavenegar و سرویس‌های کمکی نیز فقط در محیط سرور تنظیم شوند.
7. SMTP را با `MAIL_HOST`، `MAIL_PORT`، `MAIL_USERNAME`، `MAIL_PASSWORD` و `MAIL_ENCRYPTION` پیکربندی کنید؛ بدون این مقادیر ارسال ایمیل Production را تأییدشده تلقی نکنید.

## ساخت و انتشار برنامه

برای هر Release از SHA مشخص و تأییدشده استفاده کنید:

```bash
# Build dependencies in a new, inactive release directory; do not mutate the live vendor tree.
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

# After the release is staged and its shared storage/.env are wired.
# Stop immediately on any failure; do not bring the site up after a failed migration.
set -euo pipefail
php artisan down --render="errors::503" --retry=60
php artisan migrate:status
php artisan migrate --force
php artisan config:cache
# Route caching is intentionally disabled: current route files still contain
# Closure-based routes. Clear any stale cache; enable route:cache only after
# those routes are converted to controller actions and the cache command is tested.
php artisan route:clear
php artisan view:cache
php artisan event:cache
php artisan queue:restart
php artisan up
```

- قبل از فعال‌سازی Release و اجرای Migration از دیتابیس و Storage بکاپ verifyشده بگیرید.
- وابستگی‌ها را در Release جدید بسازید؛ `composer install` را روی `vendor/` نسخه‌ای که ترافیک زنده می‌گیرد اجرا نکنید.
- فایل `.env` و مسیر `storage/` باید بین Releaseها به‌صورت کنترل‌شده و پایدار مشترک باشند.
- Migrationهای destructive باید جداگانه بررسی شوند؛ `migrate:rollback` کورکورانه روش Rollback تولید نیست.
- در استقرار Zero-Downtime، فعال‌سازی symlink/Release باید اتمیک باشد و فقط پس از بررسی سازگاری Schema انجام شود.
- در اولین راه‌اندازی فقط، بعد از تعیین `ADMIN_INITIAL_PASSWORD` و بررسی Seederها، seed اولیه را اجرا کنید. `db:seed` را در هر Release به‌صورت خودکار اجرا نکنید.
- وب‌سرور فقط باید به `public/` دسترسی مستقیم داشته باشد؛ `.env`، `storage/app/private`، فایل‌های بکاپ و کدهای داخلی نباید public باشند.

## Scheduler

Cron باید هر دقیقه Scheduler لاراول را اجرا کند؛ خود Scheduler زمان اجرای هر کار را از `routes/console.php` تعیین می‌کند:

```cron
* * * * * cd /path/to/farast && php artisan schedule:run >> /dev/null 2>&1
```

اجرای مستقیم `farast:purge-files` هر دقیقه جایگزین Scheduler نیست. بکاپ هفتگی فعلی یکشنبه ساعت 02:00 زمان‌بندی شده است؛ تا زمان تصویب RPO، این تناوب را برای سامانه پرتغییر کافی فرض نکنید.

## Worker

یکی از دو مدل زیر را انتخاب کنید:

### مدل A — Cron-only / هاست اشتراکی

`QUEUE_SCHEDULED_WORKER=true` باقی بماند. Scheduler، Workerهای کوتاه‌عمر `--stop-when-empty` را برای صف‌های اسناد، پیش‌فرض و بکاپ هر دقیقه اجرا می‌کند. این مدل نیازمند Cron سالم و قفل مشترک Cache/Database است.

### مدل B — Worker دائمی تحت Supervisor یا systemd

دو Worker مستقل را تحت Process Manager اجرا و restart کنید:

```bash
php artisan queue:work database --queue=documents,documents-audio,default --sleep=3 --tries=3 --timeout=900
php artisan queue:work backups --queue=backups --sleep=3 --tries=1 --timeout=14400
```

در این مدل `QUEUE_SCHEDULED_WORKER=false` قرار دهید تا Scheduler Workerهای زمان‌بندی‌شده را هم‌زمان اجرا نکند. برای هر Worker، `autorestart`، توقف graceful، کاربر غیر root و لاگ مستقل تنظیم کنید. زمان `retry_after` صف بکاپ باید از `--timeout` آن بیشتر باشد؛ در تنظیم فعلی 15000 در برابر 14400 ثانیه است.

## سلامت و بررسی پس از انتشار

- `GET /up`: Liveness لاراول.
- `GET /ready`: Readiness دیتابیس اصلی با `SELECT 1`؛ پاسخ 503 یعنی سرویس نباید ترافیک بگیرد.
- پس از Deploy، HTTP، ورود، مسیرهای اصلی، DB، صف‌های pending/failed، Scheduler، Storage و لاگ‌های 5xx بررسی شوند.
- `php artisan schedule:list` را برای اطمینان از بارگذاری زمان‌بندی‌ها اجرا کنید.
- `php artisan queue:failed` و `php artisan migrate:status` را بررسی کنید.
- آزمون بیرونی پس از Deploy: `FARAST_BASE_URL=https://your-domain.example bash scripts/ops/health-check.sh`؛ این اسکریپت HTTPS، `/up` و پاسخ JSON `/ready` را کنترل می‌کند.
- موفقیت `/ready` به‌تنهایی سلامت Redis، Storage خارجی، سرویس صوت یا ارائه‌دهندگان AI را تضمین نمی‌کند.

## Backup و Restore

- `storage:backup-weekly` یک درخواست را در صف اختصاصی `backups` قرار می‌دهد؛ تولید آرشیو توسط `RunStorageBackupJob` و Worker صف `backups` انجام می‌شود، نه در فرایند Cron. درخواست‌های `queued/running` در `storage_backup_runs` قابل پایش‌اند.
- درخواست زمان‌بندی‌شده فقط وقتی قابل اجراست که `FARAST_BACKUP_ENCRYPTION_KEY` و حداقل یک Volume سالم با `drive_key=foreign_backup` پیکربندی شده باشد.
- بکاپ با AES-256 رمزنگاری می‌شود؛ SHA-256 پس از انتقال مقایسه می‌شود و خوانایی Manifest و جریان دیتابیس رمزگشایی‌شده نیز قبل از موفق علامت‌گذاری شدن بررسی می‌شود.
- درخواست زمان‌بندی‌شده‌ای که بیش از ۵ ساعت در حالت running یا بیش از ۲۴ ساعت در حالت queued بماند، هنگام اجرای بعدی فرمان زمان‌بندی‌شده به failed تبدیل می‌شود؛ این پاک‌سازی جایگزین پایش پیوسته Worker و صف نیست.
- بررسی دستی: `php artisan storage:verify-backup {runId}`.
- Restore مخرب است و دیتابیس و فایل‌ها را جایگزین می‌کند: `php artisan storage:restore-backup {runId} --confirm`. فقط در محیط بازیابی جداگانه یا پنجره رخداد کنترل‌شده اجرا شود. پس از Restore برنامه عمداً در Maintenance Mode باقی می‌ماند؛ پس از اعتبارسنجی داده‌ها، Storage و Workerها، اپراتور باید `php artisan up` را اجرا کند.
- آزمون Restore باید از دیتابیس و Storage جداگانه استفاده کند؛ هرگز برای تمرین روی Production اجرا نشود.
- **محدودیت بازیابی از نابودی کامل دیتابیس:** فرمان فعلی Restore برای پیدا کردن آرشیو به رکورد `storage_backup_runs` و تنظیمات Volume در دیتابیس موجود نیاز دارد. بنابراین بازیابی از دیتابیس کاملاً ازدست‌رفته هنوز مسیر bootstrap مستقل و خودکار ندارد؛ این سناریو تا زمان طراحی و آزمون Restore از آرشیو محلی/Manifest خارجی، تأییدشده محسوب نمی‌شود.
- کلید رمزنگاری بکاپ، `APP_KEY`، اطلاعات دسترسی مقصدهای خارجی و شناسه/مسیر آرشیوها باید خارج از همان محیط/حساب نگهداری بکاپ قابل بازیابی باشند.
- Retention فعلی عملاً `keep_all` است و حذف خودکار بکاپ‌های قدیمی پیاده‌سازی نشده؛ ظرفیت مقصد را پایش کنید و تا تصویب RPO/RTO حذف خودکار را فعال نکنید.

## وضعیت میزبان و سرویس‌های جانبی

- این مخزن هیچ Blueprint یا Dockerfile برای برنامه اصلی Laravel ندارد؛ `render.yaml` فقط دو سرویس کمکی Python را تعریف می‌کند. Workspace متصل‌شده Render نیز در ممیزی، سرویس Laravel یا دیتابیس Production نشان نداد.
- `DEPLOY.md` و فایل‌های مخزن به‌تنهایی نام میزبان واقعی Laravel، دامنه Production یا دیتابیس زنده را تعیین نمی‌کنند. تا زمان شناسایی آن‌ها، هیچ Migration یا Deploy تولیدی نباید اجرا شود.
- سرویس Voice-typing مخزن مستقل `a4adelahmadian86-wq/Voice-typing` است. Blueprint آن Docker، پلن پولی 2 CPU / 8 GB و دیسک پایدار 10 GB در Frankfurt تعریف می‌کند؛ راهنمای همان مخزن می‌گوید پلن Free برای اجرای مدل‌های صوتی مناسب نیست. در مستندات این مخزن، وضعیت پنل زنده با Blueprint اختلاف دارد. قبل از Sync یا تغییر پلن/منطقه باید قیمت و تغییرات دقیق در Render بررسی و تأیید شوند.

## نکته امنیتی مهم

جلوگیری مطلق از کپی متن در مرورگر ممکن نیست؛ کاربر دارای کنترل کامل روی دستگاه خود می‌تواند DevTools یا ابزارهای ضبط صفحه استفاده کند. این پروژه کپی عادی، cut، context menu و میانبرهای رایج را در ویرایشگر می‌بندد و متن خام را از API عمومی ارائه نمی‌کند. امنیت واقعی باید با کنترل دسترسی سمت سرور و محدود کردن export پیاده شود.
