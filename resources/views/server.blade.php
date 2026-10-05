@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">Server Master</div>
        <div class="dash-subtitle">Deployment targets — SSH &amp; aaPanel credentials per server</div>
    </div>
    <button class="btn-primary-custom ms-auto" data-bs-toggle="modal" data-bs-target="#addServerModal">
        <i class="bi bi-plus-circle"></i> Add Server
    </button>
</div>

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-hdd-network"></i> Servers ({{ count($servers) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">Name</th>
                    <th style="text-align:left">SSH Host</th>
                    <th style="text-align:left">Panel URL</th>
                    <th style="text-align:center">PHP</th>
                    <th style="text-align:center">Sites</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($servers as $s)
                <tr>
                    <td style="text-align:left;font-weight:600;color:#c4b5fd">{{ $s['name'] }}</td>
                    <td style="text-align:left"><code style="color:#7dd3fc">{{ $s['ssh_username'] }}@{{ $s['ssh_host'] }}:{{ $s['ssh_port'] }}</code></td>
                    <td style="text-align:left;color:var(--text-muted)">{{ $s['bt_panel_url'] }}</td>
                    <td style="text-align:center">{{ $s['bt_panel_php_version'] }}</td>
                    <td style="text-align:center">{{ $siteCounts[$s['id']] ?? 0 }}</td>
                    <td style="text-align:center">
                        @if ($s['active'])
                            <span class="pill ok static"><span class="dotmark"></span>Active</span>
                        @else
                            <span class="pill static">Off</span>
                        @endif
                    </td>
                    <td style="text-align:center">
                        <div style="display:flex;gap:6px;justify-content:center">
                            <form method="post" action="{{ url('/server-masters') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="action" value="categorize">
                                <input type="hidden" name="id" value="{{ $s['id'] }}">
                                <button type="submit" class="btn-sm-custom"
                                        data-confirm="Categorise all sites deployed to '{{ $s['name'] }}' on aaPanel (Meta URL → Sub, GAM URLs → Main)?"
                                        title="Categorise this server's sites on aaPanel (Sub / Main)">
                                    <i class="bi bi-folder-symlink"></i>
                                </button>
                            </form>
                            <a href="{{ url('/server-masters') }}?diag_categories={{ $s['id'] }}" target="_blank" class="btn-sm-custom" title="Dump aaPanel site categories (diagnostic)">
                                <i class="bi bi-tags"></i>
                            </a>
                            <a href="{{ url('/server-masters') }}?edit={{ $s['id'] }}" class="btn-sm-custom" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" action="{{ url('/server-masters') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="{{ $s['id'] }}">
                                <button type="submit" class="btn-danger-custom"
                                        data-confirm="Delete server '{{ $s['name'] }}'? This cannot be undone." title="Delete">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:30px">No servers yet. Add one to deploy to it.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- name.com DNS credentials (used to auto-create A @ / A www on deploy) -->
<div class="data-card" style="margin-top:16px">
    <div class="data-card-header">
        <i class="bi bi-globe2"></i> name.com DNS
        <span style="color:var(--text-muted);font-weight:400;font-size:.85rem">— on deploy, each site's <code>A @</code> and <code>A www</code> are pointed at the chosen server's IP</span>
    </div>
    <div style="padding:16px 20px">
        <form method="post" action="{{ url('/server-masters') }}" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
            @csrf
            <input type="hidden" name="action" value="save_namecom">
            <div>
                <label class="form-label" style="font-size:12px;color:var(--text-muted)">API Username</label>
                <input type="text" name="namecom_username" class="form-control" value="{{ $namecomUser }}" placeholder="name.com username" style="min-width:220px">
            </div>
            <div>
                <label class="form-label" style="font-size:12px;color:var(--text-muted)">API Token
                    @if ($namecomHasToken)<span style="color:#00c853"><i class="bi bi-check-circle"></i> set</span>@else<span style="color:#fbbf24">not set</span>@endif
                </label>
                <input type="password" name="namecom_token" class="form-control" placeholder="{{ $namecomHasToken ? '•••••• (leave blank to keep)' : 'paste API token' }}" style="min-width:260px" autocomplete="new-password">
            </div>
            <button type="submit" class="btn-primary-custom"><i class="bi bi-save"></i> Save</button>
        </form>
        <div style="font-size:11px;color:var(--text-muted);margin-top:8px">
            <i class="bi bi-info-circle"></i> Create a token at name.com → Account → API Tokens. The token is stored encrypted. Leave the token blank to keep the current one.
        </div>
    </div>
</div>

@php
    // Shared form body for both add & edit (edit passes $editServer).
    $sv = $editServer ?? null;
@endphp

<!-- Add Server Modal -->
<div class="modal fade" id="addServerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add Server</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ url('/server-masters') }}" method="POST">
                @csrf
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    @include('partials.server-fields', ['s' => null, 'isEdit' => false])
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Server</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Server Modal -->
@if ($editServer)
<div class="modal fade show" id="editServerModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Server — {{ $editServer['name'] }}</h5>
                <a href="{{ url('/server-masters') }}" class="btn-close btn-close-white"></a>
            </div>
            <form action="{{ url('/server-masters') }}" method="POST">
                @csrf
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editServer['id'] }}">
                <div class="modal-body">
                    @include('partials.server-fields', ['s' => $editServer, 'isEdit' => true])
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/server-masters') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
