# فهرست مدل‌ها و روابط Eloquent

> آخرین تطبیق فایل‌های منبع: `main` در commit `5a9323e9f323e43ebfc1ccc94dd52102a3fd4ddf`. تغییرهای بین snapshot قبلی و این commit در فایل‌های مربوط به Reader/Browser و مستندات یکپارچگی کسب‌وکار بوده و فایل‌های منبع این inventory را تغییر نداده‌اند.

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
| `Conversation` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `creator:belongsTo(User; 'created_by')`<br>`participants:belongsToMany(User; 'conversation_participants')->withPivot(['role','last_read_message_id','read_at','muted_at','archived_at','joined_at','last_active_at','typing_until'])->withTimestamps()`<br>`messages:hasMany(ConversationMessage)->whereNull('deleted_at')` | `app/Models/Conversation.php` |
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

| `SiteSetting` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/SiteSetting.php` |
| `StorageBackupRun` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `volume:belongsTo(StorageVolume; 'storage_volume_id')` | `app/Models/StorageBackupRun.php` |
| `StorageManagedFile` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `volume:belongsTo(StorageVolume; 'storage_volume_id')`<br>`replicaVolume:belongsTo(StorageVolume; 'replicated_to_volume_id')` | `app/Models/StorageManagedFile.php` |
| `StorageVolume` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `files:hasMany(StorageManagedFile; '!=', 'deleted')`<br>`allFiles:hasMany(StorageManagedFile)` | `app/Models/StorageVolume.php` |
| `StoreCart` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`items:hasMany(StoreCartItem; 'cart_id')->with('product')` | `app/Models/StoreCart.php` |
| `StoreCartItem` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `cart:belongsTo(StoreCart; 'cart_id')`<br>`product:belongsTo(StoreProduct; 'product_id')` | `app/Models/StoreCartItem.php` |
| `StoreCategory` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `parent:belongsTo(self; 'parent_id')`<br>`children:hasMany(self; 'parent_id')->orderBy('sort_order')`<br>`products:hasMany(StoreProduct; 'category_id')` | `app/Models/StoreCategory.php` |
| `StoreCoupon` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/StoreCoupon.php` |
| `StoreCouponAssignment` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `coupon:belongsTo(StoreCoupon; 'coupon_id')`<br>`user:belongsTo(User)`<br>`product:belongsTo(StoreProduct; 'product_id')`<br>`redeemedOrder:belongsTo(Order; 'redeemed_order_id')`<br>`notification:belongsTo(FarastNotification; 'notification_id')` | `app/Models/StoreCouponAssignment.php` |
| `StoreCourse` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `product:belongsTo(StoreProduct; 'product_id')`<br>`sections:hasMany(StoreCourseSection; 'course_id')->orderBy('sort_order')->orderBy('id')` | `app/Models/StoreCourse.php` |
| `StoreCourseEnrollment` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `course:belongsTo(StoreCourse; 'course_id')`<br>`product:belongsTo(StoreProduct; 'product_id')`<br>`user:belongsTo(User; 'user_id')`<br>`libraryItem:belongsTo(StoreLibraryItem; 'library_item_id')`<br>`progress:hasMany(StoreCourseLessonProgress; 'enrollment_id')` | `app/Models/StoreCourseEnrollment.php` |
| `StoreCourseLesson` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `section:belongsTo(StoreCourseSection; 'section_id')`<br>`resources:hasMany(StoreProductFile; 'course_lesson_id') ->where('purpose', 'lesson_resource')->orderBy('id)` | `app/Models/StoreCourseLesson.php` |
| `StoreCourseLessonProgress` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `enrollment:belongsTo(StoreCourseEnrollment; 'enrollment_id')`<br>`lesson:belongsTo(StoreCourseLesson; 'lesson_id')` | `app/Models/StoreCourseLessonProgress.php` |
| `StoreCourseSection` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `course:belongsTo(StoreCourse; 'course_id')`<br>`lessons:hasMany(StoreCourseLesson; 'section_id')->orderBy('sort_order')->orderBy('id')` | `app/Models/StoreCourseSection.php` |
| `StoreDownload` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `libraryItem:belongsTo(StoreLibraryItem; 'library_item_id')`<br>`user:belongsTo(User)`<br>`file:belongsTo(StoreProductFile; 'product_file_id')` | `app/Models/StoreDownload.php` |

