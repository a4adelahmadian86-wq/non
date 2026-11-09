@php
    $notificationService = app(\App\Services\NotificationService::class);
    $unread = auth()->check() ? $notificationService->unreadCount((int) auth()->id()) : 0;
    $recent = auth()->check() ? $notificationService->latest((int) auth()->id(), 5) : collect();
@endphp
<link rel="stylesheet" href="{{ asset('css/notifications.css') }}?v=20261109center1">
<div class="nf-bell-wrap">
    <details class="nf-bell-menu">
        <summary class="nf-bell" title="اعلان‌ها" aria-label="باز کردن اعلان‌های اخیر">
            <i class="fa-regular fa-bell" aria-hidden="true"></i>
            <span class="nf-bell-badge" data-nf-badge @if($unread < 1) hidden @endif>{{ $unread > 99 ? '99+' : $unread }}</span>
        </summary>
        <section class="nf-bell-panel" aria-label="اعلان‌های اخیر">
            <header><div><strong>اعلان‌های اخیر</strong><small>{{ number_format($unread) }} خوانده‌نشده</small></div><a href="{{ route('platform.notifications') }}">مرکز اعلان‌ها</a></header>
            <div class="nf-bell-items" data-nf-items>
                @forelse($recent as $notification)
                    <a class="nf-bell-item {{ $notification->isRead() ? 'is-read' : 'is-unread' }}" href="{{ route('platform.notifications.visit', $notification->id) }}">
                        <span class="nf-bell-item-icon"><i class="fa-solid {{ $notification->icon() }}" aria-hidden="true"></i></span>
                        <span class="nf-bell-item-copy"><strong>{{ $notification->title }}</strong><small>{{ $notification->created_at?->timezone(config('app.timezone'))->diffForHumans() }}</small></span>
                        @unless($notification->isRead())<i class="nf-bell-unread-dot" aria-label="خوانده‌نشده"></i>@endunless
                    </a>
                @empty
                    <div class="nf-bell-empty"><i class="fa-regular fa-bell-slash"></i><span>هنوز اعلانی ندارید.</span></div>
                @endforelse
            </div>
            <footer><a href="{{ route('platform.notifications') }}">مشاهده همه اعلان‌ها <i class="fa-solid fa-arrow-left"></i></a></footer>
        </section>
    </details>
</div>
<script>
(() => {
  const menus = document.querySelectorAll('.nf-bell-menu');
  if (!menus.length) return;

  const badges = document.querySelectorAll('[data-nf-badge]');
  const refresh = async () => {
    try {
      const response = await fetch(@json(route('notifications.latest')), {
        headers: { 'Accept': 'application/json' },
        credentials: 'same-origin',
        cache: 'no-store'
      });
      if (!response.ok) return;
      const payload = await response.json();
      if (!payload || payload.ok !== true) return;

      const count = Math.max(0, Number(payload.count || 0));
      badges.forEach((badge) => {
        badge.hidden = count === 0;
        badge.textContent = count > 99 ? '99+' : String(count);
      });

      menus.forEach((menu) => {
        const container = menu.querySelector('[data-nf-items]');
        if (!container || !Array.isArray(payload.items)) return;
        const fragment = document.createDocumentFragment();

        if (!payload.items.length) {
          const empty = document.createElement('div');
          empty.className = 'nf-bell-empty';
          const icon = document.createElement('i');
          icon.className = 'fa-regular fa-bell-slash';
          const label = document.createElement('span');
          label.textContent = 'هنوز اعلانی ندارید.';
          empty.append(icon, label);
          fragment.append(empty);
        } else {
          payload.items.forEach((item) => {
            const link = document.createElement('a');
            link.className = 'nf-bell-item ' + (item.read ? 'is-read' : 'is-unread');
            link.href = item.visit_url || @json(route('platform.notifications'));
            const iconWrap = document.createElement('span');
            iconWrap.className = 'nf-bell-item-icon';
            const icon = document.createElement('i');
            icon.className = 'fa-solid ' + (item.icon || 'fa-bell');
            icon.setAttribute('aria-hidden', 'true');
            iconWrap.append(icon);

            const copy = document.createElement('span');
            copy.className = 'nf-bell-item-copy';
            const title = document.createElement('strong');
            title.textContent = item.title || 'اعلان جدید';
            const time = document.createElement('small');
            time.textContent = item.time || '';
            copy.append(title, time);
            link.append(iconWrap, copy);

            if (!item.read) {
              const dot = document.createElement('i');
              dot.className = 'nf-bell-unread-dot';
              dot.setAttribute('aria-label', 'خوانده‌نشده');
              link.append(dot);
            }
            fragment.append(link);
          });
        }

        container.replaceChildren(fragment);
      });
    } catch (_) {
      // Keep the server-rendered preview available when the network is offline.
    }
  };

  menus.forEach((menu) => menu.addEventListener('toggle', () => {
    if (menu.open) refresh();
  }));
  window.setInterval(refresh, 45000);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') refresh();
  });
})();
</script>
