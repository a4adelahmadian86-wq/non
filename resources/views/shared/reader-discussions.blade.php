<section class="reader-discussions" id="reader-discussions" dir="rtl" aria-labelledby="reader-discussions-title">
    <header class="reader-discussions-head">
        <div><span class="reader-discussions-eyebrow">گفت‌وگوی صفحه‌ای</span><h2 id="reader-discussions-title">نظرها درباره بخش‌های این فایل</h2><p>متن دلخواه را در خوانشگر انتخاب کنید تا دیدگاه شما به همان قسمت پیوند بخورد. دیگر خوانندگان می‌توانند پاسخ دهند و واکنش ثبت کنند.</p></div>
        <span class="reader-discussions-count">{{ number_format($readerDiscussions->total()) }} گفت‌وگو</span>
    </header>

    @if(session('discussion_status'))
        <div class="reader-discussions-flash" role="status">{{ session('discussion_status') }}</div>
    @endif
    @if($errors->any())
        <div class="reader-discussions-error" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif

    <div class="reader-discussion-compose">
        @auth
            <form method="post" action="{{ route('discussions.store') }}" id="reader-discussion-form">
                @csrf
                <input type="hidden" name="target_type" value="reader">
                <input type="hidden" name="target_key" value="{{ $product->id }}">
                <input type="hidden" name="kind" value="comment">
                <input type="hidden" name="anchor_page" id="reader-discussion-page" value="1">
                <input type="hidden" name="anchor_quote" id="reader-discussion-quote" value="">
                <input type="hidden" name="anchor_start" id="reader-discussion-start" value="">
                <input type="hidden" name="anchor_end" id="reader-discussion-end" value="">
                <input type="hidden" name="anchor_context" id="reader-discussion-context" value="">
                <label for="reader-discussion-body">دیدگاه شما</label>
                <textarea id="reader-discussion-body" name="body" rows="3" minlength="10" maxlength="5000" required placeholder="درباره قسمت انتخاب‌شده سؤال یا نظر خود را بنویسید...">{{ old('body') }}</textarea>
                <div class="reader-discussion-selection" id="reader-discussion-selection" aria-live="polite">ابتدا بخشی از متن را در خوانشگر انتخاب کنید.</div>
                <div class="reader-discussion-compose-foot"><small>نظر پس از بررسی منتشر می‌شود؛ نقل‌قول انتخاب‌شده به آن متصل خواهد بود.</small><button type="submit">ثبت دیدگاه برای این بخش</button></div>
            </form>
        @else
            <div class="reader-discussion-login">برای ثبت دیدگاه یا پاسخ، <a href="{{ route('login') }}">وارد حساب کاربری</a> شوید. دیدگاه‌های منتشرشده برای همه قابل مشاهده‌اند.</div>
        @endauth
    </div>

    <div class="reader-discussion-list">
        @forelse($readerDiscussions as $entry)
            <article class="reader-discussion-entry" id="discussion-{{ $entry->id }}">
                <header><strong>{{ $entry->user?->name ?: 'کاربر' }}</strong><span>بخش {{ number_format((int) $entry->anchor_page) }}</span><time>{{ $entry->created_at?->translatedFormat('d F Y') }}</time></header>
                @if($entry->anchor_quote)<blockquote>«{{ $entry->anchor_quote }}»</blockquote>@endif
                <p>{{ $entry->body }}</p>
                @include('shared.discussion-reactions', ['discussionItem' => $entry])
                @include('shared.discussion-report', ['discussionItem' => $entry])
                @auth
                    <form method="post" action="{{ route('discussions.follow', $entry) }}" class="reader-discussion-follow">@csrf<button type="submit">{{ $entry->is_following ? 'لغو دنبال‌کردن پاسخ‌ها' : 'دنبال‌کردن پاسخ‌ها' }}</button></form>
                    <form method="post" action="{{ route('discussions.public-reply', $entry) }}" class="reader-discussion-reply-form">@csrf<label for="reader-reply-{{ $entry->id }}">پاسخ شما</label><textarea id="reader-reply-{{ $entry->id }}" name="body" rows="2" minlength="2" maxlength="3000" required placeholder="پاسخ مرتبط و محترمانه..."></textarea><button type="submit">ارسال پاسخ</button></form>
                @endauth
                @if($entry->replies->isNotEmpty())
                    <div class="reader-discussion-replies">
                        @foreach($entry->replies as $reply)
                            <article id="discussion-{{ $reply->id }}"><strong>{{ $reply->user?->name ?: 'کاربر' }}</strong><time>{{ $reply->created_at?->translatedFormat('d F Y') }}</time><p>{{ $reply->body }}</p>@include('shared.discussion-reactions', ['discussionItem' => $reply])</article>
                        @endforeach
                    </div>
                @endif
            </article>
        @empty
            <p class="reader-discussion-empty">هنوز دیدگاهی برای بخش‌های این فایل منتشر نشده است. می‌توانید اولین نفر باشید.</p>
        @endforelse
    </div>
    @if(is_object($readerDiscussions) && method_exists($readerDiscussions, 'links'))
        <nav class="reader-discussion-pagination" aria-label="صفحه‌بندی دیدگاه‌ها">{{ $readerDiscussions->links() }}</nav>
    @endif
