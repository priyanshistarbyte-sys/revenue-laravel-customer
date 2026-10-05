@extends('layouts.app')

@section('content')

@include('partials.flash')

<!-- Header -->
<div class="dash-header" style="margin-bottom:16px;flex-wrap:wrap;gap:12px;padding:16px 20px">
    <div>
        <div class="dash-title" style="font-size:1.2rem"><i class="bi bi-clipboard-pulse"></i> GAM Check</div>
        <div class="dash-subtitle">
            Flags links whose Ad Exchange CTR looks dead — <strong>last 3 hours all &lt; {{ $lowCtr }}%</strong>,
            or <strong>more than half the hours &lt; {{ $lowCtr }}%</strong> (when there are &gt; 5 hours). A near-zero CTR counts as 0%. Uses each site's most recent day.
        </div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        @if ($latestDate)<span style="font-size:11px;color:var(--text-muted)">Latest data: <strong style="color:#c4b5fd">{{ $latestDate }}</strong></span>@endif
        <a href="{{ url('/upload') }}" class="btn-sm-custom"><i class="bi bi-cloud-upload"></i> Upload hourly report</a>
    </div>
</div>

<!-- Summary -->
<div class="kpi-row" style="margin-bottom:16px">
    <div class="kpi-card"><div class="kpi-label"><i class="bi bi-list-check"></i> Links checked</div><div class="kpi-value white">{{ $totalChecked }}</div></div>
    <div class="kpi-card"><div class="kpi-label"><i class="bi bi-exclamation-triangle"></i> Open (to fix)</div><div class="kpi-value {{ $flaggedCount > 0 ? 'red' : 'green' }}">{{ $flaggedCount }}</div></div>
    <div class="kpi-card"><div class="kpi-label"><i class="bi bi-check2-circle"></i> Resolved</div><div class="kpi-value green">{{ $resolvedCount }}</div></div>
    <div class="kpi-card"><div class="kpi-label"><i class="bi bi-dash-circle"></i> No hourly data</div><div class="kpi-value orange">{{ $noDataCount }}</div></div>
</div>

<!-- Filter -->
<form method="get" action="{{ url('/gam_check') }}" class="lp-filter" style="margin-bottom:14px">
    {{-- Keep the chosen column sort when the date / tasks filter changes. --}}
    @if ($sort !== '')<input type="hidden" name="sort" value="{{ $sort }}"><input type="hidden" name="dir" value="{{ $dir }}">@endif
    <span class="lp-flabel"><i class="bi bi-calendar-range"></i> Date</span>
    <select name="date" class="form-select" onchange="this.form.submit()" style="max-width:180px">
        <option value="">Latest per site</option>
        @foreach ($dates as $d)
        <option value="{{ $d }}" {{ $fDate === $d ? 'selected' : '' }}>{{ $d }}</option>
        @endforeach
    </select>
    <label class="lp-flabel" style="cursor:pointer">
        <input type="checkbox" name="flagged" value="1" onchange="this.form.submit()" {{ $flaggedOnly ? 'checked' : '' }}
               style="accent-color:var(--purple);width:15px;height:15px">
        Show only my tasks (flagged &amp; resolved)
    </label>
    @if ($flaggedOnly || $fDate !== '')<a href="{{ url('/gam_check') }}" class="icon-btn"><i class="bi bi-x-lg"></i> Clear</a>@endif
</form>

@if (!$latestDate)
<div class="data-card" style="padding:40px;text-align:center;color:var(--text-muted)">
    <i class="bi bi-clipboard-x" style="font-size:2rem;opacity:.5;display:block;margin-bottom:10px"></i>
    No hourly GAM data yet. Export the hourly Ad Exchange report from GAM (Site × Hour with CTR) and
    <a href="{{ url('/upload') }}" style="color:#a78bfa">upload it here →</a>
