@extends('layouts.app')

@section('content')
<link rel="stylesheet" href="{{ asset('css/support-tickets.css') }}?v=20261018list">

<div class="tk-page" dir="rtl">
    <nav class="tk-breadcrumb">
        <div class="tk-breadcrumb-inner">
            <a href="{{ route('support') }}" class="tk-crumb-strong">تیکت ها</a>
            <span class="tk-crumb-sep"></span>
            <a href="{{ route('dashboard') }}" class="tk-crumb-icon" title="داشبورد"><i class="fa-solid fa-house"></i></a>
            <span class="tk-crumb-dash">-</span>
            <a href="{{ route('support') }}">تیکت ها</a>
            <span class="tk-crumb-dash">-</span>
            <span>ثبت تیکت جدید</span>
        </div>
    </nav>

    <main class="tk-main">
        <article class="tk-card tk-card-narrow">
            <div class="tk-card-head">
                <hgroup>
                    <h1>ثبت تیکت جدید</h1>
                    <p>موضوع و شرح مشکل را کامل بنویسید تا سریع‌تر راهنمایی شوید.</p>
                </hgroup>
            </div>
            <div class="tk-divider"></div>

            <form method="post" action="{{ route('support.create') }}" class="tk-form">
                @csrf
                <label class="tk-field">دپارتمان
                    <select name="department">
                        <option value="support">بخش پشتیبانی</option>
                        <option value="sales">بخش فروش</option>
                        <option value="billing">بخش مالی</option>
                        <option value="complaint">بخش شکایات</option>
                    </select>
                </label>
                <label class="tk-field">اولویت
                    <select name="priority">
                        <option value="low">کم</option>
                        <option value="normal" selected>متوسط</option>
                        <option value="high">زیاد</option>
                    </select>
                </label>
                <label class="tk-field">عنوان
                    <input type="text" name="subject" required maxlength="160" value="{{ old('subject') }}" placeholder="موضوع تیکت را بنویسید">
                    @error('subject')<small class="tk-error">{{ $message }}</small>@enderror
                </label>
                <label class="tk-field">شرح پیام
                    <textarea name="body" required maxlength="5000" rows="7" placeholder="مشکل یا سؤال خود را کامل توضیح دهید...">{{ old('body') }}</textarea>
                    @error('body')<small class="tk-error">{{ $message }}</small>@enderror
                </label>
                <div class="tk-form-actions">
                    <a href="{{ route('support') }}" class="tk-btn-ghost">انصراف</a>
                    <button type="submit" class="tk-btn-primary">ثبت تیکت</button>
                </div>
            </form>
        </article>
    </main>
</div>
@endsection
