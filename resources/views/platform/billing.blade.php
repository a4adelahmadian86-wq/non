@extends('layouts.app')
@section('content')
<div class="container" dir="rtl">
    <h1>پلن و سهمیه</h1>
    @if(session('status'))<div role="status">{{ session('status') }}</div>@endif
    @if($errors->any())<div role="alert">{{ $errors->first() }}</div>@endif
    @if($sub)
        <section>
            <h2>پلن فعلی: {{ $sub->name }}</h2>
            <p>کد پلن: {{ $sub->code }}</p>
            @if($sub->ends_at)<p>اعتبار تا: {{ $sub->ends_at }}</p>@endif
        </section>
    @endif
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px">
        @foreach($plans as $p)
            <article style="border:1px solid #ddd;padding:18px;border-radius:12px">
                <h2>{{ $p->name }}</h2>
                <p>{{ number_format((int)$p->price) }} {{ $p->currency }}</p>
                @php($quotas = is_array($p->quotas) ? $p->quotas : (json_decode((string)$p->quotas, true) ?: []))
                @if($quotas)
                    <ul>
                        @foreach($quotas as $key => $value)
                            <li>{{ $key }}: {{ number_format((int)$value) }}</li>
                        @endforeach
                    </ul>
                @endif
                @if((int)$p->price === 0)
                    <form method="post" action="{{ route('platform.billing.subscribe', $p->id) }}">
                        @csrf
                        <button type="submit">فعال‌سازی پلن رایگان</button>
                    </form>
                @else
                    <p>برای فعال‌سازی این پلن باید پرداخت آنلاین به فرایند اشتراک متصل و تأیید شود.</p>
                    <button type="button" disabled>پرداخت هنوز متصل نشده است</button>
                @endif
            </article>
        @endforeach
    </div>
</div>
@endsection