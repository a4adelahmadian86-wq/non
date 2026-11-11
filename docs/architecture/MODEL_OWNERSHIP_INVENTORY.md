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
