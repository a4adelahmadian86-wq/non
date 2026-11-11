# فهرست مدل‌ها و روابط Eloquent

> مبنا: شاخه `main`، فایل‌های `app/Models/*.php`. جدول صریح فقط در صورت وجود `$table` ثبت شده؛ در غیر این صورت نام جدول از قرارداد Eloquent در runtime مشتق می‌شود و در این فهرست به‌عنوان حدس قطعی ثبت نشده است. روابطی که با الگوی استاتیک قابل استخراج نبوده‌اند باید با `Model::getRelations()`/تست runtime تکمیل شوند.

- مدل‌های فایل‌محور شناسایی‌شده: **104**
- این فهرست مالکیت فایل مدل، table override، primary key، connection و روابط مستقیم قابل استخراج را ثبت می‌کند.

| Model | Explicit table | Table basis | Primary key | Connection | Relations | Source |
|---|---|---|---|---|---|
| `AiFeedback` | `ai_feedback` | explicit | `id` | `default` | `user:belongsTo(User)`<br>`document:belongsTo(TypingDocument; 'document_id')`<br>`interaction:belongsTo(AiInteraction; 'ai_interaction_id')` | `app/Models/AiFeedback.php` |
| `AiInteraction` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`document:belongsTo(TypingDocument; 'document_id')` | `app/Models/AiInteraction.php` |
| `Announcement` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `creator:belongsTo(User; 'created_by')` | `app/Models/Announcement.php` |
| `CanvaConnection` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/CanvaConnection.php` |
| `ContentDiscussion` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `moderationEvents:hasMany(DiscussionModerationEvent; 'discussion_id')->latest()`<br>`reports:hasMany(DiscussionReport; 'discussion_id')`<br>`reactions:hasMany(DiscussionReaction; 'discussion_id')`<br>`user:belongsTo(User)`<br>`moderator:belongsTo(User; 'moderator_id')`<br>`parent:belongsTo(self; 'parent_id')`<br>`replies:hasMany(self; 'parent_id')`<br>`subscriptions:hasMany(DiscussionSubscription; 'discussion_id')` | `app/Models/ContentDiscussion.php` |
| `Conversation` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `creator:belongsTo(User; 'created_by')`<br>`participants:belongsToMany(User; 'conversation_participants') ->withPivot(['role', 'last_read_message_i)`<br>`messages:hasMany(ConversationMessage)` | `app/Models/Conversation.php` |
| `ConversationMessage` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `conversation:belongsTo(Conversation)`<br>`user:belongsTo(User)`<br>`replyTo:belongsTo(self; 'reply_to_id')` | `app/Models/ConversationMessage.php` |
| `DiscussionModerationEvent` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `discussion:belongsTo(ContentDiscussion; 'discussion_id')`<br>`moderator:belongsTo(User; 'moderator_id')` | `app/Models/DiscussionModerationEvent.php` |
| `DiscussionReaction` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `discussion:belongsTo(ContentDiscussion; 'discussion_id')`<br>`user:belongsTo(User)` | `app/Models/DiscussionReaction.php` |
| `DiscussionReport` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `discussion:belongsTo(ContentDiscussion; 'discussion_id')`<br>`reporter:belongsTo(User; 'reporter_id')`<br>`reviewer:belongsTo(User; 'reviewed_by')` | `app/Models/DiscussionReport.php` |
| `DiscussionSubscription` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `discussion:belongsTo(ContentDiscussion; 'discussion_id')`<br>`user:belongsTo(User; 'user_id')` | `app/Models/DiscussionSubscription.php` |
| `EditorSession` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/EditorSession.php` |
| `EmailLog` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)` | `app/Models/EmailLog.php` |
| `EmailOtpChallenge` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/EmailOtpChallenge.php` |
| `FarastAgentTask` | `farast_agent_tasks` | explicit | `id` | `default` | `user:belongsTo(User)`<br>`project:belongsTo(FarastProject; 'project_id')`<br>`document:belongsTo(FarastDocument; 'document_id')` | `app/Models/FarastAgentTask.php` |

| `FarastApplication` | `farast_applications` | explicit | `id` | `default` | `tools:belongsToMany(FarastTool; 'farast_tool_applications','application_id','tool_id')` | `app/Models/FarastApplication.php` |
| `FarastApprovedKnowledge` | `farast_approved_knowledge` | explicit | `id` | `default` | — | `app/Models/FarastApprovedKnowledge.php` |
| `FarastAuditEvent` | `farast_audit_events` | explicit | `id` | `default` | — | `app/Models/FarastAuditEvent.php` |
| `FarastCapability` | `farast_capabilities` | explicit | `id` | `default` | — | `app/Models/FarastCapability.php` |
| `FarastCharge` | `farast_charges` | explicit | `id` | `default` | — | `app/Models/FarastCharge.php` |
| `FarastCommercialPlan` | `farast_commercial_plans` | explicit | `id` | `default` | — | `app/Models/FarastCommercialPlan.php` |
| `FarastCommercialProduct` | `farast_commercial_products` | explicit | `id` | `default` | — | `app/Models/FarastCommercialProduct.php` |
| `FarastCostEvent` | `farast_cost_events` | explicit | `id` | `default` | — | `app/Models/FarastCostEvent.php` |
| `FarastCoupon` | `farast_coupons` | explicit | `id` | `default` | — | `app/Models/FarastCoupon.php` |
| `FarastCouponRedemption` | `farast_coupon_redemptions` | explicit | `id` | `default` | — | `app/Models/FarastCouponRedemption.php` |
| `FarastDocument` | `farast_documents` | explicit | `id` | `default` | `user:belongsTo(User)`<br>`project:belongsTo(FarastProject)`<br>`typingDocument:hasOne(TypingDocument; 'farast_document_id', 'id')`<br>`versions:hasMany(FarastDocumentVersion; 'document_id')` | `app/Models/FarastDocument.php` |
| `FarastDocumentVersion` | `farast_document_versions` | explicit | `id` | `default` | `document:belongsTo(FarastDocument; 'document_id')`<br>`user:belongsTo(User)` | `app/Models/FarastDocumentVersion.php` |
| `FarastEntitlement` | `farast_entitlements` | explicit | `id` | `default` | — | `app/Models/FarastEntitlement.php` |
| `FarastEvaluationCase` | `farast_evaluation_cases` | explicit | `id` | `default` | — | `app/Models/FarastEvaluationCase.php` |
| `FarastEvaluationDataset` | `farast_evaluation_datasets` | explicit | `id` | `default` | — | `app/Models/FarastEvaluationDataset.php` |

| `FarastFeedbackEvidence` | `farast_feedback_evidence` | explicit | `id` | `default` | — | `app/Models/FarastFeedbackEvidence.php` |
| `FarastHumanTask` | `farast_human_tasks` | explicit | `id` | `default` | — | `app/Models/FarastHumanTask.php` |
| `FarastInvoice` | `farast_invoices` | explicit | `id` | `default` | `items:hasMany(FarastInvoiceItem; 'invoice_id')` | `app/Models/FarastInvoice.php` |
| `FarastInvoiceItem` | `farast_invoice_items` | explicit | `id` | `default` | — | `app/Models/FarastInvoiceItem.php` |
| `FarastNotification` | `farast_notifications` | explicit | `id` | `default` | `user:belongsTo(User)` | `app/Models/FarastNotification.php` |
| `FarastPriceQuote` | `farast_price_quotes` | explicit | `id` | `default` | — | `app/Models/FarastPriceQuote.php` |
| `FarastPricingPolicy` | `farast_pricing_policies` | explicit | `id` | `default` | — | `app/Models/FarastPricingPolicy.php` |
| `FarastPricingPolicyVersion` | `farast_pricing_policy_versions` | explicit | `id` | `default` | — | `app/Models/FarastPricingPolicyVersion.php` |
| `FarastProject` | `farast_projects` | explicit | `id` | `default` | `user:belongsTo(User)`<br>`documents:hasMany(FarastDocument; 'project_id')`<br>`typingDocuments:hasMany(TypingDocument; 'project_id')` | `app/Models/FarastProject.php` |
| `FarastPromotion` | `farast_promotions` | explicit | `id` | `default` | — | `app/Models/FarastPromotion.php` |
| `FarastProvider` | `farast_providers` | explicit | `id` | `default` | — | `app/Models/FarastProvider.php` |
| `FarastRefund` | `farast_refunds` | explicit | `id` | `default` | — | `app/Models/FarastRefund.php` |
| `FarastReviewCase` | `farast_review_cases` | explicit | `id` | `default` | — | `app/Models/FarastReviewCase.php` |
| `FarastStorageObject` | `farast_storage_objects` | explicit | `id` | `default` | — | `app/Models/FarastStorageObject.php` |
| `FarastSubscription` | `farast_subscriptions` | explicit | `id` | `default` | — | `app/Models/FarastSubscription.php` |

| `FarastTool` | `farast_tools` | explicit | `id` | `default` | `capability:belongsTo(FarastCapability; 'capability_id')`<br>`provider:belongsTo(FarastProvider; 'provider_id')`<br>`versions:hasMany(FarastToolVersion; 'tool_id')`<br>`applications:belongsToMany(FarastApplication; 'farast_tool_applications','tool_id','application_id')` | `app/Models/FarastTool.php` |
| `FarastToolExecution` | `farast_tool_executions` | explicit | `id` | `default` | — | `app/Models/FarastToolExecution.php` |
| `FarastToolVersion` | `farast_tool_versions` | explicit | `id` | `default` | `tool:belongsTo(FarastTool; 'tool_id')` | `app/Models/FarastToolVersion.php` |
| `FarastUsageCounter` | `farast_usage_counters` | explicit | `id` | `default` | — | `app/Models/FarastUsageCounter.php` |
| `FarastUsageEvent` | `farast_usage_events` | explicit | `id` | `default` | — | `app/Models/FarastUsageEvent.php` |
| `FarastUsageReservation` | `farast_usage_reservations` | explicit | `id` | `default` | — | `app/Models/FarastUsageReservation.php` |
| `FarastWalletReservation` | `farast_wallet_reservations` | explicit | `id` | `default` | — | `app/Models/FarastWalletReservation.php` |
| `FreeCredit` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)` | `app/Models/FreeCredit.php` |
| `Order` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`document:belongsTo(TypingDocument; 'document_id')`<br>`payments:hasMany(Payment)`<br>`storeItems:hasMany(StoreOrderItem; 'order_id')` | `app/Models/Order.php` |
| `Organization` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `users:hasMany(User)`<br>`teams:hasMany(Team)` | `app/Models/Organization.php` |
| `OtpChallenge` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/OtpChallenge.php` |
| `Payment` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `order:belongsTo(Order)` | `app/Models/Payment.php` |
| `Permission` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/Permission.php` |
| `PricingRule` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/PricingRule.php` |
| `RolePermission` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `permission:belongsTo(Permission)` | `app/Models/RolePermission.php` |
