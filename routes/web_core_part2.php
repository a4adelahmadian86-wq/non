<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\ContentDiscussionController;
use App\Http\Controllers\AdminPlatformController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\CanvaController;
use App\Http\Controllers\CrmController;
use App\Http\Controllers\CrmAnalyticsController;
use App\Http\Controllers\CrmMarketingController;
use App\Http\Controllers\CrmProjectController;
use App\Http\Controllers\EditorAiAssistController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\EditorDocumentStateController;
use App\Http\Controllers\EditorSaveController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PlatformCompletionController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProviderAdminController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/projects/new', [ProjectController::class, 'create'])->middleware('auth')->name('projects.create');
Route::post('/projects', [ProjectController::class, 'store'])->middleware(['auth', 'throttle:30,10', 'capability:can_type'])->name('projects.store');
Route::get('/editor', [EditorController::class, 'create'])->middleware(['auth', 'single.editor'])->name('editor');
Route::post('/editor/documents', [EditorController::class, 'createDocument'])->middleware(['auth', 'single.editor', 'throttle:60,10'])->name('editor.documents.create');

Route::middleware(['auth', 'single.editor'])->group(function () {
    Route::post('/editor/analyze', [EditorController::class, 'analyze'])->middleware(['throttle:20,10', 'capability:can_ai'])->name('editor.analyze');
    Route::post('/editor/ai/assist', EditorAiAssistController::class)->middleware(['throttle:30,10', 'capability:can_ai'])->name('editor.ai.assist');
    Route::post('/editor/agent/plan', [\App\Http\Controllers\EditorAgentController::class, 'plan'])->middleware(['throttle:20,10'])->name('editor.agent.plan');
    Route::post('/editor/agent/tasks/{task}/approve', [\App\Http\Controllers\EditorAgentController::class, 'approve'])->middleware(['throttle:30,10'])->name('editor.agent.approve');
    Route::post('/editor/agent/tasks/{task}/commit', [\App\Http\Controllers\EditorAgentController::class, 'commit'])->middleware(['throttle:30,10'])->name('editor.agent.commit');
    Route::post('/editor/agent/tasks/{task}/undo', [\App\Http\Controllers\EditorAgentController::class, 'undo'])->middleware(['throttle:30,10'])->name('editor.agent.undo');
    Route::post('/editor/save', EditorSaveController::class)->middleware(['throttle:120,1', 'capability:can_type'])->name('editor.save');
    Route::post('/editor/assets', [EditorController::class, 'uploadAsset'])->middleware('throttle:60,10')->name('editor.assets.upload');
    Route::get('/editor/documents/{document}/assets/{resource}', [EditorController::class, 'asset'])->middleware('throttle:120,10')->name('editor.document.asset');
    Route::get('/editor/documents/{document}/state', [EditorDocumentStateController::class, 'show'])->middleware('throttle:60,10')->name('editor.document.state');
    Route::get('/editor/documents/{document}/versions', [EditorDocumentStateController::class, 'versions'])->middleware('throttle:30,10')->name('editor.document.versions');
    Route::post('/editor/documents/{document}/versions/{version}/restore', [EditorDocumentStateController::class, 'restore'])->middleware('throttle:20,10')->name('editor.document.version.restore');
    Route::post('/editor/feedback', [EditorController::class, 'feedback'])->middleware(['throttle:60,10', 'capability:can_feedback'])->name('editor.feedback');
    Route::post('/editor/voice/stream-token', [VoiceController::class, 'streamToken'])->middleware(['throttle:30,10', 'capability:can_voice'])->name('editor.voice.stream-token');
    Route::post('/editor/voice/transcribe', [VoiceController::class, 'transcribe'])->middleware(['throttle:30,10', 'capability:can_voice'])->name('editor.voice.transcribe');
    Route::post('/editor/voice/insert', [VoiceController::class, 'insertTranscript'])->middleware(['throttle:120,10', 'capability:can_voice'])->name('editor.voice.insert');
    Route::post('/editor/export/{format}', [ExportController::class, 'export'])->whereIn('format', ['docx', 'pdf'])->middleware('throttle:10,10')->name('editor.export');
    Route::post('/editor/heartbeat', [EditorController::class, 'heartbeat'])->middleware('throttle:60,1')->name('editor.heartbeat');
});

