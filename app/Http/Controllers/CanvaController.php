<?php
namespace App\Http\Controllers;
use App\Services\CanvaService;
use Illuminate\Http\Request;
use RuntimeException;
class CanvaController extends Controller {
 public function connect(CanvaService $canva){try{$a=$canva->authorizeUrl(auth()->user());return redirect()->away($a['url']);}catch(\Throwable $e){return back()->withErrors(['canva'=>'اتصال Canva تنظیم نشده یا قابل شروع نیست.']);}}
 public function callback(Request $request,CanvaService $canva){try{$canva->callback(auth()->user(),(string)$request->query('code'),(string)$request->query('state'));return redirect()->route('admin.integrations')->with('status','اتصال Canva با موفقیت برقرار شد.');}catch(\Throwable $e){return redirect()->route('admin.integrations')->withErrors(['canva'=>'اتصال Canva ناموفق بود: '.$e->getMessage()]);}}
 public function disconnect(){\App\Models\CanvaConnection::where('user_id',auth()->id())->delete();return back()->with('status','اتصال Canva قطع شد.');}
 public function designs(CanvaService $canva){try{return response()->json(['ok'=>true,'data'=>$canva->designs(auth()->user())]);}catch(\Throwable $e){return response()->json(['ok'=>false,'message'=>'اتصال Canva در دسترس نیست.'],502);}}
 public function create(Request $request,CanvaService $canva){$data=$request->validate(['title'=>'required|string|max:255']);try{return response()->json(['ok'=>true,'data'=>$canva->createDoc(auth()->user(),$data['title'])]);}catch(\Throwable $e){return response()->json(['ok'=>false,'message'=>'ساخت طراحی Canva ناموفق بود.'],502);}}
 public function export(Request $request,CanvaService $canva,string $design){$type=$request->validate(['type'=>'nullable|in:pdf,jpg,png,pptx,mp4,csv,html_bundle,html_standalone'])['type']??'pdf';try{return response()->json(['ok'=>true,'data'=>$canva->export(auth()->user(),$design,$type)]);}catch(\Throwable $e){return response()->json(['ok'=>false,'message'=>'خروجی Canva ناموفق بود.'],502);}}
}
