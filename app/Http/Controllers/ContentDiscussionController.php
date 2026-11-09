<?php
namespace App\Http\Controllers;
use App\Models\ContentDiscussion;
use App\Models\DiscussionReport;
use App\Models\DiscussionSubscription;
use App\Services\NotificationService;
use App\Models\StoreProduct;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
class ContentDiscussionController extends Controller {
 public function store(Request $request) {
  $request->merge(['body'=>is_string($request->input('body'))?trim($request->input('body')):$request->input('body'),'title'=>is_string($request->input('title'))?trim($request->input('title')):$request->input('title')]);
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
  $discussion=ContentDiscussion::create(['target_type'=>$data['target_type'],'target_key'=>$key,'kind'=>$data['kind'],'user_id'=>$request->user()->id,'title'=>($data['title'] ?? null) ?: null,'body'=>trim($data['body']),'rating'=>$data['rating']??null,'status'=>'pending']);
  DiscussionSubscription::firstOrCreate(['discussion_id'=>$discussion->id,'user_id'=>$request->user()->id]);
  return redirect($redirect)->with('discussion_status','پیام شما ثبت شد و پس از بررسی مدیر در همین صفحه نمایش داده می‌شود.');
 }
 public function react(Request $request, ContentDiscussion $discussion) {
  $data=$request->validate(['type'=>['required','in:like,dislike']]);
  abort_unless($discussion->status==='approved',404);
  abort_if((int)$discussion->user_id===(int)$request->user()->id,403);
  DB::transaction(function() use ($discussion,$request,$data) {
   ContentDiscussion::query()->whereKey($discussion->id)->lockForUpdate()->firstOrFail();
   $reaction=\App\Models\DiscussionReaction::query()->where('discussion_id',$discussion->id)->where('user_id',$request->user()->id)->lockForUpdate()->first();
   if($reaction && $reaction->type===$data['type']) { $reaction->delete(); return; }
   if($reaction) { $reaction->type=$data['type']; $reaction->save(); return; }
   \App\Models\DiscussionReaction::create(['discussion_id'=>$discussion->id,'user_id'=>$request->user()->id,'type'=>$data['type']]);
  });
  return redirect()->to(url()->previous().'#store-discussions')->with('discussion_status','واکنش شما ثبت شد.');
 }
 public function report(Request $request, ContentDiscussion $discussion) {
  abort_unless($discussion->status==='approved',404);
  abort_if((int)$discussion->user_id===(int)$request->user()->id,403);
  $data=$request->validate(['reason'=>['required','in:spam,abuse,harassment,misinformation,other'],'details'=>['nullable','string','max:1000']]);
  $report=DiscussionReport::firstOrCreate(
   ['discussion_id'=>$discussion->id,'reporter_id'=>$request->user()->id],
   ['reason'=>$data['reason'],'details'=>trim((string)($data['details']??'')) ?: null,'status'=>'pending']
  );
  return back()->with('discussion_status',$report->wasRecentlyCreated?'گزارش شما برای بررسی به تیم مدیریت ارسال شد.':'گزارش قبلی شما برای این محتوا ثبت شده است.');
 }
 public function toggleFollow(Request $request, ContentDiscussion $discussion) {
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);
  $subscription=DiscussionSubscription::query()->where('discussion_id',$discussion->id)->where('user_id',$request->user()->id)->first();
  if($subscription){$subscription->delete();$message='اعلان پاسخ‌های این گفت‌وگو برای شما غیرفعال شد.';}
  else {DiscussionSubscription::create(['discussion_id'=>$discussion->id,'user_id'=>$request->user()->id]);$message='این گفت‌وگو را دنبال می‌کنید؛ پاسخ‌های جدید به شما اطلاع داده می‌شود.';}
  return back()->with('discussion_status',$message);
 }
 private function notifyDiscussionFollowers(ContentDiscussion $reply): void {
  if(!$reply->parent_id) return;
  $parent=ContentDiscussion::query()->with('subscriptions')->find($reply->parent_id);
  if(!$parent) return;
  $url=$parent->target_type==='product'
   ? (($product=StoreProduct::find((int)$parent->target_key)) ? route('store.product',$product->slug) : null)
   : route('blog.show',$parent->target_key);
  if(!$url) return;
  $recipients=$parent->subscriptions->pluck('user_id')->push($parent->user_id)->filter()->unique()->reject(fn($id)=>(int)$id===(int)$reply->user_id);
  foreach($recipients as $userId){
   app(NotificationService::class)->push((int)$userId,'discussion_reply','پاسخ جدید در گفت‌وگوی دنبال‌شده','برای گفت‌وگویی که دنبال می‌کنید پاسخ تازه‌ای منتشر شد.',['url'=>$url.'#discussion-'.$parent->id,'discussion_id'=>$parent->id,'reply_id'=>$reply->id]);
  }
 }
 public function publicReply(Request $request, ContentDiscussion $discussion) {
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);
  $request->merge(['body'=>is_string($request->input('body'))?trim($request->input('body')):$request->input('body')]);
  $data=$request->validate(['body'=>['required','string','min:2','max:3000']]);
  ContentDiscussion::create([
   'target_type'=>$discussion->target_type,
   'target_key'=>$discussion->target_key,
   'kind'=>'reply',
   'parent_id'=>$discussion->id,
   'user_id'=>$request->user()->id,
   'body'=>trim($data['body']),
   'status'=>'pending',
  ]);
  return back()->with('discussion_status','پاسخ شما ثبت شد و پس از بررسی مدیر منتشر می‌شود.');
 }
 public function adminReports(Request $request) {
  $status=in_array($request->query('status'),['pending','resolved','dismissed'],true)?$request->query('status'):'pending';
  $base=DiscussionReport::query();
  $stats=['pending'=>(clone $base)->where('status','pending')->count(),'resolved'=>(clone $base)->where('status','resolved')->count(),'dismissed'=>(clone $base)->where('status','dismissed')->count()];
  $reports=(clone $base)->with(['discussion.user','reporter','reviewer'])->where('status',$status)->latest()->paginate(25)->withQueryString();
  return view('admin.discussion-reports.index',compact('reports','stats','status'));
 }
 public function moderateReport(Request $request, DiscussionReport $report) {
  $data=$request->validate(['action'=>['required','in:dismiss,remove_content']]);
  DB::transaction(function() use($request,$report,$data) {
   $locked=DiscussionReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
   $discussion=ContentDiscussion::query()->whereKey($locked->discussion_id)->lockForUpdate()->firstOrFail();
   if($data['action']==='remove_content') {
    $discussion->forceFill(['status'=>'rejected','moderator_id'=>$request->user()->id,'moderated_at'=>now()])->save();
    $locked->status='resolved';
   } else { $locked->status='dismissed'; }
   $locked->reviewed_by=$request->user()->id;
   $locked->reviewed_at=now();
   $locked->save();
  });
  return back()->with('status',$data['action']==='remove_content'?'محتوا از سایت برداشته و گزارش رسیدگی شد.':'گزارش بررسی و مختومه شد.');
 }
 public function adminIndex(Request $request) {
  $status=in_array($request->query('status'),['pending','approved','rejected'],true)?$request->query('status'):'pending';
  $targetType=in_array($request->query('target_type'),['product','article'],true)?$request->query('target_type'):'';
  $base=ContentDiscussion::query();
  $stats=['pending'=>(clone $base)->where('status','pending')->count(),'approved'=>(clone $base)->where('status','approved')->count(),'rejected'=>(clone $base)->where('status','rejected')->count()];
  $discussions=(clone $base)->with(['user','parent.user'])->where('status',$status)->when($targetType!=='',fn($q)=>$q->where('target_type',$targetType))->latest()->paginate(25)->withQueryString();
  $items=$discussions->getCollection();
  $productIds=$items->where('target_type','product')->pluck('target_key')->map(fn($key)=>(int)$key)->filter()->unique()->values();
  $products=$productIds->isNotEmpty()
   ? StoreProduct::query()->whereIn('id',$productIds)->get(['id','title','slug'])->keyBy(fn($product)=>(string)$product->id)
   : collect();
  $articles=collect(BlogController::articles());
  $items->each(function($item) use ($products,$articles) {
   if($item->target_type==='product') {
    $product=$products->get((string)$item->target_key);
    $item->setAttribute('target_label',$product?->title ?: 'محصول حذف‌شده');
    $item->setAttribute('target_url',$product?->slug ? route('store.product',$product->slug) : null);
   } else {
    $article=$articles->get($item->target_key);
    $item->setAttribute('target_label',$article['title'] ?? 'مقاله ناموجود');
    $item->setAttribute('target_url',$article ? route('blog.show',$item->target_key) : null);
   }
  });
  return view('admin.discussions.index',compact('discussions','stats','status','targetType'));
 }
 public function moderate(Request $request,ContentDiscussion $discussion) {
  $data=$request->validate(['status'=>['required','in:approved,rejected']]);
  $wasApproved=$discussion->status==='approved';
  $discussion->forceFill(['status'=>$data['status'],'moderator_id'=>$request->user()->id,'moderated_at'=>now()])->save();
  if(!$wasApproved && $data['status']==='approved' && $discussion->kind==='reply') $this->notifyDiscussionFollowers($discussion);
  return back()->with('status',$data['status']==='approved'?'محتوا منتشر شد.':'محتوا رد شد و در سایت نمایش داده نمی‌شود.');
 }
 public function reply(Request $request,ContentDiscussion $discussion) {
  $request->merge(['body'=>is_string($request->input('body'))?trim($request->input('body')):$request->input('body')]);
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);$data=$request->validate(['body'=>['required','string','min:2','max:3000']]);
  $reply=ContentDiscussion::create(['target_type'=>$discussion->target_type,'target_key'=>$discussion->target_key,'kind'=>'reply','parent_id'=>$discussion->id,'user_id'=>$request->user()->id,'body'=>trim($data['body']),'status'=>'approved','moderator_id'=>$request->user()->id,'moderated_at'=>now()]);
  $this->notifyDiscussionFollowers($reply);
  return back()->with('status','پاسخ شما ثبت و منتشر شد.');
 }
}