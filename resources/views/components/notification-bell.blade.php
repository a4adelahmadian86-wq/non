@php
    $unread = 0;
    try {
        $unread = app(\App\Services\NotificationService::class)->unreadCount(auth()->id());
    } catch (\Throwable) {}
@endphp
<link rel="stylesheet" href="{{ asset('css/notifications.css') }}?v=20261019n1">
<a href="{{ route('platform.notifications') }}" class="nf-bell" title="اعلان‌ها" aria-label="اعلان‌ها">
    <i class="fa-regular fa-bell"></i>
    @if($unread > 0)
        <span class="nf-bell-badge" data-nf-badge>{{ $unread > 99 ? '99+' : $unread }}</span>
    @else
        <span class="nf-bell-badge" data-nf-badge hidden>0</span>
    @endif
</a>
<script>
(() => {
  const badge = document.querySelector('[data-nf-badge]');
  if (!badge) return;
  const refresh = async () => {
    try {
      const r = await fetch(@json(route('notifications.unread')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
      if (!r.ok) return;
      const j = await r.json();
      const n = Number(j.count || 0);
      if (n > 0) {
        badge.hidden = false;
        badge.textContent = n > 99 ? '99+' : String(n);
      } else {
        badge.hidden = true;
      }
    } catch (_) {}
  };
  setInterval(refresh, 45000);
})();
</script>
