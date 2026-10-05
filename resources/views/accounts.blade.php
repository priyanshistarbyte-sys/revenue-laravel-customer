@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">META ACCOUNTS</div>
        <div class="dash-subtitle">Facebook / Meta ad account credentials — click 👁 to reveal sensitive fields</div>
    </div>
    <div style="display:flex;gap:10px;margin-left:auto;align-items:center">
        <form method="get" action="{{ url('/accounts') }}" style="display:flex;gap:6px">
            <input type="text" name="q" class="form-control" placeholder="Search name / BM / Ad ID…" value="{{ $search }}" style="width:220px">
            <button type="submit" class="btn-primary-custom"><i class="bi bi-search"></i></button>
            @if ($search)
            <a href="{{ url('/accounts') }}" class="btn-sm-custom" style="padding:7px 10px"><i class="bi bi-x"></i></a>
            @endif
        </form>
        <a href="{{ url('/meta_assets_sync') }}" class="btn-sm-custom"
           data-confirm="Sync Meta ad accounts, pages and pixels from the Graph API for all accounts?"
           title="Fetch ad accounts, pages and pixels from Meta (for the campaign builder)">
            <i class="bi bi-arrow-repeat"></i> Sync assets
        </a>
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#accModal">
            <i class="bi bi-plus-circle"></i> Add Account
        </button>
    </div>
</div>

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-person-badge"></i> All Accounts ({{ count($accounts) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left;width:28px">#</th>
                    <th style="text-align:left">Name</th>
                    <th style="text-align:left">Username</th>
                    <th style="text-align:left">Password</th>
                    <th style="text-align:left">Auth</th>
                    <th style="text-align:left">Email</th>
                    <th style="text-align:left">Email Pwd</th>
                    <th style="text-align:left">Recovery Email</th>
                    <th style="text-align:left">Recovery Pwd</th>
                    <th style="text-align:left">BM ID(s)</th>
                    <th style="text-align:left">Ad Account ID(s)</th>
                    <th style="text-align:left">Page</th>
                    @if ($metaEdit)<th style="text-align:center">API Sync</th>@endif
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if (empty($accounts))
            <tr><td colspan="{{ 14 + ($metaEdit ? 1 : 0) }}" style="text-align:center;color:var(--text-muted);padding:36px">
                No accounts yet. <a href="#" data-bs-toggle="modal" data-bs-target="#accModal" style="color:#a78bfa">Add your first →</a>
            </td></tr>
            @endif
            @foreach ($accounts as $i => $a)
            <tr style="{{ !$a['active'] ? 'opacity:.45' : '' }}">
                <td class="c-muted">{{ $i + 1 }}</td>
                <td style="font-weight:600;color:#c4b5fd;white-space:nowrap">{{ $a['name'] }}</td>
                <td>{!! accSecretCell($a['username']) !!}</td>
                <td>{!! accSecretCell($a['password']) !!}</td>
                <td style="max-width:130px">{!! accSecretCell($a['auth']) !!}</td>
                <td style="font-size:11px">
                    @if ($a['email'])
                    <a href="mailto:{{ $a['email'] }}" style="color:#7dd3fc">{{ $a['email'] }}</a>
                    <button class="btn-copy" data-copy="{{ $a['email'] }}"><i class="bi bi-clipboard"></i></button>
                    @else<span style="color:var(--text-muted)">—</span>@endif
                </td>
                <td>{!! accSecretCell($a['email_password']) !!}</td>
                <td style="font-size:11px">
                    @if ($a['recovery_email'])
                    <a href="mailto:{{ $a['recovery_email'] }}" style="color:#7dd3fc">{{ $a['recovery_email'] }}</a>
                    <button class="btn-copy" data-copy="{{ $a['recovery_email'] }}"><i class="bi bi-clipboard"></i></button>
                    @else<span style="color:var(--text-muted)">—</span>@endif
                </td>
                <td>{!! accSecretCell($a['recovery_password']) !!}</td>
                <td>{!! accTagCell($a['bm']) !!}</td>
                <td>{!! accTagCell($a['ad_account_ids']) !!}</td>
                <td>
                    @if ($a['page_url'])
                    <a href="{{ $a['page_url'] }}" target="_blank" class="url-tag"><i class="bi bi-box-arrow-up-right"></i> Open</a>
                    @else<span style="color:var(--text-muted)">—</span>@endif
                </td>
                @if ($metaEdit)
                    @php $syncReady = trim((string)($a['api_token'] ?? '')) !== '' && trim((string)$a['ad_account_ids']) !== '' && (int)($a['owner_user_id'] ?? 0) > 0; @endphp
                <td style="text-align:center;white-space:nowrap">
                    @if ($syncReady)
                        <a href="{{ url('/meta_sync') }}?account={{ $a['id'] }}&force=1" class="btn-sm-custom" title="Sync now"><i class="bi bi-arrow-repeat"></i></a>
                        <a href="{{ url('/meta_sync') }}?account={{ $a['id'] }}&list_accounts=1" class="btn-sm-custom" title="List ad accounts token can see" target="_blank"><i class="bi bi-list-check"></i></a>
                        @if (!empty($a['last_error']))
                            <div style="color:#f87171;font-size:10px;max-width:160px;white-space:normal" title="{{ $a['last_error'] }}">{{ mb_strimwidth($a['last_error'], 0, 60, '…') }}</div>
                        @elseif (!empty($a['last_sync']))
                            <div style="color:var(--text-muted);font-size:10px">✓ {{ $a['last_sync'] }}</div>
                        @endif
                    @else
                        <span style="color:var(--text-muted);font-size:11px" title="Set Owner + API Token + Ad Account ID(s)">not configured</span>
                    @endif
                </td>
                @endif
                <td style="text-align:center">
                    {!! $a['active'] ? '<span class="chip-mapped">Active</span>' : '<span class="chip-unmapped">Inactive</span>' !!}
                </td>
                <td style="text-align:center">
                    <div style="display:flex;gap:5px;justify-content:center">
                        <a href="{{ url('/accounts') }}?edit={{ $a['id'] }}{{ $search ? '&q='.urlencode($search) : '' }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="{{ url('/accounts') }}" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="{{ $a['id'] }}">
                            <button class="btn-sm-custom" title="Toggle status"><i class="bi bi-{{ $a['active'] ? 'pause' : 'play' }}-circle"></i></button>
                        </form>
                        <form method="post" action="{{ url('/accounts') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $a['id'] }}">
                            <button class="btn-danger-custom" data-confirm="Delete '{{ $a['name'] }}'?"><i class="bi bi-trash3"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

