<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('errors.title.405') }}</title>
    <style>
      :root{--brand:#0f172a;--brand-soft:#e2e8f0}
      body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f7f7f8;color:#111827}
      .wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
      .card{background:#fff;border:1px solid #e5e7eb;border-radius:12px;max-width:560px;width:100%;padding:28px;box-shadow:0 8px 24px rgba(0,0,0,.06);position:relative;overflow:hidden}
      .bar{position:absolute;left:0;top:0;height:4px;width:100%;background:var(--brand)}
      .code{font-size:14px;letter-spacing:1px;text-transform:uppercase;color:#6b7280}
      h1{margin:8px 0 6px;font-size:28px}
      p{margin:0 0 16px;color:#4b5563;line-height:1.5}
      .brand{margin-top:10px;font-size:14px;color:#6b7280}
      .logo{height:40px;margin-bottom:14px}
      a.btn{display:inline-block;background:var(--brand);color:#fff;text-decoration:none;padding:10px 16px;border-radius:8px}
    </style>
  </head>
  <body>
    <div class="wrap">
      <div class="card">
        <div class="bar"></div>
        <img class="logo" src="{{ asset('images/logo.png') }}" alt="{{ __('errors.brand') }}">
        <div class="code">{{ __('errors.code.405') }}</div>
        <h1>{{ __('errors.title.405') }}</h1>
        <p>{{ __('errors.message.405') }}</p>
        <a class="btn" href="{{ url('/') }}">{{ __('errors.action.home') }}</a>
        <div class="brand">{{ __('errors.brand') }}</div>
      </div>
    </div>
  </body>
</html>
