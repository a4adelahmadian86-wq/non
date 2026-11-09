<?php

namespace App\Http\Controllers;

use App\Models\ContentDiscussion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;

class BlogController extends Controller
{
    private static function articles(): array
    {
        return [
            'workflow-writing' => [
                'slug' => 'workflow-writing',
                'title' => 'از صفحه سفید تا متن نهایی؛ یک workflow حرفه‌ای برای نوشتن',
                'excerpt' => 'چطور ایده خام را به یک سند ساختارمند تبدیل کنیم؛ بدون اینکه در ابزارها و مراحل پراکنده گم شویم.',
                'category' => 'بهره‌وری و نوشتن',
                'category_slug' => 'writing',
                'date' => '۱۴۰۵/۰۸/۱۲',
                'read_time' => 6,
                'author' => 'تحریریه فراست',
                'image' => 'https://images.unsplash.com/photo-1455390582262-044cdead277a?auto=format&fit=crop&w=1400&q=85',
                'alt' => 'میز کار و ابزار نوشتن',
                'featured' => true,
                'tags' => ['نوشتن', 'مدیریت محتوا', 'بهره‌وری'],
                'sections' => [
                    ['id'=>'start','title'=>'با هدف شروع کنید، نه با ابزار','paragraphs'=>['قبل از باز کردن ویرایشگر، در یک جمله مشخص کنید خروجی قرار است چه مشکلی را حل کند و مخاطب آن کیست. همین تصمیم ساده از بازنویسی‌های بی‌هدف جلوگیری می‌کند.','یک خروجی خوب معمولاً سه ویژگی دارد: مخاطب روشن، ساختار قابل پیش‌بینی و معیار مشخص برای کامل‌شدن.']],
                    ['id'=>'outline','title'=>'اسکلت متن را زودتر بسازید','paragraphs'=>['عنوان موقت، سه تا پنج بخش اصلی و یک نتیجه‌گیری کوتاه کافی است تا مسیر نوشتن مشخص شود. لازم نیست در مرحله اول جمله‌ها کامل باشند.','هر بخش را با یک پرسش راهنما آغاز کنید. اگر نتوانید به پرسش آن بخش پاسخ دهید، احتمالاً هنوز جای آن در ساختار روشن نیست.']],
                    ['id'=>'draft','title'=>'پیش‌نویس و ویرایش را جدا کنید','paragraphs'=>['در دور اول فقط محتوا را بیرون بریزید. در دور دوم منطق و ترتیب را بررسی کنید و در دور سوم سراغ لحن، املا و یکدستی واژه‌ها بروید.','جداکردن این مرحله‌ها بار ذهنی را کم می‌کند و اجازه می‌دهد به‌جای اصلاح مداوم یک جمله، کل سند را ببینید.']],
                    ['id'=>'finish','title'=>'یک چک‌لیست کوتاه برای تحویل','paragraphs'=>['عنوان با محتوای متن هم‌خوان است؟ هر بخش یک پیام اصلی دارد؟ مثال‌ها و اعداد قابل بررسی‌اند؟ لینک‌ها و فایل‌های پیوست باز می‌شوند؟','در پایان، سند را در قالبی خروجی بگیرید که مخاطب واقعاً به آن نیاز دارد و یک نسخه قابل ویرایش را هم نگه دارید.']],
                ],
            ],
            'document-digitization' => [
                'slug' => 'document-digitization',
                'title' => 'اسکن تا سند قابل ویرایش؛ راهنمای انتخاب مسیر درست OCR',
                'excerpt' => 'برای تبدیل تصویر و PDF به متن قابل جست‌وجو، کیفیت ورودی و بازبینی خروجی از خود ابزار مهم‌ترند.',
                'category' => 'فایل و اسناد',
                'category_slug' => 'documents',
                'date' => '۱۴۰۵/۰۸/۰۹',
                'read_time' => 7,
                'author' => 'تیم محصول فراست',
                'image' => 'https://images.unsplash.com/photo-1456324504439-367cee3b3c32?auto=format&fit=crop&w=1400&q=85',
                'alt' => 'کتاب و یادداشت‌های کاغذی روی میز',
                'featured' => false,
                'tags' => ['OCR', 'PDF', 'دیجیتال‌سازی'],
                'sections' => [
                    ['id'=>'quality','title'=>'کیفیت تصویر، نقطه شروع دقت است','paragraphs'=>['صفحه صاف، نور یکنواخت، وضوح کافی و کادر بدون حاشیه اضافی، احتمال خطا را کم می‌کنند. اگر متن کج یا تار باشد، حتی موتور OCR قوی هم ناچار به حدس‌زدن می‌شود.']],
                    ['id'=>'language','title'=>'زبان و نوع سند را در نظر بگیرید','paragraphs'=>['متن فارسی، جدول، فرمول و سند چندزبانه نیازهای متفاوتی دارند. برای سندهای ترکیبی، انتظار نداشته باشید همه بخش‌ها با یک تنظیم واحد بهترین نتیجه را بدهند.']],
                    ['id'=>'review','title'=>'بازبینی هوشمند را حذف نکنید','paragraphs'=>['اعداد، نام‌ها، تاریخ‌ها و ستون‌های جدول را جداگانه بررسی کنید. چند دقیقه کنترل هدفمند معمولاً از اصلاح گسترده سند در مرحله بعد جلوگیری می‌کند.']],
                ],
            ],
            'voice-to-text' => [
                'slug' => 'voice-to-text',
                'title' => 'جلسه‌ها را به متن قابل استفاده تبدیل کنید؛ فراتر از پیاده‌سازی صوت',
                'excerpt' => 'یک خروجی ارزشمند فقط کلمات گفته‌شده نیست؛ ساختار، گوینده‌ها و تصمیم‌های جلسه هم باید قابل پیگیری باشند.',
                'category' => 'هوش مصنوعی',
                'category_slug' => 'ai',
                'date' => '۱۴۰۵/۰۸/۰۵',
                'read_time' => 5,
                'author' => 'تحریریه فراست',
                'image' => 'https://images.unsplash.com/photo-1590602847861-f357a9332bbc?auto=format&fit=crop&w=1400&q=85',
                'alt' => 'میکروفون برای ضبط و تبدیل صدا',
                'featured' => false,
                'tags' => ['تبدیل صوت', 'جلسه', 'هوش مصنوعی'],
                'sections' => [
                    ['id'=>'record','title'=>'قبل از ضبط، شرایط را آماده کنید','paragraphs'=>['میکروفون را نزدیک‌تر قرار دهید، صدای محیط را کاهش دهید و در جلسه‌های چندنفره از افراد بخواهید نام خود را هنگام شروع صحبت اعلام کنند.']],
                    ['id'=>'transcript','title'=>'متن خام را به خروجی عملی تبدیل کنید','paragraphs'=>['پس از پیاده‌سازی، متن را به بخش‌های موضوعی تقسیم کنید. تصمیم‌ها، مسئول هر اقدام و موعد انجام را جدا کنید تا سند فقط آرشیو گفتگو نباشد.']],
                    ['id'=>'privacy','title'=>'حریم خصوصی را جدی بگیرید','paragraphs'=>['پیش از ضبط یا بارگذاری فایل، مجوز افراد و سیاست نگهداری داده را بررسی کنید. برای گفتگوهای حساس، فقط از مسیرهای مورد تأیید سازمان استفاده کنید.']],
                ],
            ],
            'clean-workspace' => [
                'slug' => 'clean-workspace',
                'title' => 'چطور فضای کاری دیجیتال را خلوت و قابل اعتماد نگه داریم؟',
                'excerpt' => 'نام‌گذاری، نسخه‌بندی و یک مسیر روشن برای فایل‌ها، زمان جست‌وجو و دوباره‌کاری را کم می‌کند.',
                'category' => 'بهره‌وری و نوشتن',
                'category_slug' => 'writing',
                'date' => '۱۴۰۵/۰۷/۲۸',
                'read_time' => 4,
                'author' => 'تیم محصول فراست',
                'image' => 'https://images.unsplash.com/photo-1498050108023-c5249f4df085?auto=format&fit=crop&w=1400&q=85',
                'alt' => 'فضای کاری دیجیتال با لپ‌تاپ',
                'featured' => false,
                'tags' => ['مدیریت فایل', 'فضای کار', 'بهره‌وری'],
                'sections' => [
                    ['id'=>'names','title'=>'نام فایل باید قابل فهم باشد','paragraphs'=>['یک الگوی ثابت مثل نام پروژه، موضوع و تاریخ انتخاب کنید. نام‌هایی مثل نسخه نهایی جدید ۲ را با شماره نسخه یا تاریخ دقیق جایگزین کنید.']],
                    ['id'=>'folders','title'=>'ساختار را بیش از حد پیچیده نکنید','paragraphs'=>['چند پوشه سطح بالا و دسته‌بندی روشن معمولاً از درخت‌های عمیق بهترند. اگر برای پیدا کردن یک فایل باید چندین مسیر را حدس بزنید، ساختار نیاز به بازنگری دارد.']],
                    ['id'=>'versions','title'=>'نسخه‌ها و مالکیت را مشخص کنید','paragraphs'=>['نسخه نهایی را از پیش‌نویس جدا نگه دارید و برای فایل‌های مشترک، مسئول بازبینی و آخرین تغییر را روشن کنید.']],
                ],
            ],
            'ai-assisted-editing' => [
                'slug' => 'ai-assisted-editing',
                'title' => 'همکاری با دستیار هوشمند؛ چطور کنترل کیفیت را حفظ کنیم؟',
                'excerpt' => 'هوش مصنوعی می‌تواند سرعت کار را بالا ببرد؛ اما تصمیم نهایی، صحت اطلاعات و لحن برند باید دست شما بماند.',
                'category' => 'هوش مصنوعی',
                'category_slug' => 'ai',
                'date' => '۱۴۰۵/۰۷/۲۲',
                'read_time' => 6,
                'author' => 'تحریریه فراست',
                'image' => 'https://images.unsplash.com/photo-1677442136019-21780ecad995?auto=format&fit=crop&w=1400&q=85',
                'alt' => 'تصویر مفهومی هوش مصنوعی و فناوری',
                'featured' => false,
                'tags' => ['دستیار هوشمند', 'ویرایش', 'کنترل کیفیت'],
                'sections' => [
                    ['id'=>'brief','title'=>'درخواست دقیق، خروجی قابل ارزیابی','paragraphs'=>['هدف، مخاطب، لحن، محدودیت‌ها و قالب خروجی را مشخص کنید. هرچه زمینه روشن‌تر باشد، احتمال دریافت پاسخ قابل استفاده بیشتر می‌شود.']],
                    ['id'=>'verify','title'=>'ادعاها را مستقل بررسی کنید','paragraphs'=>['نام‌ها، تاریخ‌ها، آمار، نقل‌قول‌ها و منابع را از منبع اصلی کنترل کنید. متن روان لزوماً متن درست نیست.']],
                    ['id'=>'voice','title'=>'صدای برند را حفظ کنید','paragraphs'=>['خروجی را با واژگان و لحن سازمان هماهنگ کنید و اطلاعات محرمانه را بدون مجوز وارد ابزارهای بیرونی نکنید.']],
                ],
            ],
            'digital-products' => [
                'slug' => 'digital-products',
                'title' => 'یک فایل دیجیتال خوب چه ویژگی‌هایی دارد؟',
                'excerpt' => 'ارزش یک محصول دیجیتال فقط به محتوای آن نیست؛ پیش‌نمایش، توضیح شفاف و تجربه دریافت هم مهم‌اند.',
                'category' => 'فایل و اسناد',
                'category_slug' => 'documents',
                'date' => '۱۴۰۵/۰۷/۱۶',
                'read_time' => 5,
                'author' => 'تیم محصول فراست',
                'image' => 'https://images.unsplash.com/photo-1516979187457-637abb4f9353?auto=format&fit=crop&w=1400&q=85',
                'alt' => 'کتاب‌ها و منابع مطالعه',
                'featured' => false,
                'tags' => ['فایل دیجیتال', 'محصول', 'تجربه کاربری'],
                'sections' => [
                    ['id'=>'value','title'=>'ارزش را در چند ثانیه روشن کنید','paragraphs'=>['عنوان دقیق، توضیح کوتاه و مخاطب محصول باید از همان ابتدا روشن باشد. از وعده‌های مبهم و ادعاهای غیرقابل سنجش دوری کنید.']],
                    ['id'=>'preview','title'=>'پیش‌نمایش واقعی ارائه دهید','paragraphs'=>['چند صفحه یا نمونه واقعی کمک می‌کند کاربر پیش از خرید کیفیت و ساختار فایل را ارزیابی کند. تصاویر تزئینی جای پیش‌نمایش واقعی را نمی‌گیرند.']],
                    ['id'=>'delivery','title'=>'دریافت و پشتیبانی را ساده کنید','paragraphs'=>['فرمت، مجوز استفاده، حجم و روش دریافت را شفاف بنویسید. تجربه پس از پرداخت بخشی از کیفیت خود محصول است.']],
                ],
            ],
        ];
    }

