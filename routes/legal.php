<?php
use Illuminate\Support\Facades\Route;
Route::view('/terms','legal.terms')->name('terms');Route::view('/refund-policy','legal.refunds')->name('refunds');Route::view('/privacy','legal.privacy')->name('privacy');
