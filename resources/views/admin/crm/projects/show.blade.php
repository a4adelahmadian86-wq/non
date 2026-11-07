@extends('layouts.admin')
@section('admin-content')
@php
  $statusLabels = ['active'=>'فعال','pending'=>'در انتظار','completed'=>'تکمیل‌شده','cancelled'=>'لغوشده','archived'=>'بایگانی'];
  $orderLabels = ['pending'=>'در انتظار پرداخت','paid'=>'پرداخت‌شده','failed'=>'ناموفق','cancelled'=>'لغوشده','refunded'=>'بازپرداخت‌شده'];
@endphp
<section class="farast-crm" dir="rtl">
  <header class="farast-crm__header">
    <div><span class="farast-crm__eyebrow">CUSTOMER 360 · PROJECT DOSSIER #{{ $record->id }}</span><h1>{{ $record->name ?: 'پروژه بدون عنوان' }}</h1><p>پرونده مشتری، اسناد، وضعیت مالی و کارهای تخصصی متصل به این پروژه.</p></div>
    <a class="farast-crm__back" href="{{ route('admin.crm.projects.index') }}"><i class="fa-solid fa-arrow-right"></i> بازگشت به پروژه‌ها</a>
  </header>

  <div class="farast-crm__customer">
    <div class="farast-crm__avatar">{{ mb_substr($record->user?->name ?: 'م',0,1) }}</div>
    <div><strong>{{ $record->user?->name ?: 'کاربر حذف‌شده' }}</strong><span>{{ $record->user?->mobile ?: '—' }} · {{ $record->user?->email ?: 'بدون ایمیل' }}</span></div>
    <span class="farast-crm__status">{{ $statusLabels[$record->status] ?? $record->status }}</span>
  </div>

  <div class="farast-crm__stats">
    <article><span>اسناد کاری</span><strong>{{ number_format($record->documents->count()) }}</strong></article>
    <article><span>اسناد تایپ</span><strong>{{ number_format($record->typingDocuments->count()) }}</strong></article>
    <article><span>سفارش‌ها</span><strong>{{ number_format($orders->count()) }}</strong></article>
    <article><span>کارهای تخصصی</span><strong>{{ number_format($humanTasks->count()) }}</strong></article>
  </div>

  <div class="farast-crm__grid">
    <section class="farast-crm__panel">
      <div class="farast-crm__panel-head"><div><span>WORKSPACE</span><h2>اسناد و خروجی‌های پروژه</h2></div></div>
      <h3>اسناد فضای کاری</h3>
      @forelse($record->documents as $document)
        <div class="farast-crm__row"><div><strong>{{ $document->title }}</strong><small>نسخه {{ $document->revision }} · {{ $document->updated_at?->format('Y/m/d H:i') }}</small></div><span>{{ $document->status }}</span></div>
      @empty
        <p class="farast-crm__muted">سندی در فضای کاری به این پروژه متصل نشده است.</p>
      @endforelse
      <h3>اسناد تایپ و سفارش خدمات</h3>
      @forelse($record->typingDocuments as $document)
        <div class="farast-crm__row"><div><strong>{{ $document->title }}</strong><small>{{ number_format((int)$document->page_count) }} صفحه · {{ number_format((int)$document->word_count) }} واژه · {{ $document->updated_at?->format('Y/m/d H:i') }}</small></div><span>{{ $document->status }}</span></div>
      @empty
        <p class="farast-crm__muted">سند تایپی متصل به این پروژه وجود ندارد.</p>
      @endforelse
    </section>

    <section class="farast-crm__panel">
      <div class="farast-crm__panel-head"><div><span>BILLING</span><h2>وضعیت مالی پروژه</h2></div></div>
      <div class="farast-crm__money"><span>برآورد هزینه</span><strong>{{ number_format((int)$financials['estimated_price_rials']/10) }} تومان</strong></div>
      <div class="farast-crm__money"><span>پرداخت ثبت‌شده</span><strong>{{ number_format((int)$financials['paid_rials']/10) }} تومان</strong></div>
      <div class="farast-crm__money is-due"><span>مانده برآوردی</span><strong>{{ number_format((int)$financials['remaining_rials']/10) }} تومان</strong></div>
      <h3>سفارش‌ها و پرداخت‌ها</h3>
      @forelse($orders as $order)
        <div class="farast-crm__order">
          <div><strong>سفارش #{{ $order->id }}</strong><small>{{ $orderLabels[$order->status] ?? $order->status }} · {{ $order->paid_at?->format('Y/m/d H:i') ?: 'بدون زمان پرداخت' }}</small></div>
          <b>{{ number_format((int)$order->total_rials/10) }} تومان</b>
          @foreach($order->payments as $payment)
            <small class="farast-crm__payment">{{ $payment->gateway }} · {{ $payment->status }} · {{ number_format((int)$payment->amount_rials/10) }} تومان</small>
          @endforeach
        </div>
      @empty
        <p class="farast-crm__muted">برای اسناد تایپ این پروژه سفارشی ثبت نشده است.</p>
      @endforelse
    </section>
  </div>

  <section class="farast-crm__panel farast-crm__tasks">
    <div class="farast-crm__panel-head"><div><span>HUMAN SERVICE</span><h2>وظایف و ارجاع به متخصص</h2><p>تخصیص فقط به کاربران فعال و داخل دامنه دسترسی مدیر مجاز است.</p></div></div>
    @forelse($humanTasks as $task)
      <article class="farast-crm__task">
        <div class="farast-crm__task-info"><strong>{{ $task->capability ?: $task->task_id }}</strong><small>وظیفه #{{ $task->id }} · وضعیت: {{ $task->status }} · SLA: {{ $task->sla ?: '—' }}</small><small>مسئول فعلی: {{ $task->specialist_name ?: 'تخصیص نیافته' }}</small></div>
        <form class="farast-crm__assign" data-task-assign action="{{ route('admin.crm.projects.tasks.assign',[$record->id,$task->id]) }}" method="post">
          @csrf
          <select name="specialist_id" required aria-label="انتخاب متخصص">
            <option value="">انتخاب متخصص</option>
            @foreach($specialists as $specialist)<option value="{{ $specialist->id }}" @selected((int)$task->specialist_id===(int)$specialist->id)>{{ $specialist->name }}</option>@endforeach
          </select>
          <button type="submit">ثبت ارجاع</button>
        </form>
      </article>
    @empty
      <p class="farast-crm__muted">در حال حاضر وظیفه انسانی برای این پروژه ثبت نشده است.</p>
    @endforelse
    <p class="farast-crm__feedback" data-task-feedback role="status" aria-live="polite"></p>
  </section>
