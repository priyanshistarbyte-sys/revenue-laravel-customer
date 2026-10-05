<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Login · Amaira</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('assets/favicon.svg') }}">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
<style>
  html,body{height:100%}
  body{display:flex;align-items:center;justify-content:center;background:var(--header-bg);padding:16px}
  .login-card{width:380px;max-width:100%;background:#12122a;border:1px solid #2e2e5a;border-radius:14px;
              padding:36px 32px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
  .login-label{display:block;color:var(--text-muted);font-size:11px;margin:0 0 4px}
  .login-input{width:100%;background:#0d0d22;border:1px solid var(--border);border-radius:8px;
               color:#fff;padding:10px 12px;font-size:13px;margin-bottom:14px}
  .login-input:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(108,63,197,.25);outline:none}
  .pw-wrap{position:relative}
  .pw-toggle{position:absolute;right:8px;top:7px;background:none;border:0;color:var(--text-muted);cursor:pointer;font-size:15px}
</style>
</head>
<body>
<div class="login-card">
    <div style="text-align:center;margin-bottom:6px">
        <i class="bi bi-person-vcard" style="font-size:2rem;color:var(--purple)"></i>
    </div>
    <h4 style="text-align:center;color:#fff;margin-bottom:4px">Customer Login</h4>
    <p style="text-align:center;color:var(--text-muted);font-size:12px;margin-bottom:18px">Sign in with your ID and password</p>

    @if ($error)
    <div style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;
                border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:14px;text-align:center">
        {{ $error }}
    </div>
    @elseif ($lockLeft > 0)
    <div style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24;
                border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:14px;text-align:center">
        Too many attempts. Try again in <span id="lockTimer">{{ $lockLeft }}</span>s.
    </div>
    @endif

    <form method="post" action="{{ url('/customer/login') }}" {!! $lockLeft > 0 ? 'style="opacity:.4;pointer-events:none"' : '' !!}>
        @csrf
        <label class="login-label" for="login_id">ID</label>
        <input type="text" name="login_id" id="login_id" class="login-input" value="{{ $loginId }}"
               required maxlength="100" autocomplete="username" autofocus>

        <label class="login-label" for="password">Password</label>
        <div class="pw-wrap">
            <input type="password" name="password" id="password" class="login-input" required autocomplete="current-password">
            <button type="button" class="pw-toggle" aria-label="Show password"
                    onclick="const p=document.getElementById('password');p.type=p.type==='password'?'text':'password';this.firstElementChild.className='bi bi-eye'+(p.type==='text'?'-slash':'')">
                <i class="bi bi-eye"></i>
            </button>
        </div>

        <button type="submit" class="btn-primary-custom w-100" style="justify-content:center;margin-top:4px">
            <i class="bi bi-box-arrow-in-right"></i> Sign In
        </button>
    </form>
</div>

@if ($lockLeft > 0)
<script>
(function () {
    let left = {{ $lockLeft }};
    const el = document.getElementById('lockTimer');
    const t = setInterval(() => { left--; if (el) el.textContent = left; if (left <= 0) { clearInterval(t); location.href = location.pathname; } }, 1000);
})();
</script>
@endif
</body>
</html>
