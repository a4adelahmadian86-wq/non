# FARAST — AI Typing Platform

پلتفرم تایپ آنلاین فارسی با OCR، تایپ صوتی، ویرایشگر حرفه‌ای، قیمت‌گذاری سروری، احراز OTP و پنل مدیریت.

## معماری
- Laravel 12 / PHP 8.2+
- PostgreSQL یا MySQL
- Redis برای قفل نشست، rate limit و صف
- ذخیره موقت فایل با TTL حداکثر ۱۴ روز
- Gemini به‌صورت provider قابل تعویض
- خروجی DOCX و PDF
- RTL و B Nazanin 16px به‌صورت پیش‌فرض

## اصول امنیتی
- رمزها و API keyها فقط در environment و secret manager؛ هرگز داخل repository
- OTP یک‌بارمصرف، دارای انقضا، محدودیت تلاش و rate limit
- سهم رایگان هفتگی به‌صورت تراکنشی و server-side
- قفل همزمانی حساب با heartbeat و session lease
- فایل‌ها private و دارای URL امضاشده کوتاه‌مدت
- متن و فایل اصلی از client به‌عنوان منبع حقیقت قیمت پذیرفته نمی‌شود
- قیمت فقط از PricingRuleهای فعال سمت سرور محاسبه می‌شود
- export تنها مسیر مجاز برای خروجی گرفتن از متن تایپ‌شده است
- audit log برای عملیات مالی، مدیریت، احراز هویت و export

## وضعیت
این repository از صفر ایجاد شده و skeleton تولیدی آن مرحله‌به‌مرحله تکمیل می‌شود.