@include('partials.account_modal', ['mType' => 'add', 'acc' => []])
@if ($editAcc)
    @include('partials.account_modal', ['mType' => 'edit', 'acc' => $editAcc])
@endif

@push('scripts')
<script>
function toggleReveal(btn) {
    const td = btn.closest('td');
    const hidden = td.querySelector('.acc-hidden');
    const reveal = td.querySelector('.acc-reveal');
    const icon = btn.querySelector('i');
    const shown = reveal.style.display !== 'none';
    hidden.style.display = shown ? 'inline' : 'none';
    reveal.style.display = shown ? 'none' : 'inline';
    icon.className = shown ? 'bi bi-eye' : 'bi bi-eye-slash';
}
function togglePwd(btn) {
    const input = btn.closest('div').querySelector('.pwd-field');
    const icon = btn.querySelector('i');
    input.type = input.type === 'password' ? 'text' : 'password';
    icon.className = input.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}
document.querySelectorAll('.btn-copy').forEach(btn => {
    btn.addEventListener('click', () => {
        navigator.clipboard.writeText(btn.dataset.copy).then(() => {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2"></i>';
            btn.style.color = '#00c853';
            setTimeout(() => { btn.innerHTML = orig; btn.style.color = ''; }, 1500);
        });
    });
});
document.addEventListener('DOMContentLoaded', () => {
    [
        { wrap: 'addBmWrap',  input: 'addBmInput',  hidden: 'addBmValue'  },
        { wrap: 'addAdWrap',  input: 'addAdInput',  hidden: 'addAdValue'  },
        { wrap: 'editBmWrap', input: 'editBmInput', hidden: 'editBmValue' },
        { wrap: 'editAdWrap', input: 'editAdInput', hidden: 'editAdValue' },
    ].forEach(({ wrap, input, hidden }) => window.initTagInput && initTagInput(wrap, input, hidden, []));
});
</script>
@endpush

@endsection
