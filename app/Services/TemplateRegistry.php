<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TemplateRegistry
{
    public const TEMPLATES=['simple_typing'=>['name'=>'تایپ ساده','description'=>'قالب پایه A4 راست‌به‌چپ.'],'academic_typing'=>['name'=>'تایپ دانشگاهی','description'=>'قالب رسمی دانشگاهی.'],'official_document'=>['name'=>'سند رسمی','description'=>'قالب رسمی با حاشیه.']];

    public function all():array
    {
        if(!DB::getSchemaBuilder()->hasTable('farast_templates'))return self::TEMPLATES;
        return DB::table('farast_templates')->where('status','active')->orderBy('id')->get()->mapWithKeys(fn($x)=>[$x->code=>['name'=>self::TEMPLATES[$x->code]['name']??$x->code,'description'=>self::TEMPLATES[$x->code]['description']??'','settings'=>is_string($x->settings)?json_decode($x->settings,true):$x->settings,'version'=>$x->version,'checksum'=>$x->checksum]])->all();
    }
    public function get(string $code):array{return $this->all()[$code]??$this->all()['simple_typing'];}
}