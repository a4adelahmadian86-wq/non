@extends('layouts.app')
@section('content')
<section class="announcement-page" dir="rtl">
    <div class="announcement-page-head">
        <span class="announcement-page-icon"><i class="fa-solid fa-bullhorn"></i></span>
        <div>
            <span class="eyebrow">FARAST UPDATES</span>
            <h1>اعلانات و اطلاع‌رسانی</h1>
            <p>آخرین خبرها، تغییرات سرویس و اطلاعیه‌های مهم فراست.</p>
        </div>
    </div>
    <div class="announcement-page-list">
        @forelse($announcements as $announcement)
            <article class="public-announcement type-{{ $announcement->type }} {{ ($announcement->is_pinned ?? false) ? 'is-pinned' : '' }}">
                <div class="public-announcement-icon">
                    <i class="fa-solid {{ method_exists($announcement, 'icon') ? $announcement->icon() : 'fa-circle-info' }}"></i>
                </div>
                <div>
                    <div class="public-announcement-meta">
                        <span>{{ $announcement->created_at?->format('Y/m/d') }}</span>
                        <span>{{ method_exists($announcement, 'typeLabel') ? $announcement->typeLabel() : $announcement->type }}</span>
                        @if($announcement->is_pinned ?? false)
                            <span class="pin-badge"><i class="fa-solid fa-thumbtack"></i> پین</span>
                        @endif
                    </div>
                    <h2>{{ $announcement->title }}</h2>
                    <p>{{ $announcement->body }}</p>
                    @if($announcement->link_url ?? null)
                        <a href="{{ $announcement->link_url }}" class="announcement-link" target="_blank" rel="noopener">مشاهده لینک مرتبط</a>
                    @endif
                </div>
            </article>
        @empty
            <div class="empty-public-announcements">
                <i class="fa-regular fa-bell-slash"></i>
                <b>اعلان فعالی وجود ندارد</b>
                <span>در صورت انتشار اطلاعیه جدید، اینجا نمایش داده می‌شود.</span>
            </div>
        @endforelse
    </div>
    {{ $announcements->links() }}
</section>
@endsection