| `StoreLibraryItem` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`product:belongsTo(StoreProduct; 'product_id')`<br>`order:belongsTo(Order)`<br>`orderItem:belongsTo(StoreOrderItem; 'order_item_id')`<br>`file:belongsTo(StoreProductFile; 'product_file_id')`<br>`downloads:hasMany(StoreDownload; 'library_item_id')` | `app/Models/StoreLibraryItem.php` |
| `StoreOrderItem` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `order:belongsTo(Order)`<br>`product:belongsTo(StoreProduct; 'product_id')` | `app/Models/StoreOrderItem.php` |
| `StoreProduct` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `category:belongsTo(StoreCategory; 'category_id')`<br>`seller:belongsTo(User; 'seller_id')`<br>`files:hasMany(StoreProductFile; 'product_id')`<br>`previews:hasMany(StoreProductPreview; 'product_id')->orderBy('sort_order')`<br>`images:hasMany(StoreProductImage; 'product_id')->orderBy('sort_order')`<br>`tags:belongsToMany(StoreTag; 'store_product_tag', 'product_id', 'tag_id')`<br>`related:belongsToMany(self; 'store_product_related', 'product_id', 'related_product_id')`<br>`reviews:hasMany(StoreProductReview; 'product_id')`<br>`course:hasOne(StoreCourse; 'product_id')`<br>`libraryItems:hasMany(StoreLibraryItem; 'product_id')`<br>`orderItems:hasMany(StoreOrderItem; 'product_id')` | `app/Models/StoreProduct.php` |
| `StoreProductDocument` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `product:belongsTo(StoreProduct; 'product_id')`<br>`file:belongsTo(StoreProductFile; 'product_file_id')`<br>`chunks:hasMany(StoreProductDocumentChunk; 'document_id')->orderBy('chunk_index')`<br>`artifacts:hasMany(StoreProductDocumentArtifact; 'document_id')`<br>`facts:hasMany(StoreProductDocumentFact; 'document_id')`<br>`pages:hasMany(StoreProductDocumentPage; 'document_id')->orderBy('page_number')` | `app/Models/StoreProductDocument.php` |
| `StoreProductDocumentArtifact` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `document:belongsTo(StoreProductDocument; 'document_id')` | `app/Models/StoreProductDocumentArtifact.php` |
| `StoreProductDocumentChunk` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `document:belongsTo(StoreProductDocument; 'document_id')` | `app/Models/StoreProductDocumentChunk.php` |
| `StoreProductDocumentFact` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `document:belongsTo(StoreProductDocument; 'document_id')` | `app/Models/StoreProductDocumentFact.php` |
| `StoreProductDocumentPage` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `document:belongsTo(StoreProductDocument; 'document_id')` | `app/Models/StoreProductDocumentPage.php` |
| `StoreProductFile` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `product:belongsTo(StoreProduct; 'product_id')`<br>`lesson:belongsTo(StoreCourseLesson; 'course_lesson_id')`<br>`document:hasOne(StoreProductDocument; 'product_file_id')` | `app/Models/StoreProductFile.php` |
| `StoreProductImage` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `product:belongsTo(StoreProduct; 'product_id')` | `app/Models/StoreProductImage.php` |
| `StoreProductIngestion` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `product:belongsTo(StoreProduct; 'product_id')`<br>`user:belongsTo(User; 'user_id')` | `app/Models/StoreProductIngestion.php` |
| `StoreProductPreview` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `product:belongsTo(StoreProduct; 'product_id')` | `app/Models/StoreProductPreview.php` |
| `StoreReaderState` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`product:belongsTo(StoreProduct; 'product_id')` | `app/Models/StoreReaderState.php` |
| `StoreTag` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `products:belongsToMany(StoreProduct; 'store_product_tag', 'tag_id', 'product_id')` | `app/Models/StoreTag.php` |
| `StoreWishlist` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`product:belongsTo(StoreProduct; 'product_id')` | `app/Models/StoreWishlist.php` |

| `Team` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `organization:belongsTo(Organization)`<br>`supervisor:belongsTo(User; 'supervisor_id')`<br>`members:belongsToMany(User)` | `app/Models/Team.php` |
| `Ticket` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`assignee:belongsTo(User; 'assigned_to')`<br>`relatedProduct:belongsTo(StoreProduct; 'related_product_id')`<br>`messages:hasMany(TicketMessage)`<br>`publicMessages:hasMany(TicketMessage; false)->orWhereNull('is_internal')` | `app/Models/Ticket.php` |
| `TicketMessage` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `ticket:belongsTo(Ticket)`<br>`user:belongsTo(User)` | `app/Models/TicketMessage.php` |
| `TypingDocument` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`project:belongsTo(FarastProject)`<br>`farastDocument:hasOne(FarastDocument; 'id', 'farast_document_id')` | `app/Models/TypingDocument.php` |
| `User` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `documents:hasMany(TypingDocument)`<br>`projects:hasMany(FarastProject)`<br>`knowledge:hasMany(UserKnowledge)`<br>`tickets:hasMany(Ticket)`<br>`wallet:hasOne(Wallet)`<br>`orders:hasMany(Order)`<br>`freeCredits:hasMany(FreeCredit)`<br>`organization:belongsTo(Organization)`<br>`teams:belongsToMany(Team)`<br>`canvaConnection:hasOne(CanvaConnection)` | `app/Models/User.php` |
| `UserCapability` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)` | `app/Models/UserCapability.php` |
| `UserFile` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)` | `app/Models/UserFile.php` |
| `UserKnowledge` | `farast_user_knowledge` | explicit | `id` | `default` | `user:belongsTo(User)` | `app/Models/UserKnowledge.php` |
| `VoiceCorrection` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)` | `app/Models/VoiceCorrection.php` |
| `VoiceProviderAccount` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `usage:hasMany(VoiceProviderUsage)` | `app/Models/VoiceProviderAccount.php` |
| `VoiceProviderUsage` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `providerAccount:belongsTo(VoiceProviderAccount; 'voice_provider_account_id')` | `app/Models/VoiceProviderUsage.php` |
| `Wallet` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `user:belongsTo(User)`<br>`transactions:hasMany(WalletTransaction)` | `app/Models/Wallet.php` |
| `WalletTransaction` | `—` | Eloquent convention; runtime not verified | `id` | `default` | `wallet:belongsTo(Wallet)` | `app/Models/WalletTransaction.php` |
| `WeeklyFreePage` | `—` | Eloquent convention; runtime not verified | `id` | `default` | — | `app/Models/WeeklyFreePage.php` |
