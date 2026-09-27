<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AnnouncementController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EditorAiAssistController;
use App\Http\Controllers\EditorController;
use App\Http\Controllers\EditorSaveController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\SocialController;
use App\Http\Controllers\StoreCartController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\StoreLibraryController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TypingPreflightController;
use App\Http\Controllers\UserFileController;
use App\Http\Controllers\VoiceController;
use App\Http\Controllers\WalletController;
use App\Models\Announcement;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/', function () { $announcements=Schema::hasTable('announcements')?Announcement::visible()->latest()->limit(4)->get():collect(); return view('home',compact('announcements')); })->name('home');
Route::get('/store',[StoreController::class,'index'])->name('store');
Route::get('/store/category/{slug}',[StoreController::class,'category'])->name('store.category');
Route::get('/store/product/{slug}',[StoreController::class,'product'])->name('store.product');
Route::get('/store/preview/{preview}',[StoreController::class,'preview'])->name('store.preview');
Route::get('/cart',[StoreCartController::class,'index'])->name('cart');
Route::post('/cart/products/{product}',[StoreCartController::class,'add'])->middleware('throttle:60,10')->name('cart.add');
Route::post('/cart/products/{product}/update',[StoreCartController::class,'update'])->middleware('throttle:60,10')->name('cart.update');
Route::post('/cart/products/{product}/remove',[StoreCartController::class,'remove'])->middleware('throttle:60,10')->name('cart.remove');
Route::post('/cart/checkout',[StoreCartController::class,'checkout'])->middleware(['auth','throttle:10,10'])->name('cart.checkout');
Route::get('/pricing',[EditorController::class,'pricing'])->name('pricing');
Route::get('/announcements',[AnnouncementController::class,'index'])->name('announcements');
Route::get('/social',[SocialController::class,'index'])->name('social');
Route::view('/terms','legal.terms')->name('terms');
Route::view('/refund-policy','legal.refunds')->name('refunds');
Route::view('/privacy','legal.privacy')->name('privacy');
Route::get('/assets/sounds/{file}',function(string $file){abort_unless(preg_match('/^[0-9]{2}-[a-z0-9-]+\.ogg$/',$file)===1);$path=base_path('FARAST-UI-SOUNDS/OGG/'.$file);abort_unless(is_file($path),404);return response()->file($path,['Content-Type'=>'audio/ogg','Cache-Control'=>'public, max-age=31536000, immutable','X-Content-Type-Options'=>'nosniff']);})->where('file','[0-9]{2}-[A-Za-z0-9-]+\.ogg')->name('assets.sounds');

Route::get('/login',[AuthController::class,'showLogin'])->name('login');
Route::post('/login/phone',[AuthController::class,'phoneContinue'])->middleware('throttle:10,10')->name('login.phone');
Route::get('/login/password',[AuthController::class,'passwordForm'])->name('login.password');
Route::post('/login/password',[AuthController::class,'passwordLogin'])->middleware('throttle:10,10')->name('login.password.store');
Route::get('/register',[AuthController::class,'registerForm'])->name('register');
Route::post('/register',[AuthController::class,'registerStore'])->middleware('throttle:10,10')->name('register.store');
Route::post('/login/register/verify',[AuthController::class,'verifyRegistrationOtp'])->middleware('throttle:10,10')->name('register.otp.verify');
Route::post('/login/request-otp',[AuthController::class,'requestOtp'])->middleware('throttle:5,10')->name('login.otp');
Route::get('/login/email',[AuthController::class,'emailLoginForm'])->name('login.email');
Route::post('/login/email',[AuthController::class,'requestEmailOtp'])->middleware('throttle:5,10')->name('login.email.request');
Route::post('/login/email/verify',[AuthController::class,'verifyEmailOtp'])->middleware('throttle:10,10')->name('login.email.verify');
Route::get('/login/email/mobile',[AuthController::class,'emailMobileForm'])->name('login.email.mobile');
Route::post('/login/email/mobile',[AuthController::class,'requestEmailMobileOtp'])->middleware('throttle:5,10')->name('login.email.mobile.request');
Route::post('/logout',[AuthController::class,'logout'])->middleware('auth')->name('logout');

Route::post('/editor/voice/stream-config',[VoiceController::class,'streamConfig'])->middleware('throttle:120,1')->name('editor.voice.stream-config');
Route::post('/editor/voice/stream-usage',[VoiceController::class,'streamUsage'])->middleware('throttle:120,1')->name('editor.voice.stream-usage');

