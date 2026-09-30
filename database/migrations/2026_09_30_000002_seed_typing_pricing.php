<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $rules = [
            ['key'=>'page_base','value'=>300000,'label'=>'صفحه استاندارد فارسی (ریال)'],
            ['key'=>'typing_manual_page','value'=>300000,'label'=>'تایپ دستی / صفحه'],
            ['key'=>'typing_voice_page','value'=>450000,'label'=>'تایپ صوتی / صفحه معادل'],
            ['key'=>'typing_printed_page','value'=>330000,'label'=>'منبع چاپی / صفحه'],
            ['key'=>'typing_handwritten_page','value'=>500000,'label'=>'منبع دست‌نویس / صفحه'],
            ['key'=>'typing_mixed_page','value'=>600000,'label'=>'منبع ترکیبی / صفحه'],
            ['key'=>'typing_voice_minute','value'=>10000,'label'=>'تایپ صوتی / دقیقه'],
            ['key'=>'typing_payg_multiplier','value'=>125,'label'=>'ضریب پرداخت آزاد (%)'],
        ];
        foreach ($rules as $rule) {
            DB::table('pricing_rules')->updateOrInsert(
                ['key'=>$rule['key']],
                ['value'=>$rule['value'],'label'=>$rule['label'],'active'=>true,'updated_at'=>now(),'created_at'=>now()]
            );
        }
    }

    public function down(): void
    {
        DB::table('pricing_rules')->whereIn('key',[
            'typing_manual_page','typing_voice_page','typing_printed_page','typing_handwritten_page',
            'typing_mixed_page','typing_voice_minute','typing_payg_multiplier'
        ])->delete();
    }
};
