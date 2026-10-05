@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">CUSTOMERS</div>
        <div class="dash-subtitle">Each customer has a unique ID, a password and their own page permissions</div>
    </div>
    @if (userCan('customers', 'add'))
    <button class="btn-primary-custom ms-auto" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
        <i class="bi bi-person-plus"></i> Add Customer
    </button>
    @endif
</div>

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-person-vcard"></i> All Customers ({{ count($customers) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">#</th>
                    <th style="text-align:left">Name</th>
                    <th style="text-align:left">ID</th>
                    <th style="text-align:center">Share %</th>
                    <th style="text-align:left">Page Permission</th>
                    <th style="text-align:left">Created</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($customers as $i => $c)
                @php $cid = (int) $c['id']; $cp = $customerPerms[$cid] ?? []; @endphp
                <tr style="{{ !$c['active'] ? 'opacity:.45' : '' }}">
                    <td class="c-muted">{{ $i + 1 }}</td>
                    <td style="text-align:left;font-weight:600;color:#c4b5fd">{{ $c['name'] }}</td>
                    <td style="text-align:left;font-family:monospace;font-size:12px">{{ $c['login_id'] }}</td>
                    <td style="text-align:center">{{ rtrim(rtrim(number_format((float) $c['share_percentage'], 2), '0'), '.') }}%</td>
                    <td style="text-align:left">
                        <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:520px">
                        @foreach (permissionPages() as $page => $meta)
                            @php
                                $pv = $cp[$page] ?? [];
                                $acts = array_values(array_filter(['view','add','edit','delete'], fn($a)=>!empty($pv[$a])));
                            @endphp
                            @if ($acts)
                            <span style="background:#1a1a42;color:#7dd3fc;border-radius:8px;font-size:10px;padding:2px 7px">
                                {{ $meta['label'] }}: {{ implode('/', array_map('ucfirst', $acts)) }}
                            </span>
                            @endif
                        @endforeach
                        @if (empty($cp))<span style="color:var(--text-muted);font-size:11px">No permissions</span>@endif
                        </div>
                    </td>
                    <td class="c-muted" style="font-size:11px">{{ date('d M Y', strtotime($c['created_at'])) }}</td>
                    <td style="text-align:center">
                        {!! $c['active'] ? '<span class="chip-mapped">Active</span>' : '<span class="chip-unmapped">Inactive</span>' !!}
                    </td>
                    <td style="text-align:center">
                        <div style="display:flex;gap:6px;justify-content:center">
                            @if (userCan('customers', 'edit'))
                            <a href="{{ url('/customers') }}?edit={{ $cid }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="{{ url('/customers') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="{{ $cid }}">
                                <button type="submit" class="btn-sm-custom" title="{{ $c['active'] ? 'Deactivate' : 'Activate' }}">
                                    <i class="bi bi-{{ $c['active'] ? 'pause' : 'play' }}-circle"></i>
                                </button>
                            </form>
                            @endif
                            @if (userCan('customers', 'delete'))
                            <form method="post" action="{{ url('/customers') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="{{ $cid }}">
                                <button type="submit" class="btn-danger-custom"
                                        data-confirm="Delete customer '{{ $c['name'] }}'?" title="Delete">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:30px">No customers yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Add Customer Modal -->
@if (userCan('customers', 'add'))
<div class="modal fade" id="addCustomerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-person-plus"></i> Add Customer</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/customers') }}">
                @csrf
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="e.g. Acme Media" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ID *</label>
                            <input type="text" name="login_id" class="form-control" placeholder="e.g. acme01" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._@\-]+" autocomplete="off">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Password *</label>
                            <input type="password" name="password" class="form-control" minlength="6" required autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password *</label>
                            <input type="password" name="password_confirm" class="form-control" minlength="6" required autocomplete="new-password">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Share Percentage *</label>
                            <div class="input-group">
                                <input type="number" name="share_percentage" class="form-control" min="0" max="100" step="0.01" value="0" required>
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <label class="form-label">Page Permission</label>
                    @include('partials.perm_grid')
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Customer</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

<!-- Edit Customer Modal -->
@if ($editCustomer && userCan('customers', 'edit'))
<div class="modal fade show" id="editCustomerModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Customer — {{ $editCustomer['name'] }}</h5>
                <a href="{{ url('/customers') }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/customers') }}">
                @csrf
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ (int)$editCustomer['id'] }}">
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" value="{{ $editCustomer['name'] }}" required maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">ID *</label>
                            <input type="text" name="login_id" class="form-control" value="{{ $editCustomer['login_id'] }}" required minlength="3" maxlength="100" pattern="[A-Za-z0-9._@\-]+" autocomplete="off">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">New Password <small style="color:#7dd3fc;font-weight:400">(leave blank to keep)</small></label>
                            <input type="password" name="password" class="form-control" minlength="6" autocomplete="new-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Confirm Password</label>
                            <input type="password" name="password_confirm" class="form-control" minlength="6" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Share Percentage *</label>
                            <div class="input-group">
                                <input type="number" name="share_percentage" class="form-control" min="0" max="100" step="0.01" value="{{ (float) $editCustomer['share_percentage'] }}" required>
                                <span class="input-group-text">%</span>
                            </div>
                        </div>
                    </div>
                    <label class="form-label">Page Permission</label>
                    @include('partials.perm_grid', ['permVal' => $customerPerms[(int)$editCustomer['id']] ?? []])
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/customers') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
