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

| `LocalTotpService` | `app/Services/LocalTotpService.php` | — | — | — | — | `generateSecret()`, `provisioningUri()`, `verify()`, `matchingCounter()`, `codeAtCounter()`, `generateRecoveryCodes()`, `normalizeRecoveryCode()` | 0 | 0 |
| `MailConfigService` | `app/Services/MailConfigService.php` | — | `SiteSetting` | — | — | `currentProvider()`, `apply()`, `providerConfigured()`, `assertAvailable()`, `diagnostics()`, `localMtaAvailable()`, `sendmailBinaryAvailable()` | 0 | 0 |
| `MarketingSignalService` | `app/Services/MarketingSignalService.php` | `SmartDiscountMarketingService` | `User` | `farast_workflow_events`, `store_coupon_assignments`, `store_coupons` | — | `record()`, `withdraw()` | 7 | 0 |
| `MessengerAccessService` | `app/Services/MessengerAccessService.php` | — | `Conversation`, `User` | — | — | `canUse()`, `assertCanUse()`, `usersFor()`, `canInvite()`, `canCreateGroup()`, `canAddToGroup()`, `assertConversationScope()` | 0 | 0 |
| `NotificationService` | `app/Services/NotificationService.php` | — | `FarastNotification`, `User` | `farast_notifications` | — | `push()`, `pushToUser()`, `unreadCount()`, `latest()`, `paginateForUser()`, `markRead()`, `markAllRead()`, `archive()`, `restore()`, `countsByCategory()` | 0 | 0 |
| `OcrManager` | `app/Services/Ocr/OcrManager.php` | — | — | — | — | `__construct()`, `recognize()`, `available()` | 0 | 0 |
| `OcrProviderInterface` | `app/Services/Ocr/OcrProviderInterface.php` | — | — | — | — | `recognize()` | 0 | 0 |
| `OcrToolHandler` | `app/Services/OcrToolHandler.php` | `GeminiService` | `User` | — | — | `__construct()`, `handle()` | 0 | 0 |
| `OutputAuthorizationService` | `app/Services/OutputAuthorizationService.php` | `CommerceAuthorizationService` | `Order`, `FarastProject`, `TypingDocument`, `User` | — | — | `__construct()`, `authorize()` | 0 | 0 |
| `PricingEngine` | `app/Services/PricingEngine.php` | — | `FarastPricingPolicyVersion` | — | — | `resolve()`, `quote()` | 0 | 0 |
| `PricingPolicyAdminService` | `app/Services/PricingPolicyAdminService.php` | — | `FarastPricingPolicyVersion` | — | — | `createVersion()`, `schedule()`, `retire()`, `compare()`, `activate()` | 1 | 0 |
| `PricingService` | `app/Services/PricingService.php` | `PricingEngine` | `PricingRule`, `FarastPricingPolicy` | — | — | `projectQuote()`, `initialTypingPrices()`, `calculate()`, `quote()`, `estimateByPages()` | 0 | 0 |
| `ProductContentGenerationService` | `app/Services/ProductContentGenerationService.php` | `ProductKnowledgeService`, `EditorAiAssistService`, `ProductContentPrivacyFilter` | `StoreCategory`, `StoreProduct`, `User` | — | — | `__construct()`, `readiness()`, `generate()`, `currentSourceFingerprints()`, `signature()`, `assertFreshProvenance()`, `validateFieldEvidence()` | 0 | 0 |
| `ProductContentPrivacyFilter` | `app/Services/ProductContentPrivacyFilter.php` | — | — | — | — | `sanitize()`, `sanitizeText()` | 0 | 0 |
| `ProductDocumentAudioService` | `app/Services/ProductDocumentAudioService.php` | — | — | — | — | `configured()`, `generate()` | 0 | 0 |

