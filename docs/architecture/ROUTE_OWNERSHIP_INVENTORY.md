# فهرست اجرایی مالکیت Routeها

> مبنای فهرست فایل‌های Route: snapshot `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf`؛ deltaهای main تا `d6050242449e4d0869cf39daff0d5ada69e258ba` در انتهای این سند ثبت شده‌اند. برای وضعیت جاری، `ARCHITECTURE_AUDIT_CURRENT_MAIN_DELTA.md` بخش 18 مرجع تکمیلی است.

مبنای این نسخه: شاخه ممیزی پس از حذف Routeهای اعلان تکراری؛ فایل‌های فعال requireشده از `routes/web.php`. فهرست ایستا است و باید پس از اجرای runtime `php artisan route:list` با آن تطبیق داده شود.

- تعداد declarationهای استخراج‌شده: **334**
- ورودی اصلی: `routes/web.php` که `web_core.php` و فایل‌های domain routes را require می‌کند.
- `routes/legal.php` در ورودی اصلی require نشده و در inventory فعال نیست.
- middlewareهای سراسری و گروه `web` در `bootstrap/app.php` تعریف شده‌اند؛ ستون‌های زیر فقط middleware گروه Route و middleware صریح هر route را نشان می‌دهند.
- URI با prefix گروهی و route name با name prefix گروهی ترکیب شده‌اند.
- controllerهای invokable (`Controller::class`) نیز به‌عنوان action ثبت شده‌اند.

## تعارض‌ها در شاخه ممیزی

در اسکن ایستا این شاخه، نام تکراری یا Method/URI تکراری یافت نشد.

## وضعیت Route ownership در main جاری

- PR #371 ادغام شده است؛ دو تعریف تکراری اعلان از `web_core_part2.php` حذف شده و `routes/notifications.php` با `NotificationController` مالک یگانه مسیرهای اعلان شخصی است.
- `NotificationRouteOwnershipTest` در CI، مالکیت `platform.notifications` و `platform.notifications.read` را به `NotificationController@index/read` assert می‌کند.
- `bootstrap/app.php` علاوه بر health route داخلی Laravel در `/up`، مسیر `GET /ready` با نام `health.ready` را به `ReadinessController::__invoke` متصل می‌کند؛ این endpoint خارج از middleware گروه web ثبت شده است.
- `routes/web_core_part2.php` مسیر `GET /admin/observability` با نام `admin.observability.index` را به `Admin\\ObservabilityController@index` متصل می‌کند؛ middleware گروه `auth, admin` و gate صریح `admin.section:audit` است.
- `routes/console.php` مالک endpoint HTTP نیست، اما schedulerهای observability و storage recovery را ثبت می‌کند؛ آن‌ها در بخش 18 سند delta معماری فهرست شده‌اند.

334 declaration در این inventory مربوط به snapshot ایستای فایل‌های domain route است. مسیرهای bootstrap و دو route افزوده‌شده در main باید در شمارش runtime لحاظ شوند؛ شمار نهایی ثبت‌شده در Laravel عمداً تا اجرای گام کامل `route:list --json` اعلام نمی‌شود.

## Routeها به تفکیک فایل

