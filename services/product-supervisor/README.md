# FARAST Product Supervisor

سرویس مستقل و stateless برای بررسی فایل‌های محصولات FARAST. این سرویس جایگزین هسته Laravel یا دیتابیس آن نیست و هیچ محصولی را منتشر/تأیید نهایی نمی‌کند.

## نقش در معماری

- **اجرای داخل ایران:** روی Runflare رایگان به‌عنوان worker سبک برای استخراج متن، ساخت chunk، بررسی ساختار فایل و ارسال درخواست‌های مدل.
- **مدل‌های قوی:** فقط از طریق FARAST AI Worker در Cloudflare Workers AI؛ خود Product Supervisor هیچ درخواست مستقیمی به `api.cloudflare.com`، Gemini یا Groq نمی‌فرستد.
- **منبع حقیقت:** Laravel/MySQL موجود؛ فایل‌ها از StorageManager خوانده می‌شوند و chunkها، factها، وضعیت بررسی و گزارش‌ها در دیتابیس اصلی ذخیره می‌شوند.
- **حافظه خصوصی:** هیچ پروفایل کاربر، استنباط رفتاری یا حافظهٔ خصوصی کاربر به این سرویس ارسال نمی‌شود.
- **ذخیره‌سازی:** این کانتینر فایل یا دانش دائمی نگهداری نمی‌کند. فایل موقت پس از پردازش حذف می‌شود.

## مسیرهای مدل

| کار | مدل پشت Worker | مسیر |
|---|---|---|
| تحلیل متن و metadata | GPT-OSS 20B | `POST /v1/chat/completions` |
| تحلیل تصویر | Moondream 3.1 | `POST /v1/ai/run/vision` |
| رونویسی صوت | Whisper Large V3 Turbo | `POST /v1/ai/run/audio` |

Cloudflare Workers AI سهمیهٔ رایگان روزانهٔ ۱۰٬۰۰۰ Neurons دارد. سهمیه میان دستیار/ویرایشگر و Product Supervisor در همان حساب مشترک است. پس از اتمام سهمیه یا قطع اینترنت، تحلیل AI موقتاً متوقف می‌شود؛ فایل اصلی و job پایدار در زیرساخت FARAST باقی می‌مانند تا دوباره پردازش شوند.

## حالت قطعی اینترنت بین‌الملل

Runflare می‌تواند در ایران فایل را دریافت و استخراج ساختاری انجام دهد؛ اما بدون اتصال به Worker خارجی، تولید تحلیل زبانی/بینایی/رونویسی ممکن نیست. در این حالت:

- متن و chunkهای قابل استخراج در Laravel ذخیره می‌شوند.
- وضعیت سند به‌صورت `extracted` یا `partial` می‌ماند و مجوز انتشار صادر نمی‌شود.
- jobهای تحلیل باید پس از بازگشت اتصال دوباره اجرا شوند؛ در این شاخه، retry خودکار اسناد ناقص در حال تکمیل است.
- هیچ دادهٔ دائمی فقط روی Runflare باقی نمی‌ماند.

## ایمنی فایل و حریم خصوصی

- حداکثر اندازهٔ آپلود ۲۵ MiB است؛ این سرویس برای فایل‌های چندگیگابایتی طراحی نشده است.
- نوع فایل از امضای محتوا تشخیص داده می‌شود، نه فقط پسوند یا MIME اعلام‌شده.
- فایل اجرایی و اسکریپت رد می‌شود. ZIP دارای محدودیت تعداد entry، حجم بازشده، نسبت فشرده‌سازی، رمزگذاری و مسیرهای خطرناک است.
- PDF، DOCX، XLSX و PPTX پس از بررسی ساختار آرشیو پردازش می‌شوند. صفحات، متن، chunkها و تصاویر دارای سقف‌اند.
- تصاویر پیش از ارسال به Worker resize و بررسی ابعاد می‌شوند. صوت حداکثر ۲۵ MiB است و نام اصلی فایل برای مدل ارسال نمی‌شود.
- داده‌های حساس رایج مانند کلیدها، bearer tokenها، ایمیل و شماره تلفن پیش از ارسال تا حد امکان redacted می‌شوند؛ این کار تضمین حذف تمام دادهٔ حساس نیست.
- متن و تصویر محصول فقط برای همان تحلیل محصول به Worker فرستاده می‌شود؛ پروفایل خصوصی کاربران، رفتار پنهان، رمزها و اطلاعات حساب هرگز ارسال نمی‌شوند.
- نتیجهٔ AI صرفاً پیشنهاد است. پاسخ سرویس همیشه `publication_authorized=false` و `automated_publication=false` دارد.
- Groq و Gemini در مسیر پیش‌فرض استفاده نمی‌شوند. اگر بعداً fallback اضافه شد، باید سیاست نگهداری داده و محدودیت منطقه‌ای همان سرویس جداگانه بررسی شود.