| `ProductDocumentProcessingState` | `app/Services/ProductDocumentProcessingState.php` | — | — | — | — | `derive()` | 0 | 0 |
| `ProductDocumentRetryService` | `app/Services/ProductDocumentRetryService.php` | `AuditEventService`, `ProductSupervisorClient` | `StoreProductDocument`, `StoreProductFile` | — | private | `__construct()`, `retry()` | 1 | 1 |
| `ProductKnowledgeService` | `app/Services/ProductKnowledgeService.php` | — | `StoreProduct` | — | — | `contextForProduct()`, `contentGenerationContext()` | 0 | 0 |
| `ProductLifecycleHealthService` | `app/Services/ProductLifecycleHealthService.php` | `ProductPublicationReadiness` | `StoreProduct`, `StoreProductDocument`, `StoreProductIngestion` | `store_products`, `store_product_ingestions`, `store_product_documents` | — | `__construct()`, `snapshot()` | 1 | 0 |
| `ProductPublicationReadiness` | `app/Services/ProductPublicationReadiness.php` | — | `StoreProduct` | — | — | `hasReadyPrimaryReader()` | 0 | 0 |
| `ProductRelatedProductsService` | `app/Services/ProductRelatedProductsService.php` | — | `StoreProduct` | — | — | `recommend()` | 0 | 0 |
| `ProductSupervisorClient` | `app/Services/ProductSupervisorClient.php` | — | — | — | — | `configured()`, `review()`, `reviewFile()` | 0 | 0 |
| `ProductSupervisorSnapshotBuilder` | `app/Services/ProductSupervisorSnapshotBuilder.php` | — | `StoreCourseLesson`, `StoreProduct` | — | — | `build()`, `fingerprint()` | 0 | 0 |
| `ProfessionalCommerceService` | `app/Services/ProfessionalCommerceService.php` | `CommerceAuthorizationService`, `SubscriptionEntitlementService` | `FarastCommercialPlan`, `FarastCommercialProduct`, `FarastEntitlement`, `FarastPriceQuote`, `FarastSubscription`, `FarastUsageCounter`, `User` | — | — | `__construct()`, `quote()`, `createProduct()`, `createPlan()`, `subscribe()`, `grantTemporaryPurchase()`, `recordUsageCounter()` | 1 | 0 |
| `ProjectBillingService` | `app/Services/ProjectBillingService.php` | `PricingService` | `FarastProject`, `TypingDocument`, `Order`, `User` | `farast_subscriptions`, `farast_usage_counters` | — | `__construct()`, `quote()`, `entitlement()`, `includedAllowance()`, `applyQuote()`, `markPaid()` | 2 | 0 |
| `ProjectInterviewService` | `app/Services/ProjectInterviewService.php` | `ApplicationRegistry` | — | — | — | `__construct()`, `nextQuestion()`, `suggestTemplate()`, `buildContext()` | 0 | 0 |
| `PromotionService` | `app/Services/PromotionService.php` | — | `FarastCoupon`, `FarastCouponRedemption`, `FarastPromotion`, `User` | — | — | `trustedAdjustments()` | 0 | 0 |
| `ProviderConnectionHealth` | `app/Services/ProviderConnectionHealth.php` | — | `SiteSetting` | — | — | `recordSuccess()`, `recordFailure()`, `state()` | 0 | 0 |
| `ProviderRegistry` | `app/Services/ProviderRegistry.php` | `AiProviderRegistry`, `VoiceProviderRouter`, `ProviderConnectionHealth` | `SiteSetting`, `VoiceProviderAccount` | — | — | `__construct()`, `catalog()`, `forCapability()` | 0 | 0 |

