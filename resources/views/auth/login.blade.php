<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login · Amaira</title>
<link rel="icon" type="image/svg+xml" href="{{ asset('assets/favicon.svg') }}">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
<style>
  html,body{height:100%}
  body{display:flex;align-items:center;justify-content:center;background:var(--header-bg)}
  .login-card{width:380px;background:#12122a;border:1px solid #2e2e5a;border-radius:14px;
              padding:36px 32px;box-shadow:0 20px 60px rgba(0,0,0,.5)}
  .otp-row{display:flex;gap:8px;justify-content:center;margin:14px 0}
  .otp-box{width:44px;height:54px;text-align:center;font-size:22px;font-weight:700;
           background:#0d0d22;border:1px solid var(--border);border-radius:8px;color:#fff}
  .otp-box:focus{border-color:var(--purple);box-shadow:0 0 0 3px rgba(108,63,197,.25);outline:none}
</style>
</head>
<body>
<div class="login-card">
    <div style="text-align:center;margin-bottom:6px">
        <i class="bi bi-graph-up-arrow" style="font-size:2rem;color:var(--purple)"></i>
    </div>
    <h4 style="text-align:center;color:#fff;margin-bottom:4px">Amaira</h4>
    @if ($mode === 'verify2')
    <div style="text-align:center;margin-bottom:10px">
        <span style="background:rgba(108,63,197,.18);border:1px solid rgba(167,139,250,.4);color:#c4b5fd;
                     border-radius:20px;font-size:11px;padding:3px 10px">
            <i class="bi bi-shield-lock"></i> Step 2 of 2 · {{ $pendingUser['name'] }}
        </span>
    </div>
    @endif
    <p style="text-align:center;color:var(--text-muted);font-size:12px;margin-bottom:10px">
        @if ($mode === 'setup')
            Create the admin account — your name and a 6-digit PIN
        @elseif ($mode === 'verify2')
            Enter your <strong style="color:#fff">second PIN</strong> to finish signing in
        @else
            Enter your 6-digit PIN to continue
        @endif
    </p>

    @if ($error)
    <div style="background:rgba(248,113,113,.1);border:1px solid rgba(248,113,113,.3);color:#f87171;
                border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:14px;text-align:center">
        {{ $error }}
    </div>
    @endif

    @if ($lockSecondsLeft > 0 && !$error)
    <div style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.3);color:#fbbf24;
                border-radius:8px;padding:10px 14px;font-size:12px;margin-bottom:14px;text-align:center">
        Too many attempts. Try again in <span id="lockTimer">{{ $lockSecondsLeft }}</span>s.
    </div>
    @endif

    <form method="post" action="{{ url('/admin/login') }}" id="pinForm" {!! $lockSecondsLeft > 0 ? 'style="opacity:.4;pointer-events:none"' : '' !!}>
        @csrf
        @if ($mode === 'setup')
        <p style="text-align:center;color:var(--text-muted);font-size:11px;margin:0 0 4px">Your Name</p>
        <input type="text" name="name" required maxlength="100" placeholder="e.g. Parth"
               style="width:100%;background:#0d0d22;border:1px solid var(--border);border-radius:8px;
                      color:#fff;padding:9px 12px;font-size:13px;margin-bottom:12px"
               value="{{ old('name') }}">
        <p style="text-align:center;color:var(--text-muted);font-size:11px;margin:0 0 4px">Choose a 6-digit PIN</p>
        @endif
        <input type="hidden" name="pin" id="pinHidden">
        <div class="otp-row" data-target="pinHidden">
            @for ($i = 0; $i < 6; $i++)
            <input type="password" inputmode="numeric" maxlength="1" class="otp-box otp-input" autocomplete="off">
            @endfor
        </div>

        @if ($mode === 'setup')
        <p style="text-align:center;color:var(--text-muted);font-size:11px;margin:14px 0 4px">Confirm PIN</p>
        <input type="hidden" name="pin_confirm" id="pinConfirmHidden">
        <div class="otp-row" data-target="pinConfirmHidden">
            @for ($i = 0; $i < 6; $i++)
            <input type="password" inputmode="numeric" maxlength="1" class="otp-box otp-input" autocomplete="off">
            @endfor
        </div>
        @endif

        <button type="submit" class="btn-primary-custom w-100" style="justify-content:center;margin-top:18px">
            <i class="bi bi-unlock"></i>
            {{ $mode === 'setup' ? 'Create Admin & Continue' : ($mode === 'verify2' ? 'Verify & Unlock' : 'Unlock') }}
        </button>

        <p style="text-align:center;color:var(--text-muted);font-size:11px;margin-top:14px">
            @if ($mode === 'verify2')
                <a href="{{ url('/admin/login?restart=1') }}" style="color:#a78bfa;text-decoration:none">← Start over</a>
                · Expires in {{ (int)($PIN2_PENDING_TTL / 60) }} min
            @else
                Session stays unlocked for 3 hours.
            @endif
        </p>
    </form>
</div>

<script>
document.querySelectorAll('.otp-row').forEach(row => {
    const boxes  = Array.from(row.querySelectorAll('.otp-box'));
    const hidden = document.getElementById(row.dataset.target);

    function sync() { hidden.value = boxes.map(b => b.value).join(''); }

    boxes.forEach((box, i) => {
        box.addEventListener('input', () => {
            box.value = box.value.replace(/\D/g, '').slice(0, 1);
            if (box.value && boxes[i + 1]) boxes[i + 1].focus();
            sync();
        });
        box.addEventListener('keydown', e => {
            if (e.key === 'Backspace' && !box.value && boxes[i - 1]) boxes[i - 1].focus();
        });
        box.addEventListener('paste', e => {
            const text = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
            if (!text) return;
            e.preventDefault();
            text.split('').forEach((ch, idx) => { if (boxes[idx]) boxes[idx].value = ch; });
            sync();
            (boxes[text.length] || boxes[boxes.length - 1]).focus();
        });
    });
    sync();
});

@if ($lockSecondsLeft > 0)
(function () {
    let left = {{ $lockSecondsLeft }};
    const timerEl = document.getElementById('lockTimer');
    const t = setInterval(() => {
        left--;
        if (timerEl) timerEl.textContent = left;
        if (left <= 0) { clearInterval(t); location.reload(); }
    }, 1000);
})();
@endif

document.querySelector('.otp-box')?.focus();
</script>
</body>
</html>
