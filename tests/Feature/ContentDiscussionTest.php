<?php
namespace Tests\Feature;
use App\Models\ContentDiscussion;
use App\Models\StoreCategory;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class ContentDiscussionTest extends TestCase {
 use RefreshDatabase;
 private function product(): StoreProduct {$c=StoreCategory::create(['name'=>'دسته گفت‌وگو','slug'=>'discussion-test-category','is_active'=>true]);return StoreProduct::create(['category_id'=>$c->id,'title'=>'محصول گفت‌وگو','slug'=>'discussion-test-product','sku'=>'DISCUSSION-TEST-001','type'=>'digital_file','price_rials'=>100000,'status'=>'published','published_at'=>now()]);}
 private function user(string $mobile,string $role='user'): User {return User::create(['name'=>$role==='admin'?'مدیر فراست':'کاربر آزمایشی','mobile'=>$mobile,'password'=>'secret','role'=>$role]);}
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
  $this->get(route('store.product',['slug'=>$p->slug,'discussion_sort'=>'helpful']))->assertOk()->assertSee('discussion-sort');
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
}