</section>
@endsection
@push('styles')
<style>
.farast-crm{max-width:1500px;margin:0 auto;padding:0 0 30px;color:#18243b;font-family:Vazirmatn,Tahoma,sans-serif}
.farast-crm__header{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;margin-bottom:22px}
.farast-crm__eyebrow{font-size:10px;font-weight:800;letter-spacing:1.2px;color:#6380a9}
.farast-crm h1{font-size:clamp(22px,3vw,30px);margin:8px 0;color:#14213a}.farast-crm__header p{margin:0;color:#77869d;font-size:12px;line-height:1.9}
.farast-crm__back{display:inline-flex;align-items:center;gap:8px;text-decoration:none;border-radius:11px;padding:10px 14px;background:#edf4ff;color:#2367cb;font-size:12px;font-weight:700;white-space:nowrap}
.farast-crm__customer{display:flex;align-items:center;gap:12px;padding:15px 18px;background:#fff;border:1px solid #e7edf5;border-radius:15px;margin-bottom:14px}
.farast-crm__avatar{width:42px;height:42px;border-radius:13px;background:#eaf2ff;color:#1769df;display:grid;place-items:center;font-weight:900;font-size:18px}
.farast-crm__customer strong,.farast-crm__customer span{display:block}.farast-crm__customer strong{font-size:13px}.farast-crm__customer div span{font-size:10px;color:#8290a4;margin-top:4px}.farast-crm__status{margin-right:auto;padding:6px 10px;border-radius:99px;background:#edf8f2;color:#20845b;font-size:10px}
.farast-crm__stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:14px}.farast-crm__stats article{padding:16px 18px;border:1px solid #e7edf5;border-radius:15px;background:#fff}.farast-crm__stats span,.farast-crm__stats strong{display:block}.farast-crm__stats span{font-size:10px;color:#8390a4}.farast-crm__stats strong{font-size:23px;margin-top:7px;color:#1c3155}
.farast-crm__grid{display:grid;grid-template-columns:1.15fr .85fr;gap:14px;margin-bottom:14px}.farast-crm__panel{background:#fff;border:1px solid #e7edf5;border-radius:16px;padding:19px;min-width:0}
.farast-crm__panel-head{display:flex;justify-content:space-between;gap:12px;margin-bottom:14px}.farast-crm__panel-head span{font-size:8px;letter-spacing:1px;color:#8393aa;font-weight:800}.farast-crm__panel h2{font-size:15px;margin:4px 0}.farast-crm__panel-head p{font-size:10px;color:#8390a4;margin:4px 0 0}
.farast-crm__panel h3{font-size:11px;margin:17px 0 7px;color:#53637b}.farast-crm__row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:11px 0;border-bottom:1px solid #edf1f6}.farast-crm__row strong,.farast-crm__row small{display:block}.farast-crm__row strong{font-size:11px}.farast-crm__row small{font-size:9px;color:#8b98aa;margin-top:4px}.farast-crm__row>span{font-size:9px;color:#6d7d94}
.farast-crm__muted{font-size:10px;color:#98a3b2;padding:7px 0}.farast-crm__money{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #edf1f6;font-size:10px;color:#78869a}.farast-crm__money strong{font-size:11px;color:#24344e}.farast-crm__money.is-due strong{color:#c47b24}
.farast-crm__order{padding:11px 0;border-bottom:1px solid #edf1f6;display:grid;grid-template-columns:1fr auto;gap:5px}.farast-crm__order strong,.farast-crm__order small{display:block}.farast-crm__order strong{font-size:10px}.farast-crm__order small{font-size:9px;color:#8290a4;margin-top:4px}.farast-crm__order>b{font-size:10px;color:#263c5c}.farast-crm__payment{grid-column:1/-1}
.farast-crm__tasks{margin-bottom:18px}.farast-crm__task{display:flex;align-items:center;justify-content:space-between;gap:15px;padding:13px 0;border-top:1px solid #edf1f6}.farast-crm__task-info strong,.farast-crm__task-info small{display:block}.farast-crm__task-info strong{font-size:11px}.farast-crm__task-info small{font-size:9px;color:#8592a5;margin-top:4px}
.farast-crm__assign{display:flex;gap:7px;align-items:center}.farast-crm__assign select{min-width:180px;max-width:240px;border:1px solid #dfe6ef;border-radius:8px;padding:9px;background:#fbfcfe;font:10px Vazirmatn,Tahoma}.farast-crm__assign button{border:0;border-radius:8px;padding:9px 12px;background:#1769df;color:#fff;font:10px Vazirmatn,Tahoma;cursor:pointer}.farast-crm__feedback{font-size:10px;color:#20845b}
@media(max-width:900px){.farast-crm__grid{grid-template-columns:1fr}}@media(max-width:760px){.farast-crm__header{display:block}.farast-crm__back{margin-top:12px}.farast-crm__stats{grid-template-columns:repeat(2,minmax(0,1fr))}.farast-crm__task{align-items:stretch;flex-direction:column}.farast-crm__assign{width:100%}.farast-crm__assign select{flex:1;min-width:0}}
</style>
@endpush
@push('scripts')
<script>
document.querySelectorAll('[data-task-assign]').forEach((form)=>{
  form.addEventListener('submit',async(event)=>{
    event.preventDefault();
    const button=form.querySelector('button[type="submit"]');
    const feedback=document.querySelector('[data-task-feedback]');
    button.disabled=true;
    try{
      const response=await fetch(form.action,{method:'POST',headers:{'X-CSRF-TOKEN':form.querySelector('input[name="_token"]').value,'Accept':'application/json'},body:new FormData(form)});
      const result=await response.json();
      if(!response.ok||!result.ok) throw new Error(result.message||'ثبت ارجاع انجام نشد.');
      if(feedback) feedback.textContent='ارجاع وظیفه با موفقیت ثبت شد.';
    }catch(error){if(feedback) feedback.textContent=error.message||'خطا در ثبت ارجاع.'}
    finally{button.disabled=false}
  });
});
</script>
@endpush
