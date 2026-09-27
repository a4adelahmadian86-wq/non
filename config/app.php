<?php
return ['name'=>env('APP_NAME','FARAST'),'env'=>env('APP_ENV','production'),'debug'=>(bool)env('APP_DEBUG',false),'url'=>env('APP_URL','http://localhost'),'timezone'=>'Asia/Tehran','locale'=>'fa','fallback_locale'=>'fa','faker_locale'=>'fa_IR','cipher'=>'AES-256-CBC','key'=>env('APP_KEY'),'maintenance'=>['driver'=>'file']];
