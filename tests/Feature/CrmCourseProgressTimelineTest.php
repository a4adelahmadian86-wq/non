<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\FarastProject;
use App\Models\FarastDocument;
use App\Models\FarastDocumentVersion;
use App\Models\StoreCategory;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\StoreCourse;
use App\Models\StoreCourseEnrollment;
use App\Models\StoreCourseLesson;
use App\Models\StoreCourseLessonProgress;
use App\Models\StoreCourseSection;
use App\Models\StoreLibraryItem;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\AuthorizationService;
use App\Services\StoreCourseEnrollmentService;
use App\Services\StoreCourseProgressService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrmCourseProgressTimelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['farast_admin.multi_role_console' => true]);
        app(AuthorizationService::class)->syncDefaults();
    }

    public function test_customer_360_uses_the_same_released_lesson_progress_as_the_learning_workspace(): void
    {
        $admin = User::factory()->create(['role' => User::ROLE_ADMIN]);
        $customer = User::factory()->create(['role' => User::ROLE_EMPLOYEE]);

        $category = StoreCategory::create([
            'name' => 'دوره‌های CRM',
            'slug' => 'crm-course-progress-category',
            'is_active' => true,
        ]);
        $product = StoreProduct::create([
            'category_id' => $category->id,
            'seller_id' => $admin->id,
            'title' => 'دوره پیشرفت CRM',
            'slug' => 'crm-course-progress',
            'sku' => 'CRM-PROGRESS-001',
            'type' => 'course',
            'price_rials' => 300000,
            'status' => 'published',
            'published_at' => now(),
        ]);
        $course = StoreCourse::create([
            'product_id' => $product->id,
            'level' => 'beginner',
            'language' => 'fa',
            'drip_enabled' => true,
        ]);
        $section = StoreCourseSection::create([
            'course_id' => $course->id,
            'title' => 'فصل اول',
            'sort_order' => 1,
        ]);
        $completedLesson = StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'درس تکمیل‌شده',
            'slug' => 'crm-progress-completed',
            'lesson_type' => 'article',
            'content' => 'متن درس',
            'is_published' => true,
            'sort_order' => 1,
        ]);
        StoreCourseLesson::create([
            'section_id' => $section->id,
            'title' => 'درس آینده',
            'slug' => 'crm-progress-future',
            'lesson_type' => 'article',
            'content' => 'متن درس آینده',
            'is_published' => true,
            'drip_after_days' => 3,
            'sort_order' => 2,
        ]);

        $order = Order::create([
            'user_id' => $customer->id,
            'subtotal_rials' => 300000,
            'discount_rials' => 0,
            'tax_rials' => 0,
            'total_rials' => 300000,
            'status' => 'paid',
            'pricing_snapshot' => ['kind' => 'store'],
            'paid_at' => now(),
        ]);
        $libraryItem = StoreLibraryItem::create([
            'user_id' => $customer->id,
            'product_id' => $product->id,
            'order_id' => $order->id,
            'granted_at' => now(),
        ]);
        $enrollment = app(StoreCourseEnrollmentService::class)->grant($libraryItem, $product);

        StoreCourseLessonProgress::create([
            'enrollment_id' => $enrollment->id,
            'lesson_id' => $completedLesson->id,
            'last_position_seconds' => 45,
            'completed_at' => now(),
        ]);

        $progress = app(StoreCourseProgressService::class);
        $enrollment = $progress->syncCompletion($enrollment->fresh(['course.sections.lessons', 'progress']));
        $summary = $progress->summarize($enrollment);

        $this->assertSame(1, $summary['total']);
        $this->assertSame(1, $summary['completed']);
        $this->assertSame(100, $summary['progress_percent']);
        $this->assertNotNull($enrollment->completed_at);

        $ticket = Ticket::create([
            'user_id' => $customer->id,
            'ticket_code' => 'TK-CRM-001',
            'subject' => 'پیگیری سفارش دوره',
            'status' => 'answered',
            'priority' => 'normal',
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $customer->id,
            'body' => 'لطفاً وضعیت دسترسی دوره را بررسی کنید.',
            'is_internal' => false,
            'is_staff' => false,
        ]);
        TicketMessage::create([
            'ticket_id' => $ticket->id,
            'user_id' => $admin->id,
            'body' => 'این یادداشت داخلی محرمانه است.',
            'is_internal' => true,
            'is_staff' => true,
        ]);

        $project = FarastProject::create([
            'user_id' => $customer->id,
            'name' => 'پروژه یکپارچه CRM',
            'project_type' => 'typing',
            'workflow' => 'manual',
            'template_code' => 'default',
            'status' => 'active',
            'context' => ['source' => 'phase7-test'],
            'billing_state' => ['status' => 'estimated'],
            'output_state' => ['status' => 'locked'],
            'estimated_pages' => 2,
            'used_pages' => 1,
            'estimated_price_rials' => 500000,
            'paid_rials' => 200000,
        ]);
        $document = FarastDocument::create([
            'user_id' => $customer->id,
            'project_id' => $project->id,
            'title' => 'سند مرجع پروژه CRM',
            'content' => 'محتوای نسخه اصلی',
            'status' => 'active',
            'revision' => 3,
            'last_saved_at' => now(),
        ]);
        FarastDocumentVersion::create([
            'document_id' => $document->id,
            'user_id' => $customer->id,
            'content' => 'محتوای نسخه قبلی',
            'label' => 'نسخه اول',
        ]);

        $this->actingAs($admin)
            ->getJson('/admin/crm/customers/'.$customer->id)
            ->assertOk()
            ->assertJsonPath('metrics.course_enrollments_count', 1)
            ->assertJsonPath('metrics.completed_course_enrollments_count', 1)
            ->assertJsonPath('metrics.completed_course_lessons_count', 1)
            ->assertJsonPath('metrics.projects_count', 1)
            ->assertJsonPath('metrics.farast_documents_count', 1)
            ->assertJsonPath('metrics.farast_document_versions_count', 1)
            ->assertJsonFragment([
                'type' => 'farast_project',
                'id' => $project->id,
                'title' => 'پروژه یکپارچه CRM',
                'documents_count' => 1,
            ])
            ->assertJsonFragment([
                'type' => 'farast_document',
                'id' => $document->id,
                'title' => 'سند مرجع پروژه CRM',
                'project_id' => $project->id,
                'revision' => 3,
                'versions_count' => 1,
            ])
            ->assertJsonFragment([
                'type' => 'ticket_message',
                'ticket_id' => $ticket->id,
                'body' => 'لطفاً وضعیت دسترسی دوره را بررسی کنید.',
                'sender_type' => 'customer',
            ])
            ->assertDontSee('این یادداشت داخلی محرمانه است')
            ->assertJsonFragment([
                'type' => 'course_enrollment',
                'id' => $enrollment->id,
                'status' => 'completed',
                'completed_lessons_count' => 1,
                'published_lessons_count' => 1,
                'progress_percent' => 100,
            ]);
    }
}
