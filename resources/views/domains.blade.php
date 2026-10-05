@extends('layouts.app')

@section('content')

@include('partials.flash')

<!-- Page Header -->
<div class="dash-header" style="margin-bottom:16px;flex-wrap:wrap;gap:12px;padding:16px 20px">
    <div>
        <div class="dash-title" style="font-size:1.2rem">Domains</div>
        <div class="dash-subtitle">Main domains group your subdomains and carry the Approved status + ADX network</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <form method="post" action="{{ url('/domains') }}" style="display:inline">
            <input type="hidden" name="action" value="sync_sites">
            <button type="submit" class="btn-sm-custom" title="Look up each domain's GAM site id (and approval) from GAM"
                    data-confirm="Sync GAM site IDs now? This queries GAM for every domain missing a site id and may take a while.">
                <i class="bi bi-arrow-repeat"></i> Sync site IDs
            </button>
        </form>
        <a href="{{ url('/links') }}" class="btn-sm-custom"><i class="bi bi-link-45deg"></i> Subdomains</a>
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addDomainModal">
            <i class="bi bi-globe2"></i> Add Domain
        </button>
    </div>
</div>

<!-- Filters -->
<form method="get" action="{{ url('/domains') }}" class="lp-filter">
    <div class="lp-search">
        <i class="bi bi-search"></i>
        <input type="text" name="f_q" value="{{ $fQ }}" placeholder="Search domain name…" autocomplete="off">
    </div>
    <span class="lp-flabel"><i class="bi bi-funnel"></i> Filter</span>
    <select name="f_adx" class="form-select">
        <option value="">All ADX</option>
        @foreach ($adxOptions as $ax)
        <option value="{{ $ax['id'] }}" {{ $fAdx !== '' && (int)$fAdx === (int)$ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
        @endforeach
    </select>
    <select name="f_approved" class="form-select">
        <option value="">All statuses</option>
        <option value="1" {{ $fApproved === '1' ? 'selected' : '' }}>Approved</option>
        <option value="0" {{ $fApproved === '0' ? 'selected' : '' }}>Pending</option>
    </select>
    <label class="lp-flabel" style="cursor:pointer">
        <input type="checkbox" name="f_sub" value="none" {{ $fSub ? 'checked' : '' }} style="accent-color:var(--purple);width:15px;height:15px">
        No subdomain
    </label>
    <button type="submit" class="icon-btn" style="border-color:var(--border)"><i class="bi bi-funnel-fill"></i> Apply</button>
    @if ($filterActive)
    <a href="{{ url('/domains') }}" class="icon-btn"><i class="bi bi-x-lg"></i> Clear</a>
    @endif
</form>

<!-- Domains table -->
<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-globe2"></i> Domains <span style="color:var(--text-muted);font-weight:400">({{ count($domains) }})</span>
        @if ($filterActive)<span style="margin-left:6px;font-size:11px;color:#fbbf24">filtered</span>@endif
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">#</th>
                    <th style="text-align:left">Domain</th>
                    <th style="text-align:left">ADX Network</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Subdomains</th>
                    <th style="text-align:center">GAM Ad Unit</th>
                    <th style="text-align:left">Notes</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if (empty($domains))
            <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:32px">
                @if ($filterActive)
                    Nothing matches the current filter. <a href="{{ url('/domains') }}" style="color:#a78bfa">Clear filter</a>
                @else
                    No domains yet. <a href="#" data-bs-toggle="modal" data-bs-target="#addDomainModal" style="color:#a78bfa">Add your first main domain →</a>
                @endif
            </td></tr>
            @endif
            @foreach ($domains as $i => $d)
            <tr>
                <td class="c-muted">{{ $i + 1 }}</td>
                <td style="font-weight:600;color:#c4b5fd"><i class="bi bi-globe2" style="opacity:.7"></i> {{ $d['name'] }}</td>
                <td>
                    @if ($d['adx_name'])<span class="tag-soft"><i class="bi bi-diagram-3"></i> {{ $d['adx_name'] }}</span>
                    @else<span style="color:var(--text-muted)">—</span>@endif
                </td>
                <td style="text-align:center">
                    <form method="post" action="{{ url('/domains') }}" style="display:inline;margin:0">
                        <input type="hidden" name="action" value="toggle_approved">
                        <input type="hidden" name="id" value="{{ (int)$d['id'] }}">
                        <button type="submit" class="pill {{ $d['approved'] ? 'ok' : '' }}"
                                title="{{ $d['approved'] ? 'Click to mark Pending' : 'Click to mark Approved' }}">
                            <span class="dotmark"></span>{{ $d['approved'] ? 'Approved' : 'Pending' }}
                        </button>
                    </form>
                </td>
                <td style="text-align:center">
                    <a href="{{ url('/links') }}?f_domain={{ (int)$d['id'] }}" class="url-tag" title="View this domain's subdomains">
                        {{ $d['link_count'] }} subdomain{{ $d['link_count'] != 1 ? 's' : '' }}
                    </a>
                </td>
                <td style="text-align:center;white-space:nowrap">
                    @php $gamReady = !empty($d['adx_network']) && !empty($d['adx_key']); @endphp
                    @if (!empty($d['gam_ad_unit_id']))
                        @php $unitCount = count(array_filter(explode(',', $d['gam_ad_unit_id']))); @endphp
                        <span class="pill ok" title="{{ $d['gam_ad_unit_code'] ?? '' }} · {{ $d['gam_ad_unit_synced_at'] ?? '' }}"><span class="dotmark"></span> {{ $unitCount }} unit{{ $unitCount != 1 ? 's' : '' }}</span>
                        @if (!empty($d['gam_site_id']))
                        <div style="margin-top:3px"><span style="font-size:10px;color:#7dd3fc" title="GAM site id {{ $d['gam_site_id'] }}"><i class="bi bi-globe"></i> site ✓</span></div>
                        @endif
                    @elseif (empty($d['adx_id']))
                        <span style="color:var(--text-muted);font-size:11px" title="Assign an ADX network to enable">— no ADX —</span>
                    @else
                        <form method="post" action="{{ url('/domains') }}" style="display:inline">
                            <input type="hidden" name="action" value="create_ad_unit">
                            <input type="hidden" name="id" value="{{ (int)$d['id'] }}">
                            <button type="submit" class="btn-sm-custom"
                                    data-confirm="Create a GAM ad unit for '{{ $d['name'] }}' under {{ $d['adx_name'] }}?"
                                    title="{{ $gamReady ? 'Create ad unit in GAM' : 'ADX network is missing a network code or key file' }}">
                                <i class="bi bi-plus-square"></i> Create
                            </button>
                        </form>
                        @if (!empty($d['gam_ad_unit_error']))
                        <div style="font-size:10px;color:#f87171;max-width:150px;white-space:normal;margin-top:3px" title="{{ $d['gam_ad_unit_error'] }}">
                            <i class="bi bi-exclamation-triangle"></i> last error
                        </div>
                        @endif
                    @endif
                </td>
                <td class="c-muted" style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $d['notes'] ?? '' }}">{{ $d['notes'] ?? '' }}</td>
                <td style="text-align:center">
                    <div style="display:flex;gap:4px;justify-content:center">
                        <a href="{{ url('/links') }}?domain={{ (int)$d['id'] }}" class="icon-btn" title="Add subdomain to this domain"><i class="bi bi-plus-lg"></i></a>
                        <a href="{{ url('/domains') }}?edit={{ (int)$d['id'] }}" class="icon-btn" title="Edit domain"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="{{ url('/domains') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete_domain">
                            <input type="hidden" name="id" value="{{ (int)$d['id'] }}">
                            <button type="submit" class="icon-btn danger" data-confirm="Delete domain '{{ $d['name'] }}'? (only if it has no subdomains)" title="Delete domain">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Add Domain Modal -->