### `routes/web_core_part1.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/` | `home` | `Closure / unresolved` | — | — | `routes/web_core_part1.php:31` |
| GET | `/storage/managed/{file}` | `storage.managed.public` | `\App\Http\Controllers\ManagedStoragePublicController@show` | — | — | `routes/web_core_part1.php:60` |
| GET | `/blog` | `blog` | `BlogController@index` | — | — | `routes/web_core_part1.php:61` |
| GET | `/community` | `community.index` | `ContentDiscussionController@communityIndex` | — | — | `routes/web_core_part1.php:62` |
| GET | `/my/community` | `community.mine` | `ContentDiscussionController@myDiscussions` | auth | — | `routes/web_core_part1.php:64` |
| PUT | `/my/community/{discussion}` | `community.mine.update` | `ContentDiscussionController@updateOwn` | auth | throttle:10,10 | `routes/web_core_part1.php:65` |
| DELETE | `/my/community/{discussion}` | `community.mine.withdraw` | `ContentDiscussionController@withdrawOwn` | auth | throttle:10,10 | `routes/web_core_part1.php:66` |
| GET | `/community/members/{user}` | `community.member` | `ContentDiscussionController@member` | — | — | `routes/web_core_part1.php:68` |
| POST | `/discussions` | `discussions.store` | `ContentDiscussionController@store` | — | auth, throttle:5,10 | `routes/web_core_part1.php:69` |
| POST | `/discussions/{discussion}/reaction` | `discussions.reaction` | `ContentDiscussionController@react` | — | auth, throttle:30,10 | `routes/web_core_part1.php:70` |
| POST | `/discussions/{discussion}/report` | `discussions.report` | `ContentDiscussionController@report` | — | auth, throttle:5,10 | `routes/web_core_part1.php:71` |
| POST | `/discussions/{discussion}/follow` | `discussions.follow` | `ContentDiscussionController@toggleFollow` | — | auth, throttle:30,10 | `routes/web_core_part1.php:72` |
| POST | `/discussions/{discussion}/reply` | `discussions.public-reply` | `ContentDiscussionController@publicReply` | — | auth, throttle:5,10 | `routes/web_core_part1.php:73` |
| GET | `/blog/{slug}` | `blog.show` | `BlogController@show` | — | — | `routes/web_core_part1.php:74` |
| GET | `/store` | `store` | `StoreController@index` | — | — | `routes/web_core_part1.php:75` |
| GET | `/store/category/{slug}` | `store.category` | `StoreController@category` | — | — | `routes/web_core_part1.php:76` |
| GET | `/store/product/{slug}` | `store.product` | `StoreController@product` | — | — | `routes/web_core_part1.php:77` |
| POST | `/store/smart-offers/{assignment}/interaction` | `store.smart-offer.interaction` | `StoreController@trackSmartOfferInteraction` | — | auth, throttle:30,10 | `routes/web_core_part1.php:78` |
| GET | `/store/preview/{preview}` | `store.preview` | `StoreController@preview` | — | throttle:120,10 | `routes/web_core_part1.php:79` |
| GET | `/cart` | `cart` | `StoreCartController@index` | — | — | `routes/web_core_part1.php:80` |
| GET | `/cart/summary` | `cart.summary` | `StoreCartController@summary` | — | throttle:60,1 | `routes/web_core_part1.php:81` |
| POST | `/cart/products/{product}` | `cart.add` | `StoreCartController@add` | — | throttle:60,10 | `routes/web_core_part1.php:82` |
| POST | `/cart/products/{product}/update` | `cart.update` | `StoreCartController@update` | — | throttle:60,10 | `routes/web_core_part1.php:83` |
| POST | `/cart/products/{product}/remove` | `cart.remove` | `StoreCartController@remove` | — | throttle:60,10 | `routes/web_core_part1.php:84` |
| POST | `/cart/checkout` | `cart.checkout` | `StoreCartController@checkout` | — | auth, throttle:10,10 | `routes/web_core_part1.php:85` |
| POST | `/cart/coupon` | `cart.coupon` | `StoreCartController@applyCoupon` | — | throttle:30,10 | `routes/web_core_part1.php:86` |
| POST | `/cart/coupon/clear` | `cart.coupon.clear` | `StoreCartController@clearCoupon` | — | throttle:30,10 | `routes/web_core_part1.php:87` |
| GET | `/pricing` | `pricing` | `EditorController@pricing` | — | — | `routes/web_core_part1.php:88` |
| POST | `/assistant/chat/stream` | `assistant.chat.stream` | `GlobalAssistantController@chatStream` | — | auth, throttle:20,1 | `routes/web_core_part1.php:89` |
| POST | `/assistant/chat` | `assistant.chat` | `GlobalAssistantController@chat` | — | auth, throttle:20,1 | `routes/web_core_part1.php:90` |
| GET | `/assistant/history` | `assistant.history` | `GlobalAssistantController@history` | — | auth, throttle:30,1 | `routes/web_core_part1.php:91` |
| GET | `/assistant/memories` | `assistant.memories` | `GlobalAssistantController@memories` | — | auth, throttle:30,1 | `routes/web_core_part1.php:92` |
| POST | `/assistant/memories` | `assistant.memories.store` | `GlobalAssistantController@saveMemory` | — | auth, throttle:10,1 | `routes/web_core_part1.php:93` |
| DELETE | `/assistant/memories` | `assistant.memories.clear` | `GlobalAssistantController@clearMemories` | — | auth, throttle:5,1 | `routes/web_core_part1.php:94` |
| DELETE | `/assistant/history` | `assistant.history.clear` | `GlobalAssistantController@clearHistory` | — | auth, throttle:5,1 | `routes/web_core_part1.php:95` |
| GET | `/assistant/voice/status` | `assistant.voice.status` | `GlobalAssistantController@voiceStatus` | — | auth, throttle:60,1 | `routes/web_core_part1.php:96` |
| GET | `/assistant/csrf-token` | `assistant.csrf-token` | `Closure / unresolved` | — | auth, throttle:60,1 | `routes/web_core_part1.php:97` |
| POST | `/assistant/voice/stream-token` | `assistant.voice.stream-token` | `VoiceController@streamToken` | — | auth, throttle:30,1, capability:can_voice | `routes/web_core_part1.php:98` |
| POST | `/assistant/transcribe` | `assistant.transcribe` | `GlobalAssistantController@transcribe` | — | auth, throttle:20,1 | `routes/web_core_part1.php:99` |
| POST | `/assistant/speak` | `assistant.speak` | `GlobalAssistantController@speak` | — | auth, throttle:30,1 | `routes/web_core_part1.php:100` |
| GET | `/social` | `social` | `SocialController@index` | — | — | `routes/web_core_part1.php:101` |
| VIEW | `/terms` | `terms` | `view:/terms -> legal.terms` | — | — | `routes/web_core_part1.php:102` |
| VIEW | `/refund-policy` | `refunds` | `view:/refund-policy -> legal.refunds` | — | — | `routes/web_core_part1.php:103` |
| VIEW | `/privacy` | `privacy` | `view:/privacy -> legal.privacy` | — | — | `routes/web_core_part1.php:104` |
| GET | `/trust` | `trust` | `Closure / unresolved` | — | — | `routes/web_core_part1.php:106` |
| POST | `/api/support/guest-message` | `support.guest` | `SupportController@guestMessage` | — | throttle:20,10 | `routes/web_core_part1.php:123` |
| GET | `/assets/sounds/{file}` | `assets.sounds` | `Closure / unresolved` | — | — | `routes/web_core_part1.php:127` |
| GET | `/login` | `login` | `AuthController@showLogin` | — | — | `routes/web_core_part1.php:129` |
| POST | `/login/phone` | `login.phone` | `AuthController@phoneContinue` | — | throttle:10,10 | `routes/web_core_part1.php:130` |
| GET | `/login/password` | `login.password` | `AuthController@passwordForm` | — | — | `routes/web_core_part1.php:131` |
| POST | `/login/password` | `login.password.store` | `AuthController@passwordLogin` | — | throttle:10,10 | `routes/web_core_part1.php:132` |
| GET | `/register` | `register` | `AuthController@registerForm` | — | — | `routes/web_core_part1.php:133` |
| POST | `/register` | `register.store` | `AuthController@registerStore` | — | throttle:10,10 | `routes/web_core_part1.php:134` |
| POST | `/login/register/verify` | `register.otp.verify` | `AuthController@verifyRegistrationOtp` | — | throttle:10,10 | `routes/web_core_part1.php:135` |
| POST | `/login/request-otp` | `login.otp` | `AuthController@requestOtp` | — | throttle:5,10 | `routes/web_core_part1.php:136` |
| GET | `/login/email` | `login.email` | `AuthController@emailLoginForm` | — | — | `routes/web_core_part1.php:137` |
| POST | `/login/email` | `login.email.request` | `AuthController@requestEmailOtp` | — | throttle:5,10 | `routes/web_core_part1.php:138` |
| POST | `/login/email/verify` | `login.email.verify` | `AuthController@verifyEmailOtp` | — | throttle:10,10 | `routes/web_core_part1.php:139` |
| GET | `/forgot-password` | `password.request` | `AuthController@forgotPasswordForm` | — | — | `routes/web_core_part1.php:140` |
| POST | `/forgot-password` | `password.email` | `AuthController@sendPasswordResetOtp` | — | throttle:5,10 | `routes/web_core_part1.php:141` |
| POST | `/forgot-password/verify` | `password.verify` | `AuthController@verifyPasswordResetOtp` | — | throttle:10,10 | `routes/web_core_part1.php:142` |
| GET | `/reset-password` | `password.reset` | `AuthController@resetPasswordForm` | — | — | `routes/web_core_part1.php:143` |
| POST | `/reset-password` | `password.update` | `AuthController@resetPassword` | — | throttle:10,10 | `routes/web_core_part1.php:144` |
| GET | `/login/email/mobile` | `login.email.mobile` | `AuthController@emailMobileForm` | — | — | `routes/web_core_part1.php:145` |
| POST | `/login/email/mobile` | `login.email.mobile.request` | `AuthController@requestEmailMobileOtp` | — | throttle:5,10 | `routes/web_core_part1.php:146` |
| POST | `/logout` | `logout` | `AuthController@logout` | — | auth | `routes/web_core_part1.php:147` |
| GET | `/two-factor/challenge` | `two-factor.challenge` | `\App\Http\Controllers\AccountTwoFactorController@challenge` | auth | throttle:30,1 | `routes/web_core_part1.php:150` |
| POST | `/two-factor/challenge` | `two-factor.challenge.verify` | `\App\Http\Controllers\AccountTwoFactorController@verifyChallenge` | auth | throttle:10,1 | `routes/web_core_part1.php:152` |
| GET | `/editor/voice/status` | `editor.voice.status` | `VoiceController@voiceStatus` | — | throttle:60,1, web | `routes/web_core_part1.php:156` |
| GET | `/editor/voice/health` | `editor.voice.health` | `Closure / unresolved` | — | auth, throttle:60,1, web | `routes/web_core_part1.php:157` |
| POST | `/editor/voice/stream-config` | `editor.voice.stream-config` | `VoiceController@streamConfig` | — | throttle:120,1 | `routes/web_core_part1.php:176` |
| POST | `/editor/voice/provider-config` | `editor.voice.provider-config` | `VoiceController@streamProviderConfig` | — | throttle:120,1 | `routes/web_core_part1.php:177` |
| POST | `/editor/voice/stream-usage` | `editor.voice.stream-usage` | `VoiceController@streamUsage` | — | throttle:120,1 | `routes/web_core_part1.php:178` |
| POST | `/editor/upload` | `editor.upload` | `EditorController@upload` | — | throttle:20,10 | `routes/web_core_part1.php:179` |
| GET | `/announcements` | `announcements` | `AnnouncementController@index` | auth | — | `routes/web_core_part1.php:182` |
| GET | `/editor/preflight/estimate` | `editor.preflight.estimate.get` | `TypingPreflightController@estimate` | auth | throttle:30,10 | `routes/web_core_part1.php:183` |
| POST | `/editor/preflight/estimate` | `editor.preflight.estimate` | `TypingPreflightController@estimate` | auth | throttle:30,10 | `routes/web_core_part1.php:184` |
| POST | `/editor/preflight/accept` | `editor.preflight.accept` | `TypingPreflightController@accept` | auth | throttle:30,10 | `routes/web_core_part1.php:185` |
| POST | `/editor/preflight/decline` | `editor.preflight.decline` | `TypingPreflightController@decline` | auth | throttle:30,10 | `routes/web_core_part1.php:186` |
| GET | `/editor/pending` | `editor.pending` | `EditorController@pending` | auth | throttle:60,10 | `routes/web_core_part1.php:187` |
| GET | `/library` | `library` | `StoreLibraryController@index` | auth | — | `routes/web_core_part1.php:188` |
| POST | `/library/{libraryItem}/download` | `library.download.issue` | `StoreLibraryController@issue` | auth | throttle:20,10 | `routes/web_core_part1.php:189` |
| GET | `/downloads/{download}` | `store.download.stream` | `StoreLibraryController@stream` | auth | throttle:60,10 | `routes/web_core_part1.php:190` |
| GET | `/editor/files` | `editor.files.index` | `UserFileController@index` | auth | throttle:60,10 | `routes/web_core_part1.php:191` |
| POST | `/editor/files` | `editor.files.upload` | `UserFileController@upload` | auth | throttle:20,10 | `routes/web_core_part1.php:192` |
| POST | `/editor/files/{file}/select` | `editor.files.select` | `UserFileController@select` | auth | throttle:30,10 | `routes/web_core_part1.php:193` |
| DELETE | `/editor/files/{file}` | `editor.files.destroy` | `UserFileController@destroy` | auth | throttle:30,10 | `routes/web_core_part1.php:194` |
| GET | `/account/marketing-preferences` | `account.marketing-preferences.edit` | `\App\Http\Controllers\MarketingPreferenceController@edit` | auth | — | `routes/web_core_part1.php:200` |
| PUT | `/account/marketing-preferences` | `account.marketing-preferences.update` | `\App\Http\Controllers\MarketingPreferenceController@update` | auth | throttle:10,10 | `routes/web_core_part1.php:201` |

