@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">SETTINGS</div>
        <div class="dash-subtitle">{{ userCan('settings', 'edit') ? 'Configure exchange rate, GST, and other parameters' : 'Manage your PIN and review the P/L formula' }}</div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        @if (userCan('settings', 'edit'))
        <form method="post" action="{{ url('/settings') }}">
            <input type="hidden" name="form" value="financial">
            <div class="settings-card">
                <h5><i class="bi bi-currency-exchange"></i> Financial Settings</h5>
                <div class="mb-4">
                    <label class="form-label">Default Currency &amp; Exchange Rates</label>
                    <div style="background:var(--header-bg);border:1px solid var(--border);border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:12px">
                        <i class="bi bi-currency-exchange" style="font-size:1.3rem;color:#fbbf24"></i>
                        <div style="flex:1">
                            <div style="font-size:13px;color:#fff">
                                All figures display in <strong style="color:#fbbf24">{{ $defaultCur['symbol'] . ' ' . $defaultCur['code'] }}</strong>
                                — 1 USD = {{ number_format($usdRate, 2) }} {{ $defaultCur['code'] }}
                            </div>
                            <small style="color:var(--text-muted)">GAM revenue (USD) and Meta spend are auto-converted using these rates.</small>
                        </div>
                        <a href="{{ url('/currencies') }}" class="btn-sm-custom"><i class="bi bi-gear"></i> Manage in Currency Master</a>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label">GST Rate (%)</label>
                    <div style="display:flex;align-items:center;gap:10px">
                        <input type="number" name="gst_rate" class="form-control" value="{{ $gstRate }}" step="0.1" min="0" max="50" style="width:100px">
                        <span style="color:var(--text-muted)">% of Meta Spend</span>
                    </div>
                    <small style="color:var(--text-muted)">GST is added on top of Meta spend to calculate total cost.</small>
                </div>
                <button type="submit" class="btn-primary-custom"><i class="bi bi-floppy"></i> Save Settings</button>
            </div>
        </form>
        @endif

        <form method="post" action="{{ url('/settings') }}">
            <input type="hidden" name="form" value="change_pin">
            <div class="settings-card">
                <h5><i class="bi bi-shield-lock"></i> Security — Change My PIN ({{ currentUserName() }})</h5>
                <p style="color:var(--text-muted);font-size:12px;margin-bottom:16px">
                    Your dashboard locks automatically after <strong style="color:#fff">3 hours</strong> from login.
                    Each user signs in with their own unique PIN — changing yours requires your current PIN.
                    @if (userCan('users', 'edit'))Manage other users' PINs on the <a href="{{ url('/users') }}" style="color:#a78bfa">Users page</a>.@endif
                </p>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Current PIN</label>
                        <input type="password" name="current_pin" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">New PIN</label>
                        <input type="password" name="new_pin" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="6 digits" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm New PIN</label>
                        <input type="password" name="new_pin_confirm" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="6 digits" required>
                    </div>
                </div>
                <button type="submit" class="btn-primary-custom"><i class="bi bi-key"></i> Update PIN</button>
            </div>
        </form>

        <form method="post" action="{{ url('/settings') }}">
            <input type="hidden" name="form" value="pin2">
            <div class="settings-card">
                <h5>
                    <i class="bi bi-shield-check"></i> Two-Step Verification
                    @if ($has2)
                    <span style="background:#065f46;color:#6ee7b7;border-radius:8px;font-size:9px;padding:2px 7px;margin-left:6px;vertical-align:middle">ENABLED</span>
                    @else
                    <span style="background:#4b1113;color:#fca5a5;border-radius:8px;font-size:9px;padding:2px 7px;margin-left:6px;vertical-align:middle">DISABLED</span>
                    @endif
                </h5>
                <p style="color:var(--text-muted);font-size:12px;margin-bottom:16px">
                    {{ $has2
                        ? 'Every login asks for your PIN, then this second PIN.'
                        : 'Add a second PIN so signing in needs two codes instead of one. Your PIN alone will no longer be enough to sign in.' }}
                </p>
                <div class="row g-3 mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Current Login PIN</label>
                        <input type="password" name="current_pin" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required autocomplete="off">
                    </div>
                    @if ($has2)
                    <div class="col-md-4">
                        <label class="form-label">Current Second PIN</label>
                        <input type="password" name="current_pin2" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" autocomplete="off">
                    </div>
                    @endif
                    <div class="col-md-4">
                        <label class="form-label">{{ $has2 ? 'New Second PIN' : 'Second PIN' }}</label>
                        <input type="password" name="new_pin2" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="6 digits" autocomplete="off">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Confirm</label>
                        <input type="password" name="new_pin2_confirm" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="6 digits" autocomplete="off">
                    </div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-shield-lock"></i> {{ $has2 ? 'Update Second PIN' : 'Enable Two-Step' }}</button>
                    @if ($has2)
                    <button type="submit" name="disable" value="1" class="btn-danger-custom" formnovalidate data-confirm="Disable two-step verification? Your login will need only one PIN.">
                        <i class="bi bi-shield-slash"></i> Disable
                    </button>
                    @endif
                </div>
                <small style="color:var(--text-muted);display:block;margin-top:10px">
                    Must differ from your login PIN. If you forget it, an admin can clear it from the Users page.
                </small>
            </div>
        </form>
    </div>

    <div class="col-md-6">
        <div class="settings-card">
            <h5><i class="bi bi-calculator"></i> P/L Formula Reference</h5>
            <div style="line-height:2;font-size:12px;color:var(--text-muted)">
                <div><strong style="color:#7dd3fc">GAM Revenue (INR)</strong> = GAM Revenue (USD) × {{ $usdRate }}</div>
                <div><strong style="color:#ff8c00">GST</strong> = Meta Spend × {{ $gstRate }}%</div>
                <div><strong style="color:#fff">Total Cost</strong> = Meta Spend + GST</div>
                <div><strong style="color:#a78bfa">Net P/L</strong> = GAM Revenue (INR) − Total Cost</div>
                <div><strong style="color:#a78bfa">Margin</strong> = Net P/L ÷ Total Cost × 100</div>
            </div>
        </div>

        @if (userCan('settings', 'view'))
        <div class="settings-card">
            <h5><i class="bi bi-database"></i> Database Info</h5>
            @if ($dbErr)
            <p style="color:#ff7777;font-size:12px">DB error: {{ $dbErr }}</p>
            @elseif ($dbInfo)
            <table style="font-size:12px;width:100%;border-collapse:collapse">
                <tr><td style="color:var(--text-muted);padding:4px 0">Meta records</td><td style="color:#fff;text-align:right">{{ number_format($dbInfo['mCount']) }} rows ({{ $dbInfo['mDates'] }} dates)</td></tr>
                <tr><td style="color:var(--text-muted);padding:4px 0">GAM records</td><td style="color:#fff;text-align:right">{{ number_format($dbInfo['gCount']) }} rows ({{ $dbInfo['gDates'] }} dates)</td></tr>
                <tr><td style="color:var(--text-muted);padding:4px 0">Links</td><td style="color:#fff;text-align:right">{{ number_format($dbInfo['lCount']) }} mappings</td></tr>
            </table>
            @endif
        </div>
        @endif
    </div>
</div>

@endsection
