# FARAST critical-route load test

This k6 scenario measures latency and HTTP failure rate for explicitly selected, verified read-only GET routes. It applies p95/p99 and error/success thresholds both to the aggregate run and separately to every configured route. It never sends POST/PUT/PATCH/DELETE requests; however, some GET endpoints have side effects, so do not include routes such as notification visit/read actions.

## Safety requirements

- Run only against a staging environment or a production environment with an approved test window.
- Start with a low VU count and increase it gradually. Do not run against a shared host or a third-party service without approval.
- Use a dedicated staging account with the minimum permissions needed. Never commit credentials, cookies, or environment files.
- Configure route paths explicitly and verify each one is semantically read-only before testing. Authenticated paths are GET-only and require a valid Laravel session cookie.
- The script deliberately requires explicit p95/p99/error-rate objectives. Do not treat example numbers as approved service-level objectives.

## Example

Install k6 using the official instructions, then run:

```bash
k6 run \
  -e BASE_URL=https://staging.example.test \
  -e PUBLIC_PATHS=/,/store,/login \
  -e AUTH_PATHS=/workspace,/admin/support-report,/api/notifications/unread-count \
  -e SESSION_COOKIE_NAME=farast_session \
  -e AUTH_COOKIE="$FARAST_STAGING_SESSION" \
  -e VUS=20 \
  -e SLO_P95_MS=800 \
  -e SLO_P99_MS=1500 \
  -e SLO_ERROR_RATE=0.01 \
  scripts/performance/critical-routes.js
```

Replace the route list with routes that exist in the target deployment and are safe to read. The session cookie must belong to the dedicated test account and must not be printed or stored in source control.

## Configurable load profile

| Variable | Default | Meaning |
|---|---:|---|
| `BASE_URL` | required | Absolute URL of the approved test environment |
| `PUBLIC_PATHS` | `/,/store,/login` | Comma-separated public GET paths |
| `AUTH_PATHS` | empty | Comma-separated authenticated GET paths |
| `AUTH_COOKIE` | empty | Laravel session cookie value for a dedicated test account |
| `SESSION_COOKIE_NAME` | `farast_session` | Session cookie name for the deployment |
| `VUS` | `20` | Peak virtual users |
| `SLO_P95_MS` | required | Approved p95 latency objective in milliseconds |
| `SLO_P99_MS` | required | Approved p99 latency objective in milliseconds |
| `SLO_ERROR_RATE` | required | Maximum HTTP failure ratio, from 0 to 1 |
| `WARMUP_DURATION` | `1m` | Warm-up stage duration |
| `RAMP_DURATION` | `2m` | Ramp to peak VUs |
| `HOLD_DURATION` | `3m` | Hold peak VUs |
| `COOLDOWN_DURATION` | `1m` | Ramp down duration |
| `REQUEST_TIMEOUT` | `15s` | Per-request timeout |
| `THINK_TIME_SECONDS` | `1` | Delay between iterations |

## Cache and rate-limit capacity

Laravel's repository default cache store is `database`; the effective production value can be overridden by `CACHE_STORE`. Rate-limit counters therefore may add database reads/writes and must be included in connection and query-load measurements. Verify the effective store in the target environment before benchmarking.

Do not use the `array` cache store for a multi-worker production deployment: counters are process-local and do not provide a shared rate-limit budget across workers. If a shared Redis-compatible store is provisioned later, validate atomic counter behavior, TTL expiry, failover behavior, and all application instances using the same store before switching the limiter.

## Evidence to retain

For every run, record the commit SHA, environment/build identifier, database engine and size, cache/queue configuration, VU profile, exact route list, SLO values, k6 summary, and concurrent CPU/memory/database-connection/queue metrics. Run at least one warm-up and three repeatable measured runs. A passing k6 run alone is not proof of scalability unless resource saturation and database behavior are measured at the same time.

This script is a measurement harness, not a substitute for database EXPLAIN plans, query-count tests, dependency-failure tests, or production observability.

## اجرای مرحله‌ای و توقف امن

1. ابتدا با `VUS=1` و زمان‌های کوتاه، درستی مسیرها، نشست احراز هویت و جمع‌آوری متریک را بررسی کنید.
2. بار را به‌صورت مرحله‌ای (برای مثال 5، سپس 10 و سپس سقف مورد توافق) افزایش دهید؛ هر مرحله باید بدون خطای غیرمنتظره تمام شود.
3. هم‌زمان تعداد اتصال‌های فعال/در انتظار دیتابیس، CPU، حافظه، صف‌های معوق، نرخ خطا و Timeout سرویس‌های وابسته را ثبت کنید.
4. اگر نرخ خطا، زمان پاسخ یا مصرف منابع از حد توقف مصوب عبور کرد، آزمون را متوقف کنید؛ افزایش بار برای «اثبات» ظرفیت، در صورت اشباع شدن زیرساخت مجاز نیست.
5. پس از تغییر کد یا پیکربندی، همان پروفایل را با همان داده و شرایط تکرار کنید تا مقایسه قبل/بعد معتبر باشد.

خروجی هر اجرا باید به SHA دقیق کد و شناسه محیط متصل باشد. نتایج اجراهای محیط‌های متفاوت یا نسخه‌های متفاوت را در یک baseline ادغام نکنید.
