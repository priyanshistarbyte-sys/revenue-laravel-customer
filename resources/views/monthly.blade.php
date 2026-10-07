@extends('layouts.app')

@section('content')

@php
    $userParam = ($viewUserId > 0 && canSeeAllUsers()) ? '&user=' . $viewUserId : '';
    $adxParam  = ($adxFilter > 0) ? '&adx=' . $adxFilter : '';
@endphp
<div class="month-header">
    <div style="display:flex;align-items:center;justify-content:center;gap:20px">
        <a href="?year={{ $prevM->format('Y') }}&month={{ $prevM->format('n') }}{{ $userParam }}{{ $adxParam }}" class="btn-sm-custom">
            <i class="bi bi-chevron-left"></i>
        </a>
        <h2>{{ strtoupper($monthName) }} — MONTHLY {!! showMeta() ? 'P&amp;L' : 'REVENUE' !!} SUMMARY</h2>
        <a href="?year={{ $nextM->format('Y') }}&month={{ $nextM->format('n') }}{{ $userParam }}{{ $adxParam }}" class="btn-sm-custom">
            <i class="bi bi-chevron-right"></i>
        </a>
    </div>
    @if (canSeeAllUsers() || !empty($adxOptions))
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
    </div>
    @endif
</div>

@if (!$dbOk)
<div class="alert-custom alert-error"><i class="bi bi-exclamation-circle"></i> {{ $error }}</div>
@endif

<!-- KPI Summary Row -->
<div class="kpi-row" style="margin-bottom:20px">
    <div class="kpi-card"><div class="kpi-label">Month GAM Revenue</div><div class="kpi-value green">{{ fmtINR($curGAM) }}</div></div>
    @if (showMeta())
    <div class="kpi-card"><div class="kpi-label">Month Meta Spend</div><div class="kpi-value orange">{{ fmtINR($curMeta) }}</div><div style="font-size:11px;color:var(--text-muted);margin-top:2px">{{ fmtUsdFromInr($curMeta, $usdRate) }}</div></div>
    <div class="kpi-card"><div class="kpi-label">Month GST</div><div class="kpi-value gold">{{ fmtINR($curGST) }}</div></div>
    <div class="kpi-card"><div class="kpi-label">Month Total Cost</div><div class="kpi-value white">{{ fmtINR($curCost) }}</div></div>
    <div class="kpi-card"><div class="kpi-label">Month Net P/L</div><div class="kpi-value {{ $curPL >= 0 ? 'green' : 'red' }}">{{ fmtINR($curPL) }}</div></div>
    <div class="kpi-card"><div class="kpi-label">Month Margin</div><div class="kpi-value {{ $curMgn >= 0 ? 'green' : 'red' }}">{{ fmtPct($curMgn) }}</div></div>
    @endif
</div>