### `routes/web_core_part2.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/projects/new` | `projects.create` | `ProjectController@create` | — | auth | `routes/web_core_part2.php:29` |
| POST | `/projects` | `projects.store` | `ProjectController@store` | — | auth, throttle:30,10, capability:can_type | `routes/web_core_part2.php:30` |
| GET | `/editor` | `editor` | `EditorController@create` | — | auth, single.editor | `routes/web_core_part2.php:31` |
| POST | `/editor/documents` | `editor.documents.create` | `EditorController@createDocument` | — | auth, single.editor, throttle:60,10 | `routes/web_core_part2.php:32` |
| POST | `/editor/analyze` | `editor.analyze` | `EditorController@analyze` | auth, single.editor | throttle:20,10, capability:can_ai | `routes/web_core_part2.php:35` |
| POST | `/editor/ai/assist` | `editor.ai.assist` | `EditorAiAssistController` | auth, single.editor | throttle:30,10, capability:can_ai | `routes/web_core_part2.php:36` |
| POST | `/editor/agent/plan` | `editor.agent.plan` | `\App\Http\Controllers\EditorAgentController@plan` | auth, single.editor | throttle:20,10 | `routes/web_core_part2.php:37` |
| POST | `/editor/agent/tasks/{task}/approve` | `editor.agent.approve` | `\App\Http\Controllers\EditorAgentController@approve` | auth, single.editor | throttle:30,10 | `routes/web_core_part2.php:38` |
| POST | `/editor/agent/tasks/{task}/commit` | `editor.agent.commit` | `\App\Http\Controllers\EditorAgentController@commit` | auth, single.editor | throttle:30,10 | `routes/web_core_part2.php:39` |
| POST | `/editor/agent/tasks/{task}/undo` | `editor.agent.undo` | `\App\Http\Controllers\EditorAgentController@undo` | auth, single.editor | throttle:30,10 | `routes/web_core_part2.php:40` |
| POST | `/editor/save` | `editor.save` | `EditorSaveController` | auth, single.editor | throttle:120,1, capability:can_type | `routes/web_core_part2.php:41` |
| POST | `/editor/assets` | `editor.assets.upload` | `EditorController@uploadAsset` | auth, single.editor | throttle:60,10 | `routes/web_core_part2.php:42` |
| GET | `/editor/documents/{document}/assets/{resource}` | `editor.document.asset` | `EditorController@asset` | auth, single.editor | throttle:120,10 | `routes/web_core_part2.php:43` |
| GET | `/editor/documents/{document}/state` | `editor.document.state` | `EditorDocumentStateController@show` | auth, single.editor | throttle:60,10 | `routes/web_core_part2.php:44` |
| GET | `/editor/documents/{document}/versions` | `editor.document.versions` | `EditorDocumentStateController@versions` | auth, single.editor | throttle:30,10 | `routes/web_core_part2.php:45` |
| POST | `/editor/documents/{document}/versions/{version}/restore` | `editor.document.version.restore` | `EditorDocumentStateController@restore` | auth, single.editor | throttle:20,10 | `routes/web_core_part2.php:46` |
| POST | `/editor/feedback` | `editor.feedback` | `EditorController@feedback` | auth, single.editor | throttle:60,10, capability:can_feedback | `routes/web_core_part2.php:47` |
| POST | `/editor/voice/stream-token` | `editor.voice.stream-token` | `VoiceController@streamToken` | auth, single.editor | throttle:30,10, capability:can_voice | `routes/web_core_part2.php:48` |
| POST | `/editor/voice/transcribe` | `editor.voice.transcribe` | `VoiceController@transcribe` | auth, single.editor | throttle:30,10, capability:can_voice | `routes/web_core_part2.php:49` |
| POST | `/editor/voice/insert` | `editor.voice.insert` | `VoiceController@insertTranscript` | auth, single.editor | throttle:120,10, capability:can_voice | `routes/web_core_part2.php:50` |
| POST | `/editor/export/{format}` | `editor.export` | `ExportController@export` | auth, single.editor | throttle:10,10 | `routes/web_core_part2.php:51` |
| POST | `/editor/heartbeat` | `editor.heartbeat` | `EditorController@heartbeat` | auth, single.editor | throttle:60,1 | `routes/web_core_part2.php:52` |
| GET | `/dashboard` | `dashboard` | `EditorController@dashboard` | auth | — | `routes/web_core_part2.php:57` |
| GET | `/workspace/documents` | `platform.documents` | `PlatformCompletionController@documents` | auth | — | `routes/web_core_part2.php:58` |
| POST | `/workspace/documents` | `platform.documents.create` | `PlatformCompletionController@createDocument` | auth | — | `routes/web_core_part2.php:59` |
| POST | `/workspace/folders` | `platform.folders.create` | `PlatformCompletionController@createFolder` | auth | — | `routes/web_core_part2.php:60` |
| POST | `/workspace/documents/{id}` | `platform.documents.update` | `PlatformCompletionController@updateDocument` | auth | — | `routes/web_core_part2.php:61` |
| POST | `/workspace/documents/{id}/favorite` | `platform.documents.favorite` | `PlatformCompletionController@favorite` | auth | — | `routes/web_core_part2.php:62` |
| POST | `/workspace/documents/{id}/trash` | `platform.documents.trash` | `PlatformCompletionController@trash` | auth | — | `routes/web_core_part2.php:63` |
| POST | `/workspace/documents/{id}/restore` | `platform.documents.restore` | `PlatformCompletionController@restore` | auth | — | `routes/web_core_part2.php:64` |
| DELETE | `/workspace/documents/{id}` | `platform.documents.destroy` | `PlatformCompletionController@destroy` | auth | — | `routes/web_core_part2.php:65` |
| GET | `/workspace/documents/{id}/versions` | `platform.documents.versions` | `PlatformCompletionController@versions` | auth | — | `routes/web_core_part2.php:66` |
| POST | `/workspace/documents/{id}/share` | `platform.documents.share` | `PlatformCompletionController@share` | auth | — | `routes/web_core_part2.php:67` |
| DELETE | `/workspace/shares/{id}` | `platform.documents.shares.destroy` | `PlatformCompletionController@revokeShare` | auth | — | `routes/web_core_part2.php:68` |
| GET | `/workspace/workflows` | `platform.workflows` | `PlatformCompletionController@workflow` | auth | — | `routes/web_core_part2.php:70` |
| POST | `/workspace/workflows` | `platform.workflows.save` | `PlatformCompletionController@saveWorkflow` | auth | throttle:20,1 | `routes/web_core_part2.php:71` |
| POST | `/workspace/workflows/{id}/run` | `platform.workflows.run` | `PlatformCompletionController@runWorkflow` | auth | throttle:20,1 | `routes/web_core_part2.php:72` |
| GET | `/workspace/security` | `platform.security` | `AccountSecurityController@index` | auth | — | `routes/web_core_part2.php:73` |
| POST | `/workspace/security/two-factor/setup` | `platform.security.two-factor.setup` | `AccountTwoFactorController@startEnrollment` | auth | throttle:5,10 | `routes/web_core_part2.php:74` |
| POST | `/workspace/security/two-factor/enable` | `platform.security.two-factor.enable` | `AccountTwoFactorController@enable` | auth | throttle:5,10 | `routes/web_core_part2.php:75` |
| POST | `/workspace/security/two-factor/recovery-codes` | `platform.security.two-factor.recovery-codes` | `AccountTwoFactorController@regenerateRecoveryCodes` | auth | throttle:5,10 | `routes/web_core_part2.php:76` |
| POST | `/workspace/security/two-factor/disable` | `platform.security.two-factor.disable` | `AccountTwoFactorController@disable` | auth | throttle:5,10 | `routes/web_core_part2.php:77` |
| POST | `/workspace/security/sessions/{session}/revoke` | `platform.security.sessions.revoke` | `AccountSecurityController@revoke` | auth | throttle:10,10 | `routes/web_core_part2.php:78` |
| POST | `/workspace/security/sessions/{session}/block-device` | `platform.security.devices.block` | `AccountSecurityController@blockDevice` | auth | throttle:5,10 | `routes/web_core_part2.php:79` |
| POST | `/workspace/security/devices/{device}/unblock` | `platform.security.devices.unblock` | `AccountSecurityController@unblockDevice` | auth | throttle:5,10 | `routes/web_core_part2.php:80` |
| POST | `/workspace/security/sessions/revoke-others` | `platform.security.sessions.revoke-others` | `AccountSecurityController@revokeOthers` | auth | throttle:5,10 | `routes/web_core_part2.php:81` |
| POST | `/workspace/security/password` | `platform.security.password.update` | `AccountSecurityController@changePassword` | auth | throttle:5,10 | `routes/web_core_part2.php:82` |
| POST | `/workspace/security/sessions/revoke-all` | `platform.security.sessions.revoke-all` | `AccountSecurityController@revokeAll` | auth | throttle:3,10 | `routes/web_core_part2.php:83` |
| POST | `/workspace/ocr` | `platform.ocr` | `PlatformCompletionController@ocr` | auth | throttle:10,1 | `routes/web_core_part2.php:84` |
| GET | `/workspace/ocr/{id}` | `platform.ocr.status` | `PlatformCompletionController@ocrStatus` | auth | throttle:60,1 | `routes/web_core_part2.php:85` |
| GET | `/workspace/billing` | `platform.billing` | `PlatformCompletionController@billing` | auth | — | `routes/web_core_part2.php:86` |
| POST | `/workspace/billing/{plan}` | `platform.billing.subscribe` | `PlatformCompletionController@subscribe` | auth | throttle:5,10 | `routes/web_core_part2.php:87` |
| GET | `/support` | `support` | `SupportController@index` | auth | capability:can_support | `routes/web_core_part2.php:89` |
| GET | `/support/create` | `support.create.form` | `SupportController@createForm` | auth | capability:can_support | `routes/web_core_part2.php:90` |
| GET | `/support/tickets/{ticket}` | `support.show` | `SupportController@show` | auth | capability:can_support | `routes/web_core_part2.php:91` |
| GET | `/support/tickets/{ticket}/messages` | `support.messages.poll` | `SupportController@pollMessages` | auth | capability:can_support, throttle:60,1 | `routes/web_core_part2.php:92` |
| POST | `/support/tickets` | `support.create` | `SupportController@create` | auth | throttle:10,10, capability:can_support | `routes/web_core_part2.php:93` |
| POST | `/support/tickets/{ticket}/messages` | `support.message` | `SupportController@message` | auth | throttle:30,10, capability:can_support | `routes/web_core_part2.php:94` |
| POST | `/support/tickets/{ticket}/close` | `support.close` | `SupportController@close` | auth | throttle:10,10, capability:can_support | `routes/web_core_part2.php:95` |
| GET | `/wallet` | `wallet` | `WalletController@index` | auth | — | `routes/web_core_part2.php:96` |
| POST | `/wallet/top-up` | `wallet.topup` | `WalletController@topUp` | auth | throttle:10,10 | `routes/web_core_part2.php:97` |
| GET | `/checkout/{order}` | `checkout` | `PaymentController@show` | auth | — | `routes/web_core_part2.php:98` |
| POST | `/checkout/{order}/wallet` | `checkout.wallet` | `PaymentController@payWithWallet` | auth | throttle:10,10 | `routes/web_core_part2.php:99` |
| POST | `/documents/{document}/checkout` | `documents.checkout` | `PaymentController@createForDocument` | auth | throttle:10,10 | `routes/web_core_part2.php:100` |
| GET | `/shared/documents/{token}` | `platform.documents.shared` | `PlatformCompletionController@shared` | — | — | `routes/web_core_part2.php:103` |
| GET | `/canva/connect` | `canva.connect` | `CanvaController@connect` | auth | — | `routes/web_core_part2.php:106` |
| GET | `/canva/callback` | `canva.callback` | `CanvaController@callback` | auth | — | `routes/web_core_part2.php:107` |
| POST | `/canva/disconnect` | `canva.disconnect` | `CanvaController@disconnect` | auth | — | `routes/web_core_part2.php:108` |
| GET | `/canva/designs` | `canva.designs` | `CanvaController@designs` | auth | — | `routes/web_core_part2.php:109` |
| POST | `/canva/designs` | `canva.designs.create` | `CanvaController@create` | auth | — | `routes/web_core_part2.php:110` |
| POST | `/canva/designs/{design}/export` | `canva.designs.export` | `CanvaController@export` | auth | — | `routes/web_core_part2.php:111` |
| GET | `/canva/exports/{job}` | `canva.exports.status` | `CanvaController@exportStatus` | auth | — | `routes/web_core_part2.php:112` |
| POST | `/canva/imports` | `canva.imports.create` | `CanvaController@import` | auth | — | `routes/web_core_part2.php:113` |
| GET | `/canva/imports/{job}` | `canva.imports.status` | `CanvaController@importStatus` | auth | — | `routes/web_core_part2.php:114` |
| GET | `/admin/storage` | `admin.storage.index` | `\App\Http\Controllers\AdminStorageController@index` | auth, admin | admin.section:storage | `routes/web_core_part2.php:119` |
| GET | `/admin/storage/backups` | `admin.storage.backups` | `\App\Http\Controllers\AdminStorageController@backups` | auth, admin | admin.section:storage | `routes/web_core_part2.php:120` |
| POST | `/admin/storage/backups/run` | `admin.storage.backups.run` | `\App\Http\Controllers\AdminStorageController@requestBackup` | auth, admin | admin.section:storage, throttle:2,10 | `routes/web_core_part2.php:121` |
| POST | `/admin/storage/volumes` | `admin.storage.store` | `\App\Http\Controllers\AdminStorageController@store` | auth, admin | admin.section:storage, throttle:5,10 | `routes/web_core_part2.php:122` |
| GET | `/admin/storage/volumes/{volume}` | `admin.storage.volume` | `\App\Http\Controllers\AdminStorageController@show` | auth, admin | admin.section:storage | `routes/web_core_part2.php:123` |
| GET | `/admin/storage/files/{file}/download` | `admin.storage.files.download` | `\App\Http\Controllers\AdminStorageController@downloadFile` | auth, admin | admin.section:storage | `routes/web_core_part2.php:124` |
| POST | `/admin/storage/volumes/{volume}/verify` | `admin.storage.verify` | `\App\Http\Controllers\AdminStorageController@verify` | auth, admin | admin.section:storage, throttle:5,10 | `routes/web_core_part2.php:125` |
| POST | `/admin/storage/volumes/{volume}/toggle` | `admin.storage.toggle` | `\App\Http\Controllers\AdminStorageController@toggle` | auth, admin | admin.section:storage | `routes/web_core_part2.php:126` |
| DELETE | `/admin/storage/volumes/{volume}` | `admin.storage.destroy` | `\App\Http\Controllers\AdminStorageController@destroy` | auth, admin | admin.section:storage | `routes/web_core_part2.php:127` |
| GET | `/admin/discussion-reports` | `admin.discussion-reports.index` | `ContentDiscussionController@adminReports` | auth, admin | admin.section:store | `routes/web_core_part2.php:129` |
| PATCH | `/admin/discussion-reports/{report}` | `admin.discussion-reports.moderate` | `ContentDiscussionController@moderateReport` | auth, admin | admin.section:store, throttle:30,10 | `routes/web_core_part2.php:130` |
| GET | `/admin/discussions` | `admin.discussions.index` | `ContentDiscussionController@adminIndex` | auth, admin | admin.section:store | `routes/web_core_part2.php:131` |
| PATCH | `/admin/discussions/{discussion}/moderate` | `admin.discussions.moderate` | `ContentDiscussionController@moderate` | auth, admin | admin.section:store, throttle:30,10 | `routes/web_core_part2.php:132` |
| POST | `/admin/discussions/{discussion}/reply` | `admin.discussions.reply` | `ContentDiscussionController@reply` | auth, admin | admin.section:store, throttle:20,10 | `routes/web_core_part2.php:133` |
| GET | `/admin/store-coupons` | `admin.store-coupons.index` | `\App\Http\Controllers\AdminStoreCouponController@index` | auth, admin | admin.section:marketing | `routes/web_core_part2.php:136` |
| PUT | `/admin/store-coupons/settings` | `admin.store-coupons.settings` | `\App\Http\Controllers\AdminStoreCouponController@saveSettings` | auth, admin | admin.section:marketing, throttle:10,10 | `routes/web_core_part2.php:137` |
| POST | `/admin/store-coupons` | `admin.store-coupons.store` | `\App\Http\Controllers\AdminStoreCouponController@store` | auth, admin | admin.section:marketing, throttle:10,10 | `routes/web_core_part2.php:138` |
| PATCH | `/admin/store-coupons/{coupon}/toggle` | `admin.store-coupons.toggle` | `\App\Http\Controllers\AdminStoreCouponController@toggle` | auth, admin | admin.section:marketing, throttle:20,10 | `routes/web_core_part2.php:139` |
| POST | `/admin/store-coupons/assignments/{assignment}/revoke` | `admin.store-coupons.assignments.revoke` | `\App\Http\Controllers\AdminStoreCouponController@revokeAssignment` | auth, admin | admin.section:marketing, throttle:20,10 | `routes/web_core_part2.php:140` |
| GET | `/admin/crm/marketing` | `admin.crm.marketing.index` | `CrmMarketingController@dashboard` | auth, admin | admin.section:marketing | `routes/web_core_part2.php:143` |
| GET | `/admin/crm/marketing/segments` | `admin.crm.marketing.segments.index` | `CrmMarketingController@segments` | auth, admin | admin.section:marketing | `routes/web_core_part2.php:144` |
| POST | `/admin/crm/marketing/segments` | `admin.crm.marketing.segments.store` | `CrmMarketingController@storeSegment` | auth, admin | admin.section:marketing, throttle:20,10 | `routes/web_core_part2.php:145` |
| POST | `/admin/crm/marketing/audience/preview` | `admin.crm.marketing.audience.preview` | `CrmMarketingController@previewAudience` | auth, admin | admin.section:marketing | `routes/web_core_part2.php:146` |
| GET | `/admin/crm/marketing/campaigns` | `admin.crm.marketing.campaigns.index` | `CrmMarketingController@campaigns` | auth, admin | admin.section:marketing | `routes/web_core_part2.php:147` |
| POST | `/admin/crm/marketing/campaigns` | `admin.crm.marketing.campaigns.store` | `CrmMarketingController@storeCampaign` | auth, admin | admin.section:marketing, throttle:20,10 | `routes/web_core_part2.php:148` |
| POST | `/admin/crm/marketing/campaigns/{campaign}/send` | `admin.crm.marketing.campaigns.send` | `CrmMarketingController@sendCampaign` | auth, admin | admin.section:marketing, throttle:5,10 | `routes/web_core_part2.php:149` |
| GET | `/admin/crm/analytics` | `admin.crm.analytics` | `CrmAnalyticsController` | auth, admin | admin.section:marketing | `routes/web_core_part2.php:151` |
| GET | `/admin/crm/projects` | `admin.crm.projects.index` | `CrmProjectController@index` | auth, admin | admin.section:users | `routes/web_core_part2.php:153` |
| GET | `/admin/crm/projects/{project}` | `admin.crm.projects.show` | `CrmProjectController@show` | auth, admin | admin.section:users | `routes/web_core_part2.php:154` |
| POST | `/admin/crm/projects/{project}/tasks/{task}/assign` | `admin.crm.projects.tasks.assign` | `CrmProjectController@assignHumanTask` | auth, admin | admin.section:users, throttle:30,10 | `routes/web_core_part2.php:155` |
| GET | `/admin/crm/follow-ups` | `admin.crm.followups.index` | `CrmController@followUps` | auth, admin | admin.section:users | `routes/web_core_part2.php:158` |
| GET | `/admin/crm/customers` | `admin.crm.customers.index` | `CrmController@customers` | auth, admin | admin.section:users | `routes/web_core_part2.php:159` |
| GET | `/admin/crm/customers/{customer}` | `admin.crm.customers.show` | `CrmController@show` | auth, admin | admin.section:users | `routes/web_core_part2.php:160` |
| POST | `/admin/crm/customers/{customer}/notes` | `admin.crm.customers.notes.store` | `CrmController@storeNote` | auth, admin | admin.section:users, throttle:30,10 | `routes/web_core_part2.php:161` |
| POST | `/admin/crm/customers/{customer}/follow-ups` | `admin.crm.customers.followups.store` | `CrmController@storeFollowUp` | auth, admin | admin.section:users, throttle:30,10 | `routes/web_core_part2.php:162` |
| PATCH | `/admin/crm/customers/{customer}/follow-ups/{task}` | `admin.crm.customers.followups.update` | `CrmController@updateFollowUp` | auth, admin | admin.section:users, throttle:30,10 | `routes/web_core_part2.php:163` |
| GET | `/admin` | `admin.index` | `AdminController@index` | auth, admin | admin.section:overview | `routes/web_core_part2.php:164` |
| GET | `/admin/users` | `admin.users` | `AdminController@users` | auth, admin | admin.section:users | `routes/web_core_part2.php:165` |
| POST | `/admin/users/{user}/role` | `admin.users.role` | `AdminController@updateUserRole` | auth, admin | admin.section:users | `routes/web_core_part2.php:166` |
| GET | `/admin/finance` | `admin.finance` | `AdminController@finance` | auth, admin | admin.section:finance | `routes/web_core_part2.php:167` |
| POST | `/admin/finance` | `admin.finance.update` | `AdminController@updateFinance` | auth, admin | admin.section:finance_settings | `routes/web_core_part2.php:168` |
| POST | `/admin/finance/users/{user}/wallet` | `admin.finance.wallet` | `AdminController@adjustWallet` | auth, admin | admin.section:finance_manage | `routes/web_core_part2.php:169` |
| GET | `/admin/social` | `admin.social` | `SocialController@admin` | auth, admin | admin.section:social | `routes/web_core_part2.php:170` |
| POST | `/admin/social` | `admin.social.update` | `SocialController@update` | auth, admin | admin.section:social | `routes/web_core_part2.php:171` |
| POST | `/admin/settings` | `admin.settings.update` | `AdminController@updateSettings` | auth, admin | admin.section:settings | `routes/web_core_part2.php:172` |
| POST | `/admin/voice-providers` | `admin.voice.providers.update` | `AdminController@updateVoiceProviders` | auth, admin | admin.section:voice | `routes/web_core_part2.php:173` |
| POST | `/admin/pricing` | `admin.pricing.update` | `AdminController@updatePricing` | auth, admin | admin.section:pricing | `routes/web_core_part2.php:174` |
| POST | `/admin/users/{user}/capabilities` | `admin.user.capabilities` | `AdminController@updateUserCapabilities` | auth, admin | admin.section:users | `routes/web_core_part2.php:175` |
| POST | `/admin/users/{user}/block` | `admin.user.toggle` | `AdminController@toggleUser` | auth, admin | admin.section:users | `routes/web_core_part2.php:176` |
| GET | `/admin/announcements` | `admin.announcements.index` | `AnnouncementController@manage` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:177` |
| POST | `/admin/announcements` | `admin.announcements.store` | `AnnouncementController@store` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:178` |
| PUT | `/admin/announcements/{announcement}` | `admin.announcements.update` | `AnnouncementController@update` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:179` |
| POST | `/admin/announcements/{announcement}/toggle` | `admin.announcements.toggle` | `AnnouncementController@toggle` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:180` |
| POST | `/admin/announcements/{announcement}/rebroadcast` | `admin.announcements.rebroadcast` | `AnnouncementController@rebroadcast` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:181` |
| DELETE | `/admin/announcements/{announcement}` | `admin.announcements.destroy` | `AnnouncementController@destroy` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:182` |
| POST | `/admin/announcements/{announcement}/archive` | `admin.announcements.archive` | `AnnouncementController@archive` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:183` |
| POST | `/admin/announcements/{announcement}/restore` | `admin.announcements.restore` | `AnnouncementController@restore` | auth, admin | admin.section:announcements | `routes/web_core_part2.php:184` |
| GET | `/admin/emails` | `admin.emails` | `AdminController@emails` | auth, admin | admin.section:emails | `routes/web_core_part2.php:185` |
| POST | `/admin/emails/settings` | `admin.emails.settings` | `AdminController@updateEmailSettings` | auth, admin | admin.section:emails | `routes/web_core_part2.php:186` |
| POST | `/admin/emails/test` | `admin.emails.test` | `AdminController@sendTestEmail` | auth, admin | admin.section:emails | `routes/web_core_part2.php:187` |
| GET | `/admin/integrations` | `admin.integrations` | `AdminPlatformController@integrations` | auth, admin | admin.section:integrations | `routes/web_core_part2.php:188` |
| POST | `/admin/integrations/canva` | `admin.integrations.canva.save` | `AdminPlatformController@saveCanva` | auth, admin | admin.section:integrations | `routes/web_core_part2.php:189` |
| GET | `/admin/integrations/canva/connect` | `admin.integrations.canva.connect` | `CanvaController@connect` | auth, admin | admin.section:integrations | `routes/web_core_part2.php:190` |
| GET | `/admin/integrations/canva/callback` | `admin.integrations.canva.callback` | `CanvaController@callback` | auth, admin | admin.section:integrations | `routes/web_core_part2.php:191` |
| POST | `/admin/integrations/canva/disconnect` | `admin.integrations.canva.disconnect` | `CanvaController@disconnect` | auth, admin | admin.section:integrations | `routes/web_core_part2.php:192` |
| GET | `/admin/access` | `admin.access` | `AdminPlatformController@access` | auth, admin | admin.section:access | `routes/web_core_part2.php:193` |
| POST | `/admin/access/roles/{role}` | `admin.access.roles.update` | `AdminPlatformController@updateRole` | auth, admin | admin.section:access | `routes/web_core_part2.php:194` |
| GET | `/admin/organizations` | `admin.organizations` | `AdminPlatformController@organizations` | auth, admin | admin.section:organizations | `routes/web_core_part2.php:195` |
| POST | `/admin/organizations` | `admin.organizations.store` | `AdminPlatformController@storeOrganization` | auth, admin | admin.section:organizations | `routes/web_core_part2.php:196` |
| GET | `/admin/analytics` | `admin.analytics` | `AdminPlatformController@analytics` | auth, admin | admin.section:analytics | `routes/web_core_part2.php:197` |
| GET | `/admin/audit` | `admin.audit` | `AdminPlatformController@audit` | auth, admin | admin.section:audit | `routes/web_core_part2.php:198` |
| POST | `/admin/providers/ai` | `admin.providers.ai.update` | `ProviderAdminController@updateAi` | auth, admin | admin.section:ai_providers | `routes/web_core_part2.php:199` |
| GET | `/admin/providers/ai/test` | `admin.providers.ai.test` | `ProviderAdminController@testAi` | auth, admin | admin.section:ai_providers, throttle:5,1 | `routes/web_core_part2.php:200` |
| GET | `/admin/providers/voice/{provider}/test` | `admin.providers.voice.test` | `ProviderAdminController@testVoice` | auth, admin | admin.section:voice | `routes/web_core_part2.php:201` |
| POST | `/admin/providers/groq` | `admin.providers.groq.update` | `ProviderAdminController@updateGroq` | auth, admin | admin.section:ai_providers | `routes/web_core_part2.php:202` |
| GET | `/admin/providers/groq/test` | `admin.providers.groq.test` | `ProviderAdminController@testGroq` | auth, admin | admin.section:ai_providers, throttle:5,1 | `routes/web_core_part2.php:203` |
| GET | `/admin/support-sms` | `admin.support.sms` | `AdminController@supportSms` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:205` |
| POST | `/admin/support-sms` | `admin.support.sms.update` | `AdminController@updateSupportSms` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:206` |
| GET | `/admin/support-management` | `admin.support.management` | `SupportController@adminIndex` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:207` |
| POST | `/admin/tickets/{ticket}/reply` | `admin.tickets.reply` | `SupportController@adminReply` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:208` |
| POST | `/admin/tickets/{ticket}/status` | `admin.tickets.status` | `SupportController@adminStatus` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:209` |
| POST | `/admin/tickets/{ticket}/assign` | `admin.tickets.assign` | `SupportController@adminAssign` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:210` |
| POST | `/admin/tickets/{ticket}/meta` | `admin.tickets.meta` | `SupportController@adminMeta` | auth, admin | admin.section:support_admin | `routes/web_core_part2.php:211` |
| GET | `/admin/plans` | `admin.plans` | `PlatformCompletionController@adminPlans` | auth, admin | admin.section:plans | `routes/web_core_part2.php:212` |
| POST | `/admin/plans` | `admin.plans.save` | `PlatformCompletionController@savePlan` | auth, admin | admin.section:plans | `routes/web_core_part2.php:213` |
| GET | `/admin/cms` | `admin.cms` | `PlatformCompletionController@cms` | auth, admin | admin.section:cms | `routes/web_core_part2.php:214` |
| GET | `/admin/teams/{team}/members` | `admin.teams.members` | `PlatformCompletionController@teamMembers` | auth, admin | admin.section:access | `routes/web_core_part2.php:215` |
| POST | `/admin/teams/{team}/members` | `admin.teams.members.add` | `PlatformCompletionController@addTeamMember` | auth, admin | admin.section:access | `routes/web_core_part2.php:216` |
| DELETE | `/admin/teams/{team}/members/{user}` | `admin.teams.members.remove` | `PlatformCompletionController@removeTeamMember` | auth, admin | admin.section:access | `routes/web_core_part2.php:217` |
| POST | `/admin/cms` | `admin.cms.save` | `PlatformCompletionController@saveCms` | auth, admin | admin.section:cms | `routes/web_core_part2.php:218` |

### `routes/store_routes.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/store/product/{slug}/reader` | `store.reader` | `StoreController@reader` | — | — | `routes/store_routes.php:12` |
| GET | `/store/product/{product}/reader/pages/{page}` | `store.reader.page` | `StoreController@readerPage` | auth | throttle:300,10 | `routes/store_routes.php:14` |
| GET | `/store/product/{product}/reader/audio/{document}` | `store.reader.audio` | `StoreController@readerAudio` | auth | throttle:300,10 | `routes/store_routes.php:15` |
| GET | `/store/preview/{preview}/audio` | `store.preview.audio` | `StoreController@previewAudio` | — | throttle:60,10 | `routes/store_routes.php:17` |
| GET | `/store/product/{product}/lessons/{lesson}/preview` | `store.course.preview` | `StoreController@coursePreview` | — | — | `routes/store_routes.php:18` |
| GET | `/store/{product}/reader-state` | `store.reader.state.show` | `\App\Http\Controllers\StoreReaderStateController@show` | auth | throttle:120,10 | `routes/store_routes.php:21` |
| PUT | `/store/{product}/reader-state` | `store.reader.state.update` | `\App\Http\Controllers\StoreReaderStateController@update` | auth | throttle:60,10 | `routes/store_routes.php:22` |
| GET | `/my-courses/{product}` | `store.course.learn` | `StoreCourseLearningController@show` | auth | — | `routes/store_routes.php:26` |
| GET | `/my-courses/{product}/lessons/{lesson}` | `store.course.lesson` | `StoreCourseLearningController@lesson` | auth | — | `routes/store_routes.php:27` |
| POST | `/my-courses/{product}/lessons/{lesson}/progress` | `store.course.progress` | `StoreCourseLearningController@progress` | auth | throttle:60,10 | `routes/store_routes.php:28` |
| GET | `/my-courses/{product}/lessons/{lesson}/resources/{file}` | `store.course.resource` | `StoreCourseLearningController@resource` | auth | throttle:60,10 | `routes/store_routes.php:29` |
| GET | `/wishlist` | `wishlist` | `StoreWishlistController@index` | auth | — | `routes/store_routes.php:33` |
| POST | `/wishlist/{product}` | `wishlist.toggle` | `StoreWishlistController@toggle` | auth | throttle:60,10 | `routes/store_routes.php:34` |
| DELETE | `/wishlist/{product}` | `wishlist.destroy` | `StoreWishlistController@destroy` | auth | throttle:60,10 | `routes/store_routes.php:35` |
| POST | `/checkout/{order}/zarinpal` | `checkout.zarinpal` | `PaymentController@payWithZarinpal` | auth | throttle:10,10 | `routes/store_routes.php:39` |
| GET | `/checkout/zarinpal/callback` | `checkout.zarinpal.callback` | `PaymentController@zarinpalCallback` | auth | throttle:30,10 | `routes/store_routes.php:40` |
| GET | `/wallet/top-up/zarinpal/callback` | `wallet.topup.callback` | `\App\Http\Controllers\WalletController@topUpCallback` | — | throttle:30,10 | `routes/store_routes.php:43` |
| POST | `/admin/store/{product}/ai-supervisor/review` | `admin.store.ai-supervisor.review` | `\App\Http\Controllers\AdminProductSupervisorController@review` | auth, admin | throttle:5,10 | `routes/store_routes.php:46` |
| POST | `/admin/store/{product}/ai-supervisor/files/{file}/review` | `admin.store.ai-supervisor.files.review` | `\App\Http\Controllers\AdminProductSupervisorController@reviewFile` | auth, admin | throttle:5,10 | `routes/store_routes.php:47` |
| POST | `/admin/store/{product}/ai-supervisor/images/{image}/review` | `admin.store.ai-supervisor.images.review` | `\App\Http\Controllers\AdminProductSupervisorController@reviewImage` | auth, admin | throttle:5,10 | `routes/store_routes.php:48` |
| POST | `/admin/store/{product}/ai-supervisor/cover/review` | `admin.store.ai-supervisor.cover.review` | `\App\Http\Controllers\AdminProductSupervisorController@reviewCover` | auth, admin | throttle:5,10 | `routes/store_routes.php:49` |
| POST | `/admin/store/{product}/ai-content/generate` | `admin.store.ai-content.generate` | `\App\Http\Controllers\AdminProductContentController@generate` | auth, admin | throttle:3,10 | `routes/store_routes.php:50` |
| GET | `/admin/store` | `admin.store.index` | `AdminStoreController@index` | auth, admin | — | `routes/store_routes.php:51` |
| POST | `/admin/store/bulk-archive` | `admin.store.bulk-archive` | `AdminStoreController@bulkArchive` | auth, admin | throttle:10,10 | `routes/store_routes.php:52` |
| GET | `/admin/store/create` | `admin.store.create` | `AdminStoreController@create` | auth, admin | — | `routes/store_routes.php:53` |
| POST | `/admin/store` | `admin.store.store` | `AdminStoreController@store` | auth, admin | — | `routes/store_routes.php:54` |
| GET | `/admin/store/{product}/edit` | `admin.store.edit` | `AdminStoreController@edit` | auth, admin | — | `routes/store_routes.php:55` |
| PUT | `/admin/store/{product}` | `admin.store.update` | `AdminStoreController@update` | auth, admin | — | `routes/store_routes.php:56` |
| DELETE | `/admin/store/{product}` | `admin.store.destroy` | `AdminStoreController@destroy` | auth, admin | — | `routes/store_routes.php:57` |
| POST | `/admin/store/{product}/cover` | `admin.store.cover.upload` | `AdminStoreController@uploadCover` | auth, admin | throttle:10,10 | `routes/store_routes.php:58` |
| POST | `/admin/store/{product}/images` | `admin.store.images.upload` | `AdminStoreController@uploadImage` | auth, admin | throttle:20,10 | `routes/store_routes.php:59` |
| DELETE | `/admin/store/{product}/images/{image}` | `admin.store.images.delete` | `AdminStoreController@deleteImage` | auth, admin | — | `routes/store_routes.php:60` |
| POST | `/admin/store/{product}/files/preflight` | `admin.store.files.preflight` | `AdminStoreController@preflightFile` | auth, admin | throttle:120,10 | `routes/store_routes.php:61` |
| GET | `/admin/store/{product}/files/{file}/processing` | `admin.store.files.processing` | `AdminStoreController@fileProcessingStatus` | auth, admin | throttle:60,10 | `routes/store_routes.php:62` |
| POST | `/admin/store/{product}/files/{ingestionId}/confirm` | `admin.store.files.confirm` | `AdminStoreController@confirmFileUpload` | auth, admin | throttle:120,10 | `routes/store_routes.php:63` |
| DELETE | `/admin/store/{product}/files/{ingestionId}/preflight` | `admin.store.files.cancel` | `AdminStoreController@cancelFilePreflight` | auth, admin | throttle:120,10 | `routes/store_routes.php:64` |
| POST | `/admin/store/{product}/files` | `admin.store.files.upload` | `AdminStoreController@uploadFile` | auth, admin | throttle:10,10 | `routes/store_routes.php:65` |
| DELETE | `/admin/store/{product}/files/{file}` | `admin.store.files.delete` | `AdminStoreController@deleteFile` | auth, admin | — | `routes/store_routes.php:66` |
| POST | `/admin/store/{product}/previews` | `admin.store.previews.upload` | `AdminStoreController@uploadPreview` | auth, admin | throttle:10,10 | `routes/store_routes.php:67` |
| POST | `/admin/store/{product}/previews/{preview}/audio` | `admin.store.previews.audio.upload` | `AdminStoreController@uploadPreviewAudio` | auth, admin | throttle:10,10 | `routes/store_routes.php:68` |
| DELETE | `/admin/store/{product}/previews/{preview}` | `admin.store.previews.delete` | `AdminStoreController@deletePreview` | auth, admin | — | `routes/store_routes.php:69` |
| GET | `/admin/store/{product}/course` | `admin.store.course.edit` | `AdminCourseController@edit` | auth, admin | — | `routes/store_routes.php:71` |
| PUT | `/admin/store/{product}/course` | `admin.store.course.update` | `AdminCourseController@update` | auth, admin | — | `routes/store_routes.php:72` |
| POST | `/admin/store/{product}/course/sections` | `admin.store.course.sections.store` | `AdminCourseController@storeSection` | auth, admin | — | `routes/store_routes.php:73` |
| PUT | `/admin/store/{product}/course/sections/{section}` | `admin.store.course.sections.update` | `AdminCourseController@updateSection` | auth, admin | — | `routes/store_routes.php:74` |
| DELETE | `/admin/store/{product}/course/sections/{section}` | `admin.store.course.sections.delete` | `AdminCourseController@deleteSection` | auth, admin | — | `routes/store_routes.php:75` |
| POST | `/admin/store/{product}/course/sections/{section}/lessons` | `admin.store.course.lessons.store` | `AdminCourseController@storeLesson` | auth, admin | — | `routes/store_routes.php:76` |
| PUT | `/admin/store/{product}/course/lessons/{lesson}` | `admin.store.course.lessons.update` | `AdminCourseController@updateLesson` | auth, admin | — | `routes/store_routes.php:77` |
| DELETE | `/admin/store/{product}/course/lessons/{lesson}` | `admin.store.course.lessons.delete` | `AdminCourseController@deleteLesson` | auth, admin | — | `routes/store_routes.php:78` |
| POST | `/admin/store/{product}/course/lessons/{lesson}/resources` | `admin.store.course.resources.upload` | `AdminCourseController@uploadResource` | auth, admin | throttle:10,10 | `routes/store_routes.php:79` |
| DELETE | `/admin/store/{product}/course/lessons/{lesson}/resources/{file}` | `admin.store.course.resources.delete` | `AdminCourseController@deleteResource` | auth, admin | — | `routes/store_routes.php:80` |
| POST | `/admin/store/{product}/course/lessons/{lesson}/resources/{file}/review` | `admin.store.course.resources.review` | `AdminProductSupervisorController@reviewCourseResource` | auth, admin | throttle:5,10 | `routes/store_routes.php:81` |
| POST | `/admin/store/categories` | `admin.store.categories.store` | `AdminStoreController@storeCategory` | auth, admin | — | `routes/store_routes.php:83` |
| POST | `/admin/store/tags` | `admin.store.tags.store` | `AdminStoreController@storeTag` | auth, admin | — | `routes/store_routes.php:84` |

### `routes/support_extra.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/admin/communications` | `admin.communications.index` | `CommunicationCenterController` | auth, admin | admin.section:support_admin, throttle:60,1 | `routes/support_extra.php:9` |
| GET | `/admin/support-report` | `admin.support.report` | `SupportReportController` | auth, admin | — | `routes/support_extra.php:10` |
| GET | `/workspace/communications` | `platform.communications` | `CommunicationCenterController` | auth | throttle:60,1 | `routes/support_extra.php:14` |
| GET | `/support/tickets/{ticket}/messages/{message}/attachments/{index}` | `support.attachment` | `SupportController@attachment` | auth | throttle:60,10 | `routes/support_extra.php:15` |
| POST | `/support/tickets/{ticket}/csat` | `support.csat` | `SupportController@csat` | auth | — | `routes/support_extra.php:16` |

### `routes/notifications.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/workspace/notifications` | `platform.notifications` | `NotificationController@index` | auth | — | `routes/notifications.php:7` |
| GET | `/workspace/notifications/{id}/visit` | `platform.notifications.visit` | `NotificationController@visit` | auth | — | `routes/notifications.php:8` |
| POST | `/workspace/notifications/read-all` | `platform.notifications.readAll` | `NotificationController@readAll` | auth | — | `routes/notifications.php:9` |
| POST | `/workspace/notifications/{id}/read` | `platform.notifications.read` | `NotificationController@read` | auth | — | `routes/notifications.php:10` |
| POST | `/workspace/notifications/{id}/archive` | `platform.notifications.archive` | `NotificationController@archive` | auth | — | `routes/notifications.php:11` |
| POST | `/workspace/notifications/{id}/restore` | `platform.notifications.restore` | `NotificationController@restore` | auth | — | `routes/notifications.php:12` |
| GET | `/api/notifications/unread-count` | `notifications.unread` | `NotificationController@unreadCount` | auth | — | `routes/notifications.php:13` |
| GET | `/api/notifications/latest` | `notifications.latest` | `NotificationController@latest` | auth | — | `routes/notifications.php:14` |

### `routes/messenger.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/messenger` | `messenger.index` | `MessengerController@index` | auth | — | `routes/messenger.php:7` |
| POST | `/messenger/conversations` | `messenger.store` | `MessengerController@store` | auth | throttle:10,1 | `routes/messenger.php:8` |
| GET | `/messenger/conversations/{conversation}` | `messenger.show` | `MessengerController@show` | auth | — | `routes/messenger.php:9` |
| POST | `/messenger/conversations/{conversation}/messages` | `messenger.send` | `MessengerController@send` | auth | throttle:30,1 | `routes/messenger.php:10` |
| GET | `/messenger/conversations/{conversation}/poll` | `messenger.poll` | `MessengerController@poll` | auth | throttle:60,1 | `routes/messenger.php:11` |
| POST | `/messenger/conversations/{conversation}/read` | `messenger.read` | `MessengerController@markRead` | auth | throttle:60,1 | `routes/messenger.php:12` |
| POST | `/messenger/conversations/{conversation}/presence` | `messenger.presence` | `MessengerController@presence` | auth | throttle:60,1 | `routes/messenger.php:13` |
| PATCH | `/messenger/conversations/{conversation}` | `messenger.update` | `MessengerController@update` | auth | throttle:10,1 | `routes/messenger.php:14` |
| POST | `/messenger/conversations/{conversation}/participants` | `messenger.participants.add` | `MessengerController@addParticipants` | auth | throttle:10,1 | `routes/messenger.php:15` |
| DELETE | `/messenger/conversations/{conversation}/leave` | `messenger.leave` | `MessengerController@leave` | auth | throttle:10,1 | `routes/messenger.php:16` |
| POST | `/messenger/conversations/{conversation}/archive` | `messenger.archive` | `MessengerController@archive` | auth | throttle:10,1 | `routes/messenger.php:17` |
| GET | `/messenger/conversations/{conversation}/messages/{message}/attachments/{index}` | `messenger.attachment` | `MessengerController@attachment` | auth | throttle:30,1 | `routes/messenger.php:18` |

### `routes/product_operations.php`

| Method | URI | Route name | Action | Group middleware | Route middleware | Source |
|---|---|---|---|---|---|---|
| GET | `/admin/store/operations` | `admin.store.operations` | `AdminProductOperationsController@index` | auth, admin | — | `routes/product_operations.php:7` |
| POST | `/admin/store/operations/documents/{document}/retry` | `admin.store.operations.documents.retry` | `AdminProductOperationsController@retry` | auth, admin | throttle:10,10 | `routes/product_operations.php:8` |


## Routeهای افزوده‌شده در main پس از snapshot inventory

| Method | URI | Route name | Action | Middleware | Source |
|---|---|---|---|---|---|
| GET | `/ready` | `health.ready` | `ReadinessController::__invoke` | خارج از گروه web؛ پاسخ فقط وضعیت DB را می‌دهد | `bootstrap/app.php:18` |
| GET | `/admin/observability` | `admin.observability.index` | `Admin\\ObservabilityController@index` | `auth`, `admin`, `admin.section:audit` | `routes/web_core_part2.php:117-121` |
| GET | `/up` | framework health route | Laravel framework health handler | health route تعریف‌شده در `withRouting` | `bootstrap/app.php:15` |


### نتیجه runtime route:list در CI

روی همان کد برنامه main در commit `d6050242449e4d0869cf39daff0d5ada69e258ba`، job لاراول PR #475 فرمان `php artisan route:list --json` را اجرا کرد و نتیجه را چنین ثبت کرد:

- Routeهای ثبت‌شده در Laravel: **337**
- Routeهای دارای نام: **336**
- نام تکراری در Route collection: **صفر**
- گام CI: موفق؛ check route name uniqueness بدون خطا تمام شد.

این شمارش runtime شامل health route چارچوب و routeهای ثبت‌شده در bootstrap است؛ بنابراین با 334 declaration فایل‌های domain route در snapshot ایستا قابل قیاس مستقیم نیست. اجرای همین check پس از merge روی main نیز در حال تکرار است.
