# فهرست اجرایی مالکیت Routeها

مبنای این نسخه: شاخه ممیزی پس از حذف Routeهای اعلان تکراری؛ فایل‌های فعال requireشده از `routes/web.php`. فهرست ایستا است و باید پس از اجرای runtime `php artisan route:list` با آن تطبیق داده شود.

- تعداد declarationهای استخراج‌شده: **0**
- ورودی اصلی: `routes/web.php` که `web_core.php` و فایل‌های domain routes را require می‌کند.
- `routes/legal.php` در ورودی اصلی require نشده و در inventory فعال نیست.
- middlewareهای سراسری و گروه `web` در `bootstrap/app.php` تعریف شده‌اند؛ ستون‌های زیر فقط middleware گروه Route و middleware صریح هر route را نشان می‌دهند.
- URI با prefix گروهی و route name با name prefix گروهی ترکیب شده‌اند.
- controllerهای invokable (`Controller::class`) نیز به‌عنوان action ثبت شده‌اند.

## تعارض‌ها در شاخه ممیزی

در اسکن ایستا این شاخه، نام تکراری یا Method/URI تکراری یافت نشد.

## تغییر نسبت به main
در main دو تعارض واقعی وجود داشت: `GET /workspace/notifications` و `POST /workspace/notifications/{id}/read` در `web_core_part2.php` و `notifications.php`. شاخه ممیزی دو تعریف متعلق به `PlatformCompletionController` را حذف کرده و `NotificationController` را مالک یگانه کرده است؛ تست `NotificationRouteOwnershipTest` این مالکیت را assert می‌کند.

## Routeها به تفکیک فایل

### `routes/web_core_part1.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|

### `routes/web_core_part2.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|

### `routes/store_routes.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|

### `routes/support_extra.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|

### `routes/notifications.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|

### `routes/messenger.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|

### `routes/product_operations.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