<div class="modal fade" id="addDomainModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-globe2"></i> Add Main Domain</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/domains') }}">
                <input type="hidden" name="action" value="add_domain">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Domain Name(s) * <small style="color:#7dd3fc;font-weight:400">— comma-separated to add several at once</small></label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. erpnewswire.com, dailyblogzone.com, smartblogsite.xyz" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ADX Network <small style="color:#7dd3fc;font-weight:400">(optional — applies to all subdomains)</small></label>
                        <select name="adx_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach ($adxOptions as $ax)
                            <option value="{{ $ax['id'] }}">{{ $ax['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                    <p style="color:var(--text-muted);font-size:11px;margin:0">
                        New domains start as <strong>Pending</strong> — approve them from the list, then add subdomains on the <a href="{{ url('/links') }}" style="color:#a78bfa">Subdomains</a> page.
                    </p>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Domain</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Domain Modal -->
@if ($editDomain)
<div class="modal fade show" id="editDomainModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Main Domain</h5>
                <a href="{{ url('/domains') }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/domains') }}">
                <input type="hidden" name="action" value="edit_domain">
                <input type="hidden" name="id" value="{{ (int)$editDomain['id'] }}">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Domain Name *</label>
                        <input type="text" name="name" class="form-control" value="{{ $editDomain['name'] }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ADX Network <small style="color:#7dd3fc;font-weight:400">(optional — applies to all subdomains)</small></label>
                        <select name="adx_id" class="form-select">
                            <option value="">— None —</option>
                            @foreach ($adxOptions as $ax)
                            <option value="{{ $ax['id'] }}" {{ ($editDomain['adx_id'] ?? '') == $ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="{{ $editDomain['notes'] ?? '' }}">
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/domains') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
