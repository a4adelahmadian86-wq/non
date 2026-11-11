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

| `AgentValidationService` | `app/Services/Agent/AgentValidationService.php` | — | — | — | — | `validatePlan()`, `validateResult()` | 0 | 0 |
| `FarastEditorAgent` | `app/Services/Agent/FarastEditorAgent.php` | `AuditEventService`, `AuthorizationService`, `CommerceAuthorizationService`, `EditorAiAssistService`, `ToolExecutionService`, `EditorDocumentService`, `AgentContextBuilder`, `AgentIntentInterpreter`, `AgentToolSelector`, `AgentValidationService` | `FarastAgentTask`, `FarastDocument`, `TypingDocument`, `User` | — | — | `__construct()`, `plan()`, `approve()`, `commit()`, `undo()` | 2 | 0 |
| `AiEditorToolHandler` | `app/Services/AiEditorToolHandler.php` | `EditorAiAssistService` | `User` | — | — | `__construct()`, `handle()` | 0 | 0 |
| `AnnouncementService` | `app/Services/AnnouncementService.php` | `NotificationService`, `EmailService` | `Announcement`, `User` | `farast_notifications`, `announcements` | — | `__construct()`, `create()`, `update()`, `toggle()`, `broadcast()` | 0 | 0 |
| `ApplicationRegistry` | `app/Services/ApplicationRegistry.php` | — | `FarastApplication` | — | — | `definition()`, `classify()`, `canStart()`, `applicationFor()` | 0 | 0 |
| `AuditEventService` | `app/Services/AuditEventService.php` | — | `FarastAuditEvent` | — | — | `record()` | 1 | 0 |
| `AuditIntegrityService` | `app/Services/AuditIntegrityService.php` | — | `FarastAuditEvent` | — | — | `verify()` | 0 | 0 |
| `AuditLogService` | `app/Services/AuditLogService.php` | — | — | `audit_logs` | — | `record()` | 1 | 0 |
| `AuthorizationService` | `app/Services/AuthorizationService.php` | — | `Permission`, `RolePermission`, `User` | — | — | `allows()`, `syncDefaults()` | 0 | 0 |
| `CanvaService` | `app/Services/CanvaService.php` | — | `CanvaConnection`, `SiteSetting`, `User` | — | — | `configured()`, `authorizeUrl()`, `callback()`, `token()`, `request()`, `designs()`, `design()`, `createDoc()`, `export()`, `import()`, `importStatus()`, `exportStatus()` | 1 | 0 |
| `CapabilityService` | `app/Services/CapabilityService.php` | — | `SiteSetting`, `User`, `UserCapability` | — | — | `forUser()`, `allowed()` | 0 | 0 |
| `CommerceAuthorizationService` | `app/Services/CommerceAuthorizationService.php` | `PricingEngine`, `CapabilityService`, `PromotionService` | `FarastCharge`, `FarastCouponRedemption`, `FarastEntitlement`, `FarastInvoice`, `FarastInvoiceItem`, `FarastRefund`, `FarastUsageEvent`, `FarastUsageReservation`, `FarastWalletReservation`, `User`, `Wallet`, `WalletTransaction` | — | — | `__construct()`, `authorize()`, `reserve()`, `commit()`, `release()`, `settlePostpaid()`, `refund()` | 5 | 0 |
| `CommunicationSearchService` | `app/Services/CommunicationSearchService.php` | — | `Conversation`, `ConversationMessage`, `FarastNotification`, `Ticket`, `TicketMessage`, `User` | — | — | `search()` | 0 | 0 |
| `CostEventService` | `app/Services/CostEventService.php` | — | `FarastCostEvent` | — | — | `record()` | 0 | 0 |
| `CrmAudienceService` | `app/Services/CrmAudienceService.php` | — | `User` | — | — | `query()` | 0 | 0 |
