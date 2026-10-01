<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AdminPlatformController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\CanvaController;
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
Route::post('/projects', [ProjectController::class, 'store'])->middleware(['auth','throttle:30,10','capability:can_type'])->name('projects.store');
Route::get('/editor', [EditorController::class, 'create'])->middleware(['auth', 'single.editor'])->name('editor');
Route::post('/editor/documents', [EditorController::class, 'createDocument'])->middleware(['auth', 'single.editor', 'throttle:60,10'])->name('editor.documents.create');

Route::middleware(['auth', 'single.editor'])->group(function () {
    Route::post('/editor/analyze', [EditorController::class, 'analyze'])->middleware(['throttle:20,10', 'capability:can_ai'])->name('editor.analyze');
    Route::post('/editor/ai/assist', EditorAiAssistController::class)->middleware(['throttle:30,10', 'capability:can_ai'])->name('editor.ai.assist');
    Route::post('/editor/save', EditorSaveController::class)->middleware(['throttle:120,1', 'capability:can_type'])->name('editor.save');
    Route::get('/editor/documents/{document}/state', [EditorDocumentStateController::class, 'show'])->middleware('throttle:60,10')->name('editor.document.state');
    Route::get('/editor/documents/{document}/versions', [EditorDocumentStateController::class, 'versions'])->middleware('throttle:30,10')->name('editor.document.versions');
    Route::post('/editor/documents/{document}/versions/{version}/restore', [EditorDocumentStateController::class, 'restore'])->middleware('throttle:20,10')->name('editor.document.version.restore');
    Route::post('/editor/feedback', [EditorController::class, 'feedback'])->middleware(['throttle:60,10', 'capability:can_feedback'])->name('editor.feedback');
    Route::post('/editor/voice/stream-token', [VoiceController::class, 'streamToken'])->middleware(['throttle:30,10', 'capability:can_voice'])->name('editor.voice.stream-token');
    Route::post('/editor/voice/transcribe', [VoiceController::class, 'transcribe'])->middleware(['throttle:30,10', 'capability:can_voice'])->name('editor.voice.transcribe');
    Route::post('/editor/export/{format}', [ExportController::class, 'export'])->whereIn('format', ['docx', 'pdf'])->middleware('throttle:10,10')->name('editor.export');
    Route::post('/editor/heartbeat', [EditorController::class, 'heartbeat'])->middleware('throttle:60,1')->name('editor.heartbeat');
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
    Route::get('/workspace/notifications', [PlatformCompletionController::class, 'notifications'])->name('platform.notifications');
    Route::post('/workspace/notifications/{id}/read', [PlatformCompletionController::class, 'readNotification'])->name('platform.notifications.read');
    Route::get('/workspace/workflows', [PlatformCompletionController::class, 'workflow'])->name('platform.workflows');
    Route::post('/workspace/workflows', [PlatformCompletionController::class, 'saveWorkflow'])->name('platform.workflows.save');
    Route::post('/workspace/workflows/{id}/run', [PlatformCompletionController::class, 'runWorkflow'])->name('platform.workflows.run');
    Route::get('/workspace/security', [PlatformCompletionController::class, 'security'])->name('platform.security');
    Route::post('/workspace/ocr', [PlatformCompletionController::class, 'ocr'])->name('platform.ocr');
    Route::get('/workspace/ocr/{id}', [PlatformCompletionController::class, 'ocrStatus'])->name('platform.ocr.status');
    Route::get('/workspace/billing', [PlatformCompletionController::class, 'billing'])->name('platform.billing');
    Route::post('/workspace/billing/{plan}', [PlatformCompletionController::class, 'subscribe'])->name('platform.billing.subscribe');

    Route::get('/support', [SupportController::class, 'index'])->middleware('capability:can_support')->name('support');
    Route::post('/support/tickets', [SupportController::class, 'create'])->middleware(['throttle:10,10', 'capability:can_support'])->name('support.create');
    Route::post('/support/tickets/{ticket}/messages', [SupportController::class, 'message'])->middleware(['throttle:30,10', 'capability:can_support'])->name('support.message');
    Route::get('/wallet', [WalletController::class, 'index'])->name('wallet');
    Route::post('/wallet/top-up', [WalletController::class, 'topUp'])->middleware('throttle:10,10')->name('wallet.topup');
    Route::get('/checkout/{order}', [PaymentController::class, 'show'])->name('checkout');
    Route::post('/checkout/{order}/wallet', [PaymentController::class, 'payWithWallet'])->middleware('throttle:10,10')->name('checkout.wallet');
    Route::post('/documents/{document}/checkout', [PaymentController::class, 'createForDocument'])->middleware('throttle:10,10')->name('documents.checkout');
});

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