    public static function articleExists(string $slug): bool
    {
        return array_key_exists($slug, self::articles());
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $category = (string) $request->query('category', '');
        $all = collect(self::articles());
        $featured = $all->firstWhere('featured', true);
        $articles = $all
            ->when($q !== '', fn ($items) => $items->filter(fn ($article) => str_contains(mb_strtolower($article['title'].' '.$article['excerpt'].' '.implode(' ', $article['tags']), 'UTF-8'), mb_strtolower($q, 'UTF-8'))))
            ->when($category !== '', fn ($items) => $items->where('category_slug', $category))
            ->when($q === '' && $category === '' && $featured, fn ($items) => $items->reject(fn ($article) => $article['slug'] === $featured['slug']))
            ->values();
        $categories = $all->map(fn ($article) => ['slug'=>$article['category_slug'], 'name'=>$article['category']])->unique('slug')->values();

        return view('blog.index', compact('articles', 'categories', 'featured', 'q', 'category'));
    }

    public function show(string $slug)
    {
        $article = self::articles()[$slug] ?? abort(404);
        $related = collect(self::articles())
            ->filter(fn ($item) => $item['slug'] !== $slug && $item['category_slug'] === $article['category_slug'])
            ->take(3)
            ->values();

        if (Schema::hasTable('content_discussions')) {
            $discussions = ContentDiscussion::query()->where('target_type', 'article')->where('target_key', $slug)->whereNull('parent_id')->where('status', 'approved')->with(['user', 'replies' => fn ($q) => $q->where('status', 'approved')->with('user')->oldest()])->latest()->limit(30)->get();
            $discussionCounts = [
                'comments' => ContentDiscussion::query()->where('target_type', 'article')->where('target_key', $slug)->where('kind', 'comment')->whereNull('parent_id')->where('status', 'approved')->count(),
                'questions' => ContentDiscussion::query()->where('target_type', 'article')->where('target_key', $slug)->where('kind', 'question')->whereNull('parent_id')->where('status', 'approved')->count(),
            ];
        } else {
            $discussions = collect();
            $discussionCounts = ['comments' => 0, 'questions' => 0];
        }
        return view('blog.show', compact('article', 'related', 'discussions', 'discussionCounts'));
    }
}
