@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">USER MANAGEMENT</div>
        <div class="dash-subtitle">Each user signs in with a unique 6-digit PIN and sees only their own data — admins see everything</div>
    </div>
    @if (userCan('users', 'add'))
    <button class="btn-primary-custom ms-auto" data-bs-toggle="modal" data-bs-target="#addModal">
        <i class="bi bi-person-plus"></i> Add User
    </button>
    @endif
</div>

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-people"></i> All Users ({{ count($users) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">#</th>
                    <th style="text-align:left">Name</th>
                    <th style="text-align:center">Role</th>
                    <th style="text-align:left">Access</th>
                    <th style="text-align:center">Links</th>
                    <th style="text-align:center">Meta Rows</th>
                    <th style="text-align:center">GAM Rows</th>
                    <th style="text-align:left">Created</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($users as $i => $u)
                @php $uid = (int)$u['id']; $self = $uid === currentUserId(); @endphp
            <tr style="{{ !$u['active'] ? 'opacity:.45' : '' }}">
                <td class="c-muted">{{ $i + 1 }}</td>
                <td style="font-weight:600;color:#c4b5fd">
                    {{ $u['name'] }}
                    @if ($self)<span style="color:#7dd3fc;font-size:10px;margin-left:4px">(you)</span>@endif
                </td>
                <td style="text-align:center">
                    {!! $u['is_admin']
                        ? '<span style="background:#6c3fc5;color:#fff;border-radius:10px;font-size:10px;padding:2px 8px">ADMIN</span>'
                        : '<span style="background:#1a1a42;color:#7dd3fc;border-radius:10px;font-size:10px;padding:2px 8px">USER</span>' !!}
                </td>
                <td style="text-align:left">
                    @if ($u['is_admin'])
                        <span style="color:var(--text-muted);font-size:11px"><i class="bi bi-infinity"></i> Full access</span>
                    @else
                        @php
                            $scope   = $u['data_scope'] ?? 'own';
                            $nNets   = $adxCounts[$uid] ?? 0;
                            $scopeLabel = $scope === 'all'
                                ? 'All data'
                                : ($scope === 'adx' ? 'ADX · ' . $nNets . ' net' . ($nNets === 1 ? '' : 's') : 'Own data');
                            $scopeColor = $scope === 'all' ? '#f0abfc' : ($scope === 'adx' ? '#7dd3fc' : '#94a3b8');
                            $rname = !empty($u['role_id']) ? ($roleNames[(int) $u['role_id']] ?? null) : null;
                            $vc    = $viewCounts[$uid] ?? 0;
                        @endphp
                        <div style="display:flex;flex-wrap:wrap;gap:4px;align-items:center">
                            <span style="background:#1a1a42;color:{{ $scopeColor }};border-radius:8px;font-size:10px;padding:2px 7px">{{ $scopeLabel }}</span>
                            @if ($rname)
                            <span style="background:#241a42;color:#c4b5fd;border-radius:8px;font-size:10px;padding:2px 7px"><i class="bi bi-person-lock" style="font-size:9px"></i> {{ $rname }}</span>
                            @endif
                            <span style="color:var(--text-muted);font-size:10px">{{ $vc }} page{{ $vc === 1 ? '' : 's' }}</span>
                        </div>
                    @endif
                </td>
                <td style="text-align:center">{{ $counts[$uid]['links']     ?? 0 }}</td>
                <td style="text-align:center">{{ $counts[$uid]['meta_data'] ?? 0 }}</td>
                <td style="text-align:center">{{ $counts[$uid]['gam_data']  ?? 0 }}</td>
                <td class="c-muted" style="font-size:11px">{{ date('d M Y', strtotime($u['created_at'])) }}</td>
                <td style="text-align:center">
                    {!! $u['active'] ? '<span class="chip-mapped">Active</span>' : '<span class="chip-unmapped">Inactive</span>' !!}
                </td>
                <td style="text-align:center">
                    @php
                        // Non-admins can't touch admin accounts or their own (see UsersController).
                        $manageable = isAdmin() || (!$u['is_admin'] && !$self);
                    @endphp
                    <div style="display:flex;gap:6px;justify-content:center">
                        @if ($manageable && userCan('users', 'edit'))
                        <a href="{{ url('/users') }}?edit={{ $uid }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                        @endif
                        @if ($manageable && userCan('users', 'edit') && hasSecondPin($u))
                        <form method="post" action="{{ url('/users') }}" style="display:inline">
                            <input type="hidden" name="action" value="clear_pin2">
                            <input type="hidden" name="id" value="{{ $uid }}">
                            <button type="submit" class="btn-sm-custom" title="Two-step is ON — clear their second PIN (recovery)"
                                    data-confirm="Clear two-step verification for '{{ $u['name'] }}'? They'll sign in with their PIN alone until they set a new second PIN.">
                                <i class="bi bi-shield-slash" style="color:#6ee7b7"></i>
                            </button>
                        </form>
                        @endif
                        @if (!$self && $manageable && userCan('users', 'edit'))
                        <form method="post" action="{{ url('/users') }}" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="{{ $uid }}">
                            <button type="submit" class="btn-sm-custom" title="{{ $u['active'] ? 'Deactivate (blocks login)' : 'Activate' }}">
                                <i class="bi bi-{{ $u['active'] ? 'pause' : 'play' }}-circle"></i>
                            </button>
                        </form>
                        @endif
                        @if (!$self && $manageable && userCan('users', 'delete'))
                        <form method="post" action="{{ url('/users') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $uid }}">
                            <button type="submit" class="btn-danger-custom"
                                    data-confirm="Delete user '{{ $u['name'] }}'? Only possible if they own no data." title="Delete">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="data-card" style="padding:20px">
    <div style="color:var(--text-muted);font-size:12px;line-height:1.8">
        <strong style="color:#a78bfa"><i class="bi bi-info-circle"></i> How it works:</strong><br>
        • Every user gets a <strong>unique 6-digit PIN</strong> — the PIN alone identifies who is signing in.<br>
        • A normal <strong>user</strong> sees only their own links and Meta/GAM data (dashboard, monthly, uploads).<br>
        • <strong>ADX, Accounts, Expenses and Invoices are shared</strong> — every user sees the same records there.<br>
        • An <strong>admin</strong> sees everyone's links and data with the owner's name shown, and can assign them to any user.<br>
        • <strong>Deactivating</strong> a user blocks their login immediately but keeps their data.
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-person-plus"></i> Add User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/users') }}">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Rahul" required maxlength="100">
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">PIN (6 digits) *</label>
                            <input type="password" name="pin" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm PIN *</label>
                            <input type="password" name="pin_confirm" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••" required>
                        </div>
                    </div>
                    @if (isAdmin())
                    <label style="display:flex;align-items:center;gap:8px;color:var(--text-muted);font-size:12px;cursor:pointer">
                        <input type="checkbox" name="is_admin" value="1" style="accent-color:#a78bfa;width:15px;height:15px">
                        Admin — full access, bypasses the permissions below
                    </label>
                    @endif

                    @include('partials.permission_fields')
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
@if ($editUser)
<div class="modal fade show" id="editModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit User — {{ $editUser['name'] }}</h5>
                <a href="{{ url('/users') }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/users') }}">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ (int)$editUser['id'] }}">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" value="{{ $editUser['name'] }}" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New PIN <small style="color:#7dd3fc;font-weight:400">(leave blank to keep the current PIN)</small></label>
                        <input type="password" name="pin" class="form-control" maxlength="6" inputmode="numeric" pattern="\d{6}" placeholder="••••••">
                    </div>
                    @if (isAdmin())
                    <label style="display:flex;align-items:center;gap:8px;color:var(--text-muted);font-size:12px;cursor:pointer">
                        <input type="checkbox" name="is_admin" value="1" {{ $editUser['is_admin'] ? 'checked' : '' }} style="accent-color:#a78bfa;width:15px;height:15px">
                        Admin — full access, bypasses the permissions below
                    </label>
                    @endif

                    @include('partials.permission_fields', [
                        'scopeVal'   => $editUser['data_scope'] ?? 'own',
                        'allowedIds' => $editAllowedAdx,
                        'roleVal'    => $editUser['role_id'] ?? 0,
                        'permVal'    => $editPerms,
                    ])
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/users') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
(function () {
    const rolePerms = @json($rolePerms);

    // Show/hide the ADX picker when data scope is "ADX-wise".
    document.querySelectorAll('.js-scope-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            const form = radio.closest('form');
            if (!form) return;
            const box = form.querySelector('.js-adx-scope-box');
            const checked = form.querySelector('.js-scope-radio:checked');
            if (box) box.style.display = (checked && checked.value === 'adx') ? '' : 'none';
        });
    });

    // Apply a permission template to the grid (template = starting point; manual edits then win).
    document.querySelectorAll('.js-perm-template').forEach(function (sel) {
        sel.addEventListener('change', function () {
            const form = sel.closest('form');
            if (!form || sel.value === '0') return;
            const perms = rolePerms[sel.value];
            if (!perms) return;
            form.querySelectorAll('.perm-cb').forEach(function (cb) { cb.checked = false; });
            Object.keys(perms).forEach(function (page) {
                ['view', 'add', 'edit', 'delete'].forEach(function (act) {
                    if (perms[page][act]) {
                        const cb = form.querySelector('input[name="perm[' + page + '][' + act + ']"]');
                        if (cb) cb.checked = true;
                    }
                });
            });
            if (window.permSyncHeaders) window.permSyncHeaders(form.querySelector('table.perm-grid'));
        });
    });
})();
</script>
@endpush

@endsection
