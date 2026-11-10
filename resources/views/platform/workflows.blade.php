@extends('layouts.app')

@section('content')
<div class="container workflow-studio" dir="rtl">
    <style>
        .workflow-studio{max-width:1180px;padding-top:24px;padding-bottom:48px;color:#1b2a42}
        .wf-hero{padding:22px 24px;border:1px solid #e2e9f3;border-radius:18px;background:linear-gradient(125deg,#fff,#f5f8ff);margin-bottom:16px}
        .wf-hero h1{font-size:23px;margin:0 0 7px}.wf-hero p{font-size:12px;line-height:2;color:#70809a;margin:0}
        .wf-card{background:#fff;border:1px solid #e4eaf2;border-radius:15px;padding:18px;margin-bottom:15px;box-shadow:0 7px 24px #1e365008}
        .wf-card h2{font-size:15px;margin:0 0 12px}.wf-muted{font-size:10px;line-height:1.9;color:#8090a7}
        .wf-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
        .wf-field{display:flex;flex-direction:column;gap:5px;font-size:10px;color:#52637d;min-width:0}
        .wf-field input,.wf-field select,.wf-field textarea{width:100%;min-width:0;border:1px solid #dce4ef;border-radius:8px;background:#fbfcfe;padding:9px 10px;font:inherit;font-size:11px;color:#1b2a42}
        .wf-field textarea{min-height:68px;resize:vertical}.wf-full{grid-column:1/-1}
        .wf-step,.wf-condition{border:1px solid #e8edf4;border-radius:12px;padding:13px;margin-top:10px;background:#fbfcfe}
        .wf-step-head,.wf-condition-head{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-bottom:10px;font-size:11px;font-weight:700}
        .wf-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:13px}
        .wf-btn{border:0;border-radius:9px;padding:10px 14px;background:#1959a6;color:#fff;font:inherit;font-size:11px;cursor:pointer}
        .wf-btn.alt{background:#edf3fb;color:#2e527f}.wf-btn.remove{background:#fff0f0;color:#a63c43}
        .wf-checkbox{display:flex;align-items:center;gap:8px;font-size:11px;color:#52637d;margin-top:12px}
        .wf-list{display:grid;gap:9px}.wf-item{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:13px;border:1px solid #e8edf4;border-radius:11px}
        .wf-item strong{font-size:12px}.wf-item small{display:block;font-size:9px;color:#8290a5;margin-top:4px}
        .wf-status{font-size:9px;padding:5px 8px;border-radius:20px;background:#eef3fa;color:#506784;white-space:nowrap}
        .wf-table{width:100%;border-collapse:collapse;font-size:10px}.wf-table th,.wf-table td{text-align:right;padding:10px;border-bottom:1px solid #edf0f5;vertical-align:top}.wf-table th{background:#f8fafd;color:#667994}
        .wf-error{max-width:360px;white-space:normal;color:#a04444}
        @media(max-width:700px){.workflow-studio{padding-top:12px}.wf-grid{grid-template-columns:1fr}.wf-full{grid-column:auto}.wf-item{align-items:flex-start;flex-direction:column}.wf-hero{padding:18px}.wf-card{padding:13px}}
    </style>

    <header class="wf-hero">
        <h1>استودیو اتوماسیون و گردش‌کار</h1>
        <p>رویدادهای واقعی را به قواعد شرطی و چند اقدام متوالی متصل کنید. اجرای هر رویداد با کلید یکتا ثبت می‌شود؛ شکست‌ها قابل بازیابی‌اند و گردش‌کارهای موفق دوباره اجرا نمی‌شوند.</p>
    </header>

    @if(session('status'))<div class="alert alert-success">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif

    @if($showWorkflowBuilder)
    <section class="wf-card">
        <h2>{{ $editingWorkflow ? 'ویرایش گردش‌کار' : 'ساخت گردش‌کار جدید' }}</h2>
        <form method="post" action="{{ route('platform.workflows.save') }}" id="workflow-builder">
            @csrf
            @if($editingWorkflow)<input type="hidden" name="id" value="{{ $editingWorkflow->id }}">@endif
            @php
                $builderSteps = $editingWorkflow ? (json_decode((string) $editingWorkflow->steps, true) ?: []) : [];
                if (!$builderSteps) $builderSteps = [['action' => 'notify', 'title' => '', 'message' => '', 'description' => '', 'priority' => 'normal', 'due_at' => '']];
                $builderConditions = $editingWorkflow ? (json_decode((string) ($editingWorkflow->conditions ?? '[]'), true) ?: []) : [];
                $conditionFieldLabels = ['order_kind'=>'نوع سفارش','amount_rials'=>'مبلغ سفارش (ریال)','total_rials'=>'مبلغ کل (ریال)','status'=>'وضعیت','event_type'=>'نوع رویداد','document_id'=>'شناسه سند','ticket_id'=>'شناسه تیکت','product_id'=>'شناسه محصول','organization_id'=>'شناسه سازمان','team_id'=>'شناسه تیم'];
                $conditionOperatorLabels = ['equals'=>'برابر است با','not_equals'=>'برابر نیست با','greater_than'=>'بزرگ‌تر از','greater_or_equal'=>'بزرگ‌تر یا مساوی','less_than'=>'کمتر از','less_or_equal'=>'کمتر یا مساوی','contains'=>'شامل','in'=>'یکی از مقادیر','not_in'=>'هیچ‌کدام از مقادیر','exists'=>'وجود دارد'];
            @endphp
            <div class="wf-grid">
                <label class="wf-field">نام گردش‌کار<input name="name" required maxlength="160" value="{{ $editingWorkflow->name ?? '' }}" placeholder="مثلاً پیگیری سفارش‌های با مبلغ بالا"></label>
                <label class="wf-field">رویداد آغاز
                    <select name="trigger_type" required>
                        <option value="document.saved">پس از ذخیره سند</option>
                        <option value="ticket.created">پس از ثبت تیکت</option>
                        <option value="order.paid">پس از پرداخت موفق سفارش</option>
                        <option value="order.deposit_paid">پس از پرداخت پیش‌پرداخت تایپ</option>
                    </select>
                </label>
                <label class="wf-field">فاصله امن بین اجراها (ثانیه)
                    <input type="number" name="cooldown_seconds" min="0" max="86400" value="{{ (int) ($editingWorkflow->cooldown_seconds ?? 0) }}">
                </label>
            </div>

            <h3 style="font-size:12px;margin:18px 0 4px">شرایط اجرا</h3>
            <p class="wf-muted">همه شرط‌ها باید برقرار باشند. اگر شرطی تعریف نکنید، گردش‌کار برای هر رویداد از همان نوع اجرا می‌شود.</p>
            <div id="workflow-conditions">
                @foreach($builderConditions as $conditionIndex => $condition)
                    <div class="wf-condition" data-condition>
                        <div class="wf-condition-head"><span>شرط {{ $loop->iteration }}</span><button type="button" class="wf-btn remove" data-remove-condition>حذف شرط</button></div>
                        <div class="wf-grid">
                            <label class="wf-field">فیلد<select name="conditions[{{ $conditionIndex }}][field]">
                                @foreach($conditionFieldLabels as $field => $label)<option value="{{ $field }}" @selected(($condition['field'] ?? '') === $field)>{{ $label }}</option>@endforeach
                            </select></label>
                            <label class="wf-field">عملگر<select name="conditions[{{ $conditionIndex }}][operator]">
                                @foreach($conditionOperatorLabels as $operator => $label)<option value="{{ $operator }}" @selected(($condition['operator'] ?? 'equals') === $operator)>{{ $label }}</option>@endforeach
                            </select></label>
                            <label class="wf-field wf-full">مقدار<input name="conditions[{{ $conditionIndex }}][value]" maxlength="500" value="{{ $condition['value'] ?? '' }}"></label>
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="wf-muted">هیچ شرطی تعریف نشده است؛ در این حالت تمام رویدادهای متناظر اجرا می‌شوند.</p>
            <div class="wf-actions"><button type="button" class="wf-btn alt" id="add-condition">افزودن شرط</button></div>

            <h3 style="font-size:12px;margin:20px 0 4px">اقدام‌های گردش‌کار</h3>
            <p class="wf-muted">می‌توانید چند اقدام را به ترتیب اجرا کنید. اگر یک اقدام شکست بخورد، اقدام‌های موفق قبلی تکرار نمی‌شوند.</p>
            <div id="workflow-steps">
                @foreach($builderSteps as $stepIndex => $step)
                    <div class="wf-step" data-step>
                        <div class="wf-step-head"><span>گام {{ $loop->iteration }}</span><button type="button" class="wf-btn remove" data-remove-step>حذف گام</button></div>
                        <div class="wf-grid">
                            <label class="wf-field">نوع اقدام<select name="steps[{{ $stepIndex }}][action]">
                                <option value="notify" @selected(($step['action'] ?? 'notify') === 'notify')>ارسال اعلان به صاحب گردش‌کار</option>
                                <option value="create_follow_up" @selected(($step['action'] ?? '') === 'create_follow_up')>ساخت وظیفه پیگیری CRM</option>
                            </select></label>
                            <label class="wf-field">عنوان<input name="steps[{{ $stepIndex }}][title]" maxlength="180" value="{{ $step['title'] ?? '' }}" placeholder="عنوان اعلان یا وظیفه"></label>
                            <label class="wf-field wf-full">متن اعلان / توضیحات<textarea name="steps[{{ $stepIndex }}][message]" rows="2" maxlength="5000">{{ $step['message'] ?? '' }}</textarea></label>
                            <label class="wf-field wf-full">توضیحات وظیفه CRM<textarea name="steps[{{ $stepIndex }}][description]" rows="2" maxlength="4000">{{ $step['description'] ?? '' }}</textarea></label>
                            <label class="wf-field">اولویت<select name="steps[{{ $stepIndex }}][priority]">
                                <option value="normal" @selected(($step['priority'] ?? 'normal') === 'normal')>معمولی</option>
                                <option value="low" @selected(($step['priority'] ?? '') === 'low')>کم</option>
                                <option value="high" @selected(($step['priority'] ?? '') === 'high')>زیاد</option>
                            </select></label>
                            <label class="wf-field">مهلت پیگیری<input type="datetime-local" name="steps[{{ $stepIndex }}][due_at]" value="{{ $step['due_at'] ?? '' }}"></label>
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="wf-actions">
                <button type="button" class="wf-btn alt" id="add-step">افزودن اقدام</button>
                <button class="wf-btn" type="submit">{{ $editingWorkflow ? 'ذخیره تغییرات گردش‌کار' : 'ذخیره و فعال‌سازی گردش‌کار' }}</button>
                @if($editingWorkflow)<a class="wf-btn alt" href="{{ route('platform.workflows') }}">انصراف از ویرایش</a>@endif
            </div>
            <label class="wf-checkbox"><input type="checkbox" name="enabled" value="1" @checked($editingWorkflow ? (bool) $editingWorkflow->enabled : true)> گردش‌کار پس از ذخیره فعال باشد</label>
        </form>
    </section>
    @endif

    <section class="wf-card">
        <h2>گردش‌کارهای من</h2>
        <div class="wf-list">
            @forelse($items as $w)
                <article class="wf-item">
                    <div><strong>{{ $w->name }}</strong><small>{{ $w->trigger_type }} · {{ (int)($w->cooldown_seconds ?? 0) }} ثانیه فاصله اجرا</small></div>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span class="wf-status">{{ $w->enabled ? 'فعال' : 'خاموش' }}</span>
                        @if($canEditWorkflows)
                            <a class="wf-btn alt" href="{{ route('platform.workflows', ['edit' => $w->id]) }}">ویرایش</a>
                        @endif
                        @if($canDeleteWorkflows && (int)($w->runs_count ?? 0) === 0 && (int)($w->event_mappings_count ?? 0) === 0)
                            <form method="post" action="{{ route('platform.workflows.destroy', $w->id) }}" onsubmit="return confirm('این گردش‌کار هیچ سابقه اجرایی ندارد. حذف شود؟')">
                                @csrf @method('delete')
                                <button class="wf-btn remove" type="submit">حذف</button>
                            </form>
                        @endif
                        @if($canRunWorkflows && empty($w->conditions))
                            <form method="post" action="{{ route('platform.workflows.run', $w->id) }}">@csrf<button class="wf-btn alt" type="submit">اجرای دستی</button></form>
                        @elseif($canRunWorkflows)
                            <span class="wf-status">نیازمند رویداد واقعی</span>
                        @else
                            <span class="wf-status">فقط مشاهده</span>
                        @endif
                    </div>
                </article>
            @empty
                <p class="wf-muted">هنوز گردش‌کاری ثبت نشده است.</p>
            @endforelse
        </div>
    </section>

    @if($canViewWorkflowAudit)
    <section class="wf-card">
        <h2>تاریخچه اجراها</h2>
        <div style="overflow:auto">
            <table class="wf-table">
                <thead><tr><th>گردش‌کار</th><th>وضعیت</th><th>شروع</th><th>پایان</th><th>خطا</th></tr></thead>
                <tbody>
                    @forelse($runs as $run)
                        <tr>
                            <td>{{ $run->workflow_name }} <small>#{{ $run->id }}</small></td>
                            <td><span class="wf-status">{{ ['completed'=>'موفق','failed'=>'ناموفق','running'=>'در حال اجرا'][$run->status] ?? $run->status }}</span></td>
                            <td>{{ $run->started_at }}</td><td>{{ $run->finished_at ?: '—' }}</td>
                            <td class="wf-error">{{ $run->error ?: '—' }}</td>
                        </tr>
                    @empty<tr><td colspan="5" class="wf-muted">هنوز اجرایی ثبت نشده است.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </section>
    @endif

    @if($canViewWorkflowAudit)
    <section class="wf-card">
        <h2>تاریخچه تغییرات گردش‌کار</h2>
        <div style="overflow:auto">
            <table class="wf-table">
                <thead><tr><th>عملیات</th><th>گردش‌کار</th><th>زمان</th><th>توضیحات</th></tr></thead>
                <tbody>
                    @forelse($workflowAudit as $entry)
                        <tr>
                            <td>{{ ['workflow.created'=>'ایجاد','workflow.updated'=>'ویرایش','workflow.deleted'=>'حذف'][$entry->action] ?? $entry->action }}</td>
                            <td>#{{ $entry->workflow_id ?? '—' }}</td>
                            <td>{{ $entry->created_at }}</td>
                            <td>{{ data_get(json_decode($entry->after_state ?: $entry->before_state, true), 'name', '—') }}</td>
                        </tr>
                    @empty<tr><td colspan="4" class="wf-muted">تغییری ثبت نشده است.</td></tr>@endforelse
                </tbody>
            </table>
        </div>
    </section>
    @endif
</div>

@if($showWorkflowBuilder)
<template id="workflow-step-template">
    <div class="wf-step" data-step>
        <div class="wf-step-head"><span>گام جدید</span><button type="button" class="wf-btn remove" data-remove-step>حذف گام</button></div>
        <div class="wf-grid">
            <label class="wf-field">نوع اقدام<select name="steps[__INDEX__][action]"><option value="notify">ارسال اعلان به صاحب گردش‌کار</option><option value="create_follow_up">ساخت وظیفه پیگیری CRM</option></select></label>
            <label class="wf-field">عنوان<input name="steps[__INDEX__][title]" maxlength="180"></label>
            <label class="wf-field wf-full">متن اعلان / توضیحات<textarea name="steps[__INDEX__][message]" rows="2" maxlength="5000"></textarea></label>
            <label class="wf-field wf-full">توضیحات وظیفه CRM<textarea name="steps[__INDEX__][description]" rows="2" maxlength="4000"></textarea></label>
            <label class="wf-field">اولویت<select name="steps[__INDEX__][priority]"><option value="normal">معمولی</option><option value="low">کم</option><option value="high">زیاد</option></select></label>
            <label class="wf-field">مهلت پیگیری<input type="datetime-local" name="steps[__INDEX__][due_at]"></label>
        </div>
    </div>
</template>
<template id="workflow-condition-template">
    <div class="wf-condition" data-condition>
        <div class="wf-condition-head"><span>شرط جدید</span><button type="button" class="wf-btn remove" data-remove-condition>حذف شرط</button></div>
        <div class="wf-grid">
            <label class="wf-field">فیلد<select name="conditions[__INDEX__][field]">
                <option value="order_kind">نوع سفارش</option><option value="amount_rials">مبلغ سفارش (ریال)</option><option value="total_rials">مبلغ کل (ریال)</option>
                <option value="status">وضعیت</option><option value="event_type">نوع رویداد</option><option value="document_id">شناسه سند</option>
                <option value="ticket_id">شناسه تیکت</option><option value="product_id">شناسه محصول</option><option value="organization_id">شناسه سازمان</option><option value="team_id">شناسه تیم</option>
            </select></label>
            <label class="wf-field">عملگر<select name="conditions[__INDEX__][operator]">
                <option value="equals">برابر است با</option><option value="not_equals">برابر نیست با</option><option value="greater_than">بزرگ‌تر از</option>
                <option value="greater_or_equal">بزرگ‌تر یا مساوی</option><option value="less_than">کوچک‌تر از</option><option value="less_or_equal">کوچک‌تر یا مساوی</option>
                <option value="contains">شامل</option><option value="in">یکی از مقادیر</option><option value="not_in">هیچ‌کدام از مقادیر</option><option value="exists">وجود دارد</option>
            </select></label>
            <label class="wf-field wf-full">مقدار<input name="conditions[__INDEX__][value]" maxlength="500"></label>
        </div>
    </div>
</template>
<script>
(() => {
    const form = document.getElementById('workflow-builder');
    if (!form) return;
    const steps = document.getElementById('workflow-steps');
    const conditions = document.getElementById('workflow-conditions');
    let stepIndex = steps.querySelectorAll('[data-step]').length;
    let conditionIndex = 0;

    const renumber = (container, selector, label) => {
        container.querySelectorAll(selector).forEach((item, index) => {
            const heading = item.querySelector('.wf-step-head span,.wf-condition-head span');
            if (heading) heading.textContent = label + ' ' + (index + 1);
        });
    };
    const addTemplate = (templateId, container, index) => {
        const template = document.getElementById(templateId);
        const html = template.innerHTML.replaceAll('__INDEX__', String(index));
        container.insertAdjacentHTML('beforeend', html);
    };

    document.getElementById('add-step')?.addEventListener('click', () => {
        if (steps.querySelectorAll('[data-step]').length >= 20) return;
        addTemplate('workflow-step-template', steps, stepIndex++);
        renumber(steps, '[data-step]', 'گام');
    });
    document.getElementById('add-condition')?.addEventListener('click', () => {
        if (conditions.querySelectorAll('[data-condition]').length >= 10) return;
        addTemplate('workflow-condition-template', conditions, conditionIndex++);
        renumber(conditions, '[data-condition]', 'شرط');
    });
    form.addEventListener('click', (event) => {
        const removeStep = event.target.closest('[data-remove-step]');
        const removeCondition = event.target.closest('[data-remove-condition]');
        if (removeStep && steps.querySelectorAll('[data-step]').length > 1) {
            removeStep.closest('[data-step]')?.remove();
            renumber(steps, '[data-step]', 'گام');
        }
        if (removeCondition) {
            removeCondition.closest('[data-condition]')?.remove();
            renumber(conditions, '[data-condition]', 'شرط');
        }
    });
})();
</script>
@endif
@endsection
