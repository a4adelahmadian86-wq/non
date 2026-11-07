@extends('layouts.app')

@push('styles')
<link rel="stylesheet" href="{{ asset('css/workspace-documents.css') }}?v=20261107a">
@endpush

@section('content')
<div class="workspace-documents container" dir="rtl">
    <header class="workspace-documents__header">
        <div>
            <span class="workspace-documents__eyebrow"><i class="fa-solid fa-folder-open"></i> کتابخانه شخصی</span>
            <h1>مدیریت اسناد</h1>
            <p>اسناد، پوشه‌ها، موارد حذف‌شده و لینک‌های اشتراک خود را از یک محل مدیریت کنید.</p>
        </div>
        @if($mode !== 'shared' && !$trashed)
            <form class="workspace-documents__new" method="post" action="{{ route('platform.documents.create') }}">
                @csrf
                <input name="title" maxlength="255" placeholder="عنوان سند جدید" required aria-label="عنوان سند جدید">
                <button type="submit"><i class="fa-solid fa-plus"></i> ایجاد سند</button>
            </form>
        @endif
    </header>

    @if(session('status'))
        <div class="workspace-documents__notice" role="status">{{ session('status') }}</div>
    @endif
    @if($errors->any())
        <div class="workspace-documents__notice workspace-documents__notice--error" role="alert">{{ $errors->first() }}</div>
    @endif

    <nav class="workspace-documents__tabs" aria-label="فیلتر اسناد">
        <a href="{{ route('platform.documents') }}" class="{{ $mode === 'documents' ? 'is-active' : '' }}"><i class="fa-solid fa-file-lines"></i> اسناد فعال</a>
        <a href="{{ route('platform.documents', ['favorite' => 1]) }}" class="{{ $mode === 'favorites' ? 'is-active' : '' }}"><i class="fa-solid fa-star"></i> نشان‌دارها</a>
        <a href="{{ route('platform.documents', ['trashed' => 1]) }}" class="{{ $mode === 'trashed' ? 'is-active' : '' }}"><i class="fa-solid fa-trash-can"></i> سطل زباله</a>
        <a href="{{ route('platform.documents', ['shared' => 1]) }}" class="{{ $mode === 'shared' ? 'is-active' : '' }}"><i class="fa-solid fa-share-nodes"></i> اشتراک‌ها</a>
    </nav>

    <div class="workspace-documents__layout">
        <aside class="workspace-documents__folders" id="folder-panel">
            <div class="workspace-documents__section-heading">
                <h2>پوشه‌های من</h2>
                <span>{{ number_format($folders->count()) }}</span>
            </div>
            <a class="workspace-documents__folder-link {{ !request()->filled('folder') && $mode === 'documents' ? 'is-active' : '' }}" href="{{ route('platform.documents') }}">
                <i class="fa-solid fa-layer-group"></i> همه اسناد
            </a>
            @foreach($folders as $folder)
                <a class="workspace-documents__folder-link {{ (int)request()->query('folder') === (int)$folder->id ? 'is-active' : '' }}" href="{{ route('platform.documents', ['folder' => $folder->id]) }}">
                    <i class="fa-regular fa-folder"></i><span>{{ $folder->name }}</span>
                </a>
            @endforeach
            <form class="workspace-documents__folder-form" method="post" action="{{ route('platform.folders.create') }}">
                @csrf
                <label for="new-folder-name">پوشه جدید</label>
                <input id="new-folder-name" name="name" maxlength="120" placeholder="نام پوشه" required>
                <select name="parent_id" aria-label="پوشه والد">
                    <option value="">بدون پوشه والد</option>
                    @foreach($folders as $folder)
                        <option value="{{ $folder->id }}">{{ $folder->name }}</option>
                    @endforeach
                </select>
                <button type="submit"><i class="fa-solid fa-folder-plus"></i> ساخت پوشه</button>
            </form>
        </aside>

        <section class="workspace-documents__content">
            @if($mode === 'shared')
                <div class="workspace-documents__section-heading">
                    <div><h2>لینک‌های اشتراک من</h2><p>لینک‌های فعال و منقضی‌شده‌ای که برای اسناد خود ساخته‌اید.</p></div>
                    <span>{{ number_format($shares->total()) }}</span>
                </div>
                @forelse($shares as $share)
                    @php $expired = $share->expires_at && \Illuminate\Support\Carbon::parse($share->expires_at)->isPast(); @endphp
                    <article class="workspace-documents__share">
                        <div class="workspace-documents__share-icon"><i class="fa-solid fa-link"></i></div>
                        <div class="workspace-documents__share-main">
                            <strong>{{ $share->title }}</strong>
                            <small>{{ $share->permission === 'edit' ? 'اجازه ویرایش' : 'فقط مشاهده' }} · ایجاد {{ optional($share->created_at)->format('Y/m/d H:i') }}</small>
                            <input type="text" readonly value="{{ url('/shared/documents/'.$share->token) }}" aria-label="لینک اشتراک">
                        </div>
                        <span class="workspace-documents__share-state {{ $expired ? 'is-expired' : 'is-active' }}">{{ $expired ? 'منقضی شده' : 'قابل استفاده' }}</span>
                        <button type="button" class="workspace-documents__icon-button" data-copy-value="{{ url('/shared/documents/'.$share->token) }}" aria-label="کپی لینک"><i class="fa-regular fa-copy"></i></button>
                        <form method="post" action="{{ route('platform.documents.shares.destroy', $share->share_id) }}" onsubmit="return confirm('این لینک اشتراک برای همیشه لغو شود؟')">
                            @csrf @method('DELETE')
                            <button class="workspace-documents__danger-button" type="submit">لغو لینک</button>
                        </form>
                    </article>
                @empty
                    <div class="workspace-documents__empty"><i class="fa-solid fa-link-slash"></i><strong>هنوز لینک اشتراکی نساخته‌اید</strong><span>از داخل هر سند می‌توانید لینک مشاهده بسازید.</span></div>
                @endforelse
                <div class="workspace-documents__pagination">{{ $shares->links() }}</div>
            @else
                <div class="workspace-documents__section-heading">
                    <div>
                        <h2>{{ $mode === 'trashed' ? 'اسناد حذف‌شده' : ($mode === 'favorites' ? 'اسناد نشان‌دار' : 'اسناد شما') }}</h2>
                        <p>{{ $mode === 'trashed' ? 'می‌توانید سند را بازیابی کنید یا برای همیشه حذف کنید.' : 'برای ویرایش، عنوان یا محتوای سند را تغییر دهید و ذخیره کنید.' }}</p>
                    </div>
                    <span>{{ number_format($documents->total()) }}</span>
                </div>

                <div class="workspace-documents__list">
                    @forelse($documents as $document)
                        <article class="workspace-documents__document" id="workspace-document-{{ $document->id }}">
                            <div class="workspace-documents__document-head">
                                <span class="workspace-documents__document-icon"><i class="fa-regular fa-file-lines"></i></span>
                                <div><strong>{{ $document->title }}</strong><small>آخرین تغییر: {{ optional($document->updated_at)->format('Y/m/d · H:i') }}</small></div>
                                @if($document->is_favorite)<span class="workspace-documents__favorite"><i class="fa-solid fa-star"></i> نشان‌دار</span>@endif
                            </div>

                            @if($mode === 'trashed')
                                <div class="workspace-documents__document-actions">
                                    <form method="post" action="{{ route('platform.documents.restore', $document->id) }}">@csrf<button type="submit"><i class="fa-solid fa-rotate-left"></i> بازیابی سند</button></form>
                                    <form method="post" action="{{ route('platform.documents.destroy', $document->id) }}" onsubmit="return confirm('سند و نسخه‌های آن برای همیشه حذف شوند؟')">@csrf @method('DELETE')<button class="is-danger" type="submit"><i class="fa-solid fa-trash-can"></i> حذف دائمی</button></form>
                                </div>
                            @else
                                <form class="workspace-documents__editor" method="post" action="{{ route('platform.documents.update', $document->id) }}">
                                    @csrf
                                    <label>عنوان سند<input name="title" value="{{ $document->title }}" maxlength="255" required></label>
                                    <label>پوشه<select name="folder_id"><option value="">بدون پوشه</option>@foreach($folders as $folder)<option value="{{ $folder->id }}" @selected((int)$document->folder_id === (int)$folder->id)>{{ $folder->name }}</option>@endforeach</select></label>
                                    <label class="workspace-documents__body-label">محتوا<textarea name="content" rows="5">{{ $document->content }}</textarea></label>
                                    <button type="submit" class="workspace-documents__save"><i class="fa-solid fa-floppy-disk"></i> ذخیره و ثبت نسخه</button>
                                </form>
                                <div class="workspace-documents__document-actions">
                                    <form method="post" action="{{ route('platform.documents.favorite', $document->id) }}">@csrf<button type="submit"><i class="fa-solid fa-star"></i> {{ $document->is_favorite ? 'حذف از نشان‌دارها' : 'افزودن به نشان‌دارها' }}</button></form>
                                    <button type="button" data-share-endpoint="{{ route('platform.documents.share', $document->id) }}"><i class="fa-solid fa-share-nodes"></i> ساخت لینک اشتراک</button>
                                    <form method="post" action="{{ route('platform.documents.trash', $document->id) }}">@csrf<button class="is-danger" type="submit"><i class="fa-solid fa-trash-can"></i> انتقال به سطل زباله</button></form>
                                </div>
                                <div class="workspace-documents__share-result" hidden>
                                    <label>لینک اشتراک<input type="text" readonly data-share-output></label>
                                    <span role="status" aria-live="polite" data-share-status></span>
                                </div>
                            @endif
                        </article>
                    @empty
                        <div class="workspace-documents__empty"><i class="fa-regular fa-folder-open"></i><strong>موردی برای نمایش وجود ندارد</strong><span>{{ $mode === 'trashed' ? 'سند حذف‌شده‌ای ندارید.' : 'هنوز سندی در این بخش ثبت نشده است.' }}</span></div>
                    @endforelse
                </div>
                <div class="workspace-documents__pagination">{{ $documents->links() }}</div>
            @endif
        </section>
    </div>
