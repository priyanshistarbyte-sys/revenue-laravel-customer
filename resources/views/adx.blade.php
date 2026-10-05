@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
        <div class="dash-title">ADX MASTER</div>
        <div class="dash-subtitle">Ad Exchange networks — each doubles as a GAM account for the 3-hourly API sync</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
        @if ($gamEdit)
        <a href="{{ url('/gam_sync?force=1') }}" class="btn-sm-custom" title="Pull all GAM-enabled networks now"><i class="bi bi-arrow-repeat"></i> Sync all GAM</a>
        @endif
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-circle"></i> Add ADX
        </button>
    </div>
</div>

@if (!$hasGam && userCan('adx', 'edit'))
<div class="alert-custom alert-error">
    <i class="bi bi-exclamation-circle"></i> Run <a href="{{ url('/setup') }}" style="color:inherit;font-weight:700">setup</a> once to add the GAM API fields to ADX.
</div>
@endif

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-diagram-3"></i> ADX Networks ({{ count($adxList) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">#</th>
                    <th style="text-align:left">ADX Name</th>
                    <th style="text-align:left">Notes</th>
                    <th style="text-align:center">Domains</th>
                    @if ($gamEdit)
                    <th style="text-align:left">GAM Network</th>
                    <th style="text-align:left">Saved Report</th>
                    <th style="text-align:left">Last Sync</th>
                    @endif
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @php $colspan = $gamEdit ? 9 : 6; @endphp
            @if (empty($adxList))
            <tr><td colspan="{{ $colspan }}" style="text-align:center;color:var(--text-muted);padding:32px">
                No ADX networks yet. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal" style="color:#a78bfa">Add one →</a>
            </td></tr>
            @endif
            @foreach ($adxList as $i => $a)
            <tr style="{{ !$a['active'] ? 'opacity:.45' : '' }}">
                <td class="c-muted">{{ $i + 1 }}</td>
                <td style="font-weight:600;color:#c4b5fd">{{ $a['name'] }}</td>
                <td class="c-muted">{{ $a['notes'] ?? '' }}</td>
                <td style="text-align:center">
                    <span style="background:#1a1a42;border-radius:12px;padding:2px 10px;font-size:11px;color:#7dd3fc">
                        {{ $a['link_count'] }} domain{{ $a['link_count'] != 1 ? 's' : '' }}
                    </span>
                </td>
                @if ($gamEdit)
                <td style="font-family:monospace;font-size:11px">{{ $a['network_code'] ?: '—' }}</td>
                <td style="font-family:monospace;font-size:11px">{{ $a['saved_report_id'] ?: '—' }}</td>
                <td style="font-size:11px">
                    @if (!empty($a['last_error']))
                        <span style="color:#ff7777" title="{{ $a['last_error'] }}"><i class="bi bi-exclamation-triangle-fill"></i> error</span>
                    @elseif (!empty($a['last_sync']))
                        <span class="c-muted">{{ $a['last_sync'] }}</span>
                    @else
                        <span class="c-muted">{{ $a['network_code'] ? 'never' : '—' }}</span>
                    @endif
                </td>
                @endif
                <td style="text-align:center">
                    {!! $a['active'] ? '<span class="chip-mapped">Active</span>' : '<span class="chip-unmapped">Inactive</span>' !!}
                </td>
                <td style="text-align:center">
                    <div style="display:flex;gap:6px;justify-content:center">
                        @if ($gamEdit && $a['network_code'])
                        <a href="{{ url('/gam_sync') }}?account={{ (int)$a['id'] }}&force=1" class="btn-sm-custom" title="Sync this GAM network now"><i class="bi bi-arrow-repeat"></i></a>
                        <a href="{{ url('/gam_sync') }}?account={{ (int)$a['id'] }}&list_reports=1" target="_blank" class="btn-sm-custom" title="List reports this service account can see"><i class="bi bi-list-check"></i></a>
                        <a href="{{ url('/gam_sync') }}?account={{ (int)$a['id'] }}&dump_rows=1" target="_blank" class="btn-sm-custom" title="Dump this report's first-page rows (diagnostic)"><i class="bi bi-braces"></i></a>
                        <a href="{{ url('/gam_sync') }}?account={{ (int)$a['id'] }}&test_adunit=1" class="btn-sm-custom" title="Test ad-unit connection (NetworkService)"><i class="bi bi-plug"></i></a>
                        @endif
                        <a href="{{ url('/adx') }}?edit={{ $a['id'] }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="{{ url('/adx') }}" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="{{ $a['id'] }}">
                            <button type="submit" class="btn-sm-custom" title="{{ $a['active'] ? 'Deactivate' : 'Activate' }}">
                                <i class="bi bi-{{ $a['active'] ? 'pause' : 'play' }}-circle"></i>
                            </button>
                        </form>
                        <form method="post" action="{{ url('/adx') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $a['id'] }}">
                            <button type="submit" class="btn-danger-custom" data-confirm="Delete '{{ $a['name'] }}'?" title="Delete">
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add ADX Network</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/adx') }}" enctype="multipart/form-data">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ADX Name *</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Google ADX, Pubmatic" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ads.txt</label>
                        <textarea name="ads_txt" class="form-control" rows="6" placeholder="Paste ads.txt content here" style="font-family:monospace;font-size:12px"></textarea>
                    </div>
                    @if ($gamEdit)
                    <hr style="border-color:#2e2e5a">
                    <div style="color:#a78bfa;font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px"><i class="bi bi-cloud-arrow-down"></i> GAM API (optional — for 3-hourly auto-sync)</div>
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Network Code</label>
                            <input type="text" name="network_code" class="form-control js-net" placeholder="e.g. 21700000000"></div>
                        <div class="col-md-3"><label class="form-label">ADX Prefix <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— defaults to Network Code</small></label>
                            <input type="text" name="adx_prefix" class="form-control js-prefix" placeholder="defaults to Network Code"></div>
                        <div class="col-md-3"><label class="form-label">Revenue Report ID <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— optional{{ config('adledger.gam.direct_reports') ? ', not used (direct sync)' : '' }}</small></label>
                            <input type="text" name="saved_report_id" class="form-control" placeholder="daily revenue report"></div>
                        <div class="col-md-3"><label class="form-label">Hourly Report ID <small style="color:var(--text-muted);font-weight:400">— GAM Check{{ config('adledger.gam.direct_reports') ? ', not used (direct sync)' : '' }}</small></label>
                            <input type="text" name="hourly_report_id" class="form-control" placeholder="hourly CTR report"></div>
                        <div class="col-12">
                            <div style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.3);border-radius:8px;padding:8px 12px;font-size:11px;color:var(--text-muted);line-height:1.6">
                                <i class="bi bi-info-circle" style="color:#60a5fa"></i> <b style="color:#93c5fd">Report column order</b> (build the GAM saved reports with columns in exactly this order) —
                                <br><b style="color:#93c5fd">Revenue report:</b> Dimensions <code>Site</code>, <code>Date</code> · Metrics <code>Revenue</code> first (then Impressions, Clicks, Total requests, Match rate, CTR, eCPM — map extras in <code>config/adledger.php</code> → <code>column_map</code>).
                                <br><b style="color:#93c5fd">Hourly report:</b> Dimensions <code>Site</code>, <code>Date</code>, <code>Hour</code> · Metric <code>CTR</code> first (Revenue, Impressions optional).
                                <br>Use the <i class="bi bi-braces"></i> <b>Dump rows</b> button on a saved network to verify the actual order.
                            </div>
                        </div>
                        <div class="col-md-8"><label class="form-label">Service-Account Key (JSON) <small style="color:var(--text-muted);font-weight:400">— upload the key file</small></label>
                            <input type="file" name="key_upload" accept=".json,application/json" class="form-control">
                            <input type="text" name="key_file" class="form-control mt-2" placeholder="…or paste an existing server path (optional)" style="font-size:11px"></div>
                        <div class="col-md-4"><label class="form-label">Currency</label>
                            <input type="text" name="gam_currency" class="form-control" value="USD" maxlength="3"></div>
                        <div class="col-md-8"><label class="form-label">Line Item ID <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— optional: new ad units are added to this line item's targeting</small></label>
                            <input type="text" name="line_item_id" class="form-control" placeholder="e.g. 5123456789"></div>
                        <div class="col-12">
                            <label class="form-label" style="cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-transform:none;letter-spacing:0">
                                <input type="checkbox" name="auto_ad_unit" value="1" checked style="accent-color:var(--purple);width:15px;height:15px">
                                <span><i class="bi bi-magic"></i> Auto-create ad units when a domain on this network is added</span>
                            </label>
                        </div>
                    </div>
                    @endif
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add ADX</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
@if ($editAdx)
<div class="modal fade show" id="editModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit ADX</h5>
                <a href="{{ url('/adx') }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/adx') }}" enctype="multipart/form-data">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editAdx['id'] }}">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ADX Name *</label>
                        <input type="text" name="name" class="form-control" value="{{ $editAdx['name'] }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <input type="text" name="notes" class="form-control" value="{{ $editAdx['notes'] ?? '' }}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">ads.txt</label>
                        <textarea name="ads_txt" class="form-control" rows="6" placeholder="Paste ads.txt content here" style="font-family:monospace;font-size:12px">{{ $editAdx['ads_txt'] ?? '' }}</textarea>
                    </div>
                    @if ($gamEdit)
                    <hr style="border-color:#2e2e5a">
                    <div style="color:#a78bfa;font-size:11px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px"><i class="bi bi-cloud-arrow-down"></i> GAM API (optional — for 3-hourly auto-sync)</div>
                    <div class="row g-3">
                        <div class="col-md-3"><label class="form-label">Network Code</label>
                            <input type="text" name="network_code" class="form-control js-net" value="{{ $editAdx['network_code'] ?? '' }}" placeholder="e.g. 21700000000"></div>
                        <div class="col-md-3"><label class="form-label">ADX Prefix <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— defaults to Network Code</small></label>
                            <input type="text" name="adx_prefix" class="form-control js-prefix" value="{{ $editAdx['adx_prefix'] ?? '' }}" placeholder="defaults to Network Code"></div>
                        <div class="col-md-3"><label class="form-label">Revenue Report ID <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— optional{{ config('adledger.gam.direct_reports') ? ', not used (direct sync)' : '' }}</small></label>
                            <input type="text" name="saved_report_id" class="form-control" value="{{ $editAdx['saved_report_id'] ?? '' }}"></div>
                        <div class="col-md-3"><label class="form-label">Hourly Report ID <small style="color:var(--text-muted);font-weight:400">— GAM Check{{ config('adledger.gam.direct_reports') ? ', not used (direct sync)' : '' }}</small></label>
                            <input type="text" name="hourly_report_id" class="form-control" value="{{ $editAdx['hourly_report_id'] ?? '' }}"></div>
                        <div class="col-12">
                            <div style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.3);border-radius:8px;padding:8px 12px;font-size:11px;color:var(--text-muted);line-height:1.6">
                                <i class="bi bi-info-circle" style="color:#60a5fa"></i> <b style="color:#93c5fd">Report column order</b> (build the GAM saved reports with columns in exactly this order) —
                                <br><b style="color:#93c5fd">Revenue report:</b> Dimensions <code>Site</code>, <code>Date</code> · Metrics <code>Revenue</code> first (then Impressions, Clicks, Total requests, Match rate, CTR, eCPM — map extras in <code>config/adledger.php</code> → <code>column_map</code>).
                                <br><b style="color:#93c5fd">Hourly report:</b> Dimensions <code>Site</code>, <code>Date</code>, <code>Hour</code> · Metric <code>CTR</code> first (Revenue, Impressions optional).
                                <br>Use the <i class="bi bi-braces"></i> <b>Dump rows</b> button on a saved network to verify the actual order.
                            </div>
                        </div>
                        <div class="col-md-8"><label class="form-label">Service-Account Key (JSON)</label>
                            @if (!empty($editAdx['key_file']))
                            <div style="font-size:11px;color:#00c853;margin-bottom:4px"><i class="bi bi-check-circle"></i> Key stored: {{ basename($editAdx['key_file']) }} <small style="color:var(--text-muted)">— upload a new file to replace</small></div>
                            @endif
                            <input type="file" name="key_upload" accept=".json,application/json" class="form-control">
                            <input type="hidden" name="key_file" value="{{ $editAdx['key_file'] ?? '' }}"></div>
                        <div class="col-md-4"><label class="form-label">Currency</label>
                            <input type="text" name="gam_currency" class="form-control" value="{{ $editAdx['gam_currency'] ?? 'USD' }}" maxlength="3"></div>
                        <div class="col-md-8"><label class="form-label">Line Item ID <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— optional: new ad units are added to this line item's targeting</small></label>
                            <input type="text" name="line_item_id" class="form-control" value="{{ $editAdx['line_item_id'] ?? '' }}" placeholder="e.g. 5123456789"></div>
                        <div class="col-12">
                            <label class="form-label" style="cursor:pointer;display:inline-flex;align-items:center;gap:7px;text-transform:none;letter-spacing:0">
                                <input type="checkbox" name="auto_ad_unit" value="1" {{ !empty($editAdx['auto_ad_unit']) ? 'checked' : '' }} style="accent-color:var(--purple);width:15px;height:15px">
                                <span><i class="bi bi-magic"></i> Auto-create ad units when a domain on this network is added</span>
                            </label>
                        </div>
                    </div>
                    @if (!empty($editAdx['last_error']))
                    <div style="color:#ff7777;font-size:11px;margin-top:8px"><i class="bi bi-exclamation-triangle"></i> Last error: {{ $editAdx['last_error'] }}</div>
                    @endif
                    @endif
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/adx') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
// ── ADX Prefix mirrors Network Code by default, until the user edits it ──
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('form').forEach(form => {
        const net    = form.querySelector('.js-net');
        const prefix = form.querySelector('.js-prefix');
        if (!net || !prefix) return;

        // Start mirroring only when the prefix is still empty (e.g. Add form, or
        // an edit where no prefix was set yet). A pre-filled prefix is left alone.
        let mirror = prefix.value.trim() === '';

        net.addEventListener('input', () => { if (mirror) prefix.value = net.value; });
        // Once the user types their own prefix, stop mirroring.
        prefix.addEventListener('input', () => { mirror = false; });
    });
});
</script>
@endpush

@endsection
