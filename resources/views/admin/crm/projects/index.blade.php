@extends('layouts.app')
@section('content')
<section class="farast-crm" dir="rtl">
  <header class="farast-crm__header">
    <div>
      <span class="farast-crm__eyebrow">FARAST · OPERATIONS WORKSPACE</span>
      <h1>پروژه‌ها و پرونده‌های مشتریان</h1>
      <p>نمای یکپارچه پروژه، اسناد، سفارش‌ها، پرداخت‌ها و خدمات انسانی در یک پرونده.</p>
    </div>
    <a class="farast-crm__back" href="{{ route('admin.index') }}"><i class="fa-solid fa-arrow-right"></i> بازگشت به مرکز عملیات</a>
  </header>

  <div class="farast-crm__stats">
    <article><span>کل پروژه‌ها</span><strong>{{ number_format($stats['total']) }}</strong></article>
    <article><span>فعال</span><strong>{{ number_format($stats['active']) }}</strong></article>
    <article><span>تکمیل‌شده</span><strong>{{ number_format($stats['completed']) }}</strong></article>
    <article><span>در انتظار</span><strong>{{ number_format($stats['pending']) }}</strong></article>
  </div>

  <form class="farast-crm__filters" method="get" action="{{ route('admin.crm.projects.index') }}">
    <label class="farast-crm__search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" name="q" value="{{ $filters['q'] }}" placeholder="جستجو در نام پروژه یا مشخصات مشتری"></label>
    <select name="status" aria-label="وضعیت پروژه">
      <option value="">همه وضعیت‌ها</option>
      @foreach(['active'=>'فعال','pending'=>'در انتظار','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده','archived'=>'بایگانی'] as $value=>$label)
        <option value="{{ $value }}" @selected($filters['status']===$value)>{{ $label }}</option>
      @endforeach
    </select>
    <input name="project_type" value="{{ $filters['project_type'] }}" placeholder="نوع پروژه">
    <button type="submit"><i class="fa-solid fa-filter"></i> فیلتر</button>
    <a href="{{ route('admin.crm.projects.index') }}">حذف فیلترها</a>
  </form>

  <div class="farast-crm__table-wrap">
    <table class="farast-crm__table">
      <thead><tr><th>پروژه</th><th>مشتری</th><th>نوع و وضعیت</th><th>اسناد متصل</th><th>آخرین تغییر</th><th></th></tr></thead>
      <tbody>
      @forelse($projects as $project)
        <tr>
          <td><strong>{{ $project->name ?: 'پروژه بدون عنوان' }}</strong><small>شناسه #{{ $project->id }}</small></td>
          <td><strong>{{ $project->user?->name ?: 'کاربر حذف‌شده' }}</strong><small>{{ $project->user?->mobile ?: $project->user?->email ?: '—' }}</small></td>
          <td><span class="farast-crm__type">{{ $project->project_type ?: 'عمومی' }}</span><small>{{ ['active'=>'فعال','pending'=>'در انتظار','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده','archived'=>'بایگانی'][$project->status] ?? $project->status }}</small></td>
          <td><span class="farast-crm__counts"><b>{{ number_format($project->documents_count) }}</b> سند کاری <b>{{ number_format($project->typing_documents_count) }}</b> سند تایپ</span></td>
          <td>{{ $project->updated_at?->format('Y/m/d H:i') ?: '—' }}</td>
          <td><a class="farast-crm__open" href="{{ route('admin.crm.projects.show', $project->id) }}">مشاهده پرونده <i class="fa-solid fa-arrow-left"></i></a></td>
        </tr>
      @empty
        <tr><td colspan="6" class="farast-crm__empty">پروژه‌ای با این مشخصات پیدا نشد.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="farast-crm__pagination">{{ $projects->links() }}</div>
</section>
@endsection
@push('styles')
<style>
.farast-crm{max-width:1500px;margin:0 auto;padding:30px clamp(14px,3vw,42px) 60px;color:#18243b;font-family:Vazirmatn,Tahoma,sans-serif}
.farast-crm__header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:24px}
.farast-crm__eyebrow{font-size:10px;font-weight:800;letter-spacing:1.4px;color:#6380a9}
.farast-crm h1{font-size:clamp(22px,3vw,30px);margin:8px 0;color:#14213a}
.farast-crm__header p{margin:0;color:#77869d;font-size:13px;line-height:1.9}
.farast-crm__back,.farast-crm__open{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:11px;padding:10px 14px;background:#edf4ff;color:#2367cb;font-size:12px;font-weight:700;white-space:nowrap}
.farast-crm__stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
.farast-crm__stats article{padding:18px 20px;border:1px solid #e7edf5;border-radius:16px;background:#fff;box-shadow:0 8px 28px rgba(20,40,80,.045)}
.farast-crm__stats span,.farast-crm__stats strong{display:block}.farast-crm__stats span{font-size:11px;color:#8390a4}.farast-crm__stats strong{font-size:25px;margin-top:8px;color:#1c3155}
.farast-crm__filters{display:flex;gap:9px;align-items:center;flex-wrap:wrap;background:#fff;border:1px solid #e7edf5;border-radius:15px;padding:12px;margin-bottom:14px}
.farast-crm__filters input,.farast-crm__filters select{min-height:42px;border:1px solid #e1e8f1;border-radius:9px;padding:9px 11px;background:#fbfcfe;color:#263650;font:12px Vazirmatn,Tahoma,sans-serif}
.farast-crm__search{display:flex;align-items:center;gap:8px;flex:1;min-width:230px}.farast-crm__search input{width:100%;border:0;background:transparent;outline:0}
.farast-crm__filters button{min-height:42px;border:0;border-radius:9px;padding:0 17px;background:#1769df;color:#fff;font:700 12px Vazirmatn,Tahoma;cursor:pointer}
.farast-crm__filters>a{font-size:11px;color:#71819a;text-decoration:none;padding:8px}
.farast-crm__table-wrap{overflow:auto;background:#fff;border:1px solid #e7edf5;border-radius:16px;box-shadow:0 8px 28px rgba(20,40,80,.045)}
.farast-crm__table{width:100%;border-collapse:collapse;min-width:850px;text-align:right}
.farast-crm__table th{padding:14px 16px;background:#f7f9fc;color:#77869d;font-size:10px;font-weight:700;white-space:nowrap}
.farast-crm__table td{padding:15px 16px;border-top:1px solid #edf1f6;font-size:11px;color:#52627b;vertical-align:middle}
.farast-crm__table td strong,.farast-crm__table td small{display:block}.farast-crm__table td strong{font-size:12px;color:#1d2b43}.farast-crm__table td small{font-size:10px;color:#8a97aa;margin-top:5px}
.farast-crm__type{display:inline-block;padding:4px 8px;border-radius:7px;background:#f0f5ff;color:#386fbd;font-size:10px}
.farast-crm__counts{display:flex;gap:6px;align-items:center;white-space:nowrap}.farast-crm__counts b{color:#245eae}
.farast-crm__open{padding:8px 10px;font-size:10px}.farast-crm__empty{text-align:center!important;padding:35px!important;color:#8996aa!important}
.farast-crm__pagination{margin-top:16px}
@media(max-width:760px){.farast-crm__header{display:block}.farast-crm__back{margin-top:14px}.farast-crm__stats{grid-template-columns:repeat(2,minmax(0,1fr))}.farast-crm__filters>*{width:100%}.farast-crm__search{min-width:0}}
</style>
@endpush
