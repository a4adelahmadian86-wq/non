<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class TemplateRegistry
{
    public const TEMPLATES=['simple_typing'=>['name'=>'تایپ ساده','description'=>'قالب پایه A4 راست‌به‌چپ.','settings'=>['paper'=>'A4','orientation'=>'portrait','margin_top'=>25,'margin_right'=>25,'margin_bottom'=>25,'margin_left'=>25,'direction'=>'rtl','font_family'=>'B Nazanin','font_size'=>16,'line_height'=>1.8,'page_border'=>true]],'academic_typing'=>['name'=>'تایپ دانشگاهی','description'=>'قالب رسمی دانشگاهی.','settings'=>['paper'=>'A4','orientation'=>'portrait','direction'=>'rtl','font_family'=>'B Nazanin','font_size'=>16]],'official_document'=>['name'=>'سند رسمی','description'=>'قالب رسمی با حاشیه.','settings'=>['paper'=>'A4','orientation'=>'portrait','direction'=>'rtl','font_family'=>'B Nazanin','font_size'=>16,'page_border'=>true]]];

    public function all():array
    {
        if(!DB::getSchemaBuilder()->hasTable('farast_templates'))return self::TEMPLATES;
        return DB::table('farast_templates')->where('status','active')->orderBy('id')->get()->mapWithKeys(fn($x)=>[$x->code=>['name'=>self::TEMPLATES[$x->code]['name']??$x->code,'description'=>self::TEMPLATES[$x->code]['description']??'','settings'=>is_string($x->settings)?json_decode($x->settings,true):$x->settings,'version'=>$x->version,'checksum'=>$x->checksum]])->all();
    }
    public function get(string $code):array{return $this->all()[$code]??$this->all()['simple_typing'];}
}