// The single-editor lock protects editor state only; it must not block the rest of the workspace.
Route::middleware('auth')->group(function () {
    Route::get('/dashboard', [EditorController::class, 'dashboard'])->name('dashboard');
    Route::get('/workspace/documents', [PlatformCompletionController::class, 'documents'])->name('platform.documents');
    Route::post('/workspace/documents', [PlatformCompletionController::class, 'createDocument'])->name('platform.documents.create');
    Route::post('/workspace/folders', [PlatformCompletionController::class, 'createFolder'])->name('platform.folders.create');
    Route::post('/workspace/documents/{id}', [PlatformCompletionController::class, 'updateDocument'])->name('platform.documents.update');
    Route::post('/workspace/documents/{id}/favorite', [PlatformCompletionController::class, 'favorite'])->name('platform.documents.favorite');
    Route::post('/workspace/documents/{id}/trash', [PlatformCompletionController::class, 'trash'])->name('platform.documents.trash');
    Route::post('/workspace/documents/{id}/restore', [PlatformCompletionController::class, 'restore'])->name('platform.documents.restore');
    Route::delete('/workspace/documents/{id}', [PlatformCompletionController::class, 'destroy'])->name('platform.documents.destroy');
    Route::get('/workspace/documents/{id}/versions', [PlatformCompletionController::class, 'versions'])->name('platform.documents.versions');
    Route::post('/workspace/documents/{id}/share', [PlatformCompletionController::class, 'share'])->name('platform.documents.share');
    Route::delete('/workspace/shares/{id}', [PlatformCompletionController::class, 'revokeShare'])->name('platform.documents.shares.destroy');
    Route::get('/workspace/notifications', [PlatformCompletionController::class, 'notifications'])->name('platform.notifications');
    Route::post('/workspace/notifications/{id}/read', [PlatformCompletionController::class, 'readNotification'])->name('platform.notifications.read');
    Route::get('/workspace/workflows', [PlatformCompletionController::class, 'workflow'])->name('platform.workflows');
    Route::post('/workspace/workflows', [PlatformCompletionController::class, 'saveWorkflow'])->middleware('throttle:20,1')->name('platform.workflows.save');
    Route::post('/workspace/workflows/{id}/run', [PlatformCompletionController::class, 'runWorkflow'])->middleware('throttle:20,1')->name('platform.workflows.run');
    Route::get('/workspace/security', [PlatformCompletionController::class, 'security'])->name('platform.security');
    Route::post('/workspace/ocr', [PlatformCompletionController::class, 'ocr'])->middleware('throttle:10,1')->name('platform.ocr');
    Route::get('/workspace/ocr/{id}', [PlatformCompletionController::class, 'ocrStatus'])->middleware('throttle:60,1')->name('platform.ocr.status');
    Route::get('/workspace/billing', [PlatformCompletionController::class, 'billing'])->name('platform.billing');
    Route::post('/workspace/billing/{plan}', [PlatformCompletionController::class, 'subscribe'])->middleware('throttle:5,10')->name('platform.billing.subscribe');

    Route::get('/support', [SupportController::class, 'index'])->middleware('capability:can_support')->name('support');
    Route::get('/support/create', [SupportController::class, 'createForm'])->middleware('capability:can_support')->name('support.create.form');
    Route::get('/support/tickets/{ticket}', [SupportController::class, 'show'])->middleware('capability:can_support')->name('support.show');
    Route::get('/support/tickets/{ticket}/messages', [SupportController::class, 'pollMessages'])->middleware(['capability:can_support', 'throttle:60,1'])->name('support.messages.poll');
    Route::post('/support/tickets', [SupportController::class, 'create'])->middleware(['throttle:10,10', 'capability:can_support'])->name('support.create');
    Route::post('/support/tickets/{ticket}/messages', [SupportController::class, 'message'])->middleware(['throttle:30,10', 'capability:can_support'])->name('support.message');
    Route::post('/support/tickets/{ticket}/close', [SupportController::class, 'close'])->middleware(['throttle:10,10', 'capability:can_support'])->name('support.close');
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
    Route::post('/wallet/top-up', [WalletController::class, 'topUp'])->middleware('throttle:10,10')->name('wallet.topup');
    Route::get('/checkout/{order}', [PaymentController::class, 'show'])->name('checkout');
    Route::post('/checkout/{order}/wallet', [PaymentController::class, 'payWithWallet'])->middleware('throttle:10,10')->name('checkout.wallet');
    Route::post('/documents/{document}/checkout', [PaymentController::class, 'createForDocument'])->middleware('throttle:10,10')->name('documents.checkout');
});

