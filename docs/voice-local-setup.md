# راه‌اندازی محلی دستیار فراست

این راهنما برای اجرای آزمایشی محلی با پردازش خودمیزبان است. مرورگر فقط میکروفون، لغو اکو/کاهش نویز، قطعه‌بندی PCM و انتقال امن صدا را انجام می‌دهد؛ وزن مدل‌ها و استنتاج باید روی همان سرور یا میزبان خصوصی اجرا شوند.

## ۱) مدل گفت‌وگو (الزامی)

درگاه گفت‌وگو باید API سازگار با OpenAI داشته باشد و این مسیر را با پاسخ جریانی SSE پشتیبانی کند:

`POST /v1/chat/completions`

برنامه آدرس پایه را از `FARAST_AI_GATEWAY_URL` می‌گیرد و خودش `/v1/chat/completions` را به آن اضافه می‌کند؛ بنابراین در مقدار URL مسیر `/v1` یا `/chat/completions` را وارد نکن.

برای نمونه، اگر Ollama روی همان میزبان Laravel اجرا شود، می‌توان از endpoint سازگار با OpenAI آن استفاده کرد. مدل را فقط روی میزبان خصوصی دریافت و اجرا کن؛ این کار هیچ مدلی را به مرورگر کاربر دانلود نمی‌کند:

```bash
ollama pull qwen3:4b
```

نمونهٔ تنظیمات محیط محلی (توکن نمونه را با یک مقدار محرمانهٔ دلخواه جایگزین کن):

```dotenv
FARAST_VOICE_LOCAL_SPEECH_ENABLED=true
FARAST_AI_GATEWAY_URL=http://127.0.0.1:11434
FARAST_AI_GATEWAY_TOKEN=replace-with-a-private-local-token
FARAST_AI_GATEWAY_MODEL=qwen3:4b
```

اگر Ollama یا مدل دیگری روی میزبان جداگانه اجرا می‌شود، به‌جای loopback از نام DNS خصوصی یا IP خصوصی همان میزبان استفاده کن. آدرس عمومی اینترنتی پذیرفته نمی‌شود. درگاه باید Bearer token را بپذیرد و streaming را فعال کند.

## ۲) تشخیص گفتار زندهٔ فارسی

این موارد باید هم‌زمان تنظیم باشند:

- `VOICE_STREAM_URL`: آدرس پایهٔ درگاه WebSocket زندهٔ فراست.
- `VOICE_GATEWAY_SECRET`: راز مشترک Laravel و درگاه WebSocket.
- رکورد موتور `sherpa-onnx` باید در `voice_provider_accounts` فعال و سالم باشد.

پس از فعال‌کردن `FARAST_VOICE_LOCAL_SPEECH_ENABLED=true`، رکورد Sherpa را بساز یا به‌روزرسانی کن:

```bash
php artisan config:clear
php artisan db:seed --class=SherpaOnnxVoiceProviderSeeder
```

در صورت استفاده از فایل تنظیمات cache‌شده، پس از تغییر env دوباره اجرا کن:

```bash
php artisan config:cache
```

## ۳) صدای خروجی فارسی

برای صدای خروجی، سرویس خصوصی Piper باید در دسترس باشد:

- `FARAST_VOICE_TTS_URL`: مسیر خصوصی `/v1/synthesize` سرویس Piper.
- `FARAST_VOICE_TTS_TOKEN`: توکن سرویس، اگر endpoint با Bearer محافظت شده باشد.
- `FARAST_VOICE_TTS_VOICE_ID`: به‌طور پیش‌فرض `farast-fa-male-v1`.

اگر سرویس TTS آماده نباشد، متن پاسخ باید همچنان قابل‌مشاهده بماند و خطای صدا جداگانه گزارش شود.

## ۴) بررسی آمادگی قبل از تست

با حساب کاربری وارد شو و این دو endpoint را بررسی کن:

- `GET /assistant/voice/status`: در حالت محلی باید `chat_gateway_ready: true` باشد.
- `GET /editor/voice/status`: برای گفتار زنده باید `mode: gateway` باشد.

اگر `chat_gateway_ready` برابر false باشد، پاسخ JSON نام متغیرهای ناقص را می‌گوید. اگر `mode` برابر `none` باشد، فهرست `missing` تنظیمات درگاه گفتار را نشان می‌دهد. هیچ توکن یا مقدار محرمانه‌ای را در گزارش یا گفت‌وگو کپی نکن.

## ۵) محدودیت‌های حریم خصوصی

- در حالت محلی، هیچ fallback ابری برای LLM، ASR یا TTS فعال نمی‌شود.
- Web Speech API مرورگر برای تشخیص یا تولید صدا استفاده نمی‌شود.
- فایل وزن مدل، ONNX، WASM یا بستهٔ زبان به دستگاه کاربر ارسال نمی‌شود.
- اگر یکی از سرویس‌های خصوصی آماده نباشد، قابلیت مربوطه باید صریحاً غیرفعال و علت آن نمایش داده شود؛ نباید وانمود کند مکالمه فعال است.
