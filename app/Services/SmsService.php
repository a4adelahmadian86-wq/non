<?php
namespace App\Services;
use Illuminate\Support\Facades\Http;
class SmsService {public function sendOtp(string $mobile,string $code): void { $key=config('services.kavenegar.key');if(!$key)return;Http::timeout(15)->get('https://api.kavenegar.com/v1/'.$key.'/sms/send.json',['receptor'=>$mobile,'sender'=>config('services.kavenegar.sender'),'message'=>'کد ورود فراست: '.$code]); }}
