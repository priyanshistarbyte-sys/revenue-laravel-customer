@extends('layouts.app')

@section('content')

<div class="dash-header" style="margin-bottom:16px">
    <div>
        <div class="dash-title" style="font-size:1.2rem">Site Checker</div>
        <div class="dash-subtitle">Verify deployed sites have their placeholders replaced — [MAIN_URL] for sub-sites, [AD_UNIT] &amp; [ADX] for main-sites</div>
    </div>
    <div class="ms-auto" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        @if (!empty($adxOptions))
        <div style="display:flex;align-items:center;gap:6px">
            <span style="color:var(--text-muted);font-size:12px"><i class="bi bi-diagram-3"></i> ADX:</span>
            <select id="adxFilter" class="form-select" style="width:auto">
                <option value="">All ADX</option>
                @foreach ($adxOptions as $ax)
                <option value="{{ (int) $ax['id'] }}">{{ $ax['name'] }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <button id="checkAllBtn" class="btn-primary-custom"><i class="bi bi-patch-check"></i> Check all</button>
    </div>
</div>

@php
    $legend = '<span style="font-size:11px;color:var(--text-muted)"><span style="color:#4ade80">●</span> replaced &nbsp; <span style="color:#f87171">●</span> NOT replaced (placeholder in file) &nbsp; <i class="bi bi-lock-fill" style="color:#4ade80"></i> SSL &nbsp; <span style="color:#9ca3af">●</span> not on server</span>';
@endphp

{{-- MAIN URL checker (sub-sites) --}}
<div class="data-card" style="margin-bottom:18px">
    <div class="data-card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-link-45deg"></i> MAIN URL Checker · sub-sites ({{ count($subSites) }})</span>
        {!! $legend !!}
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">Sub-site (Meta URL)</th>
                    <th style="text-align:left">Link</th>
                    <th style="text-align:left">Expected [MAIN_URL]</th>
                    <th style="text-align:left">Result</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($subSites as $s)
                <tr class="check-row" data-type="sub" data-host="{{ $s['host'] }}" data-main_url="{{ $s['main_url'] }}" data-adx_id="{{ $s['adx_id'] ?? 0 }}">
                    <td style="text-align:left"><a class="url-tag" href="https://{{ $s['host'] }}" target="_blank">{{ $s['host'] }}</a></td>
                    <td style="text-align:left;color:var(--text-muted)">{{ $s['link'] }}</td>
                    <td style="text-align:left"><code style="color:#c4b5fd">{{ $s['main_url'] ?: '—' }}</code></td>
                    <td class="check-result" style="text-align:left;color:var(--text-muted)">Not checked</td>
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:24px">No sub-sites found in Links.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- AD Unit checker (main-sites) --}}
<div class="data-card">
    <div class="data-card-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-diagram-3"></i> AD Unit Checker · main-sites ({{ count($mainSites) }})</span>
        {!! $legend !!}
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">Main-site (GAM URL)</th>
                    <th style="text-align:left">Link</th>
                    <th style="text-align:left">Expected [AD_UNIT]</th>
                    <th style="text-align:left">Expected [ADX]</th>
                    <th style="text-align:left">Result</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($mainSites as $s)
                <tr class="check-row" data-type="main" data-host="{{ $s['host'] }}" data-ad_unit="{{ $s['ad_unit'] }}" data-adx="{{ $s['adx'] }}" data-adx_id="{{ $s['adx_id'] ?? 0 }}">
                    <td style="text-align:left"><a class="url-tag" href="https://{{ $s['host'] }}" target="_blank">{{ $s['host'] }}</a></td>
                    <td style="text-align:left;color:var(--text-muted)">{{ $s['link'] }}</td>
                    <td style="text-align:left"><code style="color:#7dd3fc">{{ $s['ad_unit'] ?: '—' }}</code></td>
                    <td style="text-align:left"><code style="color:#7dd3fc">{{ $s['adx'] ?: '—' }}</code></td>
                    <td class="check-result" style="text-align:left;color:var(--text-muted)">Not checked</td>
                </tr>
            @empty
                <tr><td colspan="5" style="text-align:center;color:var(--text-muted);padding:24px">No main-sites found in Links.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const CHECK_URL = @json(url('/site-checker/check'));
    const rows = [...document.querySelectorAll('.check-row')];
    const btn  = document.getElementById('checkAllBtn');
    const adxSel = document.getElementById('adxFilter');

    // ADX filter: show only rows whose ADX matches the selection ('' = all).
    function applyFilter() {
        const want = adxSel ? adxSel.value : '';
        rows.forEach(r => {
            const show = !want || (r.dataset.adx_id || '0') === want;
            r.style.display = show ? '' : 'none';
        });
    }
    if (adxSel) adxSel.addEventListener('change', applyFilter);

    const visibleRows = () => rows.filter(r => r.style.display !== 'none');

    const esc = s => (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
    const DOT = { ok:'#4ade80', pending:'#f87171' };
    const LABEL = { ok:'replaced', pending:'NOT replaced' };

    function render(cell, data) {
        if (!data || !data.ok)          { cell.innerHTML = '<span style="color:#f87171">check failed</span>'; return; }
        if (data.state === 'error')     { cell.innerHTML = '<span style="color:#f87171"><i class="bi bi-slash-circle"></i> server unreachable</span>'; return; }
        if (data.state === 'unknown')   { cell.innerHTML = '<span style="color:#9ca3af">server unknown (not deployed)</span>'; return; }
        if (data.state === 'notfound')  { cell.innerHTML = '<span style="color:#9ca3af">folder not on server</span>'; return; }
        const parts = Object.entries(data.tokens).map(([tok, r]) =>
            `<span style="white-space:nowrap"><span style="color:${DOT[r.state]}">●</span> [${tok}] ${esc(LABEL[r.state])}</span>`
        );
        const SSL = {
            installed: ['#4ade80', '<i class="bi bi-lock-fill"></i> SSL installed'],
            expired:   ['#fbbf24', '<i class="bi bi-lock"></i> SSL expired'],
            missing:   ['#f87171', '<i class="bi bi-unlock"></i> SSL NOT installed'],
        };
        let sslLine = '';
        if (data.ssl && SSL[data.ssl]) {
            sslLine = `<div style="white-space:nowrap;color:${SSL[data.ssl][0]}">${SSL[data.ssl][1]}</div>`;
        }
        cell.innerHTML = parts.join('<br>') + sslLine;
    }

    // Row severity — higher floats to the top so issues are seen (and fixed) first.
    // 4 = placeholder NOT replaced (definite issue), 3 = unreachable/failed,
    // 1 = all replaced OK, 0 = not checked yet.
    function severityOf(data) {
        if (!data || !data.ok)      return 3;
        if (data.state === 'error') return 3;
        if (data.state === 'unknown' || data.state === 'notfound') return 2;
        let sev = 1;
        for (const r of Object.values(data.tokens || {})) {
            if (r.state === 'pending') sev = Math.max(sev, 4);
        }
        if (data.ssl === 'missing') sev = Math.max(sev, 3);
        else if (data.ssl === 'expired') sev = Math.max(sev, 2);
        return sev;
    }

    // Re-order each table so worst severity is first (stable for equal severities).
    function sortByIssues() {
        const tbodies = new Set(rows.map(r => r.parentElement));
        tbodies.forEach(tb => {
            [...tb.querySelectorAll('.check-row')]
                .sort((a, b) => (+b.dataset.sev || 0) - (+a.dataset.sev || 0))
                .forEach(r => tb.appendChild(r));
        });
    }

    function checkRow(row) {
        const cell = row.querySelector('.check-result');
        cell.innerHTML = '<span class="spinner-border spinner-border-sm" style="width:12px;height:12px;border-width:2px"></span> checking…';
        const p = new URLSearchParams({ host: row.dataset.host, type: row.dataset.type });
        if (row.dataset.type === 'main') { p.set('ad_unit', row.dataset.ad_unit || ''); p.set('adx', row.dataset.adx || ''); }
        else { p.set('main_url', row.dataset.main_url || ''); }
        return fetch(CHECK_URL + '?' + p.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(r => r.json())
            .then(d => { render(cell, d); row.dataset.sev = severityOf(d); })
            .catch(() => { render(cell, { ok: false }); row.dataset.sev = 3; });
    }

    // Run with limited concurrency so we don't fire dozens of requests at once.
    async function checkAll() {
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Checking…';
        const queue = visibleRows();   // only check rows matching the ADX filter
        const CONCURRENCY = 4;
        const workers = Array.from({ length: CONCURRENCY }, async () => {
            while (queue.length) await checkRow(queue.shift());
        });
        await Promise.all(workers);
        sortByIssues();   // float sites with issues to the top
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-patch-check"></i> Check all';
    }

    btn.addEventListener('click', checkAll);
    rows.forEach(r => r.querySelector('.check-result').addEventListener('click', () => checkRow(r).then(sortByIssues)));
});
</script>
@endpush

@endsection
