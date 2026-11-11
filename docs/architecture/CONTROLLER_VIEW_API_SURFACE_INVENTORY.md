# فهرست سطح صفحات و پاسخ‌های Controllerها

> مبنا: ۵۱ فایل PHP زیر `app/Http/Controllers` در `main` (شامل کلاس پایه و Trait). Viewها و helperهای پاسخ از کد Controller با استخراج ایستا خوانده شده‌اند. شمارش JSON فقط `response()->json()` مستقیم را می‌شمارد؛ stream/downloadهایی که از StorageManager یا Service برمی‌گردند ممکن است در شمارش مستقیم دیده نشوند. برای Route و middleware دقیق، به `ROUTE_OWNERSHIP_INVENTORY.md` رجوع کنید.

| Controller / Trait | Viewهای مستقیم | JSON مستقیم | Stream مستقیم | Download مستقیم | Redirect route | Back | Source |
|---|---|---:|---:|---:|---:|---:|---|
| `AccountSecurityController` | `platform.security` | 0 | 0 | 0 | 2 | 6 | `app/Http/Controllers/AccountSecurityController.php` |
| `AccountTwoFactorController` | `auth.two-factor-challenge` | 0 | 0 | 0 | 0 | 4 | `app/Http/Controllers/AccountTwoFactorController.php` |
| `AdminController` | `admin.index`, `admin.users`, `admin.support-sms`, `admin.finance`, `admin.emails` | 0 | 0 | 0 | 0 | 12 | `app/Http/Controllers/AdminController.php` |
| `AdminCourseController` | `admin.store.course` | 0 | 0 | 0 | 0 | 9 | `app/Http/Controllers/AdminCourseController.php` |
| `AdminPlatformController` | `admin.integrations`, `admin.access`, `admin.organizations`, `admin.analytics`, `admin.audit` | 0 | 0 | 0 | 0 | 3 | `app/Http/Controllers/AdminPlatformController.php` |
| `AdminProductContentController` | — | 2 | 0 | 0 | 0 | 0 | `app/Http/Controllers/AdminProductContentController.php` |
| `AdminProductOperationsController` | `admin.store.operations` | 0 | 0 | 0 | 0 | 1 | `app/Http/Controllers/AdminProductOperationsController.php` |
| `AdminProductSupervisorController` | — | 0 | 0 | 0 | 0 | 6 | `app/Http/Controllers/AdminProductSupervisorController.php` |
| `AdminStorageController` | `admin.storage.index`, `admin.storage.backups`, `admin.storage.volume` | 0 | 0 | 1 | 3 | 7 | `app/Http/Controllers/AdminStorageController.php` |
| `AdminStoreController` | `admin.store.index`, `admin.store.form` | 9 | 0 | 0 | 4 | 10 | `app/Http/Controllers/AdminStoreController.php` |
| `AdminStoreCouponController` | `admin.store-coupons.index` | 0 | 0 | 0 | 4 | 2 | `app/Http/Controllers/AdminStoreCouponController.php` |
| `AnnouncementController` | `announcements`, `admin.announcements` | 0 | 0 | 0 | 0 | 7 | `app/Http/Controllers/AnnouncementController.php` |
| `AuthController` | `auth.login`, `auth.register`, `auth.password`, `auth.email-login`, `auth.email-mobile`, `auth.forgot-password`, `auth.reset-password` | 6 | 0 | 0 | 8 | 0 | `app/Http/Controllers/AuthController.php` |
| `BlogController` | `blog.index`, `blog.show` | 0 | 0 | 0 | 0 | 0 | `app/Http/Controllers/BlogController.php` |
| `CanvaController` | — | 12 | 0 | 0 | 2 | 2 | `app/Http/Controllers/CanvaController.php` |
| `CommunicationCenterController` | `admin.communications.index` | 0 | 0 | 0 | 0 | 0 | `app/Http/Controllers/CommunicationCenterController.php` |
| `PaysWithZarinpal` (Trait) | — | 0 | 0 | 0 | 9 | 4 | `app/Http/Controllers/Concerns/PaysWithZarinpal.php` |
| `ContentDiscussionController` | `community.mine`, `community.index`, `community.member`, `admin.discussion-reports.index`, `admin.discussions.index` | 0 | 0 | 0 | 0 | 5 | `app/Http/Controllers/ContentDiscussionController.php` |
| `Controller` (base) | — | 0 | 0 | 0 | 0 | 0 | `app/Http/Controllers/Controller.php` |
| `CrmAnalyticsController` | — | 1 | 0 | 0 | 0 | 0 | `app/Http/Controllers/CrmAnalyticsController.php` |
| `CrmController` | — | 6 | 0 | 0 | 0 | 0 | `app/Http/Controllers/CrmController.php` |
| `CrmMarketingController` | `admin.crm.marketing` | 7 | 0 | 0 | 0 | 0 | `app/Http/Controllers/CrmMarketingController.php` |
| `CrmProjectController` | `admin.crm.projects.index`, `admin.crm.projects.show` | 3 | 0 | 0 | 0 | 0 | `app/Http/Controllers/CrmProjectController.php` |
| `EditorAgentController` | — | 8 | 0 | 0 | 0 | 0 | `app/Http/Controllers/EditorAgentController.php` |
| `EditorAiAssistController` | — | 5 | 0 | 0 | 0 | 0 | `app/Http/Controllers/EditorAiAssistController.php` |
| `EditorController` | `pricing`, `dashboard`, `editor` | 11 | 0 | 0 | 0 | 0 | `app/Http/Controllers/EditorController.php` |
| `EditorDocumentStateController` | — | 4 | 0 | 0 | 0 | 0 | `app/Http/Controllers/EditorDocumentStateController.php` |
| `EditorSaveController` | — | 2 | 0 | 0 | 0 | 0 | `app/Http/Controllers/EditorSaveController.php` |
| `ExportController` | — | 0 | 0 | 1 | 0 | 0 | `app/Http/Controllers/ExportController.php` |
| `GlobalAssistantController` | — | 32 | 1 | 0 | 0 | 0 | `app/Http/Controllers/GlobalAssistantController.php` |
| `ManagedStoragePublicController` | — | 0 | 0 | 0 | 0 | 0 | `app/Http/Controllers/ManagedStoragePublicController.php` |
| `MarketingPreferenceController` | `account.marketing-preferences` | 0 | 0 | 0 | 1 | 0 | `app/Http/Controllers/MarketingPreferenceController.php` |
| `MessengerController` | `messenger.index`, `messenger.show` | 4 | 0 | 0 | 3 | 2 | `app/Http/Controllers/MessengerController.php` |
| `NotificationController` | `platform.notifications` | 4 | 0 | 0 | 2 | 4 | `app/Http/Controllers/NotificationController.php` |
| `PaymentController` | `checkout` | 0 | 0 | 0 | 4 | 3 | `app/Http/Controllers/PaymentController.php` |
| `PlatformCompletionController` | `platform.documents`, `platform.shared-document`, `platform.workflows`, `platform.security`, `admin.cms`, `platform.billing`, `admin.plans`, `admin.support` | 7 | 0 | 0 | 1 | 14 | `app/Http/Controllers/PlatformCompletionController.php` |
| `ProjectController` | `projects.create` | 1 | 0 | 0 | 0 | 0 | `app/Http/Controllers/ProjectController.php` |
| `ProviderAdminController` | — | 11 | 0 | 0 | 0 | 2 | `app/Http/Controllers/ProviderAdminController.php` |
| `SocialController` | `social-links`, `admin.social` | 0 | 0 | 0 | 0 | 1 | `app/Http/Controllers/SocialController.php` |
| `StoreCartController` | `store.cart` | 7 | 0 | 0 | 2 | 3 | `app/Http/Controllers/StoreCartController.php` |
| `StoreController` | `store.index`, `store.product`, `store.course-preview`, `store.reader` | 2 | 3 | 0 | 1 | 0 | `app/Http/Controllers/StoreController.php` |
| `StoreCourseLearningController` | `store.course-learning`, `store.course-lesson` | 1 | 1 | 0 | 0 | 0 | `app/Http/Controllers/StoreCourseLearningController.php` |
| `StoreLibraryController` | `store.library` | 1 | 0 | 0 | 0 | 0 | `app/Http/Controllers/StoreLibraryController.php` |
| `StoreReaderStateController` | — | 2 | 0 | 0 | 0 | 0 | `app/Http/Controllers/StoreReaderStateController.php` |
| `StoreWishlistController` | `store.wishlist` | 1 | 0 | 0 | 0 | 2 | `app/Http/Controllers/StoreWishlistController.php` |
| `SupportController` | `support.index`, `support.create`, `support.show`, `admin.support` | 7 | 0 | 1 | 2 | 6 | `app/Http/Controllers/SupportController.php` |
| `SupportReportController` | `admin.support-report` | 0 | 0 | 0 | 0 | 0 | `app/Http/Controllers/SupportReportController.php` |
| `TypingPreflightController` | — | 5 | 0 | 0 | 0 | 0 | `app/Http/Controllers/TypingPreflightController.php` |
| `UserFileController` | — | 4 | 0 | 0 | 0 | 0 | `app/Http/Controllers/UserFileController.php` |
| `VoiceController` | — | 29 | 0 | 0 | 0 | 0 | `app/Http/Controllers/VoiceController.php` |
| `WalletController` | `wallet` | 0 | 0 | 0 | 10 | 2 | `app/Http/Controllers/WalletController.php` |

## محدودیت و نحوه استفاده

- Viewهای بالا نام templateهایی هستند که مستقیماً در Controller فراخوانی شده‌اند؛ viewهای nested، componentها، Blade includes و viewهای ساخته‌شده از helper/Service در این جدول نیستند.
- JSON/stream/download شمارش source-level helperهاست؛ پاسخ‌های برگشتی از Serviceها، `StorageManager`، `StreamedResponse` و Symfony response ممکن است شمارش مستقیم نداشته باشند.
- API clientهای JS و routeهای مصرف‌شده در Blade باید از Route inventory و جست‌وجوی route name/URL در frontend تطبیق داده شوند.
- نبود view به معنی نبود صفحه نیست؛ Controller ممکن است JSON/API-only یا redirect-only باشد.
- این فهرست را کنار `ROUTE_OWNERSHIP_INVENTORY.md` و `CONTROLLER_CALLSITE_INVENTORY.md` بخوانید تا زنجیره route → action → view/response → service/data قابل ردیابی باشد.