<!-- Monthly Table -->
<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-calendar3-range"></i> Daily Breakdown — {{ $monthName }}
    </div>
    <div class="table-wrap">
        <table class="ledger monthly">
            <thead>
                <tr>
                    <th style="text-align:left">DATE</th>
                    <th>GAM REV ($)</th>
                    <th>GAM REVENUE (₹)</th>
                    @if (showMeta())
                    <th>META SPEND</th>
                    <th>GST</th>
                    <th>TOTAL COST</th>
                    <th>NET P/L</th>
                    <th>MARGIN</th>
                    <th>STATUS</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @php $pmLabel = $prevMonth->format('F Y'); @endphp
            <tr style="background:#1a1a42">
                <td style="text-align:left;font-weight:700;color:#a78bfa">{{ $pmLabel }} TOTAL</td>
                <td class="c-green" style="font-size:11px">${{ number_format($pmGAMusd, 2) }}</td>
                <td class="c-green">{{ fmtINR($pmGAM) }}</td>
                @if (showMeta())
                <td class="c-orange">{{ fmtINR($pmMeta) }}<div style="font-size:10px;color:var(--text-muted);font-weight:400">{{ fmtUsdFromInr($pmMeta, $usdRate) }}</div></td>
                <td class="c-gold">{{ fmtINR($pmGST) }}</td>
                <td>{{ fmtINR($pmCost) }}</td>
                <td class="{{ $pmPL >= 0 ? 'c-green' : 'c-red' }}">{{ fmtINR($pmPL) }}</td>
                <td class="{{ $pmMgn >= 0 ? 'c-green' : 'c-red' }}">{{ fmtPct($pmMgn) }}</td>
                <td>{!! $pmPL >= 0 ? '<span class="badge-profit">✓ Profit</span>' : '<span class="badge-loss">✗ Loss</span>' !!}</td>
                @endif
            </tr>

            @for ($day = 1; $day <= $daysInMonth; $day++)
                @php
                    $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $r       = $dailyByDate[$dateStr] ?? null;
                    $isToday = ($dateStr === date('Y-m-d'));
                    if ($r) {
                        $gusd = (float)$r['gam_usd']; $gam = (float)$r['gam_inr']; $meta = (float)$r['meta_spend'];
                        $gst = round($meta * $gstRate / 100, 2); $cost = round($meta + $gst, 2);
                        $pl = round($gam - $cost, 2); $mgn = $cost > 0 ? round($pl / $cost * 100, 1) : 0; $hasD = true;
                    } else {
                        $gusd = $gam = $meta = $gst = $cost = $pl = $mgn = 0; $hasD = false;
                    }
                    $dayLabel = (new DateTime($dateStr))->format('d M Y');
                    $rowStyle = $isToday ? 'background:#1e1e3a;' : '';
                    $plCls  = !$hasD ? 'c-muted' : ($pl >= 0 ? 'c-green' : 'c-red');
                    $mgnCls = !$hasD ? 'c-muted' : ($mgn >= 0 ? 'c-green' : 'c-red');
                @endphp
            <tr style="{{ $rowStyle }}" class="{{ $isToday ? '' : ($hasD ? '' : 'row-nodata') }}">
                <td style="text-align:left;{{ $isToday ? 'font-weight:700;color:#a78bfa' : '' }}">
                    {{ $dayLabel }}
                    @if ($isToday)<span style="font-size:10px;color:#a78bfa;margin-left:6px">(Today)</span>@endif
                </td>
                <td class="{{ $hasD ? 'c-green' : 'c-muted' }}" style="font-size:11px">${{ number_format($gusd, 2) }}</td>
                <td class="{{ $hasD ? 'c-green' : 'c-muted' }}">{{ fmtINR($gam) }}</td>
                @if (showMeta())
                <td class="{{ $hasD ? 'c-orange' : 'c-muted' }}">{{ fmtINR($meta) }}<div style="font-size:10px;color:var(--text-muted);font-weight:400">{{ fmtUsdFromInr($meta, $usdRate) }}</div></td>
                <td class="{{ $hasD ? 'c-gold' : 'c-muted' }}">{{ fmtINR($gst) }}</td>
                <td class="{{ $hasD ? '' : 'c-muted' }}">{{ fmtINR($cost) }}</td>
                <td class="{{ $plCls }}" style="font-weight:{{ $hasD ? '700' : '400' }}">{{ fmtINR($pl) }}</td>
                <td class="{{ $mgnCls }}">{{ $hasD ? fmtPct($mgn) : '—' }}</td>
                <td>
                    @if (!$hasD)<span class="badge-nodata">No data</span>
                    @elseif ($pl >= 0)<span class="badge-profit">✓ Profit</span>
                    @else<span class="badge-loss">✗ Loss</span>@endif
                </td>
                @endif
            </tr>
            @endfor
            </tbody>
            <tfoot>
                <tr>
                    <td style="text-align:left">{{ strtoupper($monthName) }} TOTAL</td>
                    <td class="c-green" style="font-size:11px">${{ number_format($curGAMusd, 2) }}</td>
                    <td class="c-green">{{ fmtINR($curGAM) }}</td>
                    @if (showMeta())
                    <td class="c-orange">{{ fmtINR($curMeta) }}<div style="font-size:10px;color:var(--text-muted);font-weight:400">{{ fmtUsdFromInr($curMeta, $usdRate) }}</div></td>
                    <td class="c-gold">{{ fmtINR($curGST) }}</td>
                    <td>{{ fmtINR($curCost) }}</td>
                    <td class="{{ $curPL >= 0 ? 'c-green' : 'c-red' }}">{{ fmtINR($curPL) }}</td>
                    <td class="{{ $curMgn >= 0 ? 'c-green' : 'c-red' }}">{{ fmtPct($curMgn) }}</td>
                    <td>{!! $curPL >= 0 ? '<span class="badge-profit">✓ Profit</span>' : '<span class="badge-loss">✗ Loss</span>' !!}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- Per-Link Monthly Breakdown -->