</div>
@else
<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-table"></i> Link CTR health <span style="color:var(--text-muted);font-weight:400">({{ count($rows) }})</span>
    </div>
    <div class="table-wrap">
        <table class="ledger" id="gcTable">
            @php
                // Sortable headers that keep the date filter / tasks toggle and flip direction.
                $gcBaseQ = array_filter(['date' => $fDate, 'flagged' => $flaggedOnly ? '1' : ''], fn ($v) => $v !== '' && $v !== null);
                $gcSortTh = function ($key, $label, $align = 'left', $title = '') use ($gcBaseQ, $sort, $dir) {
                    $next  = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
                    $href  = url('/gam_check') . '?' . http_build_query($gcBaseQ + ['sort' => $key, 'dir' => $next]);
                    $arrow = $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
                    return '<th style="text-align:' . $align . '"' . ($title ? ' title="' . e($title) . '"' : '') . '>'
                         . '<a href="' . e($href) . '" style="color:inherit;text-decoration:none;white-space:nowrap;cursor:pointer">'
                         . e($label) . '<span style="color:#a78bfa">' . $arrow . '</span></a></th>';
                };
            @endphp
            <thead>
                <tr>
                    <th style="text-align:left">#</th>
                    {!! $gcSortTh('name', 'Subdomain') !!}
                    {!! $gcSortTh('entry', 'Entry Date') !!}
                    {!! $gcSortTh('meta', 'Meta URL') !!}
                    {!! $gcSortTh('adx', 'ADX') !!}
                    {!! $gcSortTh('sites', 'GAM Site(s)') !!}
                    {!! $gcSortTh('day', 'Day', 'center') !!}
                    {!! $gcSortTh('hours', 'Hours', 'center') !!}
                    {!! $gcSortTh('low', '< ' . $lowCtr . '% CTR', 'center', "Hours with CTR below {$lowCtr}% (counted as 0% CTR)") !!}
                    {!! $gcSortTh('status', 'Status', 'center') !!}
                    <th style="text-align:left">Task <small style="font-weight:400;color:var(--text-muted)">— resolve / note</small></th>
                </tr>
            </thead>
            <tbody>
            @forelse ($rows as $i => $r)
                @php
                    $sitesWithData = array_values(array_filter($r['sites'], fn ($s) => !empty($s['hasData'])));
                    $totN    = $r['totN'];
                    $totZero = $r['totZero'];
                    $day     = $r['day'] !== '' ? $r['day'] : '—';
                    $subId   = 'gc-' . $i;
                    $hasBreakdown = !empty($sitesWithData);
                @endphp
            <tr class="{{ $r['done'] ? '' : ($r['flagged'] ? 'row-loss' : ($r['anyData'] ? '' : 'row-nodata')) }}" style="{{ $r['done'] ? 'opacity:.55' : '' }}">
                <td class="c-muted">{{ $i + 1 }}</td>
                <td style="font-weight:600;color:#c4b5fd;{{ $r['done'] ? 'text-decoration:line-through' : '' }}">{{ $r['link']['link_name'] }}</td>
                <td style="font-size:11px;color:var(--text-muted);white-space:nowrap">{{ $r['entry'] !== '' ? date('Y-m-d', strtotime($r['entry'])) : '—' }}</td>
                <td>@if (!empty($r['link']['meta_url']))<a class="url-tag" href="{{ $r['link']['meta_url'] }}" target="_blank" rel="noopener" title="{{ $r['link']['meta_url'] }}">{{ $r['link']['meta_url'] }}</a>@else<span class="c-muted">—</span>@endif</td>
                <td>@if (!empty($r['link']['adx_name']))<span style="color:#7dd3fc;font-size:11px"><i class="bi bi-diagram-3"></i> {{ $r['link']['adx_name'] }}</span>@else<span class="c-muted">—</span>@endif</td>
                <td class="{{ $hasBreakdown ? 'gc-toggle' : '' }}" @if($hasBreakdown) data-target="{{ $subId }}" title="Click to view hourly CTR" style="cursor:pointer" @endif>
                    @foreach ($r['sites'] as $s)
                        <div style="white-space:nowrap;font-size:12px;{{ empty($s['hasData']) ? 'opacity:.5' : '' }}">
                            {{ $s['site'] }}
                            @if (!empty($s['hasData']) && $s['flagged'])<i class="bi bi-exclamation-triangle-fill" style="color:#f87171;font-size:9px"></i>@endif
                        </div>
                    @endforeach
                    @if ($hasBreakdown)<i class="bi bi-chevron-down" style="font-size:9px;opacity:.5"></i>@endif
                </td>
                <td style="text-align:center;font-size:11px;color:var(--text-muted)">{{ $day }}</td>
                <td style="text-align:center">{{ $r['anyData'] ? $totN : '—' }}</td>
                <td style="text-align:center" class="{{ $totZero > 0 ? 'c-red' : ($r['anyData'] ? 'c-green' : 'c-muted') }}">{{ $r['anyData'] ? $totZero : '—' }}</td>
                <td style="text-align:center">
                    @if ($r['done'])
                        <span class="badge-profit">✓ Resolved</span>
                        @if ($r['flagged'])<div style="font-size:9px;color:var(--text-muted);text-decoration:line-through">{{ implode(' · ', $r['reasons']) }}</div>@endif
                    @elseif (!$r['anyData'])
                        <span class="badge-nodata">No data</span>
                    @elseif ($r['flagged'])
                        <span class="badge-loss" title="{{ implode(' · ', $r['reasons']) }}">✗ {{ implode(' · ', $r['reasons']) }}</span>
                    @else
                        <span class="badge-profit">✓ OK</span>
                    @endif
                </td>
                <td style="min-width:210px">
                    @if ($r['flagged'] || $r['done'])
                    @php $formAction = url('/gam_check') . ($flaggedOnly ? '?flagged=1' : ''); @endphp
                    <form method="post" action="{{ $formAction }}" style="display:inline">
                        <input type="hidden" name="action" value="toggle_done">
                        <input type="hidden" name="link_id" value="{{ (int)$r['link']['id'] }}">
                        <button type="submit" class="icon-btn {{ $r['done'] ? '' : 'ok' }}" title="{{ $r['done'] ? 'Reopen this task' : 'Mark resolved (fixed / URL changed)' }}">
                            <i class="bi bi-{{ $r['done'] ? 'arrow-counterclockwise' : 'check2-square' }}"></i> {{ $r['done'] ? 'Reopen' : 'Mark done' }}
                        </button>
                    </form>
                    <form method="post" action="{{ $formAction }}" style="display:flex;gap:4px;margin-top:5px">
                        <input type="hidden" name="action" value="save_note">
                        <input type="hidden" name="link_id" value="{{ (int)$r['link']['id'] }}">
                        <input type="text" name="note" value="{{ $r['note'] }}" placeholder="note — fixed / changed URL…"
                               class="form-control" style="font-size:11px;padding:2px 8px">
                        <button type="submit" class="icon-btn" title="Save note"><i class="bi bi-save"></i></button>
                    </form>
                    @else
                    <span class="c-muted">—</span>
                    @endif
                </td>
            </tr>
            @if ($hasBreakdown)
            <tr id="{{ $subId }}" class="gc-sub-row" style="display:none">
                <td colspan="11" style="padding:0;border-bottom:2px solid var(--purple)">
                    <div style="background:#0d0d1f;padding:12px 20px">
                        @foreach ($sitesWithData as $s)
                        <div style="margin-bottom:10px">
                            <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px">
                                <i class="bi bi-diagram-3" style="color:var(--purple)"></i>
                                <span style="color:#c4b5fd">{{ $s['site'] }}</span> — {{ $s['date'] }} ·
                                {{ $s['n'] }} hours, {{ $s['zeros'] }} &lt; {{ $lowCtr }}%
                                @if ($s['flagged'])<span style="color:#f87171">· {{ implode(', ', $s['reasons']) }}</span>@endif
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:4px">
                                @foreach ($s['rows'] as $h)
                                    @php $zero = $h['ctr'] < $lowCtr; @endphp
                                    <div title="Hour {{ $h['hour'] }} · CTR {{ number_format($h['ctr'], 2) }}% · {{ number_format($h['impressions']) }} imp"
                                         style="min-width:52px;text-align:center;padding:3px 6px;border-radius:6px;font-size:10px;
                                                background:{{ $zero ? 'rgba(248,113,113,.12)' : 'var(--card-bg)' }};
                                                border:1px solid {{ $zero ? 'rgba(248,113,113,.4)' : 'var(--border)' }}">
                                        <div style="color:var(--text-muted);font-size:9px">{{ str_pad((string)$h['hour'], 2, '0', STR_PAD_LEFT) }}h</div>
                                        <div style="font-weight:700;color:{{ $zero ? '#f87171' : '#7dd3fc' }}">{{ number_format($h['ctr'], 2) }}%</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                        @endforeach
                    </div>
                </td>
            </tr>
            @endif
            @empty
            <tr><td colspan="11" style="text-align:center;color:var(--text-muted);padding:32px">
                @if ($flaggedOnly)
                    No flagged links. <a href="{{ url('/gam_check') }}" style="color:#a78bfa">Show all →</a>
                @else
                    No links to check.
                @endif
            </td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

@push('scripts')
<script>
// Toggle a link's hourly-CTR breakdown sub-row.
document.querySelectorAll('#gcTable .gc-toggle').forEach(cell => {
    cell.addEventListener('click', () => {
        const sub = document.getElementById(cell.dataset.target);
        if (!sub) return;
        const open = sub.style.display !== 'none';
        sub.style.display = open ? 'none' : 'table-row';
        const chev = cell.querySelector('.bi-chevron-down');
        if (chev) chev.style.transform = open ? '' : 'rotate(180deg)';
    });
});
</script>
@endpush

@endsection
