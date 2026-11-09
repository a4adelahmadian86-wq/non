<?php
namespace App\Http\Controllers;
use App\Models\ContentDiscussion;
use App\Models\DiscussionReport;
use App\Models\DiscussionSubscription;
use App\Services\NotificationService;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
class ContentDiscussionController extends Controller {
 private function rejectDiscussionSpam(Request $request,string $targetType,string $targetKey,string $body,?int $exceptId=null): void {
  $links=preg_match_all('~(?:https?://|www\.)~i',$body);
  if($links>2) throw ValidationException::withMessages(['body'=>'برای جلوگیری از تبلیغات ناخواسته، در هر مشارکت حداکثر دو پیوند مجاز است.']);
  $duplicate=ContentDiscussion::query()->where('user_id',$request->user()->id)
   ->where('target_type',$targetType)->where('target_key',$targetKey)
   ->where('body',$body)->where('created_at','>=',now()->subMinutes(10))
   ->when($exceptId!==null,fn($q)=>$q->where('id','!=',$exceptId))->exists();
  if($duplicate) throw ValidationException::withMessages(['body'=>'این متن را به‌تازگی در همین گفت‌وگو فرستاده‌اید؛ از ارسال تکراری خودداری کنید.']);
 }
 private function safeDiscussionRedirect(ContentDiscussion $discussion) {
  return redirect()->to($this->discussionPublicUrl($discussion) ?: route('community.index'));
 }
 /**
  * Notify the moderation team when a new contribution enters the review queue.
  */
 private function notifyModeratorsOfPendingDiscussion(ContentDiscussion $discussion): void {
  $url=route('admin.discussions.index',['status'=>'pending']);
  $kindLabel=match($discussion->kind) {
   'review'=>'دیدگاه محصول','question'=>'پرسش','comment'=>'دیدگاه','reply'=>'پاسخ',
   default=>'مشارکت جدید',
  };
  foreach(User::query()->where('role',User::ROLE_ADMIN)->where('is_blocked',false)->limit(30)->get() as $admin) {
   app(NotificationService::class)->push(
    (int)$admin->id,'discussion_moderation_pending','مشارکت تازه در انتظار بررسی',
    $kindLabel.' — '.mb_substr(trim((string)$discussion->body),0,180),
    ['url'=>$url,'discussion_id'=>$discussion->id,'kind'=>$discussion->kind],
    false,'discussion:pending:'.$discussion->id.':admin:'.$admin->id
   );
  }
 }
 private function notifyModeratorsOfReport(DiscussionReport $report, ContentDiscussion $discussion): void {
  $url=route('admin.discussion-reports.index');
  foreach(User::query()->where('role',User::ROLE_ADMIN)->where('is_blocked',false)->limit(30)->get() as $admin) {
   app(NotificationService::class)->push(
    (int)$admin->id,'discussion_report_received','گزارش تازه برای بررسی',
    'یک مشارکت در بخش گفت‌وگوها گزارش شده است. دلیل: '.$report->reason,
    ['url'=>$url,'report_id'=>$report->id,'discussion_id'=>$discussion->id],
    false,'discussion:report:'.$report->id.':admin:'.$admin->id
   );
  }
 }
 public function store(Request $request) {
  $request->merge(['body'=>is_string($request->input('body'))?trim($request->input('body')):$request->input('body'),'title'=>is_string($request->input('title'))?trim($request->input('title')):$request->input('title')]);
  $data=$request->validate(['target_type'=>['required','in:product,article,reader'],'target_key'=>['required','string','max:240'],'kind'=>['required','in:review,question,comment'],'title'=>['nullable','string','max:180'],'body'=>['required','string','min:10','max:5000'],'rating'=>['nullable','integer','min:1','max:5'],'anchor_page'=>['required_if:target_type,reader','nullable','integer','min:1','max:100000'],'anchor_section'=>['nullable','integer','min:0','max:100000'],'anchor_quote'=>['required_if:target_type,reader','nullable','string','min:1','max:500'],'anchor_start'=>['nullable','integer','min:0','max:10000000'],'anchor_end'=>['nullable','integer','min:0','max:10000000'],'anchor_context'=>['nullable','string','max:500']]);
  $product=null;
  if($data['target_type']==='reader') {
   $product=StoreProduct::published()->findOrFail((int)$data['target_key']);
   if($data['kind']==='review') throw ValidationException::withMessages(['kind'=>'برای بخش مشخصی از فایل فقط دیدگاه یا پرسش ثبت می‌شود.']);
   $data['rating']=null;
   if(isset($data['anchor_start'],$data['anchor_end']) && $data['anchor_end']<$data['anchor_start']) throw ValidationException::withMessages(['anchor_end'=>'محدوده انتخاب‌شده معتبر نیست.']);
   $key=(string)$product->id;
   $redirect=route('store.reader',$product->slug).'#reader-discussions';
  } elseif($data['target_type']==='product') {
   $product=StoreProduct::published()->findOrFail((int)$data['target_key']);
   if(!in_array($data['kind'],['review','question'],true)) throw ValidationException::withMessages(['kind'=>'برای محصول فقط دیدگاه یا پرسش مجاز است.']);
   if($data['kind']==='review' && !($data['rating'] ?? null)) throw ValidationException::withMessages(['rating'=>'برای ثبت دیدگاه، امتیاز یک تا پنج را انتخاب کنید.']);
   if($data['kind']!=='review') $data['rating']=null;
   $key=(string)$product->id;$redirect=route('store.product',$product->slug).'#store-discussions';
  } else {
   if(!BlogController::articleExists($data['target_key'])) abort(404);
   if($data['kind']==='review') throw ValidationException::withMessages(['kind'=>'امتیازدهی فقط برای محصولات فعال است.']);
   $data['rating']=null;$key=$data['target_key'];$redirect=route('blog.show',$key).'#store-discussions';
  }
  $this->rejectDiscussionSpam($request,$data['target_type'],$key,trim($data['body']));
  $discussion=DB::transaction(function() use($request,$data,$key,$product) {
   if($data['target_type']==='product') {
    $lockedProduct=StoreProduct::published()->whereKey($product->id)->lockForUpdate()->firstOrFail();
    if($data['kind']==='review' && ContentDiscussion::query()->where('target_type','product')->where('target_key',(string)$lockedProduct->id)->where('kind','review')->where('user_id',$request->user()->id)->where('status','!=','rejected')->exists()) {
     throw ValidationException::withMessages(['body'=>'برای این محصول قبلاً دیدگاه ثبت کرده‌اید.']);
    }
   }
   $item=ContentDiscussion::create(['target_type'=>$data['target_type'],'target_key'=>$key,'kind'=>$data['kind'],'user_id'=>$request->user()->id,'title'=>($data['title'] ?? null) ?: null,'body'=>trim($data['body']),'rating'=>$data['rating']??null,'anchor_page'=>$data['target_type']==='reader'?(int)$data['anchor_page']:null,'anchor_section'=>$data['target_type']==='reader'?(isset($data['anchor_section'])?(int)$data['anchor_section']:null):null,'anchor_quote'=>$data['target_type']==='reader'?trim((string)$data['anchor_quote']):null,'anchor_start'=>$data['target_type']==='reader'?($data['anchor_start']??null):null,'anchor_end'=>$data['target_type']==='reader'?($data['anchor_end']??null):null,'anchor_context'=>$data['target_type']==='reader'?trim((string)($data['anchor_context']??''))?:null:null,'status'=>'pending']);
   DiscussionSubscription::firstOrCreate(['discussion_id'=>$item->id,'user_id'=>$request->user()->id]);
   return $item;
  });
  $this->notifyModeratorsOfPendingDiscussion($discussion);
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
  return $this->safeDiscussionRedirect($discussion)->with('discussion_status','واکنش شما ثبت شد.');
 }
 public function report(Request $request, ContentDiscussion $discussion) {
  abort_unless($discussion->status==='approved',404);
  abort_if((int)$discussion->user_id===(int)$request->user()->id,403);
  $data=$request->validate(['reason'=>['required','in:spam,abuse,harassment,misinformation,other'],'details'=>['nullable','string','max:1000']]);
  $report=DiscussionReport::firstOrCreate(
   ['discussion_id'=>$discussion->id,'reporter_id'=>$request->user()->id],
   ['reason'=>$data['reason'],'details'=>trim((string)($data['details']??'')) ?: null,'status'=>'pending']
  );
  if($report->wasRecentlyCreated) $this->notifyModeratorsOfReport($report,$discussion);
  return $this->safeDiscussionRedirect($discussion)->with('discussion_status',$report->wasRecentlyCreated?'گزارش شما برای بررسی به تیم مدیریت ارسال شد.':'گزارش قبلی شما برای این محتوا ثبت شده است.');
 }
 public function toggleFollow(Request $request, ContentDiscussion $discussion) {
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);
  $subscription=DiscussionSubscription::query()->where('discussion_id',$discussion->id)->where('user_id',$request->user()->id)->first();
  if($subscription){$subscription->delete();$message='اعلان پاسخ‌های این گفت‌وگو برای شما غیرفعال شد.';}
  else {DiscussionSubscription::create(['discussion_id'=>$discussion->id,'user_id'=>$request->user()->id]);$message='این گفت‌وگو را دنبال می‌کنید؛ پاسخ‌های جدید به شما اطلاع داده می‌شود.';}
  return $this->safeDiscussionRedirect($discussion)->with('discussion_status',$message);
 }
 private function notifyDiscussionFollowers(ContentDiscussion $reply): void {
  if(!$reply->parent_id) return;
  $parent=ContentDiscussion::query()->with('subscriptions')->find($reply->parent_id);
  if(!$parent) return;
  $url=in_array($parent->target_type,['product','reader'],true)
   ? (($product=StoreProduct::find((int)$parent->target_key)) ? route($parent->target_type==='reader'?'store.reader':'store.product',$product->slug) : null)
   : route('blog.show',$parent->target_key);
  if(!$url) return;
  $recipients=$parent->subscriptions->pluck('user_id')->filter()->unique()->reject(fn($id)=>(int)$id===(int)$reply->user_id);
  foreach($recipients as $userId){
   app(NotificationService::class)->push((int)$userId,'discussion_reply','پاسخ جدید در گفت‌وگوی دنبال‌شده','برای گفت‌وگویی که دنبال می‌کنید پاسخ تازه‌ای منتشر شد.',['url'=>$url.'#discussion-'.$parent->id,'discussion_id'=>$parent->id,'reply_id'=>$reply->id]);
  }
 }
 public function publicReply(Request $request, ContentDiscussion $discussion) {
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);
  $request->merge(['body'=>is_string($request->input('body'))?trim($request->input('body')):$request->input('body')]);
  $data=$request->validate(['body'=>['required','string','min:2','max:3000']]);
  $this->rejectDiscussionSpam($request,$discussion->target_type,$discussion->target_key,trim($data['body']));
  $reply=ContentDiscussion::create([
   'target_type'=>$discussion->target_type,
   'target_key'=>$discussion->target_key,
   'kind'=>'reply',
   'parent_id'=>$discussion->id,
   'user_id'=>$request->user()->id,
   'body'=>trim($data['body']),
   'status'=>'pending',
  ]);
  $this->notifyModeratorsOfPendingDiscussion($reply);
  return $this->safeDiscussionRedirect($discussion)->with('discussion_status','پاسخ شما ثبت شد و پس از بررسی مدیر منتشر می‌شود.');
 }


 public function myDiscussions(Request $request) {
  $status=in_array($request->query('status'),['pending','approved','rejected'],true)?$request->query('status'):'all';
  $kind=in_array($request->query('kind'),['review','question','comment','reply'],true)?$request->query('kind'):'';
  $targetType=in_array($request->query('target_type'),['product','article','reader'],true)?$request->query('target_type'):'';
  $search=trim((string)$request->query('q',''));
  $search=mb_substr($search,0,100);
  $base=ContentDiscussion::query()->where('user_id',$request->user()->id);
  $stats=[
   'all'=>(clone $base)->count(),
   'pending'=>(clone $base)->where('status','pending')->count(),
   'approved'=>(clone $base)->where('status','approved')->count(),
   'rejected'=>(clone $base)->where('status','rejected')->count(),
  ];
  $discussions=(clone $base)->with('parent')
   ->when($status!=='all',fn($q)=>$q->where('status',$status))
   ->when($kind!=='',fn($q)=>$q->where('kind',$kind))
   ->when($targetType!=='',fn($q)=>$q->where('target_type',$targetType))
   ->when($search!=='',fn($q)=>$q->where(function($w) use($search){$w->where('body','like','%'.$search.'%')->orWhere('title','like','%'.$search.'%');}))
   ->latest()->paginate(15)->withQueryString();
  $discussions->getCollection()->each(function($item){
   if(in_array($item->target_type,['product','reader'],true)){
    $product=StoreProduct::find((int)$item->target_key);
    $item->setAttribute('target_url',$product?route($item->target_type==='reader'?'store.reader':'store.product',$product->slug):null);
    $item->setAttribute('target_label',$product?->title ?: 'محصول حذف‌شده');
   }else{
    $item->setAttribute('target_url',BlogController::articleExists($item->target_key)?route('blog.show',$item->target_key):null);
    $item->setAttribute('target_label','مقاله');
   }
   $anchor=$item->parent_id ? $item->parent_id : $item->id;
   $item->setAttribute('discussion_url',$item->target_url ? $item->target_url.'#discussion-'.$item->id : null);
   $item->setAttribute('parent_url',$item->target_url ? $item->target_url.'#discussion-'.$anchor : null);
  });
  return view('community.mine',compact('discussions','stats','status','kind','targetType','search'));
 }
 public function updateOwn(Request $request,ContentDiscussion $discussion) {
  $body=is_string($request->input('body'))?trim($request->input('body')):$request->input('body');
  $title=is_string($request->input('title'))?trim($request->input('title')):$request->input('title');
  $rules=['body'=>['required','string',$discussion->kind==='reply'?'min:2':'min:10',$discussion->kind==='reply'?'max:3000':'max:5000']];
  if($discussion->kind!=='reply') $rules['title']=['nullable','string','max:180'];
  if($discussion->kind==='review') $rules['rating']=['required','integer','min:1','max:5'];
  $data=$request->validate($rules);
  DB::transaction(function() use($request,$discussion,$data,$title,$body) {
   $locked=ContentDiscussion::query()->whereKey($discussion->id)->lockForUpdate()->firstOrFail();
   abort_unless((int)$locked->user_id===(int)$request->user()->id,404);
   abort_unless($locked->status==='pending',409,'پس از تصمیم تیم بررسی، ویرایش این مشارکت امکان‌پذیر نیست.');
   $this->rejectDiscussionSpam($request,$locked->target_type,$locked->target_key,trim((string)$body),$locked->id);
   $locked->forceFill([
    'body'=>trim((string)$body),
    'title'=>$locked->kind==='reply'?null:(trim((string)$title)?:null),
    'rating'=>$locked->kind==='review'?(int)$data['rating']:null,
   ])->save();
  });
  return back()->with('discussion_status','اصلاحات مشارکت شما ذخیره شد و در صف بررسی باقی ماند.');
 }
 public function withdrawOwn(Request $request, ContentDiscussion $discussion) {
  DB::transaction(function() use($request,$discussion){
   $locked=ContentDiscussion::query()->whereKey($discussion->id)->lockForUpdate()->firstOrFail();
   abort_unless((int)$locked->user_id===(int)$request->user()->id,404);
   abort_unless($locked->status==='pending',409,'فقط مشارکت‌های در انتظار بررسی قابل لغو هستند.');
   $locked->delete();
  });
  return back()->with('discussion_status','مشارکت در انتظار بررسی شما لغو شد.');
 }
 public function communityIndex(Request $request) {
  $search=mb_substr(trim((string)$request->query('q','')),0,80);
  $kind=in_array($request->query('kind'),['review','question','comment','reply'],true)?$request->query('kind'):'';
  $sort=in_array($request->query('sort'),['helpful','active'],true)?$request->query('sort'):'helpful';
  $query=User::query()->select('users.id','users.name','users.created_at')
   ->join('content_discussions','content_discussions.user_id','=','users.id')
   ->where('content_discussions.status','approved')
   ->when($search!=='',fn($q)=>$q->where('users.name','like','%'.$search.'%'))
   ->when($kind!=='',fn($q)=>$q->whereExists(function($sub) use($kind){
    $sub->selectRaw('1')->from('content_discussions as member_kind')
     ->whereColumn('member_kind.user_id','users.id')
     ->where('member_kind.status','approved')->where('member_kind.kind',$kind);
   }))
   ->groupBy('users.id','users.name','users.created_at')
   ->selectRaw('COUNT(content_discussions.id) as contributions')
   ->selectSub(DB::table('discussion_reactions')->join('content_discussions as dc','dc.id','=','discussion_reactions.discussion_id')->selectRaw('COUNT(*)')->whereColumn('dc.user_id','users.id')->where('dc.status','approved')->where('discussion_reactions.type','like'),'helpful');
  if($sort==='active') $query->orderByDesc('contributions')->orderByDesc('helpful');
  else $query->orderByDesc('helpful')->orderByDesc('contributions');
  $members=$query->orderBy('users.name')->paginate(24)->withQueryString();
  return view('community.index',compact('members','search','kind','sort'));
 }
 public function member(User $user) {
  $approved=ContentDiscussion::query()->where('user_id',$user->id)->where('status','approved');
  $stats=[
   'contributions'=>(clone $approved)->count(),
   'reviews'=>(clone $approved)->where('kind','review')->count(),
   'questions'=>(clone $approved)->where('kind','question')->count(),
   'replies'=>(clone $approved)->where('kind','reply')->count(),
   'helpful'=>DB::table('discussion_reactions')->join('content_discussions','content_discussions.id','=','discussion_reactions.discussion_id')->where('content_discussions.user_id',$user->id)->where('content_discussions.status','approved')->where('discussion_reactions.type','like')->count(),
  ];
  abort_unless($stats['contributions']>0,404);
  $activity=(clone $approved)->latest()->limit(12)->get();
  $activity->each(function($item){
   if(in_array($item->target_type,['product','reader'],true)){
    $product=StoreProduct::find((int)$item->target_key);
    $item->setAttribute('target_url',$product?route($item->target_type==='reader'?'store.reader':'store.product',$product->slug):null);
    $item->setAttribute('target_label',$product?->title ?: 'محصول');
   }else{
    $item->setAttribute('target_url',BlogController::articleExists($item->target_key)?route('blog.show',$item->target_key):null);
    $item->setAttribute('target_label','مقاله');
   }
  });
  return view('community.member',compact('user','stats','activity'));
 }
 public function adminReports(Request $request) {
  $status=in_array($request->query('status'),['pending','resolved','dismissed'],true)?$request->query('status'):'pending';
  $base=DiscussionReport::query();
  $stats=['pending'=>(clone $base)->where('status','pending')->count(),'resolved'=>(clone $base)->where('status','resolved')->count(),'dismissed'=>(clone $base)->where('status','dismissed')->count()];
  $reports=(clone $base)->with(['discussion.user','reporter','reviewer'])->where('status',$status)->latest()->paginate(25)->withQueryString();
  return view('admin.discussion-reports.index',compact('reports','stats','status'));
 }
 public function moderateReport(Request $request, DiscussionReport $report) {
  $data=$request->validate(['action'=>['required','in:dismiss,remove_content'],'review_note'=>['nullable','string','max:1000']]);
  $result=DB::transaction(function() use($request,$report,$data) {
   $locked=DiscussionReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
   abort_unless($locked->status==='pending',409,'این گزارش قبلاً رسیدگی شده است.');
   $discussion=ContentDiscussion::query()->whereKey($locked->discussion_id)->lockForUpdate()->firstOrFail();
   $reviewNote=trim((string)($data['review_note']??'')) ?: null;
   $reviewedAt=now(); $wasRemoved=false;
   if($data['action']==='remove_content') {
    if($discussion->status!=='rejected') {
     $previous=$discussion->status;
     $moderationNote=$reviewNote ?: 'محتوا پس از بررسی گزارش کاربران از انتشار خارج شد.';
     $discussion->forceFill(['status'=>'rejected','moderator_id'=>$request->user()->id,'moderated_at'=>$reviewedAt,'moderation_note'=>$moderationNote])->save();
     \App\Models\DiscussionModerationEvent::create(['discussion_id'=>$discussion->id,'moderator_id'=>$request->user()->id,'from_status'=>$previous,'to_status'=>'rejected','note'=>$moderationNote]);
     $wasRemoved=true;
    }
    DiscussionReport::query()->where('discussion_id',$discussion->id)->where('status','pending')->update(['status'=>'resolved','reviewed_by'=>$request->user()->id,'reviewed_at'=>$reviewedAt,'review_note'=>$reviewNote]);
   } else {
    $locked->forceFill(['status'=>'dismissed','reviewed_by'=>$request->user()->id,'reviewed_at'=>$reviewedAt,'review_note'=>$reviewNote])->save();
   }
   return ['removed'=>$wasRemoved?$discussion:null];
  });
  if($result['removed']) $this->notifyModerationDecision($result['removed'],'rejected');
  return back()->with('status',$data['action']==='remove_content'?'محتوا از سایت برداشته شد؛ همه گزارش‌های باز مرتبط نیز مختومه و سوابق رسیدگی ثبت شدند.':'گزارش بررسی و مختومه شد؛ یادداشت رسیدگی ثبت شد.');
 }
 public function adminIndex(Request $request) {
  $status=in_array($request->query('status'),['pending','approved','rejected'],true)?$request->query('status'):'pending';
  $targetType=in_array($request->query('target_type'),['product','article'],true)?$request->query('target_type'):'';
  $kind=in_array($request->query('kind'),['review','question','comment','reply'],true)?$request->query('kind'):'';
  $search=mb_substr(trim((string)$request->query('q','')),0,100);
  $base=ContentDiscussion::query();
  $stats=['pending'=>(clone $base)->where('status','pending')->count(),'approved'=>(clone $base)->where('status','approved')->count(),'rejected'=>(clone $base)->where('status','rejected')->count()];
  $discussions=(clone $base)->with(['user','parent.user','moderationEvents.moderator'])->withCount('reports')->where('status',$status)
   ->when($targetType!=='',fn($q)=>$q->where('target_type',$targetType))
   ->when($kind!=='',fn($q)=>$q->where('kind',$kind))
   ->when($search!=='',fn($q)=>$q->where(function($w) use($search){$w->where('body','like','%'.$search.'%')->orWhere('title','like','%'.$search.'%')->orWhereHas('user',fn($u)=>$u->where('name','like','%'.$search.'%'));}))
   ->latest()->paginate(25)->withQueryString();
  $items=$discussions->getCollection();
  $productIds=$items->where('target_type','product')->pluck('target_key')->map(fn($key)=>(int)$key)->filter()->unique()->values();
  $products=$productIds->isNotEmpty()
   ? StoreProduct::query()->whereIn('id',$productIds)->get(['id','title','slug'])->keyBy(fn($product)=>(string)$product->id)
   : collect();
  $items->each(function($item) use ($products) {
   if($item->target_type==='product') {
    $product=$products->get((string)$item->target_key);
    $item->setAttribute('target_label',$product?->title ?: 'محصول حذف‌شده');
    $item->setAttribute('target_url',$product?->slug ? route('store.product',$product->slug) : null);
   } else {
    $exists=BlogController::articleExists($item->target_key);
    $item->setAttribute('target_label',$exists ? 'مقاله · '.$item->target_key : 'مقاله ناموجود');
    $item->setAttribute('target_url',$exists ? route('blog.show',$item->target_key) : null);
   }
  });
  return view('admin.discussions.index',compact('discussions','stats','status','targetType','kind','search'));
 }
 private function discussionPublicUrl(ContentDiscussion $discussion): ?string {
  if(in_array($discussion->target_type,['product','reader'],true)) {
   $product=StoreProduct::find((int)$discussion->target_key);
   $base=$product?route($discussion->target_type==='reader'?'store.reader':'store.product',$product->slug):null;
  } else {
   $base=BlogController::articleExists($discussion->target_key)?route('blog.show',$discussion->target_key):null;
  }
  return $base ? $base.'#discussion-'.$discussion->id : null;
 }
 private function notifyModerationDecision(ContentDiscussion $discussion,string $status): void {
  if(!$discussion->user_id) return;
  $url=$this->discussionPublicUrl($discussion);
  $approved=$status==='approved';
  app(NotificationService::class)->push((int)$discussion->user_id,
   $approved?'discussion_approved':'discussion_rejected',
   $approved?'مشارکت شما منتشر شد':'نتیجه بررسی مشارکت شما',
   $approved?'مشارکت شما پس از بررسی مدیر در فراست منتشر شد.':('مشارکت شما منتشر نشد.'.($discussion->moderation_note?' دلیل: '.$discussion->moderation_note:'')),
   array_filter(['url'=>$url,'discussion_id'=>$discussion->id,'status'=>$status],fn($v)=>$v!==null)
  );
 }
 public function moderate(Request $request,ContentDiscussion $discussion) {
  $data=$request->validate(['status'=>['required','in:approved,rejected'],'moderation_note'=>['nullable','string','max:1000']]);
  $result=DB::transaction(function() use($request,$discussion,$data) {
   $locked=ContentDiscussion::query()->whereKey($discussion->id)->lockForUpdate()->firstOrFail();
   $previous=$locked->status;
   if($previous===$data['status']) return ['changed'=>false,'discussion'=>$locked];
   $note=trim((string)($data['moderation_note']??''));
   $locked->forceFill([
    'status'=>$data['status'],'moderator_id'=>$request->user()->id,'moderated_at'=>now(),
    'moderation_note'=>$note!==''?$note:null,
   ])->save();
   \App\Models\DiscussionModerationEvent::create([
    'discussion_id'=>$locked->id,'moderator_id'=>$request->user()->id,
    'from_status'=>$previous,'to_status'=>$data['status'],'note'=>$note!==''?$note:null,
   ]);
   return ['changed'=>true,'discussion'=>$locked];
  });
  if($result['changed']) {
   $this->notifyModerationDecision($result['discussion'],$data['status']);
   if($data['status']==='approved' && $result['discussion']->kind==='reply') $this->notifyDiscussionFollowers($result['discussion']);
  }
  return back()->with('status',$data['status']==='approved'?'محتوا منتشر شد و نتیجه به نویسنده اطلاع داده شد.':'محتوا رد شد و نتیجه به نویسنده اطلاع داده شد.');
 }
 public function reply(Request $request,ContentDiscussion $discussion) {
  $request->merge(['body'=>is_string($request->input('body'))?trim($request->input('body')):$request->input('body')]);
  abort_unless($discussion->parent_id===null && $discussion->status==='approved',404);$data=$request->validate(['body'=>['required','string','min:2','max:3000']]);
  $reply=ContentDiscussion::create(['target_type'=>$discussion->target_type,'target_key'=>$discussion->target_key,'kind'=>'reply','parent_id'=>$discussion->id,'user_id'=>$request->user()->id,'body'=>trim($data['body']),'status'=>'approved','moderator_id'=>$request->user()->id,'moderated_at'=>now()]);
  $this->notifyDiscussionFollowers($reply);
  return back()->with('status','پاسخ شما ثبت و منتشر شد.');
 }
}