</section>

@once
@push('styles')
<style>
.reader-discussions{max-width:1180px;margin:28px auto;padding:24px clamp(14px,3vw,32px);color:var(--reader-text,#e5e7eb);background:var(--reader-panel,#111827);border:1px solid var(--reader-line,#334155);border-radius:16px}.reader-discussions-head,.reader-discussion-entry>header,.reader-discussion-compose-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.reader-discussions-head h2{margin:5px 0;font-size:1.2rem}.reader-discussions-head p,.reader-discussions-eyebrow,.reader-discussion-entry time,.reader-discussion-entry header span{color:var(--reader-muted,#94a3b8);font-size:.78rem}.reader-discussions-count{padding:6px 10px;border:1px solid var(--reader-line);border-radius:20px;font-size:.75rem}.reader-discussion-compose{margin:18px 0;padding:16px;border:1px solid var(--reader-line);border-radius:12px}.reader-discussion-compose label,.reader-discussion-reply-form label{display:block;margin:0 0 8px;font-size:.8rem;font-weight:700}.reader-discussion-compose textarea,.reader-discussion-reply-form textarea{display:block;width:100%;padding:10px;border:1px solid var(--reader-line);border-radius:9px;background:rgba(255,255,255,.04);color:inherit;font:inherit;resize:vertical}.reader-discussion-selection{margin:9px 0;color:var(--reader-muted);font-size:.75rem}.reader-discussion-compose-foot button,.reader-discussion-reply-form button{padding:9px 14px;border:0;border-radius:8px;background:#3478d0;color:white;font:inherit;cursor:pointer}.reader-discussion-compose-foot small{color:var(--reader-muted);font-size:.7rem}.reader-discussion-entry{padding:17px 0;border-top:1px solid var(--reader-line)}.reader-discussion-entry>header{justify-content:flex-start;font-size:.78rem}.reader-discussion-entry>header time{margin-inline-start:auto}.reader-discussion-entry blockquote{margin:12px 0;padding:10px 14px;border-right:3px solid #568cf0;background:rgba(86,140,240,.08);border-radius:6px;font-size:.82rem}.reader-discussion-entry>p,.reader-discussion-replies p{white-space:pre-wrap;line-height:1.9;font-size:.86rem}.reader-discussion-follow button{border:0;background:none;color:#79aaf4;font:inherit;font-size:.75rem;cursor:pointer}.reader-discussion-reply-form{margin-top:12px;display:grid;gap:8px}.reader-discussion-reply-form button{justify-self:start}.reader-discussion-replies{margin:14px 0 0;padding:8px 14px;border-right:2px solid var(--reader-line)}.reader-discussion-replies article{padding:9px 0}.reader-discussion-replies article>strong{font-size:.78rem}.reader-discussion-replies time{margin-inline-start:8px}.reader-discussion-login,.reader-discussion-empty{padding:12px;color:var(--reader-muted);font-size:.82rem}.reader-discussion-login a{color:#79aaf4}.reader-discussions-flash,.reader-discussions-error{padding:10px;margin:10px 0;border-radius:8px;background:rgba(40,160,100,.1);font-size:.8rem}.reader-discussions-error{background:rgba(220,70,70,.1)}.reader-discussion-pagination{margin-top:16px}
</style>
@endpush
@push('scripts')
<script src="/js/farast-reader-discussions.js?v=20261109anchor1" defer></script>
@endpush
@endonce
