<?php
namespace App\Http\Controllers;
use App\Models\ContentDiscussion;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
class ContentDiscussionController extends Controller {
 public function store(Request $request) {
  $data=$request->validate(['target_type'=>['required','in:product,article'],'target_key'=>['required','string','max:240'],'kind'=>['required','in:review,question,comment'],'title'=>['nullable','string','max:180'],'body'=>['required','string','min:10','max:5000'],'rating'=>['nullable','integer','min:1','max:5']]);
  if($data['target_type']==='product') {
   $product=StoreProduct::published()->findOrFail((int)$data['target_key']);
   if(!in_array($data['kind'],['review','question'],true)) throw ValidationException::withMessages(['kind'=>'برای محصول فقط دیدگاه یا پرسش مجاز است.']);
   if($data['kind']==='review' && !($data['rating'] ?? null)) throw ValidationException::withMessages(['rating'=>'برای ثبت دیدگاه، امتیاز یک تا پنج را انتخاب کنید.']);
   if($data['kind']!=='review') $data['rating']=null;
   if($data['kind']==='review' && ContentDiscussion::where('target_type','product')->where('target_key',(string)$product->id)->where('kind','review')->where('user_id',$request->user()->id)->where('status','!=','rejected')->exists()) throw ValidationException::withMessages(['body'=>'برای این محصول قبلاً دیدگاه ثبت کرده‌اید.']);
   $key=(string)$product->id;$redirect=route('store.product',$product->slug).'#store-discussions';
  } else {
   if(!BlogController::articleExists($data['target_key'])) abort(404);
   if($data['kind']==='review') throw ValidationException::withMessages(['kind'=>'امتیازدهی فقط برای محصولات فعال است.']);
   $data['rating']=null;$key=$data['target_key'];$redirect=route('blog.show',$key).'#store-discussions';
  }
  ContentDiscussion::create(['target_type'=>$data['target_type'],'target_key'=>$key,'kind'=>$data['kind'],'user_id'=>$request->user()->id,'title'=>($data['title'] ?? null) ?: null,'body'=>trim($data['body']),'rating'=>$data['rating']??null,'status'=>'pending']);
  return redirect($redirect)->with('discussion_status','پیام شما ثبت شد و پس از بررسی مدیر در همین صفحه نمایش داده می‌شود.');
 }
 public function adminIndex(Request $request) {
  $status=in_array($request->query('status'),['pending','approved','rejected'],true)?$request->query('status'):'pending';
  $targetType=in_array($request->query('target_type'),['product','article'],true)?$request->query('target_type'):'';
  $base=ContentDiscussion::query()->whereNull('parent_id');
  $stats=['pending'=>(clone $base)->where('status','pending')->count(),'approved'=>(clone $base)->where('status','approved')->count(),'rejected'=>(clone $base)->where('status','rejected')->count()];
  $discussions=(clone $base)->with('user')->where('status',$status)->when($targetType!=='',fn($q)=>$q->where('target_type',$targetType))->latest()->paginate(25)->withQueryString();
  return view('admin.discussions.index',compact('discussions','stats','status','targetType'));
 }
 public function moderate(Request $request,ContentDiscussion $discussion) {
  abort_unless($discussion->parent_id===null,404);$data=$request->validate(['status'=>['required','in:approved,rejected']]);
  $discussion->forceFill(['status'=>$data['status'],'moderator_id'=>$request->user()->id,'moderated_at'=>now()])->save();
  return back()->with('status',$data['status']==='approved'?'محتوا منتشر شد.':'محتوا رد شد و در سایت نمایش داده نمی‌شود.');
 }
 public function reply(Request $request,ContentDiscussion $discussion) {
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);$data=$request->validate(['body'=>['required','string','min:2','max:3000']]);
  ContentDiscussion::create(['target_type'=>$discussion->target_type,'target_key'=>$discussion->target_key,'kind'=>'reply','parent_id'=>$discussion->id,'user_id'=>$request->user()->id,'body'=>trim($data['body']),'status'=>'approved','moderator_id'=>$request->user()->id,'moderated_at'=>now()]);
  return back()->with('status','پاسخ شما ثبت و منتشر شد.');
 }
}