<div class="data-card" style="margin-top:20px">
    <div class="data-card-header">
        <i class="bi bi-link-45deg"></i> Per-Link Breakdown — {{ $monthName }}
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);font-weight:400">USD/{{ getDefaultCurrency()['code'] }}: {{ number_format($usdRate, 2) }}</span>
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">LINK</th>
                    @if (canSeeAllUsers())<th style="text-align:left">USER</th>@endif
                    @if (showMeta())<th style="text-align:left">CAMPAIGNS</th>@endif
                    <th>GAM REV ($)</th>
                    <th>GAM REVENUE (₹)</th>
                    @if (showMeta())
                    <th>META SPEND</th>
                    <th>GST</th>
                    <th>TOTAL COST</th>
                    <th>NET P/L</th>
                    <th>MARGIN</th>
                    <th>STATUS</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @if (empty($linkMonthly))
            <tr><td colspan="{{ (canSeeAllUsers() ? 1 : 0) + (showMeta() ? 10 : 3) }}" style="text-align:center;color:var(--text-muted);padding:30px">No active links found.</td></tr>
            @endif
            @foreach ($linkMonthly as $lm)
                @php
                    $lGamInr = $lm['gam_usd'];
                    $lGamUsd = $usdRate > 0 ? $lGamInr / $usdRate : 0;
                    $lMeta   = $lm['meta_spend'];
                    $lGst    = round($lMeta * $gstRate / 100, 2);
                    $lCost   = round($lMeta + $lGst, 2);
                    $lPL     = round($lGamInr - $lCost, 2);
                    $lMgn    = $lCost > 0 ? round($lPL / $lCost * 100, 1) : 0;
                    $hasD    = ($lGamUsd > 0 || (showMeta() && $lMeta > 0));
                    $campTags = array_values(array_filter(array_map('trim', explode(',', $lm['meta_campaign']))));
                @endphp
            <tr class="{{ $hasD ? '' : 'row-nodata' }}">
                <td style="text-align:left;font-weight:600;color:#c4b5fd">{{ $lm['link_name'] }}</td>
                @if (canSeeAllUsers())
                <td style="text-align:left;white-space:nowrap">
                    <span style="background:#1a1a42;border-radius:12px;padding:2px 10px;font-size:11px;color:#7dd3fc">
                        <i class="bi bi-person" style="font-size:10px"></i> {{ $lm['owner_name'] }}
                    </span>
                </td>
                @endif
                @if (showMeta())
                <td style="text-align:left">
                    <div style="display:flex;flex-wrap:wrap;gap:3px;max-width:260px">
                    @foreach ($campTags as $ct)
                        <span class="campaign-tag" style="font-size:9px;padding:1px 5px">{{ $ct }}</span>
                    @endforeach
                    </div>
                </td>
                @endif
                <td class="{{ $hasD ? 'c-green' : 'c-muted' }}" style="font-size:11px">${{ number_format($lGamUsd, 2) }}</td>
                <td class="{{ $hasD ? 'c-green' : 'c-muted' }}">{{ fmtINR($lGamInr) }}</td>
                @if (showMeta())
                <td class="{{ $hasD ? 'c-orange' : 'c-muted' }}">{{ fmtINR($lMeta) }}<div style="font-size:10px;color:var(--text-muted);font-weight:400">{{ fmtUsdFromInr($lMeta, $usdRate) }}</div></td>
                <td class="{{ $hasD ? 'c-gold' : 'c-muted' }}">{{ fmtINR($lGst) }}</td>
                <td class="{{ $hasD ? '' : 'c-muted' }}">{{ fmtINR($lCost) }}</td>
                <td class="{{ !$hasD ? 'c-muted' : ($lPL >= 0 ? 'c-green' : 'c-red') }}" style="font-weight:700">{{ fmtINR($lPL) }}</td>
                <td class="{{ !$hasD ? 'c-muted' : ($lMgn >= 0 ? 'c-green' : 'c-red') }}">{{ $hasD ? fmtPct($lMgn) : '—' }}</td>
                <td>
                    @if (!$hasD)<span class="badge-nodata">No data</span>
                    @elseif ($lPL >= 0)<span class="badge-profit">✓ Profit</span>
                    @else<span class="badge-loss">✗ Loss</span>@endif
                    @if (!$lm['active'])
                    <span style="background:#2e1a1a;color:#f87171;border-radius:10px;font-size:9px;padding:1px 6px;margin-left:3px;vertical-align:middle">Inactive</span>
                    @endif
                </td>
                @endif
            </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="{{ 1 + (canSeeAllUsers() ? 1 : 0) + (showMeta() ? 1 : 0) }}" style="text-align:left">{{ strtoupper($monthName) }} TOTAL</td>
                    <td class="c-green" style="font-size:11px">${{ number_format($curGAMusd, 2) }}</td>
                    <td class="c-green">{{ fmtINR($curGAM) }}</td>
                    @if (showMeta())
                    <td class="c-orange">{{ fmtINR($curMeta) }}<div style="font-size:10px;color:var(--text-muted);font-weight:400">{{ fmtUsdFromInr($curMeta, $usdRate) }}</div></td>
                    <td class="c-gold">{{ fmtINR($curGST) }}</td>
                    <td>{{ fmtINR($curCost) }}</td>
                    <td class="{{ $curPL >= 0 ? 'c-green' : 'c-red' }}">{{ fmtINR($curPL) }}</td>
                    <td class="{{ $curMgn >= 0 ? 'c-green' : 'c-red' }}">{{ fmtPct($curMgn) }}</td>
                    <td>{!! $curPL >= 0 ? '<span class="badge-profit">✓ Profit</span>' : '<span class="badge-loss">✗ Loss</span>' !!}</td>
                    @endif
                </tr>
            </tfoot>
        </table>
    </div>
</div>

@endsection