</div>

<script>
(() => {
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    document.addEventListener('click', async (event) => {
        const shareButton = event.target.closest('[data-share-endpoint]');
        if (shareButton) {
            const card = shareButton.closest('.workspace-documents__document');
            const result = card?.querySelector('.workspace-documents__share-result');
            const output = result?.querySelector('[data-share-output]');
            const status = result?.querySelector('[data-share-status]');
            shareButton.disabled = true;
            shareButton.setAttribute('aria-busy', 'true');
            if (status) status.textContent = 'در حال ساخت لینک…';
            try {
                const response = await fetch(shareButton.dataset.shareEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': token, 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8'},
                    body: new URLSearchParams({permission: 'view'})
                });
                const data = await response.json();
                if (!response.ok || !data.url) throw new Error(data.message || 'ساخت لینک ناموفق بود.');
                output.value = data.url;
                result.hidden = false;
                try {
                    await navigator.clipboard.writeText(data.url);
                    status.textContent = 'لینک ساخته و کپی شد.';
                } catch (_) {
                    output.focus();
                    output.select();
                    status.textContent = 'لینک ساخته شد؛ آن را کپی کنید.';
                }
            } catch (error) {
                if (status) status.textContent = error.message || 'خطا در ساخت لینک.';
                if (result) result.hidden = false;
            } finally {
                shareButton.disabled = false;
                shareButton.removeAttribute('aria-busy');
            }
            return;
        }

        const copyButton = event.target.closest('[data-copy-value]');
        if (copyButton) {
            const value = copyButton.dataset.copyValue || '';
            try {
                await navigator.clipboard.writeText(value);
                copyButton.setAttribute('aria-label', 'لینک کپی شد');
            } catch (_) {
                window.prompt('لینک را کپی کنید:', value);
            }
        }
    });
})();
</script>
@endsection