| `SmartDiscountMarketingService` | `app/Services/SmartDiscountMarketingService.php` | `StoreCouponMarginPolicy`, `StoreCouponBudgetPolicy`, `NotificationService` | `Order`, `StoreCoupon`, `SiteSetting`, `StoreCouponAssignment`, `StoreProduct`, `User` | `farast_workflow_events` | — | `evaluate()`, `effectiveCampaignDiscountPercent()` | 2 | 0 |
| `SmsService` | `app/Services/SmsService.php` | — | — | — | — | `sendOtp()`, `sendText()`, `sendLookup()`, `sendEvent()` | 0 | 0 |
| `SpeechToolHandler` | `app/Services/SpeechToolHandler.php` | `VoiceTranscriptionService` | `User` | — | — | `__construct()`, `handle()` | 0 | 0 |
| `S3CompatibleAdapter` | `app/Services/Storage/S3CompatibleAdapter.php` | — | `StorageVolume` | — | — | `putFile()`, `putContents()`, `getContents()`, `downloadTo()`, `size()`, `streamRange()`, `exists()`, `delete()`, `inventory()`, `probe()` | 0 | 0 |
| `StorageBackupService` | `app/Services/Storage/StorageBackupService.php` | `StorageManager` | `StorageBackupRun`, `StorageManagedFile`, `StorageVolume` | — | — | `__construct()`, `runWeekly()` | 0 | 0 |
| `StorageManager` | `app/Services/Storage/StorageManager.php` | `S3CompatibleAdapter` | `StorageManagedFile`, `StorageBackupRun`, `StorageVolume` | `storage_volumes` | private | `__construct()`, `storeUploadedFile()`, `adoptExistingPrivate()`, `bindReference()`, `exists()`, `get()`, `delete()`, `cleanupDeferredDeletes()`, `downloadTo()`, `size()`, `streamRange()`, `uploadBackup()`, `volumeDisk()` | 3 | 8 |
| `StorageManagerProvider` | `app/Services/Storage/StorageManagerProvider.php` | — | — | — | — | `register()` | 0 | 0 |
| `StoragePath` | `app/Services/Storage/StoragePath.php` | — | `StorageVolume` | — | — | — | 0 | 0 |
| `StorageTransferService` | `app/Services/Storage/StorageTransferService.php` | `S3CompatibleAdapter` | `StorageManagedFile`, `FarastStorageObject`, `StorageBackupRun`, `StorageVolume`, `UserFile`, `StoreProductFile`, `ConversationMessage`, `TicketMessage` | `storage_volumes` | — | `__construct()`, `syncSourceReference()`, `rebindToStaging()`, `transferPending()` | 6 | 1 |
| `StorageConnectionService` | `app/Services/StorageConnectionService.php` | `S3CompatibleAdapter` | `StorageVolume` | — | — | `__construct()`, `verify()` | 0 | 0 |
| `StoreCartAbandonmentService` | `app/Services/StoreCartAbandonmentService.php` | `MarketingSignalService` | `Order`, `StoreCartItem`, `SiteSetting` | — | — | `process()` | 0 | 0 |
| `StoreCartService` | `app/Services/StoreCartService.php` | `StoreCouponMarginPolicy` | `StoreCart`, `StoreCoupon`, `StoreCouponAssignment`, `Order`, `StoreProduct` | — | — | `current()`, `add()`, `update()`, `remove()`, `applyCoupon()`, `clearCoupon()`, `payload()`, `isPurchasable()` | 1 | 0 |
| `StoreCouponBudgetPolicy` | `app/Services/StoreCouponBudgetPolicy.php` | — | `Order`, `SiteSetting` | `store_coupon_assignments as assignments` | — | `allows()`, `reservedAmount()` | 1 | 0 |
| `StoreCouponMarginPolicy` | `app/Services/StoreCouponMarginPolicy.php` | — | `StoreProduct` | — | — | `minimumMargin()`, `maximumDiscount()` | 0 | 0 |
| `StoreCouponRedemptionService` | `app/Services/StoreCouponRedemptionService.php` | `StoreCouponMarginPolicy`, `StoreCouponBudgetPolicy` | `Order`, `StoreCoupon`, `StoreCouponAssignment`, `StoreProduct`, `User` | `crm_marketing_campaigns`, `store_coupon_assignments as assignments` | — | `redeemForPaidOrder()` | 2 | 0 |

