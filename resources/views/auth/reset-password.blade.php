@extends('layouts.app')
@section('title', 'Reset your password')
@section('content')
<div class="auth-layout compact">
    <section class="auth-story reset-password-story">
        <a class="guest-brand" href="{{ route('login') }}"><span class="brand-mark">F</span> FurniCraft</a>
        <div>
            <p class="section-kicker">Account recovery</p>
            <h1>Reset your password securely.</h1>
            <p>Enter the six-digit code we emailed you. Codes expire after 15 minutes and work once.</p>
        </div>
    </section>
    <section class="auth-panel">
        <h2>{{ $verified ? 'Choose a new password' : 'Verify your reset code' }}</h2>
        <p class="text-muted">{{ $verified ? 'Your email code is verified. Set a new password to finish.' : 'Enter the six-digit code sent to your email. You can choose a new password after it is verified.' }}</p>
        @if(session('success')) <div class="alert alert-success fc-alert" role="status">{{ session('success') }}</div> @endif
        @if(session('warning')) <div class="alert alert-warning fc-alert" role="status">{{ session('warning') }}</div> @endif
        @if($errors->any())
            <div class="alert alert-danger fc-alert" role="alert"><strong>Please check the form.</strong><ul class="mb-0 mt-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if($verified)
            <form id="reset-password-form" method="POST" action="{{ route('password.update') }}" class="vstack gap-3">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
                <div><label class="form-label" for="password">New password</label><input class="form-control" id="password" type="password" name="password" autocomplete="new-password" minlength="8" required autofocus></div>
                <div><label class="form-label" for="password_confirmation">Confirm new password</label><input class="form-control" id="password_confirmation" type="password" name="password_confirmation" autocomplete="new-password" minlength="8" required></div>
                <button class="btn btn-primary btn-lg" type="submit">Change password</button>
            </form>
        @else
            <form id="verify-reset-code-form" method="POST" action="{{ route('password.verify') }}" class="vstack gap-3">
                @csrf
                <div><label class="form-label" for="email">Email address</label><input class="form-control" id="email" type="email" name="email" value="{{ old('email', $email) }}" required {{ $email ? 'readonly' : '' }}></div>
                <div class="reset-code-row">
                    <div class="reset-code-field"><label class="form-label" for="code">6-digit code</label><input class="form-control code-input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required autofocus></div>
                    @if($email)
                        <div class="reset-resend-control">
                            <button class="btn btn-outline-primary" id="reset-resend-button" type="submit" form="reset-resend-form" aria-live="polite">Resend</button>
                            <p class="small text-muted mb-0" id="reset-resend-countdown" role="status" aria-live="polite"></p>
                        </div>
                    @endif
                </div>
                <button class="btn btn-primary btn-lg" type="submit">Verify code</button>
            </form>
        @endif
        @if($email)
            <form method="POST" action="{{ route('password.resend') }}" id="reset-resend-form" class="d-none" data-resend-available-at="{{ $resendAvailableAt ?? '' }}">
                @csrf
                <input type="hidden" name="email" value="{{ $email }}">
            </form>
        @else
            <p class="mt-4"><a href="{{ route('password.request') }}">Request a reset code</a></p>
        @endif
        <p class="mt-3 mb-0"><a href="{{ route('login') }}">Return to sign in</a></p>
    </section>
</div>
@if($email)
<script>
(() => {
    const form = document.getElementById('reset-resend-form');
    const button = document.getElementById('reset-resend-button');
    const status = document.getElementById('reset-resend-countdown');
    const deadline = Number(form?.dataset.resendAvailableAt || 0) * 1000;
    if (!form || !button || !status) return;
    const tick = () => {
        const remaining = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        button.disabled = remaining > 0;
        if (remaining > 0) {
            const minutes = String(Math.floor(remaining / 60)).padStart(2, '0');
            const seconds = String(remaining % 60).padStart(2, '0');
            button.textContent = `Resend in ${minutes}:${seconds}`;
            status.textContent = '';
        } else {
            button.textContent = 'Resend';
            status.textContent = '';
        }
    };
    tick();
    window.setInterval(tick, 1000);
})();
</script>
@endif
@endsection
