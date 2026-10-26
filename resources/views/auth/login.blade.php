@extends('layouts.app')
@section('title','Sign in')
@section('body_class','auth-page')
@section('content')
<div class="auth-layout">
    <section class="auth-story" aria-labelledby="auth-story-title">
        <a class="guest-brand" href="{{ route('home') }}" aria-label="FurniCraft home"><span class="brand-mark" aria-hidden="true">F</span> FurniCraft</a>
        <div class="auth-story-copy">
            <p class="section-kicker">Lance Furniture Shop</p>
            <h1 id="auth-story-title">Furniture choices that begin with your room.</h1>
            <p>Compare locally crafted pieces by size, budget, wood, finish, and style. Browse the catalog freely. Sign in to save favorites, plan your room, and request a reservation.</p>
        </div>
        <p class="small mb-0 auth-story-note">Web-based planning only. No payments, delivery tracking, 3D, or AR.</p>
    </section>
    <section class="auth-panel" aria-labelledby="login-heading">
        <div class="auth-panel-inner">
            <p class="section-kicker">Welcome back</p>
            <h2 id="login-heading">Sign in</h2>
            <p class="text-muted auth-panel-intro">Use your customer or administrator account.</p>
            @if($redirect)
                <div class="alert alert-info fc-alert" role="status"><strong>Log in to access.</strong> Sign in to continue with your saved favorites, recommendations, room preview, or reservation.</div>
            @endif
            <form method="POST" action="{{ route('login.store') }}" class="vstack gap-3 auth-form">
                @csrf
                <input type="hidden" name="redirect" value="{{ old('redirect', $redirect) }}">
                <div>
                    <label class="form-label" for="email">Email address</label>
                    <input class="form-control @error('email') is-invalid @enderror" id="email" type="email" name="email" value="{{ old('email') }}" maxlength="255" required autofocus autocomplete="email" inputmode="email" aria-describedby="email-error">
                    @error('email')<div class="invalid-feedback" id="email-error">{{ $message }}</div>@enderror
                </div>
                <div>
                    <div class="d-flex justify-content-between align-items-center gap-3">
                        <label class="form-label" for="password">Password</label>
                        <a class="auth-inline-link" href="{{ route('password.request') }}">Forgot password?</a>
                    </div>
                    <div class="input-group">
                        <input class="form-control @error('password') is-invalid @enderror" id="password" type="password" name="password" required autocomplete="current-password" aria-describedby="password-error">
                        <button class="btn btn-outline-secondary m-0" type="button" data-password-toggle aria-controls="password" aria-label="Show password" aria-pressed="false" title="Show password">
                            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/><path data-password-slash d="M3 3 21 21" style="display:none"/></svg>
                        </button>
                        @error('password')<div class="invalid-feedback" id="password-error">{{ $message }}</div>@enderror
                    </div>
                </div>
                <label class="form-check auth-remember"><input class="form-check-input" type="checkbox" name="remember" value="1" @checked(old('remember'))><span class="form-check-label">Keep me signed in</span></label>
                <button class="btn btn-primary btn-lg w-100" type="submit">Sign in</button>
            </form>
            <p class="auth-switch">New to FurniCraft? <a href="{{ route('register') }}">Create a customer account</a></p>
        </div>
    </section>
</div>
@endsection

