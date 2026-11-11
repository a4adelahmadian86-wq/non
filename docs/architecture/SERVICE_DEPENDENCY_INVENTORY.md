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

| `DashboardNavigationService` | `app/Services/DashboardNavigationService.php` | `MessengerAccessService`, `CapabilityService`, `AuthorizationService` | `Ticket`, `User` | `tickets` | — | `__construct()`, `forUser()` | 0 | 0 |
| `DeclineMessageService` | `app/Services/DeclineMessageService.php` | — | — | — | — | `next()` | 0 | 0 |
| `DurabilityService` | `app/Services/DurabilityService.php` | — | — | `farast_backup_manifests`, `farast_restore_verifications` | farast_remote | `backup()`, `verifyRestore()` | 6 | 2 |
| `EditorAiAssistService` | `app/Services/EditorAiAssistService.php` | `AiOrchestrator`, `EntitlementService`, `CommerceAuthorizationService` | `FarastProject`, `User` | — | — | `__construct()`, `assist()` | 0 | 0 |
| `EditorDocumentService` | `app/Services/EditorDocumentService.php` | `DatabaseManager` | `FarastDocument`, `TypingDocument` | — | — | `__construct()`, `save()`, `saveCanonicalModel()`, `loadForLegacy()`, `normalizePageSettings()`, `defaultPageSettings()`, `sanitizeHtml()`, `normalizeHtml()` | 0 | 0 |
| `EditorKernelToolHandler` | `app/Services/EditorKernelToolHandler.php` | `EditorDocumentService` | `FarastDocument`, `User` | — | — | `__construct()`, `handle()` | 0 | 0 |
| `EmailService` | `app/Services/EmailService.php` | `MailConfigService` | `Announcement`, `EmailLog`, `Order`, `SiteSetting`, `Ticket`, `User` | — | — | `__construct()`, `isEnabled()`, `sendOtp()`, `sendWelcome()`, `sendOrderPaid()`, `sendTicketReply()`, `sendTicketReplyGuest()`, `sendTicketCreated()`, `sendTicketCreatedStaff()`, `sendTicketCustomerReply()`, `sendTicketClosed()`, `sendTicketAssigned()`, `sendTicketSlaAlert()`, `sendSystemAnnouncement()`, `sendPasswordChanged()`, `sendTest()` | 0 | 0 |
| `EntitlementService` | `app/Services/EntitlementService.php` | `CapabilityService` | `FarastEntitlement`, `FarastProject`, `User` | — | — | `__construct()`, `authorize()`, `allows()`, `consume()`, `grant()` | 1 | 0 |
| `EvaluationService` | `app/Services/EvaluationService.php` | `AuditEventService` | — | `farast_evaluation_datasets`, `farast_evaluation_cases`, `farast_tool_versions` | — | `createDataset()`, `addCase()`, `recordResult()`, `regression()`, `promote()` | 10 | 0 |
| `FarastToolHandlerRegistry` | `app/Services/FarastToolHandlerRegistry.php` | `EditorKernelToolHandler`, `AiEditorToolHandler`, `OcrToolHandler`, `SpeechToolHandler` | — | — | — | `handler()` | 0 | 0 |
| `FeedbackPipelineService` | `app/Services/FeedbackPipelineService.php` | — | `AiFeedback`, `FarastFeedbackEvidence` | `farast_tools`, `farast_tool_versions`, `farast_review_cases`, `farast_approved_knowledge`, `farast_evaluation_datasets`, `farast_evaluation_cases` | — | `record()`, `queueReview()`, `approve()`, `reject()`, `createEvaluationCase()`, `validateToolVersion()`, `promoteToolVersion()`, `productionKnowledge()` | 16 | 0 |
| `FreeQuotaService` | `app/Services/FreeQuotaService.php` | — | `FreeCredit`, `User` | — | — | `availablePages()`, `consume()` | 1 | 0 |
| `GeminiService` | `app/Services/GeminiService.php` | — | `AiInteraction`, `SiteSetting` | — | — | `transcribe()` | 0 | 0 |
| `GoogleSpeechToTextService` | `app/Services/GoogleSpeechToTextService.php` | — | `AiInteraction` | — | — | `enabled()`, `transcribe()` | 0 | 0 |
| `HumanTaskService` | `app/Services/HumanTaskService.php` | `AuditEventService` | `User` | `farast_human_tasks` | — | `request()`, `assign()`, `submitOutput()`, `qa()`, `accept()` | 5 | 0 |
