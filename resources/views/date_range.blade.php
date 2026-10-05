@extends('layouts.app')

@section('content')

@php
    $dl = fn ($d) => (new \DateTime($d))->format('d M Y');
    // Sub-columns shown under every date group.
    $subCols = [
        ['label' => 'Meta Spend', 'key' => 'meta', 'render' => fn ($m) => fmtINR($m['meta']), 'cls' => fn ($m) => 'c-orange'],
        ['label' => 'Net P/L',    'key' => 'pl',   'render' => fn ($m) => fmtINR($m['pl']),   'cls' => fn ($m) => $m['pl'] >= 0 ? 'c-green' : 'c-red'],
        ['label' => 'Margin',     'key' => 'mgn',  'render' => fn ($m) => fmtPct($m['mgn']),  'cls' => fn ($m) => $m['mgn'] >= 0 ? 'c-green' : 'c-red'],
    ];
    $nSub  = count($subCols);
    $fixed = 4; // META URL, ACTION, GAM URL, ADX
@endphp

<style>
    /* Freeze the META URL column while scrolling sideways through the dates. */
    #drGrid thead tr:first-child th:first-child,
    #drGrid tbody td:first-child,
    #drGrid tfoot td:first-child { position: sticky; left: 0; z-index: 2; box-shadow: inset -1px 0 0 var(--border); }
    #drGrid tbody td:first-child { background: var(--card-bg); }
    #drGrid thead tr:first-child th:first-child { z-index: 3; }
    #drGrid thead th { text-align: center; }
    #drGrid thead tr:first-child th:nth-child(-n+4) { text-align: left; }
    #drGrid .grp-start { border-left: 1px solid #2e2e5a; }

    /* Daily action badges + filter chips */
    .act-badge { display: inline-block; border-radius: 10px; padding: 2px 9px; font-size: 11px; font-weight: 600; white-space: nowrap; border: 1px solid transparent; }
    .act-note  { font-size: 10px; color: var(--text-muted); margin-top: 3px; white-space: normal; min-width: 200px; max-width: 260px; line-height: 1.35; }
    .act-stop    { background: #2a0000; color: #ff6b6b; border-color: #5a1a1a; }
    .act-review  { background: #332600; color: #ffc107; border-color: #5a4500; }
    .act-add_new { background: #0c2a3a; color: #7dd3fc; border-color: #134e6f; }
    .act-add_5k  { background: #003319; color: #00c853; border-color: #005522; }
    .act-testing { background: #221a45; color: #c4b5fd; border-color: #3b2f7a; }
    .act-passed  { background: #0b2b22; color: #6ee7b7; border-color: #14532d; }
    .act-none, .act-idle { background: #1e1e3a; color: var(--text-muted); border-color: var(--border); }
    .act-chips { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px 14px; border-bottom: 1px solid var(--border); align-items: center; }
    .act-chip { cursor: pointer; opacity: .55; }
    .act-chip:hover { opacity: .85; }
    .act-chip.is-on { opacity: 1; box-shadow: 0 0 0 1px currentColor; }
</style>

<div class="month-header">
    <div style="display:flex;align-items:center;justify-content:center;gap:12px;flex-wrap:wrap">
        <h2><i class="bi bi-calendar-week"></i> DATE RANGE REPORT — {{ $dl($from) }} to {{ $dl($to) }}</h2>
    </div>

    <form method="get" action="{{ url('/date-range') }}"
          style="display:flex;align-items:center;justify-content:center;gap:12px;margin-top:12px;flex-wrap:wrap">
        <div class="date-filter is-range">
            <i class="bi bi-calendar-week date-filter-icon"></i>
            <input type="text" class="form-control js-daterange" data-from="#drFrom" data-to="#drTo"
                   value="{{ $dl($from) }} - {{ $dl($to) }}" inputmode="none" autocomplete="off" title="Select date range">
        </div>
        <input type="hidden" name="from" id="drFrom" value="{{ $from }}">
        <input type="hidden" name="to" id="drTo" value="{{ $to }}">
        @if ($viewUserId > 0)<input type="hidden" name="user" value="{{ $viewUserId }}">@endif
        @if ($adxFilter > 0)<input type="hidden" name="adx" value="{{ $adxFilter }}">@endif
        @if ($activeOnly)<input type="hidden" name="active" value="1">@endif
    </form>

    <div style="display:flex;align-items:center;justify-content:center;gap:16px;margin-top:10px;flex-wrap:wrap">
        @if (canSeeAllUsers())
        <div style="display:flex;align-items:center;gap:8px">
            <span style="color:var(--text-muted);font-size:12px"><i class="bi bi-person"></i> User:</span>
            <select class="form-select js-user-filter" style="width:auto">
                <option value="">All users</option>
                @foreach (allUsers() as $uOpt)
                <option value="{{ (int)$uOpt['id'] }}" {{ $viewUserId === (int)$uOpt['id'] ? 'selected' : '' }}>
                    {{ $uOpt['name'] }}{{ $uOpt['is_admin'] ? ' (admin)' : '' }}
                </option>
                @endforeach
            </select>
        </div>
        @endif
        @if (!empty($adxOptions))
        <div style="display:flex;align-items:center;gap:8px">
            <span style="color:var(--text-muted);font-size:12px"><i class="bi bi-diagram-3"></i> ADX:</span>
            <select class="form-select js-adx-filter" style="width:auto">
                <option value="">All ADX</option>
                @foreach ($adxOptions as $ax)
                <option value="{{ (int)$ax['id'] }}" {{ $adxFilter === (int)$ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer;color:var(--text-muted);font-size:12px">
            <input type="checkbox" class="js-active-filter" value="1" {{ $activeOnly ? 'checked' : '' }}
                   style="accent-color:var(--purple);width:15px;height:15px">
            Active only
        </label>
    </div>
</div>

@if (!$dbOk)
<div class="alert-custom alert-error"><i class="bi bi-exclamation-circle"></i> {{ $error }}</div>
@endif
@if ($capped)
<div class="alert-custom alert-error"><i class="bi bi-info-circle"></i> Range limited to {{ $maxDays }} days — showing {{ $dl($from) }} to {{ $dl($to) }}.</div>
@endif

<div class="data-card">
    <div class="data-card-header" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
        <span><i class="bi bi-table"></i> Link Performance — {{ $dl($from) }} to {{ $dl($to) }} ({{ count($dates) }} {{ count($dates) === 1 ? 'day' : 'days' }})</span>
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);font-weight:400">USD/{{ getDefaultCurrency()['code'] }}: {{ number_format($usdRate, 2) }}</span>
    </div>
    <div class="act-chips" id="actChips">
        <span style="font-size:11px;color:var(--text-muted);margin-right:4px">
            <i class="bi bi-lightning-charge"></i> Actions for {{ $dl($actionDay) }} vs {{ $dl($prevDay) }}:
            @if ($actionShifted)
            <br><span style="color:#ffc107"><i class="bi bi-hourglass-split"></i> Today's data is still coming in, so actions use yesterday.</span>
            @endif
        </span>
        <button type="button" class="act-badge act-none act-chip is-on" data-filter="">All ({{ count($rows) }})</button>
        @foreach ($actions as $key => $label)
            @if ($actionCounts[$key] > 0)
            <button type="button" class="act-badge act-{{ $key }} act-chip" data-filter="{{ $key }}">{{ $label }} ({{ $actionCounts[$key] }})</button>
            @endif
        @endforeach
    </div>
    <div class="table-wrap" style="overflow-x:auto">
        <table class="ledger" id="drGrid" style="min-width:{{ 600 + (count($dates) + 1) * 330 }}px">
            <thead>
                <tr>
                    <th rowspan="2">META URL <small style="color:var(--text-muted)">(spend)</small></th>
                    <th rowspan="2" title="What to do, from {{ $dl($actionDay) }} vs {{ $dl($prevDay) }}">ACTION</th>
                    <th rowspan="2">GAM URL <small style="color:var(--text-muted)">(revenue)</small></th>
                    <th rowspan="2">ADX</th>
                    @foreach ($dates as $di => $dt)
                    <th colspan="{{ $nSub }}" class="grp-start" style="color:{{ $di % 2 ? '#7dd3fc' : '#c4b5fd' }}">{{ $dl($dt) }}</th>
                    @endforeach
                    <th colspan="{{ $nSub }}" class="grp-start" style="color:#fbbf24">Total</th>
                    <th rowspan="2" class="grp-start">STATUS</th>
                </tr>
                <tr>
                    @foreach (array_merge($dates, ['total']) as $gi => $dt)
                        @foreach ($subCols as $si => $sc)
                        <th class="js-sort {{ $si === 0 ? 'grp-start' : '' }}" data-col="{{ $fixed + $gi * $nSub + $si }}"
                            style="font-size:10px;cursor:pointer;user-select:none"
                            title="Sort by {{ $sc['label'] }} — {{ $dt === 'total' ? 'Total' : $dl($dt) }}">{{ $sc['label'] }} <span class="js-arrow" style="opacity:.4">↕</span></th>
                        @endforeach
                    @endforeach
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $r)
            <tr class="{{ $r['has'] ? '' : 'row-nodata' }}" data-action="{{ $r['advice']['key'] }}">
                <td style="font-weight:600;color:#c4b5fd">
                    <a class="url-tag" href="{{ $r['meta_url'] }}" target="_blank">{{ $r['meta_url'] }}</a>
                </td>
                <td>
                    <span class="act-badge act-{{ $r['advice']['key'] }}">{{ $r['advice']['label'] }}</span>
                    <div class="act-note">{{ $r['advice']['note'] }}</div>
                </td>
                <td style="font-size:11px;color:var(--text-muted)">
                    @foreach (array_filter(array_map('trim', explode(',', $r['gam_url']))) as $gh)<div>{{ $gh }}</div>@endforeach
                </td>
                <td>
                    @if ($r['adx_name'])<span style="background:#1a1a42;border-radius:10px;padding:1px 8px;font-size:11px;color:#7dd3fc"><i class="bi bi-diagram-3" style="font-size:9px"></i> {{ $r['adx_name'] }}</span>@else—@endif
                </td>
                @foreach (array_merge(array_values($r['byDate']), [$r['total']]) as $m)
                    @foreach ($subCols as $si => $sc)
                    <td class="{{ $sc['cls']($m) }} {{ $si === 0 ? 'grp-start' : '' }}" data-sort="{{ $m[$sc['key']] }}">{{ $sc['render']($m) }}</td>
                    @endforeach
                @endforeach
                <td class="grp-start">
                    @if (!$r['active'])
                        <span style="background:#2e1a1a;color:#f87171;border-radius:10px;font-size:9px;padding:1px 6px">Inactive</span>
                    @elseif (!$r['has'])<span class="badge-nodata">No data</span>
                    @endif
                </td>
            </tr>
            @empty
                <tr><td colspan="{{ $fixed + (count($dates) + 1) * $nSub + 1 }}" style="text-align:center;color:var(--text-muted);padding:30px">No links found.</td></tr>
            @endforelse
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTALS</td>
                    <td></td>
                    <td></td>
                    <td></td>
                    @foreach (array_merge(array_values($totByDate), [$grand]) as $m)
                        @foreach ($subCols as $si => $sc)
                        <td class="{{ $sc['cls']($m) }} {{ $si === 0 ? 'grp-start' : '' }}">{{ $sc['render']($m) }}</td>
                        @endforeach
                    @endforeach
                    <td class="grp-start"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<script>
// Click a sub-column header to sort rows by that value; click again to flip. TOTALS stay put.
document.addEventListener('DOMContentLoaded', () => {
    const table = document.getElementById('drGrid');
    const tbody = table && table.querySelector('tbody');
    if (!tbody) return;

    // Action chips: show only the links with that action ("All" shows every row).
    document.querySelectorAll('#actChips .act-chip').forEach(chip => {
        chip.addEventListener('click', () => {
            const want = chip.dataset.filter;
            document.querySelectorAll('#actChips .act-chip').forEach(c => c.classList.toggle('is-on', c === chip));
            tbody.querySelectorAll('tr[data-action]').forEach(tr => {
                tr.hidden = want !== '' && tr.dataset.action !== want;
            });
        });
    });

    table.querySelectorAll('th.js-sort').forEach(th => {
        th.addEventListener('click', () => {
            const col = parseInt(th.dataset.col, 10);
            const asc = th.dataset.dir !== 'asc';

            const rows = [...tbody.querySelectorAll('tr')].filter(r => !r.querySelector('td[colspan]'));
            rows.sort((a, b) => {
                const av = parseFloat(a.children[col]?.dataset.sort ?? 'NaN');
                const bv = parseFloat(b.children[col]?.dataset.sort ?? 'NaN');
                const x = isNaN(av) ? -Infinity : av;
                const y = isNaN(bv) ? -Infinity : bv;
                return asc ? x - y : y - x;
            });
            rows.forEach(r => tbody.appendChild(r));

            table.querySelectorAll('th.js-sort').forEach(o => {
                o.dataset.dir = '';
                const ar = o.querySelector('.js-arrow');
                if (ar) { ar.textContent = '↕'; ar.style.opacity = '.4'; }
            });
            th.dataset.dir = asc ? 'asc' : 'desc';
            const arrow = th.querySelector('.js-arrow');
            if (arrow) { arrow.textContent = asc ? '↑' : '↓'; arrow.style.opacity = '1'; }
        });
    });
});
</script>

@endsection
