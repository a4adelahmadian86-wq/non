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

اگر Ollama یا مدل دیگری روی میزبان جداگانه اجرا می‌شود، به‌جای loopback از نام DNS خصوصی یا IP خصوصی همان میزبان استفاده کن. درگاه باید Bearer token را بپذیرد و streaming را فعال کند. آدرس خصوصی فقط وقتی از Laravel قابل استفاده است که میزبان Laravel واقعاً به آن شبکه دسترسی داشته باشد.

## ۲) تشخیص گفتار زندهٔ فارسی

این تنظیمات در دو محیط جداگانه لازم‌اند:

**محیط Laravel**
- `VOICE_STREAM_URL`: آدرس WebSocket درگاه زنده؛ برای نمونه `ws://127.0.0.1:6002`.
- `VOICE_GATEWAY_SECRET`: راز مشترک Laravel و درگاه Node.

**محیط فرایند Node در `voice-stream`**
- `APP_KEY`: دقیقاً همان مقدار `APP_KEY` در Laravel؛ برای اعتبارسنجی توکن نشست.
- `VOICE_GATEWAY_SECRET`: دقیقاً همان راز تنظیم‌شده در Laravel.
- `VOICE_STREAM_APP_URL`: مبدأ Laravel؛ برای نمونه `http://127.0.0.1:8000`.
- `VOICE_STREAM_ORIGINS`: مبدأ دقیق مرورگرِ مجاز، شامل scheme و port؛ برای نمونه `http://127.0.0.1:8000`.
- `SHERPA_ONNX_WS_URL`: نشانی WebSocket خصوصی sidecar تشخیص گفتار؛ برای نمونه `ws://127.0.0.1:6006` یا نام میزبان خصوصی در شبکهٔ Docker.

**محیط sidecar تشخیص گفتار**
- مدل فارسی باید در `SHERPA_MODEL_DIR` وجود داشته باشد؛ دانلودکنندهٔ پروژه فایل‌ها را با SHA-256 بررسی می‌کند.
- پورت پیش‌فرض sidecar برابر `6006` است.
- این sidecar را فقط روی loopback یا شبکهٔ خصوصی اجرا کن؛ پورت `6006` نباید مستقیماً از اینترنت قابل دسترسی باشد.

برای نصب و دریافت مدل روی **سرور** (نه مرورگر کاربر):

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r voice-stream/sherpa-sidecar/requirements.txt
export SHERPA_MODEL_DIR=/opt/farast/models/sherpa
python voice-stream/sherpa-sidecar/download_model.py
SHERPA_HOST=127.0.0.1 SHERPA_PORT=6006 SHERPA_MODEL_DIR=/opt/farast/models/sherpa \
  python voice-stream/sherpa-sidecar/server.py
```

مدل فعلی فارسی، نسخهٔ int8 جریانی است. دریافت فایل‌ها یک‌بار روی سرور انجام می‌شود؛ فایل‌ها را در فضای پایدار نگه دار تا پس از راه‌اندازی مجدد دانلود نشوند.

رکورد موتور `sherpa-onnx` نیز باید در `voice_provider_accounts` فعال و سالم باشد. پس از فعال‌کردن `FARAST_VOICE_LOCAL_SPEECH_ENABLED=true`، رکورد Sherpa را بساز یا به‌روزرسانی کن:

```bash
php artisan config:clear
php artisan db:seed --class=SherpaOnnxVoiceProviderSeeder
```

در صورت استفاده از فایل تنظیمات cache‌شده، پس از تغییر env دوباره اجرا کن:

```bash
php artisan config:cache
```

## ۳) صدای خروجی فارسی

برای صدای خروجی، sidecar خصوصی Piper باید در دسترس باشد. مدل فارسی Mana حدود ۶۴ مگابایت است و روی **سرور** ذخیره و اجرا می‌شود.

در محیط sidecar:
- `PIPER_MODEL_DIR`: مسیر پایدار مدل.
- `PIPER_TTS_TOKEN`: یک راز تصادفی طولانی؛ endpoint تولید صدا بدون Bearer token درخواست را نمی‌پذیرد.
- `PIPER_HOST` و `PIPER_PORT`: به‌طور پیش‌فرض `127.0.0.1:7007`.

نصب و اجرای نمونه روی همان میزبان:

```bash
pip install -r voice-stream/piper-sidecar/requirements.txt
export PIPER_MODEL_DIR=/opt/farast/models/piper
python voice-stream/piper-sidecar/download_model.py
export PIPER_TTS_TOKEN='یک-راز-تصادفی-طولانی'
PIPER_HOST=127.0.0.1 PIPER_PORT=7007 PIPER_MODEL_DIR=/opt/farast/models/piper \
  python voice-stream/piper-sidecar/server.py
```

در محیط Laravel:
- `FARAST_VOICE_TTS_URL`: آدرس قابل‌دسترسی از خود سرور Laravel به `/v1/synthesize`.
- `FARAST_VOICE_TTS_TOKEN`: دقیقاً همان مقدار `PIPER_TTS_TOKEN`.
- `FARAST_VOICE_TTS_VOICE_ID`: شناسهٔ ثابت `farast-fa-male-v1`.

پورت Piper را مستقیم روی اینترنت باز نکن؛ آن را روی loopback یا شبکهٔ خصوصی نگه دار. اگر دو سرویس روی میزبان‌های جدا هستند، باید پیش از تنظیم Laravel مسیر شبکهٔ خصوصی بین آن‌ها برقرار باشد. در حالت خودمیزبان، Laravel آدرس عمومی را نمی‌پذیرد و در صورت نبود توکن، اصلاً درخواست تولید صدا ارسال نمی‌کند.

اگر سرویس TTS آماده نباشد، متن پاسخ همچنان قابل‌مشاهده می‌ماند و خطای صدا جداگانه گزارش می‌شود.

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