Route::get('/shared/documents/{token}', [PlatformCompletionController::class, 'shared'])->name('platform.documents.shared');

Route::middleware('auth')->group(function () {
    Route::get('/canva/connect', [CanvaController::class, 'connect'])->name('canva.connect');
    Route::get('/canva/callback', [CanvaController::class, 'callback'])->name('canva.callback');
    Route::post('/canva/disconnect', [CanvaController::class, 'disconnect'])->name('canva.disconnect');
    Route::get('/canva/designs', [CanvaController::class, 'designs'])->name('canva.designs');
    Route::post('/canva/designs', [CanvaController::class, 'create'])->name('canva.designs.create');
    Route::post('/canva/designs/{design}/export', [CanvaController::class, 'export'])->name('canva.designs.export');
    Route::get('/canva/exports/{job}', [CanvaController::class, 'exportStatus'])->name('canva.exports.status');
    Route::post('/canva/imports', [CanvaController::class, 'import'])->name('canva.imports.create');
    Route::get('/canva/imports/{job}', [CanvaController::class, 'importStatus'])->name('canva.imports.status');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/discussions', [ContentDiscussionController::class, 'adminIndex'])->middleware('admin.section:store')->name('discussions.index');
    Route::patch('/discussions/{discussion}/moderate', [ContentDiscussionController::class, 'moderate'])->middleware(['admin.section:store', 'throttle:30,10'])->name('discussions.moderate');
    Route::post('/discussions/{discussion}/reply', [ContentDiscussionController::class, 'reply'])->middleware(['admin.section:store', 'throttle:20,10'])->name('discussions.reply');

    // CRM marketing: saved segments and explicitly dispatched in-app campaigns.
    Route::get('/crm/marketing/segments', [CrmMarketingController::class, 'segments'])->middleware('admin.section:marketing')->name('crm.marketing.segments.index');
    Route::post('/crm/marketing/segments', [CrmMarketingController::class, 'storeSegment'])->middleware(['admin.section:marketing', 'throttle:20,10'])->name('crm.marketing.segments.store');
    Route::post('/crm/marketing/audience/preview', [CrmMarketingController::class, 'previewAudience'])->middleware('admin.section:marketing')->name('crm.marketing.audience.preview');
    Route::get('/crm/marketing/campaigns', [CrmMarketingController::class, 'campaigns'])->middleware('admin.section:marketing')->name('crm.marketing.campaigns.index');
    Route::post('/crm/marketing/campaigns', [CrmMarketingController::class, 'storeCampaign'])->middleware(['admin.section:marketing', 'throttle:20,10'])->name('crm.marketing.campaigns.store');
    Route::post('/crm/marketing/campaigns/{campaign}/send', [CrmMarketingController::class, 'sendCampaign'])->middleware(['admin.section:marketing', 'throttle:5,10'])->name('crm.marketing.campaigns.send');

    Route::get('/crm/analytics', CrmAnalyticsController::class)->middleware('admin.section:marketing')->name('crm.analytics');
    // CRM project workspace: typing projects, documents, payments and human-service tasks.
    Route::get('/crm/projects', [CrmProjectController::class, 'index'])->middleware('admin.section:users')->name('crm.projects.index');
    Route::get('/crm/projects/{project}', [CrmProjectController::class, 'show'])->middleware('admin.section:users')->name('crm.projects.show');
    Route::post('/crm/projects/{project}/tasks/{task}/assign', [CrmProjectController::class, 'assignHumanTask'])->middleware(['admin.section:users', 'throttle:30,10'])->name('crm.projects.tasks.assign');

    // CRM backend: customer 360, internal notes and follow-up tasks (reuses scoped users permission).
    Route::get('/crm/follow-ups', [CrmController::class, 'followUps'])->middleware('admin.section:users')->name('crm.followups.index');
    Route::get('/crm/customers', [CrmController::class, 'customers'])->middleware('admin.section:users')->name('crm.customers.index');
    Route::get('/crm/customers/{customer}', [CrmController::class, 'show'])->middleware('admin.section:users')->name('crm.customers.show');
    Route::post('/crm/customers/{customer}/notes', [CrmController::class, 'storeNote'])->middleware(['admin.section:users', 'throttle:30,10'])->name('crm.customers.notes.store');
    Route::post('/crm/customers/{customer}/follow-ups', [CrmController::class, 'storeFollowUp'])->middleware(['admin.section:users', 'throttle:30,10'])->name('crm.customers.followups.store');
    Route::patch('/crm/customers/{customer}/follow-ups/{task}', [CrmController::class, 'updateFollowUp'])->middleware(['admin.section:users', 'throttle:30,10'])->name('crm.customers.followups.update');
    Route::get('/', [AdminController::class, 'index'])->middleware('admin.section:overview')->name('index');
    Route::get('/users', [AdminController::class, 'users'])->middleware('admin.section:users')->name('users');
    Route::post('/users/{user}/role', [AdminController::class, 'updateUserRole'])->middleware('admin.section:users')->name('users.role');
    Route::get('/finance', [AdminController::class, 'finance'])->middleware('admin.section:finance')->name('finance');
    Route::post('/finance', [AdminController::class, 'updateFinance'])->middleware('admin.section:finance_settings')->name('finance.update');
    Route::post('/finance/users/{user}/wallet', [AdminController::class, 'adjustWallet'])->middleware('admin.section:finance_manage')->name('finance.wallet');
    Route::get('/social', [SocialController::class, 'admin'])->middleware('admin.section:social')->name('social');
    Route::post('/social', [SocialController::class, 'update'])->middleware('admin.section:social')->name('social.update');
    Route::post('/settings', [AdminController::class, 'updateSettings'])->middleware('admin.section:settings')->name('settings.update');
    Route::post('/voice-providers', [AdminController::class, 'updateVoiceProviders'])->middleware('admin.section:voice')->name('voice.providers.update');
    Route::post('/pricing', [AdminController::class, 'updatePricing'])->middleware('admin.section:pricing')->name('pricing.update');
    Route::post('/users/{user}/capabilities', [AdminController::class, 'updateUserCapabilities'])->middleware('admin.section:users')->name('user.capabilities');
    Route::post('/users/{user}/block', [AdminController::class, 'toggleUser'])->middleware('admin.section:users')->name('user.toggle');
    Route::get('/announcements', [AnnouncementController::class, 'manage'])->middleware('admin.section:announcements')->name('announcements.index');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->middleware('admin.section:announcements')->name('announcements.store');
    Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->middleware('admin.section:announcements')->name('announcements.update');
    Route::post('/announcements/{announcement}/toggle', [AnnouncementController::class, 'toggle'])->middleware('admin.section:announcements')->name('announcements.toggle');
    Route::post('/announcements/{announcement}/rebroadcast', [AnnouncementController::class, 'rebroadcast'])->middleware('admin.section:announcements')->name('announcements.rebroadcast');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->middleware('admin.section:announcements')->name('announcements.destroy');
    Route::post('/announcements/{announcement}/archive', [AnnouncementController::class, 'archive'])->middleware('admin.section:announcements')->name('announcements.archive');
    Route::post('/announcements/{announcement}/restore', [AnnouncementController::class, 'restore'])->middleware('admin.section:announcements')->name('announcements.restore');
    Route::get('/emails', [AdminController::class, 'emails'])->middleware('admin.section:emails')->name('emails');
    Route::post('/emails/settings', [AdminController::class, 'updateEmailSettings'])->middleware('admin.section:emails')->name('emails.settings');
    Route::post('/emails/test', [AdminController::class, 'sendTestEmail'])->middleware('admin.section:emails')->name('emails.test');
    Route::get('/integrations', [AdminPlatformController::class, 'integrations'])->middleware('admin.section:integrations')->name('integrations');
    Route::post('/integrations/canva', [AdminPlatformController::class, 'saveCanva'])->middleware('admin.section:integrations')->name('integrations.canva.save');
    Route::get('/integrations/canva/connect', [CanvaController::class, 'connect'])->middleware('admin.section:integrations')->name('integrations.canva.connect');
    Route::get('/integrations/canva/callback', [CanvaController::class, 'callback'])->middleware('admin.section:integrations')->name('integrations.canva.callback');
    Route::post('/integrations/canva/disconnect', [CanvaController::class, 'disconnect'])->middleware('admin.section:integrations')->name('integrations.canva.disconnect');
    Route::get('/access', [AdminPlatformController::class, 'access'])->middleware('admin.section:access')->name('access');
    Route::post('/access/roles/{role}', [AdminPlatformController::class, 'updateRole'])->middleware('admin.section:access')->name('access.roles.update');
    Route::get('/organizations', [AdminPlatformController::class, 'organizations'])->middleware('admin.section:organizations')->name('organizations');
    Route::post('/organizations', [AdminPlatformController::class, 'storeOrganization'])->middleware('admin.section:organizations')->name('organizations.store');
    Route::get('/analytics', [AdminPlatformController::class, 'analytics'])->middleware('admin.section:analytics')->name('analytics');
    Route::get('/audit', [AdminPlatformController::class, 'audit'])->middleware('admin.section:audit')->name('audit');
    Route::post('/providers/ai', [ProviderAdminController::class, 'updateAi'])->middleware('admin.section:ai_providers')->name('providers.ai.update');
    Route::get('/providers/ai/test', [ProviderAdminController::class, 'testAi'])->middleware(['admin.section:ai_providers', 'throttle:5,1'])->name('providers.ai.test');
    Route::get('/providers/voice/{provider}/test', [ProviderAdminController::class, 'testVoice'])->middleware('admin.section:voice')->name('providers.voice.test');
    Route::post('/providers/groq', [ProviderAdminController::class, 'updateGroq'])->middleware('admin.section:ai_providers')->name('providers.groq.update');
    Route::get('/providers/groq/test', [ProviderAdminController::class, 'testGroq'])->middleware(['admin.section:ai_providers', 'throttle:5,1'])->name('providers.groq.test');

    Route::get('/support-sms', [AdminController::class, 'supportSms'])->middleware('admin.section:support_admin')->name('support.sms');
    Route::post('/support-sms', [AdminController::class, 'updateSupportSms'])->middleware('admin.section:support_admin')->name('support.sms.update');
    Route::get('/support-management', [SupportController::class, 'adminIndex'])->middleware('admin.section:support_admin')->name('support.management');
    Route::post('/tickets/{ticket}/reply', [SupportController::class, 'adminReply'])->middleware('admin.section:support_admin')->name('tickets.reply');
    Route::post('/tickets/{ticket}/status', [SupportController::class, 'adminStatus'])->middleware('admin.section:support_admin')->name('tickets.status');
    Route::post('/tickets/{ticket}/assign', [SupportController::class, 'adminAssign'])->middleware('admin.section:support_admin')->name('tickets.assign');
    Route::post('/tickets/{ticket}/meta', [SupportController::class, 'adminMeta'])->middleware('admin.section:support_admin')->name('tickets.meta');
    Route::get('/plans', [PlatformCompletionController::class, 'adminPlans'])->middleware('admin.section:plans')->name('plans');
    Route::post('/plans', [PlatformCompletionController::class, 'savePlan'])->middleware('admin.section:plans')->name('plans.save');
    Route::get('/cms', [PlatformCompletionController::class, "cms"])->middleware('admin.section:cms')->name('cms');
    Route::get('/teams/{team}/members', [PlatformCompletionController::class, "teamMembers"])->middleware('admin.section:access')->name('teams.members');
    Route::post('/teams/{team}/members', [PlatformCompletionController::class, "addTeamMember"])->middleware('admin.section:access')->name('teams.members.add');
    Route::delete('/teams/{team}/members/{user}', [PlatformCompletionController::class, "removeTeamMember"])->middleware('admin.section:access')->name('teams.members.remove');
    Route::post('/cms', [PlatformCompletionController::class, "saveCms"])->middleware('admin.section:cms')->name('cms.save');
});