Route::get('/shared/documents/{token}', [PlatformCompletionController::class, 'shared'])->name('platform.documents.shared');

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/finance', [AdminController::class, 'finance'])->name('finance');
    Route::post('/finance', [AdminController::class, 'updateFinance'])->name('finance.update');
    Route::post('/finance/users/{user}/wallet', [AdminController::class, 'adjustWallet'])->name('finance.wallet');
    Route::get('/social', [SocialController::class, 'admin'])->name('social');
    Route::post('/social', [SocialController::class, 'update'])->name('social.update');
    Route::post('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
    Route::post('/voice-providers', [AdminController::class, 'updateVoiceProviders'])->name('voice.providers.update');
    Route::post('/pricing', [AdminController::class, 'updatePricing'])->name('pricing.update');
    Route::post('/users/{user}/capabilities', [AdminController::class, 'updateUserCapabilities'])->name('user.capabilities');
    Route::post('/users/{user}/block', [AdminController::class, 'toggleUser'])->name('user.toggle');
    Route::post('/announcements', [AnnouncementController::class, 'store'])->name('announcements.store');
    Route::put('/announcements/{announcement}', [AnnouncementController::class, 'update'])->name('announcements.update');
    Route::post('/announcements/{announcement}/toggle', [AnnouncementController::class, 'toggle'])->name('announcements.toggle');
    Route::delete('/announcements/{announcement}', [AnnouncementController::class, 'destroy'])->name('announcements.destroy');
    Route::get('/emails', [AdminController::class, 'emails'])->name('emails');
    Route::post('/emails/settings', [AdminController::class, 'updateEmailSettings'])->name('emails.settings');
    Route::post('/emails/test', [AdminController::class, 'sendTestEmail'])->name('emails.test');
    Route::get('/integrations', [AdminPlatformController::class, 'integrations'])->name('integrations');
    Route::post('/integrations/canva', [AdminPlatformController::class, 'saveCanva'])->name('integrations.canva.save');
    Route::get('/integrations/canva/connect', [CanvaController::class, 'connect'])->name('integrations.canva.connect');
    Route::get('/integrations/canva/callback', [CanvaController::class, 'callback'])->name('integrations.canva.callback');
    Route::post('/integrations/canva/disconnect', [CanvaController::class, 'disconnect'])->name('integrations.canva.disconnect');
    Route::get('/access', [AdminPlatformController::class, 'access'])->name('access');
    Route::post('/access/roles/{role}', [AdminPlatformController::class, 'updateRole'])->name('access.roles.update');
    Route::get('/organizations', [AdminPlatformController::class, 'organizations'])->name('organizations');
    Route::post('/organizations', [AdminPlatformController::class, 'storeOrganization'])->name('organizations.store');
    Route::get('/analytics', [AdminPlatformController::class, 'analytics'])->name('analytics');
    Route::get('/audit', [AdminPlatformController::class, 'audit'])->name('audit');
    Route::post('/providers/ai', [ProviderAdminController::class, 'updateAi'])->name('providers.ai.update');
    Route::get('/providers/voice/{provider}/test', [ProviderAdminController::class, 'testVoice'])->name('providers.voice.test');

    Route::post('/tickets/{ticket}/reply', [SupportController::class, 'adminReply'])->name('tickets.reply');
    Route::get('/plans', [PlatformCompletionController::class, 'adminPlans'])->name('plans');
    Route::post('/plans', [PlatformCompletionController::class, 'savePlan'])->name('plans.save');
    Route::get('/support-management', [PlatformCompletionController::class, 'adminSupport'])->name('support.management');
    Route::get('/cms', [PlatformCompletionController::class, 'cms'])->name('cms');
    Route::get('/teams/{team}/members', [PlatformCompletionController::class, 'teamMembers'])->name('teams.members');
    Route::post('/teams/{team}/members', [PlatformCompletionController::class, 'addTeamMember'])->name('teams.members.add');
    Route::delete('/teams/{team}/members/{user}', [PlatformCompletionController::class, 'removeTeamMember'])->name('teams.members.remove');
    Route::post('/cms', [PlatformCompletionController::class, 'saveCms'])->name('cms.save');
});
