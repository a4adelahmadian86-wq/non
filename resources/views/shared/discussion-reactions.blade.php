<div class="discussion-reactions" aria-label="واکنش به این محتوا">
    @php($myReaction = $discussionItem->reactions->first()?->type)
    @auth
        @if((int) $discussionItem->user_id !== (int) auth()->id())
            <form method="post" action="{{ route('discussions.reaction', $discussionItem) }}" class="discussion-reaction-form">@csrf<input type="hidden" name="type" value="like"><button type="submit" class="discussion-reaction-button" aria-pressed="{{ $myReaction === 'like' ? 'true' : 'false' }}" aria-label="پسندیدن، {{ number_format((int) ($discussionItem->likes_count ?? 0)) }} پسند"><i class="fa-regular fa-thumbs-up" aria-hidden="true"></i><span>مفید بود</span><span>{{ number_format((int) ($discussionItem->likes_count ?? 0)) }}</span></button></form>
            <form method="post" action="{{ route('discussions.reaction', $discussionItem) }}" class="discussion-reaction-form">@csrf<input type="hidden" name="type" value="dislike"><button type="submit" class="discussion-reaction-button is-dislike" aria-pressed="{{ $myReaction === 'dislike' ? 'true' : 'false' }}" aria-label="نپسندیدن، {{ number_format((int) ($discussionItem->dislikes_count ?? 0)) }} نپسند"><i class="fa-regular fa-thumbs-down" aria-hidden="true"></i><span>مفید نبود</span><span>{{ number_format((int) ($discussionItem->dislikes_count ?? 0)) }}</span></button></form>
        @else<span class="discussion-reaction-own">نویسندهٔ این محتوا هستید</span>@endif
    @else
        <span class="discussion-reaction-login">برای ثبت واکنش <a href="{{ route('login') }}">وارد حساب شوید</a></span>
        <span class="discussion-reaction-own"><i class="fa-regular fa-thumbs-up" aria-hidden="true"></i> {{ number_format((int) ($discussionItem->likes_count ?? 0)) }}</span>
        <span class="discussion-reaction-own"><i class="fa-regular fa-thumbs-down" aria-hidden="true"></i> {{ number_format((int) ($discussionItem->dislikes_count ?? 0)) }}</span>
    @endauth
</div>