@props(['title' => null, 'bodyClass' => ''])
@php
    /** @var \App\Models\User|null $authUser */
    $authUser = auth()->user();
    $theme = $authUser?->theme->value ?? request()->cookie('mp_theme', 'system');
    $density = ($authUser?->text_size->value ?? request()->cookie('mp_text_size', 'normal')) === 'large' ? 'large' : 'normal';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}" @if(in_array($theme, ['light', 'dark'], true)) data-theme="{{ $theme }}" @endif data-density="{{ $density }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="referrer" content="no-referrer">
    <meta name="color-scheme" content="light dark">
    <meta name="theme-color" media="(prefers-color-scheme: light)" content="#0B4F55">
    <meta name="theme-color" media="(prefers-color-scheme: dark)" content="#141312">
    <meta name="app-version" content="{{ config('app.version') }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ __('common.app_name') }}</title>
    <link rel="manifest" href="{{ route('pwa.manifest') }}">
    <link rel="icon" href="{{ asset('icons/icon.svg') }}" type="image/svg+xml">
    <link rel="apple-touch-icon" href="{{ asset('icons/apple-touch-icon.png') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-dvh {{ $bodyClass }}">
    <a href="#main" class="sr-only-focusable btn" style="position:absolute;left:8px;top:8px;z-index:50">{{ __('common.skip_to_content') }}</a>
    {{ $slot }}
    @livewireScripts
</body>
</html>
