# راه‌اندازی ذخیره‌سازی ایران‌محور FARAST

این راهنما فقط از زیرساخت موجود `StorageManager`، `StorageConnectionService` و `StorageBackupService` استفاده می‌کند؛ storage engine جدیدی ساخته نمی‌شود.

## انتخاب‌ها

- **اصلی داخل ایران:** ArvanCloud Object Storage با S3 API، در volume موجود با کلید `iran_primary`.
- **بکاپ رمزگذاری‌شده خارج از ایران:** Backblaze B2 در volume موجود با کلید `foreign_backup`. بکاپ جایگزین ظرفیت اصلی نیست و فقط برای بازیابی فاجعه است.
- **دانش، chunkها، ACL و حافظهٔ خصوصی:** MySQL فعلی؛ Object Storage دیتابیس رابطه‌ای نیست.
- **Runflare:** هیچ فایل یا دادهٔ دائمی روی آن ذخیره نمی‌شود.

## محدودیت رایگان فعلی ArvanCloud

صفحهٔ رسمی قیمت‌گذاری فعلی برای Object Storage بستهٔ رایگان با ۵GB فضا، ۲۰GB ترافیک و سقف ۱۰٬۰۰۰ درخواست نشان می‌دهد. این ظرفیت برای شروع است، نه ذخیره‌سازی ده‌ها گیگابایت. «رایگان دائمی» یا SLA تولیدی را نباید تضمین‌شده فرض کرد؛ شرایط همان روز ثبت‌نام باید دوباره بررسی شود.

منبع رسمی: https://www.arvancloud.ir/fa/pricing/cloud-storage

## ساخت volume اصلی

1. در پنل ArvanCloud یک Object Storage در منطقهٔ داخل ایران بسازید و bucket را private نگه دارید.
2. از پنل همان سرویس، endpoint، region، bucket و S3 access key/secret را بردارید. endpoint را از روی پنل کپی کنید؛ region یا hostname را حدس نزنید.
3. در پنل مدیریت FARAST، بخش Storage Volumes، یک volume با این مشخصات بسازید:
   - `drive_key=iran_primary`
   - `provider=arvan`
   - `driver=s3`
   - endpoint/region/bucket دقیقاً مطابق پنل Arvan
   - credentials شامل access key و secret key؛ فقط در فرم امن پنل وارد شوند، نه GitHub و نه فایل‌های عمومی
   - `is_active=true`
   - ظرفیت مطابق سهمیهٔ واقعی حساب، نه عدد فرضی
4. از `StorageConnectionService` گزینهٔ Test Connection را اجرا کنید. تا وقتی health برابر healthy نشده، volume را primary عملیاتی تلقی نکنید.
5. یک فایل آزمایشی غیرحساس upload و download کنید؛ سپس حذف آن را نیز از پنل تست کنید.

## مسیریابی داده

- فایل‌های محصول، تصویر محصول، منابع دوره و فایل خصوصی ویرایشگر باید `critical` باشند و به `iran_primary` بروند.
- آپلودهای خصوصی ویرایشگر در شاخهٔ فعلی به `StorageManager::CRITICAL` تغییر داده شده‌اند؛ بنابراین نباید به `foreign_primary` نشت کنند.
- اگر فضای ایران پر یا unavailable شود، فایل critical در staging محلی می‌ماند و job انتقال دوباره تلاش می‌کند؛ آن را به storage خارجی نفرستید.
- فقط فایل‌های عمومی/غیرحساس می‌توانند طبق سیاست موجود از `foreign_primary` استفاده کنند.

## بکاپ خارجی اختیاری

1. یک bucket خصوصی در Backblaze B2 بسازید و S3-compatible key محدود به همان bucket ایجاد کنید.
2. آن را با `drive_key=foreign_backup` و `driver=s3` در Storage Volumes ثبت کنید.
3. کلید رمزگذاری backup را در secret محیط Laravel با نام `FARAST_BACKUP_ENCRYPTION_KEY` ثبت کنید؛ این مقدار هرگز نباید در GitHub commit شود.
4. `FARAST_BACKUP_WEEKLY_RETENTION=2` دو نسخهٔ هفتگی آخر را نگه می‌دارد؛ backupهای دستی عمداً خودکار حذف نمی‌شوند.
5. پس از اولین backup، checksum و restore verification را اجرا کنید. صرفاً دیدن status=completed کافی نیست؛ بازیابی آزمایشی لازم است.

Backblaze B2 در حال حاضر ۱۰GB فضای رایگان دارد؛ این سهمیه فقط برای backup رمزگذاری‌شده است و به ظرفیت اصلی Arvan اضافه نمی‌شود. منبع: https://www.backblaze.com/cloud-storage/pricing

## رفتار در قطعی اینترنت بین‌الملل

- Laravel/MySQL و Arvan primary اگر واقعاً داخل ایران باشند، باید برای فایل و دانش ذخیره‌شده در ایران در دسترس بمانند.
- بکاپ B2 و مدل Cloudflare در قطعی بین‌الملل قابل اتکا نیستند؛ اما نسخهٔ اصلی داده در ایران باقی می‌ماند.
- Product Supervisor داخل Runflare می‌تواند استخراج ساختاری را انجام دهد؛ تحلیل AI به Worker خارجی نیاز دارد و پس از بازگشت اتصال دوباره صف می‌شود.
- محل فیزیکی هاست Laravel و MySQL هنوز باید از شرکت هاستینگ تأیید شود؛ بدون این تأیید، «همهٔ داده‌ها داخل ایران» قابل تضمین نیست.
