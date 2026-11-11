# فهرست وابستگی سرویس‌های برنامه

> مبنا: ۱۱۸ فایل `app/Services/*.php` در `main`. وابستگی‌ها از import، constructor injection و `app()/resolve()` استخراج شده‌اند؛ این یک dependency inventory ایستا است و جایگزین call graph runtime نیست. جدول‌ها فقط `DB::table` و `Schema::table/hasTable/hasColumn`های literal را نشان می‌دهند؛ queryهای Eloquent و نام‌های dynamic در آن نیستند.

| Service | Source | Service dependencies | Model imports | Direct table references | Storage disk names | Public methods | DB call count | Storage call count |
|---|---|---|---|---|---|---|---:|---:|
| `AiContextEngine` | `app/Services/AI/AiContextEngine.php` | — | — | — | — | `build()`, `buildLayered()` | 0 | 0 |
| `AiOperationRegistry` | `app/Services/AI/AiOperationRegistry.php` | — | — | — | — | `resolve()`, `publicDefinitions()` | 0 | 0 |
| `AiOrchestrator` | `app/Services/AI/AiOrchestrator.php` | `AiOperationRegistry`, `AiContextEngine`, `AiPrivacyPolicy`, `AiQuotaService`, `AiProviderRegistry` | `AiInteraction`, `User` | — | — | `__construct()`, `run()` | 0 | 0 |
| `AiPrivacyPolicy` | `app/Services/AI/AiPrivacyPolicy.php` | — | — | — | — | `resolve()` | 0 | 0 |
| `AiProviderInterface` | `app/Services/AI/AiProviderInterface.php` | — | — | — | — | `name()`, `model()`, `capabilities()`, `supports()`, `execute()` | 0 | 0 |
| `AiProviderRegistry` | `app/Services/AI/AiProviderRegistry.php` | `GeminiEditorProvider` | — | — | — | `__construct()`, `register()`, `get()`, `forOperation()`, `capabilities()` | 0 | 0 |
| `AiProviderResult` | `app/Services/AI/AiProviderResult.php` | — | — | — | — | `__construct()` | 0 | 0 |
| `AiQuotaService` | `app/Services/AI/AiQuotaService.php` | `CapabilityService` | `AiInteraction`, `User` | — | — | `__construct()`, `assertAllowed()` | 0 | 0 |
| `AiProvider` | `app/Services/AI/Contracts/AiProvider.php` | — | — | — | — | `name()`, `model()`, `supports()`, `execute()` | 0 | 0 |
| `GeminiEditorProvider` | `app/Services/AI/Providers/GeminiEditorProvider.php` | — | `SiteSetting` | — | — | `name()`, `model()`, `supports()`, `execute()` | 0 | 0 |
| `GeminiProviderAdapter` | `app/Services/AI/Providers/GeminiProviderAdapter.php` | `GeminiEditorProvider` | — | — | — | `__construct()`, `name()`, `model()`, `capabilities()`, `supports()`, `execute()` | 0 | 0 |
| `AdminConsoleService` | `app/Services/AdminConsoleService.php` | `AuthorizationService` | `Team`, `User` | `users` | — | `__construct()`, `sectionAllowed()`, `allowedSections()`, `visibleUserIds()`, `visibleTeamIds()`, `visibleOrganizationIds()`, `scopedQuery()`, `authorizeAction()`, `scopeSummary()` | 0 | 0 |
| `AgentContextBuilder` | `app/Services/Agent/AgentContextBuilder.php` | — | `FarastDocument`, `FarastProject`, `TypingDocument`, `User` | — | — | `build()` | 0 | 0 |
| `AgentIntentInterpreter` | `app/Services/Agent/AgentIntentInterpreter.php` | — | — | — | — | `interpret()` | 0 | 0 |
| `AgentToolSelector` | `app/Services/Agent/AgentToolSelector.php` | `ToolRegistry` | — | — | — | `__construct()`, `select()` | 0 | 0 |