Route::middleware('auth')->group(function(){
    Route::get('/editor/preflight/estimate',[TypingPreflightController::class,'estimate'])->middleware('throttle:30,10')->name('editor.preflight.estimate.get');
    Route::post('/editor/preflight/estimate',[TypingPreflightController::class,'estimate'])->middleware('throttle:30,10')->name('editor.preflight.estimate');
    Route::post('/editor/preflight/accept',[TypingPreflightController::class,'accept'])->middleware('throttle:30,10')->name('editor.preflight.accept');
    Route::post('/editor/preflight/decline',[TypingPreflightController::class,'decline'])->middleware('throttle:30,10')->name('editor.preflight.decline');
    Route::get('/editor/pending',[EditorController::class,'pending'])->middleware('throttle:60,10')->name('editor.pending');
    Route::post('/editor/upload',[EditorController::class,'upload'])->middleware('throttle:20,10')->name('editor.upload');
    Route::get('/library',[StoreLibraryController::class,'index'])->name('library');
    Route::post('/library/{libraryItem}/download',[StoreLibraryController::class,'issue'])->middleware('throttle:20,10')->name('library.download.issue');
    Route::get('/downloads/{download}',[StoreLibraryController::class,'stream'])->middleware('throttle:60,10')->name('store.download.stream');
    Route::get('/editor/files',[UserFileController::class,'index'])->middleware('throttle:60,10')->name('editor.files.index');
    Route::post('/editor/files',[UserFileController::class,'upload'])->middleware('throttle:20,10')->name('editor.files.upload');
    Route::post('/editor/files/{file}/select',[UserFileController::class,'select'])->middleware('throttle:30,10')->name('editor.files.select');
    Route::delete('/editor/files/{file}',[UserFileController::class,'destroy'])->middleware('throttle:30,10')->name('editor.files.destroy');
});

Route::get('/editor',[EditorController::class,'create'])->middleware(['auth','single.editor'])->name('editor');
Route::middleware(['auth','single.editor'])->group(function(){
    Route::post('/editor/analyze',[EditorController::class,'analyze'])->middleware(['throttle:20,10','capability:can_ai'])->name('editor.analyze');
    Route::post('/editor/ai/assist',EditorAiAssistController::class)->middleware(['throttle:30,10','capability:can_ai'])->name('editor.ai.assist');
    Route::post('/editor/save',EditorSaveController::class)->middleware(['throttle:120,1','capability:can_type'])->name('editor.save');
    Route::post('/editor/feedback',[EditorController::class,'feedback'])->middleware(['throttle:60,10','capability:can_feedback'])->name('editor.feedback');
    Route::post('/editor/voice/stream-token',[VoiceController::class,'streamToken'])->middleware(['throttle:30,10','capability:can_voice'])->name('editor.voice.stream-token');
    Route::post('/editor/voice/transcribe',[VoiceController::class,'transcribe'])->middleware(['throttle:30,10','capability:can_voice'])->name('editor.voice.transcribe');
    Route::post('/editor/export/{format}',[ExportController::class,'export'])->whereIn('format',['docx','pdf'])->middleware('throttle:10,10')->name('editor.export');
    Route::post('/editor/heartbeat',[EditorController::class,'heartbeat'])->middleware('throttle:60,1')->name('editor.heartbeat');
    Route::get('/dashboard',[EditorController::class,'dashboard'])->name('dashboard');
    Route::get('/support',[SupportController::class,'index'])->middleware('capability:can_support')->name('support');
    Route::post('/support/tickets',[SupportController::class,'create'])->middleware(['throttle:10,10','capability:can_support'])->name('support.create');
    Route::post('/support/tickets/{ticket}/messages',[SupportController::class,'message'])->middleware(['throttle:30,10','capability:can_support'])->name('support.message');
    Route::get('/wallet',[WalletController::class,'index'])->name('wallet');
    Route::post('/wallet/top-up',[WalletController::class,'topUp'])->middleware('throttle:10,10')->name('wallet.topup');
    Route::get('/checkout/{order}',[PaymentController::class,'show'])->name('checkout');
    Route::post('/checkout/{order}/wallet',[PaymentController::class,'payWithWallet'])->middleware('throttle:10,10')->name('checkout.wallet');
    Route::post('/documents/{document}/checkout',[PaymentController::class,'createForDocument'])->middleware('throttle:10,10')->name('documents.checkout');
});

Route::middleware(['auth','admin'])->prefix('admin')->name('admin.')->group(function(){
    Route::get('/',[AdminController::class,'index'])->name('index');
    Route::get('/finance',[AdminController::class,'finance'])->name('finance');
    Route::post('/finance',[AdminController::class,'updateFinance'])->name('finance.update');
    Route::post('/finance/users/{user}/wallet',[AdminController::class,'adjustWallet'])->name('finance.wallet');
    Route::get('/social',[SocialController::class,'admin'])->name('social');
    Route::post('/social',[SocialController::class,'update'])->name('social.update');
    Route::post('/settings',[AdminController::class,'updateSettings'])->name('settings.update');
    Route::post('/pricing',[AdminController::class,'updatePricing'])->name('pricing.update');
    Route::post('/users/{user}/capabilities',[AdminController::class,'updateUserCapabilities'])->name('user.capabilities');
    Route::post('/users/{user}/block',[AdminController::class,'toggleUser'])->name('user.toggle');
    Route::post('/announcements',[AnnouncementController::class,'store'])->name('announcements.store');
    Route::put('/announcements/{announcement}',[AnnouncementController::class,'update'])->name('announcements.update');
    Route::post('/announcements/{announcement}/toggle',[AnnouncementController::class,'toggle'])->name('announcements.toggle');
    Route::delete('/announcements/{announcement}',[AnnouncementController::class,'destroy'])->name('announcements.destroy');
    Route::get('/emails',[AdminController::class,'emails'])->name('emails');
    Route::post('/emails/settings',[AdminController::class,'updateEmailSettings'])->name('emails.settings');
    Route::post('/emails/test',[AdminController::class,'sendTestEmail'])->name('emails.test');
    Route::post('/tickets/{ticket}/reply',[SupportController::class,'adminReply'])->name('tickets.reply');
});
