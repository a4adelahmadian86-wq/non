<details class="discussion-report">
    <summary><i class="fa-regular fa-flag" aria-hidden="true"></i> گزارش محتوای نامناسب</summary>
    @auth
        @if((int) $discussionItem->user_id !== (int) auth()->id())
            <form method="post" action="{{ route('discussions.report', $discussionItem) }}" class="discussion-report-form">
                @csrf
                <select name="reason" aria-label="دلیل گزارش" required>
                    <option value="">انتخاب دلیل گزارش</option>
                    <option value="spam">تبلیغات یا هرزنامه</option>
                    <option value="abuse">توهین یا محتوای آزاردهنده</option>
                    <option value="harassment">آزار یا تهدید</option>
                    <option value="misinformation">اطلاعات گمراه‌کننده</option>
                    <option value="other">سایر موارد</option>
                </select>
                <input type="text" name="details" maxlength="1000" placeholder="توضیح تکمیلی (اختیاری)">
                <button type="submit">ارسال گزارش</button>
                <p class="discussion-report-note">گزارش شما محرمانه بررسی می‌شود و به‌تنهایی باعث حذف خودکار محتوا نخواهد شد.</p>
            </form>
        @endif
    @else
        <p class="discussion-report-note">برای گزارش محتوا ابتدا <a href="{{ route('login') }}">وارد حساب خود شوید</a>.</p>
    @endauth
</details>