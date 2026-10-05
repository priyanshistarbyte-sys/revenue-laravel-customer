@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">PERMISSION ROLES</div>
        <div class="dash-subtitle">Reusable permission templates — apply one to a user on the Users page to pre-fill their grid</div>
    </div>
    <button class="btn-primary-custom ms-auto" data-bs-toggle="modal" data-bs-target="#addRoleModal">
        <i class="bi bi-plus-circle"></i> Add Role
    </button>
</div>

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-person-lock"></i> Roles ({{ count($roles) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">Name</th>
                    <th style="text-align:left">Grants</th>
                    <th style="text-align:center">Users</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($roles as $r)
                @php $rid = (int) $r['id']; $rp = $rolePerms[$rid] ?? []; @endphp
                <tr>
                    <td style="text-align:left;font-weight:600;color:#c4b5fd">{{ $r['name'] }}</td>
                    <td style="text-align:left">
                        <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:520px">
                        @foreach (permissionPages() as $page => $meta)
                            @php
                                $pv = $rp[$page] ?? [];
                                $acts = array_values(array_filter(['view','add','edit','delete'], fn($a)=>!empty($pv[$a])));
                            @endphp
                            @if ($acts)
                            <span style="background:#1a1a42;color:#7dd3fc;border-radius:8px;font-size:10px;padding:2px 7px">
                                {{ $meta['label'] }}: {{ implode('/', array_map('ucfirst', $acts)) }}
                            </span>
                            @endif
                        @endforeach
                        @if (empty($rp))<span style="color:var(--text-muted);font-size:11px">No permissions</span>@endif
                        </div>
                    </td>
                    <td style="text-align:center">{{ $usage[$rid] ?? 0 }}</td>
                    <td style="text-align:center">
                        <div style="display:flex;gap:6px;justify-content:center">
                            <a href="{{ url('/roles') }}?edit={{ $rid }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="{{ url('/roles') }}" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="{{ $rid }}">
                                <button type="submit" class="btn-danger-custom"
                                        data-confirm="Delete role '{{ $r['name'] }}'? Users keep the permissions they already have." title="Delete">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:30px">No roles yet. Create one to reuse across users.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add Role</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/roles') }}">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Role name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Viewer, Editor" required maxlength="100">
                    </div>
                    <label class="form-label">Page permissions</label>
                    @include('partials.perm_grid')
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Create Role</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Role Modal -->
@if ($editRole)
<div class="modal fade show" id="editRoleModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Role — {{ $editRole['name'] }}</h5>
                <a href="{{ url('/roles') }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/roles') }}">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ (int)$editRole['id'] }}">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Role name *</label>
                        <input type="text" name="name" class="form-control" value="{{ $editRole['name'] }}" required maxlength="100">
                    </div>
                    <label class="form-label">Page permissions</label>
                    @include('partials.perm_grid', ['permVal' => $rolePerms[(int)$editRole['id']] ?? []])
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/roles') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
