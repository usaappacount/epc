@extends('layouts.dashboard')

@section('title', 'الملف الشخصي')

@section('content')
    <div class="mx-auto max-w-5xl space-y-5">
        <div>
            <h2 class="ledger-page-title">الملف الشخصي</h2>
            <p class="mt-1 text-sm text-slate-500">إدارة معلومات الحساب وكلمة المرور والإجراءات الحساسة.</p>
        </div>
            <div class="ledger-panel ledger-panel-padding">
                <div class="max-w-xl">
                    @include('profile.partials.update-profile-information-form')
                </div>
            </div>

            <div class="ledger-panel ledger-panel-padding">
                <div class="max-w-xl">
                    @include('profile.partials.update-password-form')
                </div>
            </div>

            <div class="ledger-panel ledger-panel-padding">
                <div class="max-w-xl">
                    @include('profile.partials.delete-user-form')
                </div>
            </div>
    </div>
@endsection
