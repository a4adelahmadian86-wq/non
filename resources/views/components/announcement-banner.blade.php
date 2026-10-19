@php
    use App\Models\Announcement;
    use Illuminate\Support\Facades\Schema;
    $bannerItems = collect();
    if (Schema::hasTable('announcements')) {
        try {
            $bannerItems = Announcement::visible()->pinned()->orderByDesc('created_at')->limit(2)->get();
        } catch (\Throwable) {
            $bannerItems = collect();
        }
    }
@endphp
@if($bannerItems->isNotEmpty())
<div class="farast-announce-banner" dir="rtl">
    @foreach($bannerItems as $a)
        <div class="farast-announce-item type-{{ $a->type }}">
            <i class="fa-solid {{ $a->icon() }}"></i>
            <div>
                <strong>{{ $a->title }}</strong>
                <span>{{ \Illuminate\Support\Str::limit($a->body, 120) }}</span>
            </div>
            <a href="{{ $a->link_url ?: route('announcements') }}">جزئیات</a>
            <button type="button" class="farast-announce-close" aria-label="بستن" onclick="this.closest('.farast-announce-item').remove()">&times;</button>
        </div>
    @endforeach
</div>
<style>
.farast-announce-banner{display:grid;gap:8px;margin:0 0 14px}
.farast-announce-item{display:flex;align-items:center;gap:12px;padding:12px 16px;border-radius:14px;background:#eef4ff;border:1px solid #d7e4ff;color:#0b1f4a}
.farast-announce-item.type-warning{background:#fff7ed;border-color:#fed7aa;color:#9a3412}
.farast-announce-item.type-danger{background:#fff1f2;border-color:#fecdd3;color:#9f1239}
.farast-announce-item.type-success{background:#ecfdf5;border-color:#a7f3d0;color:#065f46}
.farast-announce-item i{font-size:16px}
.farast-announce-item strong{display:block;font-size:13px}
.farast-announce-item span{display:block;font-size:11px;opacity:.9;margin-top:2px}
.farast-announce-item a{margin-right:auto;font-size:12px;color:inherit;text-decoration:underline;white-space:nowrap}
.farast-announce-close{border:0;background:transparent;font-size:18px;cursor:pointer;opacity:.55;line-height:1}
</style>
@endif
