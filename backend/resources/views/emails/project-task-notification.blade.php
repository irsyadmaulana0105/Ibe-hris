<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>{{ $notif->title }}</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; margin: 0; padding: 24px; color: #1e293b; }
  .card { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 28px; border: 1px solid #e2e8f0; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 9999px; background: #e0e7ff; color: #4338ca; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
  h1 { font-size: 18px; margin: 14px 0 8px; color: #0f172a; }
  .meta { color: #64748b; font-size: 13px; margin-bottom: 16px; }
  .body { background: #f8fafc; border-left: 3px solid #6366f1; padding: 12px 14px; margin: 14px 0; border-radius: 6px; font-size: 14px; line-height: 1.5; color: #334155; }
  .btn { display: inline-block; background: #4f46e5; color: #fff !important; text-decoration: none; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 14px; margin-top: 12px; }
  .btn:hover { background: #4338ca; }
  .footer { color: #94a3b8; font-size: 12px; margin-top: 24px; text-align: center; }
  .footer a { color: #64748b; }
</style>
</head>
<body>
  <div class="card">
    <span class="badge">
      @switch($notif->type)
        @case('mention')        Mention      @break
        @case('assigned')       Assignment   @break
        @case('comment')        Komentar     @break
        @case('dependency')     Dependency   @break
        @default                Notifikasi
      @endswitch
    </span>

    <h1>{{ $notif->title }}</h1>

    <div class="meta">
      @if($project)
        Projek: <strong>{{ $project->name }}</strong>
        @if($task) · Task: <strong>{{ $task->title }}</strong>@endif
      @elseif($task)
        Task: <strong>{{ $task->title }}</strong>
      @endif
    </div>

    @if($notif->body)
      <div class="body">{{ $notif->body }}</div>
    @endif

    <a href="{{ $link }}" class="btn">Buka di Projek</a>

    <p class="footer">
      Anda menerima email ini karena di-tag/di-assign di IBE Projects.<br>
      Untuk mematikan email seperti ini, masuk ke profile &rsaquo; Notifikasi.
    </p>
  </div>
</body>
</html>
