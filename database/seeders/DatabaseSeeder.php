<?php

namespace Database\Seeders;

use App\Models\PricingRule;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $mobile=env('ADMIN_MOBILE','091512345678'); $password=env('ADMIN_INITIAL_PASSWORD') ?: env('FARAST_ADMIN_PASSWORD');
        if($password)User::updateOrCreate(['mobile'=>$mobile],['name'=>'مدیر اصلی','password'=>Hash::make($password),'role'=>'admin','is_verified'=>true]);
        foreach([
            ['page_base',35000,'قیمت پایه هر صفحه'],['english_multiplier',120,'ضریب متن انگلیسی'],['arabic_multiplier',110,'ضریب متن عربی'],
            ['mixed_multiplier',105,'ضریب متن ترکیبی'],['dense_page_multiplier',110,'ضریب صفحه پرتراکم'],['formula_unit',5000,'هزینه هر الگوی فرمولی']
        ] as $r)PricingRule::updateOrCreate(['key'=>$r[0]],['value'=>$r[1],'label'=>$r[2],'active'=>true]);
    }
}
