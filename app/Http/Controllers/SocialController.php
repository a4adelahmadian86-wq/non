<?php

namespace App\Http\Controllers;

use App\Models\SiteSetting;
use Illuminate\Http\Request;

class SocialController extends Controller
{
    public function index(){return view('social-links',['links'=>$this->links()]);}
    public function admin(){return view('admin.social',['links'=>$this->links()]);}
    public function update(Request $r){$data=$r->validate(['links'=>'array|max:12','links.*.title'=>'nullable|string|max:80','links.*.url'=>'nullable|url|max:500','links.*.icon'=>'nullable|string|max:80']);$links=[];foreach($data['links']??[] as $link){if(!empty($link['title'])&&!empty($link['url']))$links[]=['title'=>$link['title'],'url'=>$link['url'],'icon'=>$link['icon']?:'fa-solid fa-link'];}SiteSetting::write('social_links',json_encode($links,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));return back()->with('status','نمادهای ارتباطی ذخیره شدند.');}
    private function links():array{$raw=SiteSetting::read('social_links','[]');$links=json_decode((string)$raw,true);return is_array($links)?array_values(array_filter($links,fn($x)=>is_array($x)&&filter_var($x['url']??'',FILTER_VALIDATE_URL))):[];}
}
