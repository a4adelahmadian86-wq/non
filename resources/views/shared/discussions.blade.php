@php
    $isProduct = $context === 'product';
    $primaryCount = $isProduct ? (int) ($counts['reviews'] ?? 0) : (int) ($counts['comments'] ?? 0);
    $questionCount = (int) ($counts['questions'] ?? 0);
    $ratingTotal = max(1, $primaryCount);
    $ratingBreakdown = $ratingBreakdown ?? [];
@endphp
<section class="farast-discussions" id="store-discussions" dir="rtl" aria-labelledby="discussion-heading">
    <header class="discussion-hero">
        <div class="discussion-hero-copy">
            <span class="discussion-eyebrow"><i class="fa-regular fa-comments" aria-hidden="true"></i> تجربه‌ها و گفت‌وگوها</span>
            <h2 id="discussion-heading">{{ $isProduct ? 'تجربه خریداران و پاسخ به پرسش‌ها' : 'دیدگاه خوانندگان و گفت‌وگوی تخصصی' }}</h2><a class="discussion-community-link" href="{{ route('community.index') }}">اعضای جامعه فراست <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a>
            <p>{{ $isProduct ? 'پیش از انتخاب، تجربه‌های ثبت‌شده را بخوانید یا پرسش خود را مطرح کنید.' : 'نظر و پرسش خود را درباره محتوای این مقاله با دیگر خوانندگان به اشتراک بگذارید.' }}</p>
            <div class="discussion-hero-stats">
                <span><i class="fa-regular fa-message" aria-hidden="true"></i>{{ number_format($primaryCount) }} {{ $isProduct ? 'دیدگاه' : 'نظر' }}</span>
                <span><i class="fa-regular fa-circle-question" aria-hidden="true"></i>{{ number_format($questionCount) }} پرسش</span>
                <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>نمایش پس از بررسی</span>
            </div>
        </div>
        @if($isProduct)
            <div class="discussion-rating-panel">
                <div class="discussion-rating-main">
                    <strong>{{ $averageRating !== null ? number_format((float) $averageRating, 1) : '—' }}</strong>
                    <span class="discussion-rating-stars {{ $averageRating !== null ? '' : 'is-empty' }}" aria-label="{{ $averageRating !== null ? 'میانگین امتیاز از پنج' : 'هنوز امتیازی ثبت نشده' }}">★★★★★</span>
                    <small>میانگین امتیاز کاربران</small>
                </div>
                <div class="discussion-rating-bars" aria-label="توزیع امتیازها">
                    @for($score = 5; $score >= 1; $score--)
                        @php($scoreCount = (int) ($ratingBreakdown[$score] ?? 0))
                        <div class="discussion-rating-row"><span>{{ $score }}</span><i class="fa-solid fa-star" aria-hidden="true"></i><span class="discussion-rating-track"><span style="width: {{ round(($scoreCount / $ratingTotal) * 100) }}%"></span></span><small>{{ number_format($scoreCount) }}</small></div>
                    @endfor
                </div>
            </div>
        @else
            <div class="discussion-editorial-mark" aria-hidden="true"><i class="fa-regular fa-message"></i><span>FARAST<br>COMMUNITY</span></div>
        @endif
    </header>

    @if(session('discussion_status'))
        <div class="discussion-flash" role="status"><i class="fa-solid fa-circle-check" aria-hidden="true"></i>{{ session('discussion_status') }}</div>
    @endif
    @if($errors->any())
        <div class="discussion-errors" role="alert" aria-live="assertive"><strong>ارسال انجام نشد؛ لطفاً موارد زیر را بررسی کنید.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="discussion-toolbar">
        <div><strong>گفت‌وگوهای منتشرشده</strong><small>مرتب‌سازی بر اساس زمان یا میزان مفیدبودن</small></div>
        <form class="discussion-sort-form" method="get" action="{{ request()->url() }}"><label for="discussion-sort">مرتب‌سازی</label><select id="discussion-sort" name="discussion_sort"><option value="newest" @selected(($discussionSort ?? request()->query('discussion_sort', 'newest')) === 'newest')>جدیدترین</option><option value="most_replied" @selected(($discussionSort ?? request()->query('discussion_sort')) === 'most_replied')>بیشترین پاسخ</option><option value="helpful" @selected(($discussionSort ?? request()->query('discussion_sort')) === 'helpful')>مفیدترین</option><option value="oldest" @selected(($discussionSort ?? request()->query('discussion_sort')) === 'oldest')>قدیمی‌ترین</option>@if($isProduct)<option value="highest_rated" @selected(($discussionSort ?? request()->query('discussion_sort')) === 'highest_rated')>بالاترین امتیاز</option>@endif</select><button type="submit">اعمال</button></form>
        <div class="discussion-filters" role="group" aria-label="فیلتر گفت‌وگوها">
            <input type="radio" name="discussion-filter" id="discussion-filter-all" checked>
            <label for="discussion-filter-all">همه <span>{{ number_format($primaryCount + $questionCount) }}</span></label>
            <input type="radio" name="discussion-filter" id="discussion-filter-primary">
            <label for="discussion-filter-primary">{{ $isProduct ? 'دیدگاه‌ها' : 'نظرها' }} <span>{{ number_format($primaryCount) }}</span></label>
            <input type="radio" name="discussion-filter" id="discussion-filter-questions">
            <label for="discussion-filter-questions">پرسش‌ها <span>{{ number_format($questionCount) }}</span></label>
        </div>
    </div>

    <div class="discussion-list">
        @forelse($entries as $entry)
            <article class="discussion-entry" id="discussion-{{ $entry->id }}" data-kind="{{ $entry->kind }}">
                <div class="discussion-entry-top">
                    <span class="discussion-avatar" aria-hidden="true">{{ $entry->user?->name ? mb_substr($entry->user->name, 0, 1) : '؟' }}</span>
                    <div class="discussion-author">@if($entry->user)<a href="{{ route('community.member',$entry->user) }}"><strong>{{ $entry->user->name ?: 'کاربر' }}</strong></a>@else<strong>حساب حذف‌شده</strong>@endif<small>{{ $entry->created_at?->translatedFormat('d F Y') }}</small><small class="discussion-reply-count">{{ number_format((int)($entry->replies_count ?? 0)) }} پاسخ</small></div>
                    <span class="discussion-kind discussion-kind-{{ $entry->kind }}">{{ ['review'=>'تجربه محصول','question'=>'پرسش','comment'=>'دیدگاه'][$entry->kind] ?? 'گفت‌وگو' }}</span>
                    @if($entry->rating)
                        <span class="discussion-entry-rating" role="img" aria-label="امتیاز {{ $entry->rating }} از ۵">{{ str_repeat('★', (int) $entry->rating) }}{{ str_repeat('☆', 5 - (int) $entry->rating) }}</span>
                    @endif
                </div>
                @if($entry->title)<h3>{{ $entry->title }}</h3>@endif
                <p class="discussion-body">{{ $entry->body }}</p>
                @include('shared.discussion-reactions', ['discussionItem' => $entry])
                @include('shared.discussion-report', ['discussionItem' => $entry])
                <a class="discussion-permalink" href="{{ url()->current() }}#discussion-{{ $entry->id }}" aria-label="پیوند مستقیم به این گفت‌وگو"><i class="fa-solid fa-link" aria-hidden="true"></i> پیوند مستقیم</a>
                @auth
                    <form method="post" action="{{ route('discussions.follow', $entry) }}" class="discussion-follow-form">
                        @csrf
                        <button type="submit" class="{{ $entry->is_following ? 'is-following' : '' }}" aria-pressed="{{ $entry->is_following ? 'true' : 'false' }}">
                            <i class="fa-{{ $entry->is_following ? 'solid' : 'regular' }} fa-bell" aria-hidden="true"></i>
                            {{ $entry->is_following ? 'دنبال می‌کنید · لغو اعلان' : 'دنبال‌کردن پاسخ‌ها' }}
                        </button>
                        <small>{{ number_format((int)($entry->followers_count ?? 0)) }} دنبال‌کننده · با پاسخ جدید اعلان می‌گیرید.</small>
                    </form>
                @endauth

                @auth
                    <form method="post" action="{{ route('discussions.public-reply', $entry) }}" class="discussion-public-reply">
                        @csrf
                        <label for="discussion-reply-{{ $entry->id }}">پاسخ شما به این گفت‌وگو</label>
                        <textarea id="discussion-reply-{{ $entry->id }}" name="body" rows="2" minlength="2" maxlength="3000" required placeholder="پاسخ محترمانه و مرتبط بنویسید..."></textarea>
                        <div class="discussion-form-foot"><small>پاسخ پس از بررسی مدیر منتشر می‌شود.</small><button type="submit">ارسال پاسخ <i class="fa-solid fa-reply" aria-hidden="true"></i></button></div>
                    </form>
                @endauth
                @if($entry->replies->isNotEmpty())
                    <div class="discussion-replies">
                        @foreach($entry->replies as $reply)
                            <div class="discussion-reply" id="discussion-{{ $reply->id }}">
                                <div class="discussion-reply-heading"><i class="fa-solid fa-reply" aria-hidden="true"></i><strong>@if($reply->user?->isAdmin())پاسخ رسمی فراست @elseif($reply->user)<a href="{{ route('community.member',$reply->user) }}">{{ $reply->user->name ?: 'کاربر' }}</a>@else کاربر @endif</strong><small>{{ $reply->created_at?->translatedFormat('d F Y') }}</small></div>
                                <p>{{ $reply->body }}</p>
                                @include('shared.discussion-reactions', ['discussionItem' => $reply])
                                @include('shared.discussion-report', ['discussionItem' => $reply])
                                <a class="discussion-permalink" href="{{ url()->current() }}#discussion-{{ $reply->id }}" aria-label="پیوند مستقیم به این پاسخ"><i class="fa-solid fa-link" aria-hidden="true"></i> پیوند مستقیم</a>
                            </div>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <div class="discussion-empty">
                <span class="discussion-empty-icon"><i class="fa-regular fa-comments" aria-hidden="true"></i></span>
                <strong>هنوز گفت‌وگویی منتشر نشده است</strong>
                <p>اگر تجربه یا پرسشی دارید، می‌توانید اولین مشارکت‌کننده باشید.</p>
            </div>
        @endforelse
    </div>

    <div class="discussion-compose" id="discussion-compose">
        <div class="discussion-compose-heading"><div><span class="discussion-eyebrow">مشارکت شما</span><h3>{{ $isProduct ? 'در انتخاب بهتر به دیگران کمک کنید' : 'به گفت‌وگوی این مقاله بپیوندید' }}</h3></div><span class="discussion-compose-note"><i class="fa-solid fa-lock" aria-hidden="true"></i> ثبت با حساب کاربری</span></div>
        @auth
            <p class="discussion-compose-intro">{{ $isProduct ? 'تجربه واقعی شما درباره کیفیت، کاربرد یا روند استفاده می‌تواند برای دیگران راهگشا باشد.' : 'دیدگاه خود را محترمانه و مرتبط با موضوع مقاله بنویسید.' }}</p>
            <div class="discussion-form-grid">
                @if($isProduct)
                    <form method="post" action="{{ route('discussions.store') }}" class="discussion-form">
                        @csrf<input type="hidden" name="target_type" value="product"><input type="hidden" name="target_key" value="{{ $targetKey }}"><input type="hidden" name="kind" value="review">
                        <div class="discussion-form-title"><span class="discussion-form-icon"><i class="fa-regular fa-star" aria-hidden="true"></i></span><div><h4>ثبت تجربه و امتیاز</h4><small>برای ارزیابی این محصول</small></div></div>
                        <label for="discussion-rating">امتیاز شما</label><select id="discussion-rating" name="rating" required><option value="">انتخاب امتیاز</option>@for($i=5;$i>=1;$i--)<option value="{{ $i }}" @selected((string) old('rating') === (string) $i)>{{ $i }} از ۵ {{ $i >= 4 ? '— عالی' : ($i === 3 ? '— متوسط' : '— نیازمند بهبود') }}</option>@endfor</select>
                        <label for="discussion-review-title">عنوان تجربه <span>اختیاری</span></label><input id="discussion-review-title" name="title" maxlength="180" value="{{ old('title') }}" placeholder="خلاصه تجربه شما">
                        <label for="discussion-review-body">متن تجربه</label><textarea id="discussion-review-body" name="body" rows="4" minlength="10" maxlength="5000" required placeholder="از تجربه استفاده، نقاط قوت یا موارد قابل بهبود بنویسید...">{{ old('body') }}</textarea>
                        <div class="discussion-form-foot"><small>۱۰ تا ۵۰۰۰ نویسه</small><button type="submit">ثبت تجربه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button></div>
                    </form>
                    <form method="post" action="{{ route('discussions.store') }}" class="discussion-form">
                        @csrf<input type="hidden" name="target_type" value="product"><input type="hidden" name="target_key" value="{{ $targetKey }}"><input type="hidden" name="kind" value="question">
                        <div class="discussion-form-title"><span class="discussion-form-icon question"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></span><div><h4>پرسش درباره محصول</h4><small>پیش از خرید ابهام خود را برطرف کنید</small></div></div>
                        <label for="discussion-product-question-title">موضوع پرسش <span>اختیاری</span></label><input id="discussion-product-question-title" name="title" maxlength="180" value="{{ old('title') }}" placeholder="مثلاً سازگاری یا نحوه استفاده">
                        <label for="discussion-product-question-body">متن پرسش</label><textarea id="discussion-product-question-body" name="body" rows="4" minlength="10" maxlength="5000" required placeholder="پرسش خود را دقیق و روشن مطرح کنید...">{{ old('body') }}</textarea>
                        <div class="discussion-form-foot"><small>۱۰ تا ۵۰۰۰ نویسه</small><button type="submit">ارسال پرسش <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button></div>
                    </form>
                @else
                    <form method="post" action="{{ route('discussions.store') }}" class="discussion-form">
                        @csrf<input type="hidden" name="target_type" value="article"><input type="hidden" name="target_key" value="{{ $targetKey }}"><input type="hidden" name="kind" value="comment">
                        <div class="discussion-form-title"><span class="discussion-form-icon"><i class="fa-regular fa-message" aria-hidden="true"></i></span><div><h4>ثبت دیدگاه</h4><small>نظر خود را درباره محتوای مقاله بنویسید</small></div></div>
                        <label for="discussion-article-comment-title">عنوان دیدگاه <span>اختیاری</span></label><input id="discussion-article-comment-title" name="title" maxlength="180" value="{{ old('title') }}" placeholder="عنوان کوتاه">
                        <label for="discussion-article-comment-body">متن دیدگاه</label><textarea id="discussion-article-comment-body" name="body" rows="4" minlength="10" maxlength="5000" required placeholder="دیدگاه شما...">{{ old('body') }}</textarea>
                        <div class="discussion-form-foot"><small>۱۰ تا ۵۰۰۰ نویسه</small><button type="submit">ثبت دیدگاه <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button></div>
                    </form>
                    <form method="post" action="{{ route('discussions.store') }}" class="discussion-form">
                        @csrf<input type="hidden" name="target_type" value="article"><input type="hidden" name="target_key" value="{{ $targetKey }}"><input type="hidden" name="kind" value="question">
                        <div class="discussion-form-title"><span class="discussion-form-icon question"><i class="fa-regular fa-circle-question" aria-hidden="true"></i></span><div><h4>پرسش درباره مقاله</h4><small>برای روشن‌تر شدن نکات مقاله</small></div></div>
                        <label for="discussion-article-question-title">موضوع پرسش <span>اختیاری</span></label><input id="discussion-article-question-title" name="title" maxlength="180" value="{{ old('title') }}" placeholder="موضوع پرسش">
                        <label for="discussion-article-question-body">متن پرسش</label><textarea id="discussion-article-question-body" name="body" rows="4" minlength="10" maxlength="5000" required placeholder="پرسش خود را مطرح کنید...">{{ old('body') }}</textarea>
                        <div class="discussion-form-foot"><small>۱۰ تا ۵۰۰۰ نویسه</small><button type="submit">ارسال پرسش <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></button></div>
                    </form>
                @endif
            </div>
            <p class="discussion-moderation-note"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> برای حفظ کیفیت گفت‌وگوها، پیام‌ها پس از بررسی مدیر منتشر می‌شوند.</p>
        @else
            <div class="discussion-login"><div><strong>برای مشارکت وارد حساب خود شوید</strong><p>پس از ورود می‌توانید تجربه، دیدگاه یا پرسش خود را ثبت کنید.</p></div><a href="{{ route('login') }}">ورود به حساب <i class="fa-solid fa-arrow-left" aria-hidden="true"></i></a></div>
        @endauth
    </div>
</section>