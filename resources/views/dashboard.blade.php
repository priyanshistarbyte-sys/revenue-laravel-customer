@extends('layouts.app')

@section('content')

<!-- Hidden inputs carry PHP config to JS -->
<input type="hidden" id="usdRate" value="{{ $usdRate }}">
<input type="hidden" id="gstRate" value="{{ $gstRate }}">

@if (!$dbOk)
<div class="alert-custom alert-error">
    <i class="bi bi-exclamation-circle"></i>
    Database error: {{ $error }} &mdash;
    <a href="{{ url('/setup') }}" style="color:inherit;font-weight:700">Run Setup →</a>
</div>
@endif

@include('partials.flash')

<!-- Header -->
<div class="dash-header">
    <div>
        <div class="dash-title">ADLEDGER &nbsp;·&nbsp; {{ strtoupper($monthYear) }} &nbsp;·&nbsp; DAILY P&amp;L DASHBOARD</div>
        @if (showMeta())
        <div class="dash-subtitle">META URL = expense (Meta Ads) &nbsp;·&nbsp; GAM URL = revenue (Google Ad Manager) &nbsp;·&nbsp; P/L = GAM Revenue − Meta Spend − GST</div>
        @else
        <div class="dash-subtitle">GAM URL = revenue (Google Ad Manager)</div>
        @endif
    </div>
    <div class="date-picker-wrap">
        @if (!empty($customerOptions))
        <label><i class="bi bi-person-vcard"></i> Customer →</label>
        <select id="customerPicker" class="form-select js-customer-filter" style="width:auto">
            <option value="">All customers</option>
            @foreach ($customerOptions as $cu)
            <option value="{{ (int)$cu['id'] }}" {{ $customerFilter === (int)$cu['id'] ? 'selected' : '' }}>
                {{ $cu['name'] }}{{ $cu['active'] ? '' : ' (inactive)' }}
            </option>
            @endforeach
            <option value="none" {{ $customerFilter === 'none' ? 'selected' : '' }}>— No customer —</option>
        </select>
        @endif
        @if (!empty($adxOptions))
        <label><i class="bi bi-diagram-3"></i> ADX →</label>
        <select class="form-select js-adx-filter" style="width:auto">
            <option value="">All ADX</option>
            @foreach ($adxOptions as $ax)
            <option value="{{ (int)$ax['id'] }}" {{ $adxFilter === (int)$ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
            @endforeach
        </select>
        @endif
        <label><i class="bi bi-calendar3"></i> Select date →</label>
        <select id="datePicker" class="form-select" style="width:auto">
            <option value="">— Pick a date —</option>
            @php
                $allDates = $datesRaw;
                if (!in_array($date, $allDates)) array_unshift($allDates, $date);
            @endphp
            @foreach ($allDates as $d)
            <option value="{{ $d }}" {{ $d === $date ? 'selected' : '' }}>{{ (new DateTime($d))->format('d M Y (D)') }}</option>
            @endforeach
        </select>
        <div style="color:var(--text-muted);font-size:11px">← Click dropdown to pick any date</div>

        <!-- Notes filter: searchable multi-select -->
        <div id="noteFilter" style="position:relative">
            <label><i class="bi bi-funnel"></i> Notes →</label>
            <button type="button" class="form-select" id="noteFilterBtn"
                    style="width:auto;min-width:150px;text-align:left;cursor:pointer;display:inline-flex;align-items:center;gap:6px">
                <span id="noteFilterLabel" style="flex:1">All</span>
                <i class="bi bi-chevron-down" style="font-size:9px;opacity:.7"></i>
            </button>
            <div id="noteFilterPanel"
                 style="display:none;position:absolute;right:0;top:110%;z-index:60;width:240px;
                        background:#12122a;border:1px solid #2e2e5a;border-radius:10px;padding:10px;
                        box-shadow:0 14px 40px rgba(0,0,0,.55)">
                <input type="text" id="noteFilterSearch" class="form-control"
                       placeholder="Search notes…" style="font-size:12px;margin-bottom:8px" autocomplete="off">
                <div style="display:flex;gap:6px;margin-bottom:8px">
                    <button type="button" class="btn-sm-custom" id="noteFilterAll"  style="flex:1;font-size:11px">Select all</button>
                    <button type="button" class="btn-sm-custom" id="noteFilterNone" style="flex:1;font-size:11px">Clear</button>
                </div>
                <div id="noteFilterList" style="max-height:220px;overflow:auto;display:flex;flex-direction:column;gap:1px">
                    @if ($hasEmptyNote)
                    <label class="note-opt" style="display:flex;gap:7px;align-items:center;padding:4px 6px;border-radius:6px;cursor:pointer;font-size:12px">
                        <input type="checkbox" value="__empty__"><span style="color:var(--text-muted)">(No note)</span>
                    </label>
                    @endif
                    @foreach ($noteOptions as $nt)
                    <label class="note-opt" style="display:flex;gap:7px;align-items:center;padding:4px 6px;border-radius:6px;cursor:pointer;font-size:12px">
                        <input type="checkbox" value="{{ $nt }}"><span>{{ $nt }}</span>
                    </label>
                    @endforeach
                    @if (!$noteOptions && !$hasEmptyNote)
                    <div style="color:var(--text-muted);font-size:11px;padding:4px 6px">No notes on any link.</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<!-- KPI Cards -->
<div class="kpi-row">
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-rupee"></i> GAM Revenue</div>
        <div class="kpi-value green">{{ fmtINR($totGAM) }}</div>
    </div>
    @if (showMeta())
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-megaphone"></i> Meta Spend</div>
        <div class="kpi-value orange">{{ fmtINR($totMeta) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-percent"></i> GST ({{ $gstRate }}%)</div>
        <div class="kpi-value gold">{{ fmtINR($totGST) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-receipt"></i> Total Cost</div>
        <div class="kpi-value white">{{ fmtINR($totCost) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-graph-up"></i> Net P/L</div>
        <div class="kpi-value {{ $totNetPL >= 0 ? 'green' : 'red' }}">{{ fmtINR($totNetPL) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-speedometer2"></i> Margin</div>
        <div class="kpi-value {{ $totMargin >= 0 ? 'green' : 'red' }}">{{ fmtPct($totMargin) }}</div>
    </div>
    @endif
    <div class="kpi-card" title="{{ number_format($totClicks) }} clicks / {{ number_format($totImpr) }} impressions">
        <div class="kpi-label"><i class="bi bi-cursor"></i> GAM CTR</div>
        <div class="kpi-value white">{{ $totCtr !== null ? number_format($totCtr, 2) . '%' : '—' }}</div>
    </div>
    @if (showMeta())
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-check-circle"></i> Profitable</div>
        <div class="kpi-value green">{{ $cntProfit }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-x-circle"></i> Losing</div>
        <div class="kpi-value red">{{ $cntLoss }}</div>
    </div>
    @endif
</div>

<!-- Main Table -->
<div class="data-card">
    <div class="data-card-header" style="flex-wrap:wrap">
        <i class="bi bi-table"></i> Link Performance &mdash; {{ $displayDate }}
        <button type="button" id="dashExportBtn" class="btn-sm-custom" style="margin-left:auto" data-date="{{ $date }}">
            <i class="bi bi-download"></i> Export CSV
        </button>
        <button type="button" id="dashDomainsBtn" class="btn-sm-custom" style="margin-left:8px" data-date="{{ $date }}"
                title="Export all data, but the GAM URL column shows only the last GAM URL (full subdomain)">
            <i class="bi bi-globe2"></i> CSV (last GAM URL)
        </button>
        @if (showMeta())
        <button type="button" class="btn-sm-custom" style="margin-left:8px" data-copy-meta-urls="dashTable"
                title="Copy the Meta URL of every row shown (respects the Notes filter), one per line">
            <i class="bi bi-clipboard"></i> Copy Meta URLs
        </button>
        @endif
        <span style="margin-left:12px;font-weight:400;font-size:11px;color:var(--text-muted)">USD/{{ getDefaultCurrency()['code'] }}: {{ number_format($usdRate, 2) }}</span>
    </div>
    <div class="table-wrap">
        <table class="ledger" id="dashTable">
            <thead>
                <tr>
                    <th data-sort="str" data-col="0">Entry Date <span class="sort-btn">⇅</span></th>
                    @if (showMeta())
                    <th data-sort="str" data-col="1">META URL <span style="font-weight:400">(spend)</span> <span class="sort-btn">⇅</span></th>
                    @endif
                    <th data-sort="str" data-col="2">GAM URL <span style="font-weight:400">(revenue)</span> <span class="sort-btn">⇅</span></th>
                    <th data-sort="str" data-col="3">ADX <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" data-col="4">GAM Rev ($) <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" data-col="5">GAM Revenue (₹) <span class="sort-btn">⇅</span></th>
                    @if (showMeta())
                    <th data-sort="num" data-col="6">Meta Spend <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" data-col="7">GST <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" data-col="8">Total Cost <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" data-col="9">Net P/L <span class="sort-btn">⇅</span></th>
                    <th data-sort="num" data-col="10">Margin <span class="sort-btn">⇅</span></th>
                    @endif
                    <th data-sort="num" data-col="11">CTR <span class="sort-btn">⇅</span></th>
                    @if (showMeta())
                    <th data-sort="str" data-col="12">Status <span class="sort-btn">⇅</span></th>
                    @endif
                    @if (canSeeAllUsers())
                    <th data-sort="str" data-col="13">User <span class="sort-btn">⇅</span></th>
                    @endif
                    <th data-sort="str" data-col="{{ canSeeAllUsers() ? 14 : 13 }}">Notes <span class="sort-btn">⇅</span></th>
                    <th style="text-align:center;width:80px">Actions</th>
                </tr>
            </thead>
            <tbody>
            @foreach ($rows as $ri => $r)
                @php
                    $cls       = !$r['hasData'] ? 'row-nodata' : (!showMeta() ? '' : ($r['netPL'] >= 0 ? 'row-profit' : 'row-loss'));
                    $campaigns = array_values(array_filter(array_map('trim', explode(',', $r['link']['meta_campaign']))));
                    $gamSites  = $r['gamSites'];
                    $multiCamp = showMeta() && count($r['campaignBreakdown']) > 1;
                    $hasAny    = !empty(array_filter($r['campaignBreakdown'], fn($c) => $c['spend'] !== null));
                    $subRowId  = 'sub-' . $ri;
                @endphp
            <!-- Main row -->
            <tr class="{{ $cls }}"
                data-date="{{ $date }}"
                data-link-id="{{ (int)$r['link']['id'] }}"
                data-user-id="{{ (int)($r['link']['user_id'] ?? 0) }}"
                data-note="{{ trim((string)($r['link']['notes'] ?? '')) }}"
                data-link-name="{{ $r['link']['link_name'] }}"
                data-gam-sites="{{ json_encode($gamSites) }}"
                data-gam-usd="{{ number_format($r['gamUSD'] ?? 0, 6, '.', '') }}"
                @if (showMeta())
                data-meta-campaigns="{{ json_encode($campaigns) }}"
                data-meta-url="{{ $r['link']['meta_url'] }}"
                data-meta-spend="{{ number_format($r['spend'], 4, '.', '') }}"
                @endif>

                <td style="color:#c4b5fd;font-size:11px;white-space:nowrap">
                    @php $entryDate = $r['link']['created_at'] ?? ''; @endphp
                    {{ $entryDate ? date('d M Y', strtotime($entryDate)) : '—' }}
                </td>
                @if (showMeta())
                <td><a class="url-tag" href="{{ $r['link']['meta_url'] }}" target="_blank">{{ parse_url($r['link']['meta_url'], PHP_URL_HOST) ?: $r['link']['meta_url'] }}</a></td>
                @endif
                <td>
                    @foreach ($gamSites as $gs)
                    <span style="color:var(--text-muted);display:inline-block">{{ $gs }}</span>@if (count($gamSites) > 1)<br>@endif
                    @endforeach
                    @if (empty($gamSites))<span style="color:var(--text-muted)">—</span>@endif
                </td>
                <td style="white-space:nowrap">
                    @if (!empty($r['adxName']))
                    <span style="background:#1a1a42;border-radius:10px;padding:2px 8px;font-size:10px;color:#7dd3fc" title="ADX network (from last GAM site)">
                        <i class="bi bi-diagram-3" style="font-size:9px"></i> {{ $r['adxName'] }}
                    </span>
                    @else
                    <span style="color:var(--text-muted)">—</span>
                    @endif
                </td>

                @if (!$r['hasData'])
                <td class="cell-gam-usd c-muted">$0.00</td>
                <td class="cell-gam-inr c-muted">₹0.00</td>
                @if (showMeta())
                <td class="c-muted"><span class="cell-meta-spend">₹0.00</span><div class="cell-meta-usd" style="font-size:10px;color:var(--text-muted);font-weight:400"></div></td>
                <td class="cell-gst c-muted">₹0.00</td>
                <td class="cell-cost c-muted">₹0.00</td>
                <td class="cell-netpl c-muted" style="font-weight:700">₹0.00</td>
                <td class="cell-margin c-muted">—</td>
                @endif
                <td class="cell-ctr c-muted">—</td>
                @if (showMeta())
                <td class="cell-status">
                    <span class="badge-nodata">No data</span>
                    @if (!$r['link']['active'])
                    <span style="background:#2e1a1a;color:#f87171;border-radius:10px;font-size:9px;padding:1px 6px;margin-left:3px;vertical-align:middle">Inactive</span>
                    @endif
                </td>
                @endif
                @else
                <td class="cell-gam-usd c-green" style="font-size:11px">${{ number_format($r['gamUSD'] ?? 0, 2) }}</td>
                <td class="cell-gam-inr c-green">{{ fmtINR($r['gamINR']) }}</td>
                @if (showMeta())
                <td class="c-orange {{ $multiCamp && $hasAny ? 'camp-toggle-cell' : '' }}"
                    @if ($multiCamp && $hasAny) data-target="{{ $subRowId }}" title="Click to view campaign breakdown" @endif>
                    <span class="cell-meta-spend">{{ fmtINR($r['spend']) }}</span>
                    @if ($multiCamp && $hasAny)
                    <i class="bi bi-chevron-down camp-chevron" style="font-size:9px;margin-left:3px;opacity:.6"></i>
                    @endif
                    <div class="cell-meta-usd" style="font-size:10px;color:var(--text-muted);font-weight:400">{{ $r['spend'] > 0 ? fmtUsdFromInr($r['spend'], $usdRate) : '' }}</div>
                </td>
                <td class="cell-gst c-gold">{{ fmtINR($r['gst']) }}</td>
                <td class="cell-cost">{{ fmtINR($r['cost']) }}</td>
                <td class="cell-netpl {{ $r['netPL'] >= 0 ? 'c-green' : 'c-red' }}" style="font-weight:700">{{ fmtINR($r['netPL']) }}</td>
                <td class="cell-margin {{ ($r['margin'] !== null && $r['margin'] >= 0) ? 'c-green' : 'c-red' }}">{{ $r['margin'] !== null ? fmtPct($r['margin']) : '—' }}</td>
                @endif
                <td class="cell-ctr" title="{{ number_format($r['gamClicks']) }} clicks / {{ number_format($r['gamImpr']) }} impressions">{{ $r['ctr'] !== null ? number_format($r['ctr'], 2) . '%' : '—' }}</td>
                @if (showMeta())
                <td class="cell-status">
                    {!! $r['netPL'] >= 0 ? '<span class="badge-profit">✓ Profit</span>' : '<span class="badge-loss">✗ Loss</span>' !!}
                    @if (!$r['link']['active'])
                    <span style="background:#2e1a1a;color:#f87171;border-radius:10px;font-size:9px;padding:1px 6px;margin-left:3px;vertical-align:middle">Inactive</span>
                    @endif
                </td>
                @endif
                @endif

                @if (canSeeAllUsers())
                <td style="white-space:nowrap">
                    <span style="background:#1a1a42;border-radius:12px;padding:2px 10px;font-size:11px;color:#7dd3fc">
                        <i class="bi bi-person" style="font-size:10px"></i> {{ $r['link']['owner_name'] ?? '—' }}
                    </span>
                </td>
                @endif

                @php $noteVal = trim((string)($r['link']['notes'] ?? '')); @endphp
                <td style="max-width:170px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $noteVal }}">
                    @if ($noteVal !== '')
                    <span style="background:#1a1a42;color:#c4b5fd;border-radius:10px;padding:2px 8px;font-size:10px">{{ $noteVal }}</span>
                    @else
                    <span style="color:var(--text-muted)">—</span>
                    @endif
                </td>

                <td style="text-align:center;white-space:nowrap">
                    <button type="button" class="btn-row-history" title="Last 30 days history">
                        <i class="bi bi-clock-history"></i>
                    </button>
                    <button class="btn-row-edit" title="{{ showMeta() ? 'Edit GAM Revenue & Meta Spend' : 'Edit GAM Revenue' }}">
                        <i class="bi bi-pencil-square"></i>
                    </button>
                </td>
            </tr>

            @if ($multiCamp && $hasAny)
            <!-- Campaign breakdown sub-row (hidden by default) -->
            <tr id="{{ $subRowId }}" class="camp-sub-row" style="display:none">
                <td colspan="{{ canSeeAllUsers() ? 16 : 15 }}" style="padding:0;border-bottom:2px solid var(--purple)">
                    <div style="background:#0d0d1f;padding:10px 20px 12px">
                        <div style="font-size:10px;color:var(--text-muted);text-transform:uppercase;
                                    letter-spacing:.6px;margin-bottom:8px">
                            <i class="bi bi-bar-chart-steps" style="color:var(--purple)"></i>
                            Campaign Breakdown — <span style="color:#c4b5fd">{{ $r['link']['link_name'] }}</span>
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ($r['campaignBreakdown'] as $c)
                            @php $has = $c['spend'] !== null; @endphp
                            <div style="display:flex;align-items:center;gap:8px;padding:6px 12px;
                                        background:var(--card-bg);border-radius:8px;
                                        border:1px solid {{ $has ? 'var(--border)' : '#1f1f3a' }};
                                        opacity:{{ $has ? '1' : '.45' }}">
                                <i class="bi bi-megaphone" style="color:{{ $has ? 'var(--orange)' : 'var(--text-muted)' }};font-size:11px"></i>
                                <span style="font-size:11px;color:var(--text);max-width:200px;
                                             overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                                      title="{{ $c['name'] }}">{{ $c['name'] }}</span>
                                @if ($has)
                                <span style="font-size:10px;color:var(--text-muted);white-space:nowrap">{{ number_format($c['impressions']) }} imp</span>
                                <span style="font-size:10px;color:var(--text-muted);white-space:nowrap">{{ $c['results'] }} res</span>
                                <span style="font-size:12px;font-weight:700;color:var(--orange);white-space:nowrap">{{ fmtINR($c['spend']) }}</span>
                                @else
                                <span style="font-size:10px;color:var(--text-muted);font-style:italic">no data</span>
                                @endif
                            </div>
                        @endforeach
                        </div>
                    </div>
                </td>
            </tr>
            @endif
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="{{ showMeta() ? 3 : 2 }}" style="text-align:left">TOTALS</td>
                    <td></td>
                    <td class="c-green" style="font-size:11px">${{ number_format($totGAMusd, 2) }}</td>
                    <td class="c-green">{{ fmtINR($totGAM) }}</td>
                    @if (showMeta())
                    <td class="c-orange">{{ fmtINR($totMeta) }}<div class="cell-meta-usd" style="font-size:10px;color:var(--text-muted);font-weight:400">{{ fmtUsdFromInr($totMeta, $usdRate) }}</div></td>
                    <td class="c-gold">{{ fmtINR($totGST) }}</td>
                    <td>{{ fmtINR($totCost) }}</td>
                    <td class="{{ $totNetPL >= 0 ? 'c-green' : 'c-red' }}">{{ fmtINR($totNetPL) }}</td>
                    <td class="{{ $totMargin >= 0 ? 'c-green' : 'c-red' }}">{{ fmtPct($totMargin) }}</td>
                    @endif
                    <td>{{ $totCtr !== null ? number_format($totCtr, 2) . '%' : '—' }}</td>
                    @if (showMeta())
                    <td><span class="badge-profit">{{ $cntProfit }} Profit</span> <span class="badge-loss">{{ $cntLoss }} Loss</span></td>
                    @endif
                    @if (canSeeAllUsers())<td></td>@endif
                    <td></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<!-- ── Row Edit Modal ── -->
<div class="modal fade" id="rowEditModal" tabindex="-1">
    <div class="modal-dialog modal-sm" style="max-width:380px">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a;padding:14px 18px">
                <div>
                    <h6 class="modal-title" style="color:#fff;margin:0"><i class="bi bi-pencil-square"></i> Edit Values</h6>
                    <div id="editModalLinkName" style="color:#a78bfa;font-size:11px;margin-top:2px"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:18px">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">GAM Revenue ($) <small style="color:var(--text-muted);font-weight:400">— USD amount from GAM</small></label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="color:var(--text-muted);font-size:12px">Current:</span>
                            <span id="editCurrentGam" style="color:#00c853;font-size:12px;font-weight:600"></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                            <span style="color:#fff;font-size:13px;font-weight:600">$</span>
                            <input type="number" id="editGamUsd" class="form-control" min="0" step="0.01" placeholder="0.00" style="flex:1">
                        </div>
                    </div>
                    @if (showMeta())
                    <div class="col-12">
                        <label class="form-label">Meta Spend (₹) <small style="color:var(--text-muted);font-weight:400">— INR amount from Meta Ads</small></label>
                        <div style="display:flex;align-items:center;gap:8px">
                            <span style="color:var(--text-muted);font-size:12px">Current:</span>
                            <span id="editCurrentMeta" style="color:#ff8c00;font-size:12px;font-weight:600"></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;margin-top:6px">
                            <span style="color:#fff;font-size:13px;font-weight:600">₹</span>
                            <input type="number" id="editMetaSpend" class="form-control" min="0" step="0.01" placeholder="0.00" style="flex:1">
                        </div>
                    </div>
                    @endif
                </div>
                <div id="editModalError" style="display:none;margin-top:12px" class="alert-custom alert-error"></div>
            </div>
            <div class="modal-footer" style="border-color:#2e2e5a;padding:12px 18px;gap:8px">
                <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="editModalSave" class="btn-primary-custom">
                    <i class="bi bi-floppy"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Link History Modal (last 30 days) ── -->
<div class="modal fade" id="linkHistoryModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a;padding:14px 18px">
                <div>
                    <h6 class="modal-title" style="color:#fff;margin:0"><i class="bi bi-clock-history"></i> Last 30 Days History</h6>
                    <div style="font-size:11px;margin-top:2px">
                        <span id="historyLinkName" style="color:#a78bfa"></span>
                        <span id="historyRange" style="color:var(--text-muted);margin-left:8px"></span>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:18px">
                <div id="historyLoading" style="text-align:center;color:var(--text-muted);padding:30px" hidden>
                    <span class="spinner-border spinner-border-sm"></span> Loading history…
                </div>
                <div id="historyError" class="alert-custom alert-error" hidden></div>
                <div id="historySummary" class="history-kpis"></div>
                <div class="table-wrap" style="overflow-x:auto">
                    <table class="ledger" id="historyTable">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>GAM Rev ($)</th>
                                <th>GAM Revenue (₹)</th>
                                @if (showMeta())
                                <th>Meta Spend</th>
                                <th>GST</th>
                                <th>Total Cost</th>
                                <th>Net P/L</th>
                                <th>Margin</th>
                                <th>Status</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody id="historyBody"></tbody>
                        <tfoot id="historyFoot"></tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

@endsection
