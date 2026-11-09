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
}