| `StoreCourseEnrollmentService` | `app/Services/StoreCourseEnrollmentService.php` | — | `StoreCourseEnrollment`, `StoreLibraryItem`, `StoreProduct` | — | — | `grant()` | 0 | 0 |
| `StorePdfPreviewPreparer` | `app/Services/StorePdfPreviewPreparer.php` | — | — | — | — | `prepareSample()` | 0 | 0 |
| `StoreProductDocumentPageBuilder` | `app/Services/StoreProductDocumentPageBuilder.php` | — | — | — | — | `build()` | 0 | 0 |
| `StoreXlsxPreviewRenderer` | `app/Services/StoreXlsxPreviewRenderer.php` | — | — | — | — | `render()` | 0 | 0 |
| `SubscriptionEntitlementService` | `app/Services/SubscriptionEntitlementService.php` | — | `FarastCommercialPlan`, `FarastEntitlement`, `User` | — | — | `sync()` | 0 | 0 |
| `SupportNotificationService` | `app/Services/SupportNotificationService.php` | `EmailService`, `SmsService`, `NotificationService` | `SiteSetting`, `Ticket`, `User` | — | — | `__construct()`, `ticketCreated()`, `customerReplied()`, `staffReplied()`, `statusChanged()`, `assigned()`, `slaWarning()`, `slaBreached()` | 0 | 0 |
| `SupportSlaService` | `app/Services/SupportSlaService.php` | — | `Ticket` | `tickets` | — | `applyTargets()`, `addSlaMinutes()`, `addBusinessMinutes()`, `firstResponseStatus()`, `resolutionStatus()`, `markResolved()`, `agentReport()` | 0 | 0 |
| `SupportTicketService` | `app/Services/SupportTicketService.php` | `WorkflowEngine`, `UploadSecurityService`, `StorageManager`, `SupportNotificationService`, `SupportSlaService` | `Ticket`, `TicketMessage`, `User` | `tickets`, `ticket_messages` | private | `__construct()`, `generateCode()`, `createTicket()`, `pickLeastLoadedAgent()`, `addMessage()`, `changeStatus()`, `assign()`, `updateMeta()`, `submitCsat()`, `markCustomerSeen()`, `claimGuestTickets()`, `findOpenGuestTicket()`, `waitingMinutes()`, `needsAgentReply()` | 1 | 2 |
| `TemplateRegistry` | `app/Services/TemplateRegistry.php` | — | — | `farast_templates` | — | `all()`, `get()` | 1 | 0 |
| `ToolExecutionService` | `app/Services/ToolExecutionService.php` | `ToolRegistry`, `ToolSchemaValidator`, `CommerceAuthorizationService`, `AuditEventService`, `HumanTaskService`, `FarastToolHandlerRegistry` | `User` | `farast_tool_executions` | — | `__construct()`, `execute()` | 4 | 0 |
| `ToolRegistry` | `app/Services/ToolRegistry.php` | — | `FarastApplication`, `FarastCapability`, `FarastTool` | — | — | `application()`, `capability()`, `tool()`, `definition()`, `toolsForApplication()`, `isAvailable()` | 0 | 0 |
| `ToolSchemaValidator` | `app/Services/ToolSchemaValidator.php` | — | — | — | — | `validate()` | 0 | 0 |
| `UploadPreflightService` | `app/Services/UploadPreflightService.php` | — | — | — | private | `estimatePages()`, `depositPercent()`, `fileHash()` | 0 | 5 |
| `UploadSecurityService` | `app/Services/UploadSecurityService.php` | — | — | — | private | `inspect()` | 0 | 1 |
| `UserFileRetentionService` | `app/Services/UserFileRetentionService.php` | `StorageManager` | `UserFile` | — | private | `__construct()`, `archiveExpiredLocalFiles()`, `deleteExpiredRemoteFiles()` | 0 | 1 |