## منابع و محدودیت Runflare

پلن رایگان Runflare فعلی ۳GB RAM، حدود ۱٫۵ vCPU، ۳GB دیسک و حداکثر دو Deployment دارد. این پلن برای توسعه/تست است، بکاپ و SLA تولیدی ندارد و پس از سه ماه سرویس و دادهٔ روی آن حذف می‌شود.

این سرویس stateless است و دادهٔ دائمی ندارد؛ بنابراین حذف کانتینر نباید دادهٔ محصول را از بین ببرد. با این حال، برای تضمین بازیابی باید Laravel/MySQL و Object Storage مستقل از Runflare باشند.

منابع:
- https://runflare.com/free-cloud-hosting/
- https://runflare.com/docs/work-with-cli/cli-commands/
- https://developers.cloudflare.com/workers-ai/platform/pricing/

## استقرار روی Runflare

1. در Runflare یک Docker service بسازید و ریشهٔ build را روی `services/product-supervisor` قرار دهید؛ از Dockerfile همین پوشه استفاده می‌شود.
2. پورت سرویس `8000` است؛ دیسک دائمی وصل نکنید.
3. در بخش Environment/Secrets این مقادیر را ثبت کنید:
   - `SUPERVISOR_API_TOKEN`: یک secret تصادفی مستقل برای ارتباط Laravel با این سرویس.
   - `FARAST_AI_GATEWAY_URL`: URL دقیق Worker مستقرشده در Cloudflare.
   - `FARAST_AI_GATEWAY_TOKEN`: token مورد انتظار Worker.
   - `MAX_CONCURRENT_FILE_REVIEWS=1`
   - `MAX_UPLOAD_BYTES=26214400`
4. پس از deploy، `/health` و سپس `/v1/ready` را با Bearer token بررسی کنید.
5. در محیط Laravel مقدار `FARAST_PRODUCT_SUPERVISOR_URL` را به URL همین سرویس و `FARAST_PRODUCT_SUPERVISOR_TOKEN` را به همان token مرحلهٔ ۳ وصل کنید. تا وقتی تست زنده موفق نشده، تنظیم Production را تغییر ندهید.

### تمدید بعد از سه ماه

در این پوشه اسکریپت CMD/PowerShell deploy نگهداری می‌شود. بعد از حذف سرویس، Runflare CLI به‌تنهایی پروژه و سرویس جدید نمی‌سازد؛ ابتدا باید پروژه و Docker service جدید در پنل Runflare ساخته شود، سپس فایل CMD را اجرا کنید. چون سرویس هیچ فایل یا دیتابیسی نگهداری نمی‌کند، فقط لازم است URL جدید در تنظیمات Laravel ثبت شود.

## API

- `GET /health`: liveness عمومی.
- `GET /v1/ready`: readiness احراز هویت‌شده.
- `POST /v1/reviews/products`: بررسی metadata محصول.
- `POST /v1/reviews/files`: بررسی فایل multipart.

تمام مسیرهای `/v1/*` به `Authorization: Bearer <SUPERVISOR_API_TOKEN>` نیاز دارند.

## اجرای محلی

Python 3.12:

```bash
python -m venv .venv
. .venv/bin/activate
pip install -r requirements.txt
export SUPERVISOR_API_TOKEN='local-only-secret'
export FARAST_AI_GATEWAY_URL='https://your-worker.workers.dev'
export FARAST_AI_GATEWAY_TOKEN='your-gateway-token'
uvicorn main:app --reload --port 8091
```

تست‌ها: `pytest -q`.
