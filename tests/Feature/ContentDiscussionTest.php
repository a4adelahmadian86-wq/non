<?php
namespace Tests\Feature;
use App\Models\ContentDiscussion;
use App\Models\Order;
use App\Models\StoreOrderItem;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ContentDiscussionTest extends TestCase {
 use RefreshDatabase;
 private function product(): StoreProduct {$c=StoreCategory::create(['name'=>'دسته گفت‌وگو','slug'=>'discussion-test-category','is_active'=>true]);return StoreProduct::create(['category_id'=>$c->id,'title'=>'محصول گفت‌وگو','slug'=>'discussion-test-product','sku'=>'DISCUSSION-TEST-001','type'=>'digital_file','price_rials'=>100000,'status'=>'published','published_at'=>now()]);}
 private function user(string $mobile,string $role='user'): User {return User::create(['name'=>$role==='admin'?'مدیر فراست':'کاربر آزمایشی','mobile'=>$mobile,'password'=>'secret','role'=>$role]);}
 public function test_new_discussions_and_reports_notify_moderators_through_the_central_ledger(): void {
  $product=$this->product();$author=$this->user('09123456901');$admin=$this->user('09123456902','admin');$reporter=$this->user('09123456903');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$product->id,'kind'=>'question','body'=>'آیا این محصول برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $discussion=ContentDiscussion::firstOrFail();
  $this->assertDatabaseHas('farast_notifications',['user_id'=>$admin->id,'type'=>'discussion_moderation_pending','dedupe_key'=>'discussion:pending:'.$discussion->id.':admin:'.$admin->id]);

  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$discussion),['status'=>'approved'])->assertRedirect();
  $this->actingAs($reporter)->post(route('discussions.report',$discussion),['reason'=>'spam','details'=>'گزارش آزمایشی برای بررسی مدیر'])->assertRedirect();
  $report=\App\Models\DiscussionReport::firstOrFail();
  $this->assertDatabaseHas('farast_notifications',['user_id'=>$admin->id,'type'=>'discussion_report_received','dedupe_key'=>'discussion:report:'.$report->id.':admin:'.$admin->id]);
 }
 public function test_product_question_moderation_and_admin_reply(): void {
  $p=$this->product();$u=$this->user('09123456781');
  $this->actingAs($u)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','title'=>'فرمت فایل چیست؟','body'=>'می‌خواهم بدانم این فایل با چه فرمتی ارائه می‌شود؟'])->assertRedirect(route('store.product',$p->slug).'#store-discussions');
  $d=ContentDiscussion::firstOrFail();$this->assertSame('pending',$d->status);$this->get(route('store.product',$p->slug))->assertDontSee('می‌خواهم بدانم این فایل');
  $a=$this->user('09123456782','admin');$this->actingAs($a)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->actingAs($a)->post(route('admin.discussions.reply',$d),['body'=>'اطلاعات فرمت در مشخصات محصول درج شده است.'])->assertRedirect();
  $this->get(route('store.product',$p->slug))->assertOk()->assertSee('می‌خواهم بدانم این فایل')->assertSee('اطلاعات فرمت در مشخصات محصول درج شده است.');
 }
 public function test_blog_comment_is_hidden_until_approved(): void {
  $u=$this->user('09123456783');$this->actingAs($u)->post(route('discussions.store'),['target_type'=>'article','target_key'=>'workflow-writing','kind'=>'comment','body'=>'این راهنمای مرحله‌بندی نوشتن برای من بسیار کاربردی بود.'])->assertRedirect(route('blog.show','workflow-writing').'#store-discussions');
  $d=ContentDiscussion::firstOrFail();$this->assertSame('pending',$d->status);$this->get(route('blog.show','workflow-writing'))->assertDontSee('این راهنمای مرحله‌بندی نوشتن');
  $a=$this->user('09123456784','admin');$this->actingAs($a)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();$this->get(route('blog.show','workflow-writing'))->assertOk()->assertSee('این راهنمای مرحله‌بندی نوشتن');
 }
 public function test_guests_cannot_submit_and_product_reviews_need_rating(): void {
  $p=$this->product();$this->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این محصول برای استفاده آموزشی مناسب است؟'])->assertRedirect(route('login'));
  $u=$this->user('09123456785');$this->actingAs($u)->from(route('store.product',$p->slug))->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'review','body'=>'تجربه من از استفاده از این محصول مثبت بوده است.'])->assertSessionHasErrors('rating');
 }
 public function test_product_review_is_unique_per_user_and_approved_rating_is_displayed(): void {
  $p=$this->product();$u=$this->user('09123456786');
  $payload=['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'review','title'=>'تجربه خرید','body'=>'این محصول برای نیاز من کاربردی و باکیفیت بوده است.','rating'=>5];
  $this->actingAs($u)->post(route('discussions.store'),$payload)->assertRedirect();
  $this->assertDatabaseHas('content_discussions',['target_type'=>'product','target_key'=>(string)$p->id,'user_id'=>$u->id,'kind'=>'review','status'=>'pending','rating'=>5]);
  $this->from(route('store.product',$p->slug))->post(route('discussions.store'),$payload)->assertSessionHasErrors('body');
  $this->assertDatabaseCount('content_discussions',1);
  $d=ContentDiscussion::firstOrFail();$a=$this->user('09123456787','admin');
  $this->actingAs($a)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->get(route('store.product',$p->slug))->assertOk()->assertSee('5.0')->assertSee('تجربه خرید');
 }
 public function test_blog_question_is_moderated_and_only_approved_content_is_public(): void {
  $u=$this->user('09123456788');
  $this->actingAs($u)->post(route('discussions.store'),['target_type'=>'article','target_key'=>'workflow-writing','kind'=>'question','title'=>'درباره روند کار','body'=>'آیا می‌توان این روند را برای پروژه‌های تیمی هم استفاده کرد؟'])->assertRedirect();
  $d=ContentDiscussion::firstOrFail();$this->assertSame('pending',$d->status);
  $this->get(route('blog.show','workflow-writing'))->assertDontSee('پروژه‌های تیمی');
  $a=$this->user('09123456789','admin');
  $this->actingAs($a)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->get(route('blog.show','workflow-writing'))->assertOk()->assertSee('پروژه‌های تیمی')->assertSee('پرسش');
 }


 public function test_discussion_reactions_can_be_added_changed_and_removed_once_per_user(): void {
  $p=$this->product();$author=$this->user('09123456790');$voter=$this->user('09123456791');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $d=ContentDiscussion::firstOrFail();$admin=$this->user('09123456792','admin');
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->actingAs($voter)->post(route('discussions.reaction',$d),['type'=>'like'])->assertRedirect();
  $this->assertDatabaseHas('discussion_reactions',['discussion_id'=>$d->id,'user_id'=>$voter->id,'type'=>'like']);
  $this->actingAs($voter)->post(route('discussions.reaction',$d),['type'=>'dislike'])->assertRedirect();
  $this->assertDatabaseHas('discussion_reactions',['discussion_id'=>$d->id,'user_id'=>$voter->id,'type'=>'dislike']);
  $this->assertDatabaseCount('discussion_reactions',1);
  $this->actingAs($voter)->post(route('discussions.reaction',$d),['type'=>'dislike'])->assertRedirect();
  $this->assertDatabaseMissing('discussion_reactions',['discussion_id'=>$d->id,'user_id'=>$voter->id]);
 }
 public function test_guests_cannot_react_and_users_cannot_react_to_own_or_pending_content(): void {
  $p=$this->product();$author=$this->user('09123456793');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا امکان دریافت نسخه قابل ویرایش این فایل وجود دارد؟'])->assertRedirect();
  $d=ContentDiscussion::firstOrFail();
  $this->app['auth']->guard()->logout();
  $this->post(route('discussions.reaction',$d),['type'=>'like'])->assertRedirect(route('login'));
  $this->actingAs($author)->post(route('discussions.reaction',$d),['type'=>'like'])->assertNotFound();
  $admin=$this->user('09123456794','admin');$this->actingAs($admin)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->actingAs($author)->post(route('discussions.reaction',$d),['type'=>'like'])->assertForbidden();
 }

 public function test_users_can_report_approved_discussion_and_admin_can_remove_reported_content(): void {
  $p=$this->product();$author=$this->user('09123456801');$reporter=$this->user('09123456802');$admin=$this->user('09123456803','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $d=ContentDiscussion::firstOrFail();$this->actingAs($admin)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->actingAs($reporter)->post(route('discussions.report',$d),['reason'=>'spam','details'=>'این پیام تبلیغاتی و نامرتبط است.'])->assertRedirect();
  $this->assertDatabaseHas('discussion_reports',['discussion_id'=>$d->id,'reporter_id'=>$reporter->id,'reason'=>'spam','status'=>'pending']);
  $this->actingAs($reporter)->post(route('discussions.report',$d),['reason'=>'abuse'])->assertRedirect();
  $this->assertDatabaseCount('discussion_reports',1);
  $report=\App\Models\DiscussionReport::firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussion-reports.moderate',$report),['action'=>'remove_content'])->assertRedirect();
  $this->assertDatabaseHas('content_discussions',['id'=>$d->id,'status'=>'rejected']);
  $this->assertDatabaseHas('discussion_reports',['id'=>$report->id,'status'=>'resolved','reviewed_by'=>$admin->id]);
 }
 public function test_guests_cannot_report_and_authors_cannot_report_their_own_content(): void {
  $p=$this->product();$author=$this->user('09123456804');$admin=$this->user('09123456805','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا امکان دریافت نسخه قابل ویرایش این فایل وجود دارد؟'])->assertRedirect();
  $d=ContentDiscussion::firstOrFail();$this->actingAs($admin)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->app['auth']->guard()->logout();
  $this->post(route('discussions.report',$d),['reason'=>'spam'])->assertRedirect(route('login'));
  $this->actingAs($author)->post(route('discussions.report',$d),['reason'=>'spam'])->assertForbidden();
 }
 public function test_public_discussion_sort_parameter_is_whitelisted(): void {
  $p=$this->product();$u=$this->user('09123456806');
  $this->actingAs($u)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $d=ContentDiscussion::firstOrFail();$admin=$this->user('09123456807','admin');$this->actingAs($admin)->patch(route('admin.discussions.moderate',$d),['status'=>'approved'])->assertRedirect();
  $this->get(route('store.product',['slug'=>$p->slug,'discussion_sort'=>'helpful']))->assertOk()->assertSee('discussion-sort')->assertSee('discussion-permalink');
  $this->get(route('store.product',['slug'=>$p->slug,'discussion_sort'=>'most_replied']))->assertOk()->assertSee('most_replied');
  $this->get(route('blog.show',['slug'=>'workflow-writing','discussion_sort'=>'most_replied']))->assertOk()->assertSee('most_replied');
 }

 public function test_user_replies_are_moderated_and_appear_as_member_replies(): void {
  $p=$this->product();$author=$this->user('09123456811');$responder=$this->user('09123456812');$admin=$this->user('09123456813','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $parent=ContentDiscussion::firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$parent),['status'=>'approved'])->assertRedirect();
  $this->actingAs($responder)->post(route('discussions.public-reply',$parent),['body'=>'بله، برای استفاده در کلاس آموزشی قابل استفاده است.'])->assertRedirect();
  $reply=ContentDiscussion::where('parent_id',$parent->id)->firstOrFail();
  $this->assertSame('pending',$reply->status);
  $this->get(route('store.product',$p->slug))->assertDontSee('برای استفاده در کلاس آموزشی قابل استفاده است.');
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$reply),['status'=>'approved'])->assertRedirect();
  $this->get(route('store.product',$p->slug))->assertOk()->assertSee('برای استفاده در کلاس آموزشی قابل استفاده است.');
 }
 public function test_users_cannot_reply_to_unapproved_or_nested_discussions(): void {
  $p=$this->product();$u=$this->user('09123456814');
  $this->actingAs($u)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $parent=ContentDiscussion::firstOrFail();
  $this->actingAs($u)->post(route('discussions.public-reply',$parent),['body'=>'پاسخ زودهنگام'])->assertNotFound();
  $admin=$this->user('09123456815','admin');$this->actingAs($admin)->patch(route('admin.discussions.moderate',$parent),['status'=>'approved'])->assertRedirect();
  $this->actingAs($u)->post(route('discussions.public-reply',$parent),['body'=>'پاسخ معتبر برای این پرسش'])->assertRedirect();
  $reply=ContentDiscussion::where('parent_id',$parent->id)->firstOrFail();
  $this->actingAs($u)->post(route('discussions.public-reply',$reply),['body'=>'پاسخ تو در تو'])->assertNotFound();
 }

 public function test_users_can_follow_discussions_and_toggle_following(): void {
  $p=$this->product();$author=$this->user('09123456821');$follower=$this->user('09123456822');$admin=$this->user('09123456823','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $parent=ContentDiscussion::firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$parent),['status'=>'approved'])->assertRedirect();
  $this->assertDatabaseHas('discussion_subscriptions',['discussion_id'=>$parent->id,'user_id'=>$author->id]);
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$parent),['status'=>'approved'])->assertRedirect();
  $this->actingAs($follower)->post(route('discussions.follow',$parent))->assertRedirect();
  $this->assertDatabaseHas('discussion_subscriptions',['discussion_id'=>$parent->id,'user_id'=>$follower->id]);
  $this->actingAs($follower)->post(route('discussions.follow',$parent))->assertRedirect();
  $this->assertDatabaseMissing('discussion_subscriptions',['discussion_id'=>$parent->id,'user_id'=>$follower->id]);
 }
 public function test_followers_receive_in_app_notification_when_reply_is_approved(): void {
  $p=$this->product();$author=$this->user('09123456824');$follower=$this->user('09123456825');$responder=$this->user('09123456826');$admin=$this->user('09123456827','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $parent=ContentDiscussion::firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$parent),['status'=>'approved'])->assertRedirect();
  $this->actingAs($follower)->post(route('discussions.follow',$parent))->assertRedirect();
  $this->actingAs($responder)->post(route('discussions.public-reply',$parent),['body'=>'بله، برای استفاده در کلاس آموزشی قابل استفاده است.'])->assertRedirect();
  $reply=ContentDiscussion::where('parent_id',$parent->id)->firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$reply),['status'=>'approved'])->assertRedirect();
  $this->assertDatabaseHas('farast_notifications',['user_id'=>$follower->id,'type'=>'discussion_reply']);
  $this->assertDatabaseHas('farast_notifications',['user_id'=>$author->id,'type'=>'discussion_reply']);
  $this->assertDatabaseMissing('farast_notifications',['user_id'=>$responder->id,'type'=>'discussion_reply']);
 }

 public function test_public_member_profile_shows_only_approved_community_activity(): void {
  $p=$this->product();$author=$this->user('09123456831');$admin=$this->user('09123456832','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $discussion=ContentDiscussion::firstOrFail();
  $this->get(route('community.member',$author))->assertNotFound();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$discussion),['status'=>'approved'])->assertRedirect();
  $this->get(route('community.member',$author))->assertOk()->assertSee('کاربر آزمایشی')->assertSee('مشارکت‌های منتشرشده')->assertSee('آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟')->assertDontSee('09123456831');
 }

 public function test_community_directory_lists_only_members_with_approved_contributions(): void {
  $p=$this->product();$author=$this->user('09123456841');$pending=$this->user('09123456842');$admin=$this->user('09123456843','admin');
  foreach([[$author,'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'],[$pending,'آیا نسخه دیگری از این فایل نیز ارائه می‌شود؟']] as [$member,$body]){
   $this->actingAs($member)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>$body])->assertRedirect();
  }
  $items=ContentDiscussion::orderBy('id')->get();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$items[0]),['status'=>'approved'])->assertRedirect();
  $this->get(route('community.index'))->assertOk()->assertSee('کاربر آزمایشی')->assertSee('مشارکت‌کننده‌های جامعه')->assertDontSee('09123456842');
 }

 public function test_members_can_manage_only_their_own_discussions_and_withdraw_pending_items(): void {
  $p=$this->product();$author=$this->user('09123456901');$other=$this->user('09123456902');$admin=$this->user('09123456903','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $pending=ContentDiscussion::firstOrFail();
  $this->actingAs($other)->get(route('community.mine'))->assertOk()->assertDontSee('آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟');
  $this->actingAs($author)->get(route('community.mine',['status'=>'pending']))->assertOk()->assertSee('آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟')->assertSee('لغو مشارکت');
  $this->actingAs($other)->delete(route('community.mine.withdraw',$pending))->assertNotFound();
  $this->actingAs($author)->delete(route('community.mine.withdraw',$pending))->assertRedirect();
  $this->assertDatabaseMissing('content_discussions',['id'=>$pending->id]);
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا نسخه دیگری از این فایل نیز ارائه می‌شود؟'])->assertRedirect();
  $approved=ContentDiscussion::latest('id')->firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$approved),['status'=>'approved'])->assertRedirect();
  $this->actingAs($author)->delete(route('community.mine.withdraw',$approved))->assertStatus(409);
  $this->assertDatabaseHas('content_discussions',['id'=>$approved->id,'status'=>'approved']);
 }
 public function test_community_notification_filter_is_available_in_notifications_page(): void {
  $user=$this->user('09123456904');
  \App\Models\FarastNotification::create(['user_id'=>$user->id,'type'=>'discussion_reply','title'=>'پاسخ جدید','body'=>'پاسخ تازه‌ای منتشر شد.','data'=>['url'=>'/community']]);
  $this->actingAs($user)->get(route('platform.notifications',['filter'=>'community']))->assertOk()->assertSee('پاسخ جدید')->assertSee('گفت‌وگوها');
 }


 public function test_community_directory_supports_member_search_kind_filter_and_activity_sort(): void {
  $p=$this->product();$member=$this->user('09123456911');$other=$this->user('09123456912');$admin=$this->user('09123456913','admin');
  foreach([[$member,'question','آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'],[$member,'reply','این پاسخ برای تکمیل پرسش ثبت شده است.'],[$other,'question','آیا نسخه دیگری از این فایل نیز ارائه می‌شود؟']] as [$author,$kind,$body]){
   $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>$kind==='reply'?'question':$kind,'body'=>$body])->assertRedirect();
  }
  $items=ContentDiscussion::orderBy('id')->get();
  foreach($items as $item){$this->actingAs($admin)->patch(route('admin.discussions.moderate',$item),['status'=>'approved'])->assertRedirect();}
  $this->get(route('community.index',['q'=>'کاربر آزمایشی','kind'=>'question']))->assertOk()->assertSee('مشارکت‌کننده‌های جامعه')->assertSee('فعال‌ترین اعضا');
  $this->get(route('community.index',['sort'=>'active']))->assertOk()->assertSee('فعال‌ترین اعضا');
 }


 public function test_product_discussions_are_paginated_beyond_the_first_thirty_items(): void {
  $p=$this->product();$author=$this->user('09123456921');
  for($i=1;$i<=16;$i++){
   ContentDiscussion::create([
    'target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question',
    'user_id'=>$author->id,'body'=>'پرسش شماره '.$i.' درباره جزئیات این محصول چیست؟','status'=>'approved',
   ]);
  }
  $this->get(route('store.product',$p->slug))->assertOk()->assertSee('discussion-pagination')->assertSee('پرسش شماره 16');
  $this->get(route('store.product',['slug'=>$p->slug,'page'=>2]))->assertOk()->assertSee('پرسش شماره 1')->assertDontSee('پرسش شماره 16');
 }


 public function test_moderation_decisions_are_audited_and_notify_the_content_author(): void {
  $p=$this->product();$author=$this->user('09123456931');$admin=$this->user('09123456932','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $item=ContentDiscussion::firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$item),['status'=>'rejected','moderation_note'=>'لطفاً پرسش را دقیق‌تر و مرتبط با محتوای محصول مطرح کنید.'])->assertRedirect();
  $this->assertDatabaseHas('discussion_moderation_events',['discussion_id'=>$item->id,'moderator_id'=>$admin->id,'from_status'=>'pending','to_status'=>'rejected']);
  $this->assertDatabaseHas('content_discussions',['id'=>$item->id,'moderation_note'=>'لطفاً پرسش را دقیق‌تر و مرتبط با محتوای محصول مطرح کنید.']);
  $this->assertDatabaseHas('farast_notifications',['user_id'=>$author->id,'type'=>'discussion_rejected']);
  $this->actingAs($author)->get(route('community.mine',['status'=>'rejected']))->assertOk()->assertSee('توضیح تیم بررسی')->assertSee('پرسش را دقیق‌تر');
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$item),['status'=>'rejected'])->assertRedirect();
  $this->assertDatabaseCount('discussion_moderation_events',1);
  $this->assertDatabaseCount('farast_notifications',1);
  $this->actingAs($admin)->get(route('admin.discussions.index',['status'=>'rejected']))->assertOk()->assertSee('سوابق رسیدگی')->assertSee('پرسش را دقیق‌تر');
 }
 public function test_admin_discussion_queue_can_filter_by_kind_and_search_author_or_text(): void {
  $p=$this->product();$author=$this->user('09123456933');$admin=$this->user('09123456934','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $this->actingAs($admin)->get(route('admin.discussions.index',['status'=>'pending','kind'=>'question','q'=>'کلاس آموزشی']))->assertOk()->assertSee('discussionKind')->assertSee('کلاس آموزشی');
  $this->actingAs($admin)->get(route('admin.discussions.index',['status'=>'pending','q'=>'کاربر آزمایشی']))->assertOk()->assertSee('آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟');
 }


 public function test_members_can_edit_only_their_own_pending_discussions(): void {
  $p=$this->product();$author=$this->user('09123456941');$other=$this->user('09123456942');$admin=$this->user('09123456943','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','title'=>'عنوان اولیه','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $item=ContentDiscussion::firstOrFail();
  $this->actingAs($other)->put(route('community.mine.update',$item),['title'=>'دستکاری','body'=>'این متن متعلق به کاربر دیگری است.'])->assertNotFound();
  $this->actingAs($author)->put(route('community.mine.update',$item),['title'=>'عنوان اصلاح‌شده','body'=>'آیا نسخه قابل ویرایش این فایل برای استفاده در کلاس آموزشی موجود است؟'])->assertRedirect();
  $this->assertDatabaseHas('content_discussions',['id'=>$item->id,'title'=>'عنوان اصلاح‌شده','status'=>'pending']);
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$item),['status'=>'approved'])->assertRedirect();
  $this->actingAs($author)->put(route('community.mine.update',$item),['body'=>'این متن پس از انتشار دیگر نباید قابل تغییر باشد.'])->assertStatus(409);
 }
 public function test_duplicate_discussion_content_and_excessive_links_are_rejected(): void {
  $p=$this->product();$author=$this->user('09123456944');
  $payload=['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'];
  $this->actingAs($author)->post(route('discussions.store'),$payload)->assertRedirect();
  $this->actingAs($author)->from(route('store.product',$p->slug))->post(route('discussions.store'),$payload)->assertSessionHasErrors('body');
  $payload['body']='لینک اول https://example.com لینک دوم https://example.org لینک سوم https://example.net';
  $this->actingAs($author)->from(route('store.product',$p->slug))->post(route('discussions.store'),$payload)->assertSessionHasErrors('body');
  $this->assertDatabaseCount('content_discussions',1);
 }
 public function test_notification_visit_marks_owned_notification_read_and_rejects_other_users(): void {
  $owner=$this->user('09123456945');$other=$this->user('09123456946');
  $notification=\App\Models\FarastNotification::create(['user_id'=>$owner->id,'type'=>'discussion_approved','title'=>'مشارکت منتشر شد','body'=>'مشارکت شما تأیید شد.','data'=>['url'=>'/community']]);
  $this->actingAs($other)->get(route('platform.notifications.visit',$notification->id))->assertNotFound();
  $this->assertDatabaseHas('farast_notifications',['id'=>$notification->id,'read_at'=>null]);
  $this->actingAs($owner)->get(route('platform.notifications.visit',$notification->id))->assertRedirect('/community');
  $this->assertDatabaseMissing('farast_notifications',['id'=>$notification->id,'read_at'=>null]);
 }


 public function test_author_can_unfollow_their_own_discussion_and_stop_reply_notifications(): void {
  $p=$this->product();$author=$this->user('09123456951');$responder=$this->user('09123456952');$admin=$this->user('09123456953','admin');
  $this->actingAs($author)->post(route('discussions.store'),['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟'])->assertRedirect();
  $parent=ContentDiscussion::firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$parent),['status'=>'approved'])->assertRedirect();
  $this->assertDatabaseHas('discussion_subscriptions',['discussion_id'=>$parent->id,'user_id'=>$author->id]);
  $this->actingAs($author)->post(route('discussions.follow',$parent))->assertRedirect();
  $this->assertDatabaseMissing('discussion_subscriptions',['discussion_id'=>$parent->id,'user_id'=>$author->id]);
  $this->actingAs($responder)->post(route('discussions.public-reply',$parent),['body'=>'بله، برای استفاده در کلاس آموزشی قابل استفاده است.'])->assertRedirect();
  $reply=ContentDiscussion::where('parent_id',$parent->id)->firstOrFail();
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$reply),['status'=>'approved'])->assertRedirect();
  $this->assertDatabaseMissing('farast_notifications',['user_id'=>$author->id,'type'=>'discussion_reply']);
 }
 public function test_notification_visit_rejects_external_redirect_targets(): void {
  $owner=$this->user('09123456954');
  $notification=\App\Models\FarastNotification::create(['user_id'=>$owner->id,'type'=>'discussion_approved','title'=>'اعلان','body'=>'متن','data'=>['url'=>'https://evil.example/phishing']]);
  $this->actingAs($owner)->get(route('platform.notifications.visit',$notification->id))->assertRedirect(route('platform.notifications'));
  $this->assertDatabaseMissing('farast_notifications',['id'=>$notification->id,'read_at'=>null]);
 }


 public function test_product_review_marks_only_paid_order_owners_as_verified_purchasers(): void {
  $p=$this->product();$verified=$this->user('09123456961');$unverified=$this->user('09123456962');
  $order=Order::create(['user_id'=>$verified->id,'subtotal_rials'=>100000,'total_rials'=>100000,'status'=>'paid','paid_at'=>now()]);
  StoreOrderItem::create(['order_id'=>$order->id,'product_id'=>$p->id,'title_snapshot'=>$p->title,'quantity'=>1,'unit_price_rials'=>100000,'total_rials'=>100000]);
  foreach([[$verified,'تجربه خریدار واقعی و تأییدشده'],[$unverified,'تجربه کاربر بدون سفارش']] as [$author,$body]){
   ContentDiscussion::create(['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'review','user_id'=>$author->id,'body'=>$body,'rating'=>5,'status'=>'approved']);
  }
  $response=$this->get(route('store.product',$p->slug))->assertOk()->assertSee('تجربه خریدار واقعی و تأییدشده')->assertSee('تجربه کاربر بدون سفارش')->assertSee('خرید تأییدشده');
  $this->assertSame(1,substr_count($response->getContent(),'discussion-verified-purchase'));
 }


 public function test_removing_reported_content_closes_all_open_reports_and_records_review_note(): void {
  $p=$this->product();$author=$this->user('09123456971');$reporterOne=$this->user('09123456972');$reporterTwo=$this->user('09123456973');$admin=$this->user('09123456974','admin');
  $discussion=ContentDiscussion::create(['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','user_id'=>$author->id,'body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟','status'=>'approved']);
  $first=\App\Models\DiscussionReport::create(['discussion_id'=>$discussion->id,'reporter_id'=>$reporterOne->id,'reason'=>'spam','status'=>'pending']);
  \App\Models\DiscussionReport::create(['discussion_id'=>$discussion->id,'reporter_id'=>$reporterTwo->id,'reason'=>'abuse','status'=>'pending']);
  $this->actingAs($admin)->patch(route('admin.discussion-reports.moderate',$first),['action'=>'remove_content','review_note'=>'بررسی شد؛ محتوای تبلیغاتی نامرتبط است.'])->assertRedirect();
  $this->assertDatabaseHas('content_discussions',['id'=>$discussion->id,'status'=>'rejected','moderation_note'=>'بررسی شد؛ محتوای تبلیغاتی نامرتبط است.']);
  $this->assertDatabaseHas('discussion_reports',['discussion_id'=>$discussion->id,'status'=>'resolved','reviewed_by'=>$admin->id,'review_note'=>'بررسی شد؛ محتوای تبلیغاتی نامرتبط است.']);
  $this->assertDatabaseCount('discussion_reports',2);
  $this->assertDatabaseCount('discussion_moderation_events',1);
  $this->actingAs($admin)->get(route('admin.discussion-reports.index',['status'=>'resolved']))->assertOk()->assertSee('یادداشت رسیدگی')->assertSee('محتوای تبلیغاتی نامرتبط');
 }

 public function test_dismissing_a_report_keeps_approved_content_and_stores_the_review_note(): void {
  $p=$this->product();$author=$this->user('09123456975');$reporter=$this->user('09123456976');$admin=$this->user('09123456977','admin');
  $discussion=ContentDiscussion::create(['target_type'=>'product','target_key'=>(string)$p->id,'kind'=>'question','user_id'=>$author->id,'body'=>'آیا این فایل برای استفاده در کلاس آموزشی مناسب است؟','status'=>'approved']);
  $report=\App\Models\DiscussionReport::create(['discussion_id'=>$discussion->id,'reporter_id'=>$reporter->id,'reason'=>'other','status'=>'pending']);
  $this->actingAs($admin)->patch(route('admin.discussion-reports.moderate',$report),['action'=>'dismiss','review_note'=>'مورد گزارش‌شده تخلف محسوب نمی‌شود.'])->assertRedirect();
  $this->assertDatabaseHas('content_discussions',['id'=>$discussion->id,'status'=>'approved']);
  $this->assertDatabaseHas('discussion_reports',['id'=>$report->id,'status'=>'dismissed','review_note'=>'مورد گزارش‌شده تخلف محسوب نمی‌شود.']);
  $this->actingAs($admin)->get(route('admin.discussion-reports.index',['status'=>'dismissed']))->assertOk()->assertSee('یادداشت رسیدگی')->assertSee('تخلف محسوب نمی‌شود');
 }

 public function test_reader_comments_are_anchored_to_selected_text_and_support_public_replies_and_reactions(): void {
  $product=$this->product();
  $author=$this->user('09123456981');
  $responder=$this->user('09123456982');
  $admin=$this->user('09123456983','admin');

  $this->actingAs($author)->post(route('discussions.store'),[
   'target_type'=>'reader',
   'target_key'=>(string)$product->id,
   'kind'=>'comment',
   'body'=>'این توضیح درباره همین بند بسیار مفید است.',
   'anchor_page'=>3,
   'anchor_quote'=>'عبارت مهم داخل فایل',
   'anchor_start'=>120,
   'anchor_end'=>138,
   'anchor_context'=>'متن قبل از عبارت مهم داخل فایل و ادامه متن',
  ])->assertRedirect(route('store.reader',$product->slug).'#reader-discussions');

  $discussion=ContentDiscussion::where('target_type','reader')->firstOrFail();
  $this->assertDatabaseHas('content_discussions',[
   'id'=>$discussion->id,
   'target_key'=>(string)$product->id,
   'anchor_page'=>3,
   'anchor_quote'=>'عبارت مهم داخل فایل',
   'anchor_start'=>120,
   'anchor_end'=>138,
   'status'=>'pending',
  ]);

  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$discussion),['status'=>'approved'])->assertRedirect();
  $this->actingAs($responder)->post(route('discussions.reaction',$discussion),['type'=>'like'])->assertRedirect();
  $this->assertDatabaseHas('discussion_reactions',['discussion_id'=>$discussion->id,'user_id'=>$responder->id,'type'=>'like']);

  $this->actingAs($responder)->post(route('discussions.public-reply',$discussion),[
   'body'=>'با این برداشت موافقم و این نکته را تکمیل می‌کنم.',
  ])->assertRedirect();
  $reply=ContentDiscussion::where('parent_id',$discussion->id)->firstOrFail();
  $this->assertSame('reader',$reply->target_type);
  $this->assertSame((string)$product->id,$reply->target_key);
  $this->actingAs($admin)->patch(route('admin.discussions.moderate',$reply),['status'=>'approved'])->assertRedirect();
  $this->assertDatabaseHas('content_discussions',['id'=>$reply->id,'status'=>'approved']);
 }

}
