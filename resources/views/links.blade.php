@extends('layouts.app')

@section('content')

@php $zips = $zips ?? []; $deployZips = $deployZips ?? []; $servers = $servers ?? []; $siteServers = $siteServers ?? []; @endphp
@php
    // Every action posts back with the current filters/sort in its query string so the
    // redirect afterwards lands on the same filtered list (see LinksController::linksRedirect).
    $lpQ       = array_diff_key(request()->query(), array_flip(['add', 'edit', 'deploy']));
    $lpQs      = http_build_query($lpQ);
    $lpUrl     = url('/links') . ($lpQs ? '?' . $lpQs : '');
    $lpUrlWith = fn (array $extra) => url('/links') . '?' . http_build_query($lpQ + $extra);
@endphp

@include('partials.flash')

@if ($hasDuplicates)
<div style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.35);color:#fbbf24;
            border-radius:10px;padding:14px 18px;margin-bottom:16px;font-size:12px;line-height:1.7">
    <div style="font-weight:700;margin-bottom:6px">
        <i class="bi bi-exclamation-triangle-fill"></i> Possible double counting
    </div>
    <div style="color:#e8e8f0;margin-bottom:8px">
        These are used in more than one subdomain, so their spend/revenue is counted once per subdomain
        on the Dashboard and Monthly pages. Keep each in a single subdomain to avoid inflating totals.
    </div>
    <ul style="margin:0;padding-left:18px;color:#e8e8f0">
        @foreach ($dupCampaigns as $d)
        <li>
            Meta campaign <code style="background:#1a1a42;padding:0 5px;border-radius:3px;color:#fbbf24">{{ $d['value'] }}</code>
            @if (canSeeAllUsers())<span style="color:var(--text-muted)">({{ $d['owner'] }})</span>@endif
            → in {{ count($d['links']) }} subdomains:
            <span style="color:#c4b5fd">{{ implode(', ', $d['links']) }}</span>
        </li>
        @endforeach
        @foreach ($dupGam as $d)
        <li>
            GAM site <code style="background:#1a1a42;padding:0 5px;border-radius:3px;color:#7dd3fc">{{ $d['value'] }}</code>
            @if (canSeeAllUsers())<span style="color:var(--text-muted)">({{ $d['owner'] }})</span>@endif
            → in {{ count($d['links']) }} subdomains:
            <span style="color:#c4b5fd">{{ implode(', ', $d['links']) }}</span>
        </li>
        @endforeach
    </ul>
</div>
@endif

<!-- Page Header -->
<div class="dash-header" style="margin-bottom:16px;flex-wrap:wrap;gap:12px;padding:16px 20px">
    <div>
        <div class="dash-title" style="font-size:1.2rem">Links <span style="color:var(--text-muted);font-weight:400;font-size:.9rem">· subdomains</span></div>
        <div class="dash-subtitle">Each subdomain maps Meta campaigns to GAM sites — group them under a main Domain</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px">
        <a href="{{ url('/domains') }}" class="btn-sm-custom"><i class="bi bi-globe2"></i> Domains</a>
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#addModal">
            <i class="bi bi-plus-lg"></i> Add Subdomain
        </button>
    </div>
</div>

<!-- Filters -->
<form method="get" action="{{ url('/links') }}" class="lp-filter">
    <div class="lp-search">
        <i class="bi bi-search"></i>
        <input type="text" name="f_q" value="{{ $fQ }}" placeholder="Search subdomain, campaign, URL, domain…" autocomplete="off">
    </div>
    <span class="lp-flabel"><i class="bi bi-funnel"></i> Filter</span>
    <select name="f_domain" class="form-select">
        <option value="">All domains</option>
        <option value="0" {{ $fDomain === '0' ? 'selected' : '' }}>— Ungrouped —</option>
        @foreach ($domains as $dOpt)
        <option value="{{ (int)$dOpt['id'] }}" {{ (string)$fDomain === (string)$dOpt['id'] ? 'selected' : '' }}>{{ $dOpt['name'] }}</option>
        @endforeach
    </select>
    <select name="f_adx" class="form-select">
        <option value="">All ADX</option>
        @foreach ($adxOptions as $ax)
        <option value="{{ (int)$ax['id'] }}" {{ (string)$fAdx === (string)$ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
        @endforeach
    </select>
    <select name="f_status" class="form-select">
        <option value="">All status</option>
        <option value="1" {{ (string)$fStatus === '1' ? 'selected' : '' }}>Active</option>
        <option value="0" {{ (string)$fStatus === '0' ? 'selected' : '' }}>Paused</option>
    </select>
    @if (canSeeAllUsers())
    <select name="f_user" class="form-select">
        <option value="">All persons</option>
        @foreach (allUsers() as $uOpt)
        <option value="{{ (int)$uOpt['id'] }}" {{ $fUser !== '' && (int)$fUser === (int)$uOpt['id'] ? 'selected' : '' }}>
            {{ $uOpt['name'] }}{{ $uOpt['is_admin'] ? ' (admin)' : '' }}
        </option>
        @endforeach
    </select>
    @endif
    <button type="submit" class="icon-btn" style="border-color:var(--border)"><i class="bi bi-funnel-fill"></i> Apply</button>
    @if ($filterActive)
    <a href="{{ url('/links') }}" class="icon-btn"><i class="bi bi-x-lg"></i> Clear</a>
    @endif
</form>

<!-- Subdomains table -->
<div class="data-card">
    <div class="data-card-header" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span><i class="bi bi-link-45deg"></i> Subdomains <span style="color:var(--text-muted);font-weight:400">({{ count($links) }})</span>
        @if ($filterActive)<span style="margin-left:6px;font-size:11px;color:#fbbf24">filtered</span>@endif</span>
        <button type="button" class="btn-sm-custom" style="margin-left:auto" data-copy-meta-urls="lpTable"
                title="Copy the Meta URL of every listed subdomain (or only the ticked ones), one per line">
            <i class="bi bi-clipboard"></i> Copy Meta URLs
        </button>
        <div id="lpBulkBar" style="display:none;align-items:center;gap:8px">
            <span style="font-size:12px;color:var(--text-muted)"><strong id="lpSelCount" style="color:#c4b5fd">0</strong> selected</span>
            @if (userCan('links', 'edit'))
            <button type="button" id="lpBulkPause" class="btn-sm-custom"><i class="bi bi-pause-circle"></i> Pause</button>
            @endif
            @if (userCan('links', 'delete'))
            <button type="button" id="lpBulkDelete" class="btn-danger-custom"><i class="bi bi-trash3"></i> Delete</button>
            @endif
        </div>
    </div>
    <div class="table-wrap">
        <table class="ledger" id="lpTable">
            @php
                // Sortable header links that preserve the active filters and toggle direction.
                $baseQ = array_filter(
                    ['f_q' => $fQ, 'f_domain' => $fDomain, 'f_adx' => $fAdx, 'f_status' => $fStatus, 'f_user' => $fUser],
                    fn ($v) => $v !== '' && $v !== null
                );
                $sortHref = function ($key) use ($baseQ, $sort, $dir) {
                    $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
                    return url('/links') . '?' . http_build_query($baseQ + ['sort' => $key, 'dir' => $next]);
                };
                $sortArrow = fn ($key) => $sort === $key ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
                $sortTh = function ($key, $label, $align = 'left') use ($sortHref, $sortArrow) {
                    return '<th style="text-align:' . $align . '"><a href="' . e($sortHref($key))
                         . '" style="color:inherit;text-decoration:none;white-space:nowrap;cursor:pointer">'
                         . e($label) . '<span style="color:#a78bfa">' . $sortArrow($key) . '</span></a></th>';
                };
            @endphp
            <thead>
                <tr>
                    <th style="text-align:center;width:28px"><input type="checkbox" id="lpSelAll" style="accent-color:var(--purple)" title="Select all"></th>
                    <th style="text-align:left">#</th>
                    @if (canSeeAllUsers()){!! $sortTh('user', 'User') !!}@endif
                    {!! $sortTh('date', 'Entry Date') !!}
                    {!! $sortTh('camp', 'Campaigns') !!}
                    {!! $sortTh('meta', 'Meta URL') !!}
                    {!! $sortTh('gam', 'GAM URL(s)') !!}
                    {!! $sortTh('adx', 'ADX') !!}
                    <th style="text-align:left">Notes</th>
                    {!! $sortTh('status', 'Status', 'center') !!}
                    <th style="text-align:center"></th>
                </tr>
            </thead>
            <tbody>
            @if (empty($links))
            <tr><td colspan="{{ $colspan }}" style="text-align:center;color:var(--text-muted);padding:32px">
                @if ($filterActive)
                    Nothing matches the current filter. <a href="{{ url('/links') }}" style="color:#a78bfa">Clear filter</a>
                @else
                    No subdomains yet. <a href="#" data-bs-toggle="modal" data-bs-target="#addModal" style="color:#a78bfa">Add your first subdomain →</a>
                @endif
            </td></tr>
            @endif
            @foreach ($links as $i => $l)
                @php
                    $campaigns = array_filter(array_map('trim', explode(',', $l['meta_campaign'])));
                    $gamSites  = array_filter(array_map('trim', explode(',', $l['gam_url'])));
                    $uid       = (int) ($l['user_id'] ?? 0);
                @endphp
            <tr data-meta-url="{{ $l['meta_url'] }}" style="{{ !$l['active'] ? 'opacity:.45' : '' }}">
                <td style="text-align:center"><input type="checkbox" class="lp-sel row-sel" value="{{ $l['id'] }}" style="accent-color:var(--purple)"></td>
                <td class="c-muted">{{ $i + 1 }}</td>
                @if (canSeeAllUsers())
                <td style="white-space:nowrap">
                    <span style="background:#1a1a42;border-radius:12px;padding:2px 10px;font-size:11px;color:#7dd3fc">
                        <i class="bi bi-person" style="font-size:10px"></i> {{ $l['owner_name'] ?? '—' }}
                    </span>
                </td>
                @endif
                <td class="c-muted" style="white-space:nowrap;font-size:12px">
                    {{ !empty($l['created_at']) ? date('Y-m-d', strtotime($l['created_at'])) : '—' }}
                </td>
                <td style="white-space:normal">
                    <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:280px">
                    @foreach ($campaigns as $cp)
                        @php $dup = isset($dupCampSet["$uid|$cp"]); @endphp
                    <code class="campaign-tag{{ $dup ? ' dup-tag' : '' }}" style="margin:0;max-width:100%;overflow-wrap:anywhere" @if($dup) title="Also used in another subdomain — spend is double-counted" @endif>
                        @if ($dup)<i class="bi bi-exclamation-triangle-fill" style="font-size:9px"></i> @endif{{ $cp }}
                    </code>
                    @endforeach
                    </div>
                </td>
                <td>
                    <a class="url-tag" href="{{ $l['meta_url'] }}" target="_blank" title="{{ $l['meta_url'] }}">{{ $l['meta_url'] }}</a>
                    @if (!empty($l['needs_redeploy']))
                    <span style="display:inline-block;margin-top:3px;background:#3a2a00;border:1px solid #a16207;border-radius:10px;padding:1px 8px;font-size:10px;color:#fbbf24;white-space:nowrap"
                          title="The GAM URL changed, so MAIN_URL ('{{ resolveMainUrl($l['gam_url']) }}') is out of date on the deployed sub-site. Re-deploy to apply.">
                        <i class="bi bi-exclamation-triangle-fill" style="font-size:9px"></i> Re-deploy needed
                    </span>
                    @endif
                </td>
                <td style="white-space:normal">
                    <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:280px">
                    @foreach ($gamSites as $gs)
                        @php $dup = isset($dupGamSet["$uid|$gs"]); @endphp
                    <code class="campaign-tag{{ $dup ? ' dup-tag' : '' }}" style="margin:0;max-width:100%;overflow-wrap:anywhere;{{ $dup ? '' : 'color:#7dd3fc' }}" @if($dup) title="Also used in another subdomain — revenue is double-counted" @endif>
                        @if ($dup)<i class="bi bi-exclamation-triangle-fill" style="font-size:9px"></i> @endif{{ $gs }}
                    </code>
                    @endforeach
                    </div>
                </td>
                <td style="white-space:nowrap">
                    @if (!empty($l['adx_name']))
                    <span style="color:#7dd3fc;font-size:11px"><i class="bi bi-diagram-3"></i> {{ $l['adx_name'] }}</span>
                    @else<span class="c-muted">—</span>@endif
                </td>
                <td class="c-muted" style="max-width:150px;overflow:hidden;text-overflow:ellipsis">{{ $l['notes'] ?? '' }}</td>
                <td style="text-align:center">
                    @if ($l['active'])
                        <span class="pill ok static"><span class="dotmark"></span>Active</span>
                    @else
                        <span class="pill static">Paused</span>
                    @endif
                </td>
                <td style="text-align:center">
                    <div style="display:flex;gap:2px;justify-content:center">
                        <button type="button" class="icon-btn" data-bs-toggle="modal" data-bs-target="#uploadZipModal{{ $l['id'] }}"
                                title="{{ !empty($l['needs_redeploy']) ? 'MAIN_URL changed — re-deploy needed' : 'Upload Site' }}"
                                style="{{ !empty($l['needs_redeploy']) ? 'color:#fbbf24' : '' }}">
                            <i class="bi bi-cloud-upload{{ !empty($l['needs_redeploy']) ? '-fill' : '' }}"></i>
                        </button>
                        <a href="{{ $lpUrlWith(['edit' => $l['id']]) }}" class="icon-btn" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="{{ $lpUrl }}" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="{{ $l['id'] }}">
                            <button type="submit" class="icon-btn" title="{{ $l['active'] ? 'Pause' : 'Activate' }}">
                                <i class="bi bi-{{ $l['active'] ? 'pause' : 'play' }}-circle"></i>
                            </button>
                        </form>
                        <form method="post" action="{{ $lpUrl }}" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $l['id'] }}">
                            <button type="submit" class="icon-btn danger" data-confirm="Delete '{{ $l['link_name'] }}'? This cannot be undone." title="Delete">
                                <i class="bi bi-trash3"></i>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>

            @endforeach
            </tbody>
        </table>
        {{-- Bulk-action form — row checkboxes live in the table (outside any per-row form). --}}
        <form method="post" action="{{ $lpUrl }}" id="lpBulkForm" style="display:none">
            @csrf
            <input type="hidden" name="action" id="lpBulkAction">
            <div id="lpBulkIds"></div>
        </form>
        @foreach ($links as $l)
        @php
            $gamSites     = array_filter(array_map('trim', explode(',', $l['gam_url'])));
            $modalMainUrl = resolveMainUrl($l['gam_url']);

            // Deploy defaults to the server chosen when the subdomain was added (kept
            // in step with the last deploy); if that is unset, fall back to whichever
            // server this link's hosts are already deployed on.
            $defaultServerId = (int) ($l['dns_server_id'] ?? 0);
            if (!$defaultServerId) {
                foreach (array_merge([$l['meta_url']], $gamSites) as $h) {
                    $k = preg_replace('/^www\./', '', normalizeUrl($h));
                    if (!empty($siteServers[$k]['server_id'])) { $defaultServerId = (int) $siteServers[$k]['server_id']; break; }
                }
            }
        @endphp
        <div class="modal fade" id="uploadZipModal{{ $l['id'] }}" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
                    <div class="modal-header" style="border-color:#2e2e5a">
                        <h5 class="modal-title" style="color:#fff"><i class="bi bi-cloud-upload"></i> Deploy </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="post" action="{{ $lpUrl }}">
                        @csrf
                        <input type="hidden" name="action" value="deploy_multi">
                        <input type="hidden" name="id" value="{{ $l['id'] }}">
                        <div class="modal-body">
                            @if (!empty($l['needs_redeploy']))
                            <div style="margin-bottom:10px;font-size:12px;background:#3a2a00;border:1px solid #a16207;border-radius:8px;padding:8px 14px;color:#fbbf24">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                The GAM URL changed since the last deploy — <strong>MAIN_URL is now {{ $modalMainUrl ?: '—' }}</strong>. Re-deploy the Meta sub-site to apply it.
                            </div>
                            @endif

                            <div style="margin-bottom:12px">
                                <label style="display:block;font-size:12px;color:var(--text-muted);margin-bottom:4px"><i class="bi bi-hdd-network"></i> Deploy to server</label>
                                @if (empty($servers))
                                    <div style="font-size:12px;color:#fbbf24">
                                        No active servers. <a href="{{ url('/server-masters') }}" style="color:#a78bfa">Add one →</a>
                                    </div>
                                @else
                                    <select name="server_id" class="form-select form-select-sm deploy-server" required>
                                        <option value="">— Select server —</option>
                                        @foreach ($servers as $sv)
                                        <option value="{{ $sv['id'] }}" @selected($defaultServerId === (int) $sv['id'])>{{ $sv['name'] }} ({{ $sv['ssh_host'] }})</option>
                                        @endforeach
                                    </select>
                                @endif
                            </div>

                            <!-- Shown by JS only when a selected host already lives on a different server. -->
                            <div class="deploy-move-box" style="display:none;margin-bottom:10px;font-size:12px;background:#2a1a0d;border:1px solid #a16207;border-radius:8px;padding:8px 14px">
                                <div style="color:#fbbf24;margin-bottom:6px">
                                    <i class="bi bi-exclamation-triangle"></i>
                                    <span class="deploy-move-hint"></span>
                                    Deploying will skip them unless you move them.
                                </div>
                                <label style="color:#e5e7eb;display:block;margin-bottom:6px;cursor:pointer">
                                    <input type="checkbox" name="move_sites" value="1" class="deploy-move-toggle"> Move these sites to this server
                                </label>
                                <select name="move_mode" class="form-select form-select-sm deploy-move-mode" disabled style="max-width:340px">
                                    <option value="stop">Stop the old site (keep its files)</option>
                                    <option value="delete">Delete the old site (removes files &amp; database)</option>
                                </select>
                            </div>
                            <div style="margin-bottom:10px;font-size:12px;background:#0d0d2b;border:1px solid #2e2e5a;border-radius:8px;padding:8px 14px;line-height:2">
                                <span style="color:var(--text-muted)">.xyz MAIN_URL:</span>
                                <code style="background:#1a1a42;padding:1px 7px;border-radius:4px;color:#c4b5fd">{{ $modalMainUrl ?: '—' }}</code>
                                &nbsp;&nbsp;
                                <span style="color:var(--text-muted)">.com AD_UNIT:</span>
                                <span style="color:var(--text-muted);font-size:11px">per site — shown below</span>
                            </div>
                            <label style="display:inline-flex;align-items:center;gap:6px;cursor:pointer;font-size:12px;color:var(--text-muted);margin-bottom:8px">
                                <input type="checkbox" class="deploy-older-toggle" style="accent-color:var(--purple);width:14px;height:14px">
                                <i class="bi bi-clock-history"></i> Show older versions
                            </label>
                            <table class="ledger" style="width:100%">
                                <thead>
                                    <tr>
                                        <th style="text-align:left">Links</th>
                                        <th style="text-align:left">Zip Code</th>
                                        <th style="text-align:left">Type</th>
                                    </tr>
                                </thead>
                                <tbody>
                                @php
                                    $deployRows = trim((string) $l['meta_url']) !== ''
                                        ? ['meta' => ['host' => $l['meta_url'], 'label' => 'Meta URL']]
                                        : [];
                                    foreach ($gamSites as $idx => $gs) {
                                        $deployRows[$idx] = ['host' => $gs, 'label' => null];
                                    }
                                @endphp
                                @foreach ($deployRows as $key => $row)
                                    @php
                                        $hostParts    = explode('.', trim($row['host']));
                                        $ext          = ($key == 'meta') ? 'sub-site' : 'main-site';
                                        $typeMatches = function ($types) use ($ext) {
                                            // Normalize legacy values (xyz/com) to the new type labels.
                                            $t = strtolower(ltrim(trim($types), '.'));
                                            $t = in_array($t, ['xyz', 'sub-site'], true) ? 'sub-site'
                                               : (in_array($t, ['com', 'main-site'], true) ? 'main-site' : $t);
                                            return $t === $ext;
                                        };
                                        $matchingZips     = array_filter($zips, fn ($z) => $typeMatches($z['types']));
                                        $matchingVersions = array_filter($zipVersions, fn ($v) => $typeMatches($v['types']));
                                        $isComRow     = ($ext === 'main-site');

                                        // Show the last zip/server this host was deployed with as info text
                                        // (NOT pre-selected — so re-opening never re-uploads by accident).
                                        $rowHostKey   = preg_replace('/^www\./', '', normalizeUrl($row['host']));
                                        $prev         = $deployZips[$l['id'] . '|' . $rowHostKey] ?? null;
                                        $prevSrv      = $siteServers[$rowHostKey] ?? null;
                                        $isChangedRow = ($key === 'meta' && !empty($l['needs_redeploy']));
                                    @endphp
                                <tr>
                                    <td style="color:{{ $key === 'meta' ? '#c4b5fd' : '#7dd3fc' }}">
                                        {{ $row['host'] }}
                                        @if ($row['label'])<small style="color:var(--text-muted)">({{ $row['label'] }})</small>@endif
                                        @if ($isChangedRow)<small style="color:#fbbf24"><i class="bi bi-arrow-left"></i> changed — pick a zip</small>@endif
                                        <input type="hidden" name="rows[{{ $key }}][host]" value="{{ $row['host'] }}"
                                               class="deploy-row-host" data-current-server="{{ $prevSrv['server_id'] ?? 0 }}"
                                               data-current-server-name="{{ $prevSrv['server_name'] ?? '' }}">
                                    </td>
                                    <td>
                                        <select name="rows[{{ $key }}][zip_id]" class="form-select form-select-sm"
                                                style="{{ $isChangedRow ? 'border-color:#a16207' : '' }}">
                                            <option value="">— Select zip —</option>
                                            @forelse ($matchingZips as $z)
                                            <option value="{{ $z['id'] }}">{{ $z['name'] }} ({{ $z['types'] }})</option>
                                            @empty
                                            @if (empty($matchingVersions))<option value="" disabled>No {{ $ext }} zip available</option>@endif
                                            @endforelse
                                            @foreach ($matchingVersions as $v)
                                            <option class="deploy-older-opt" value="v{{ $v['id'] }}" hidden disabled>↺ {{ $v['zip_name'] }} · {{ $v['name'] }} (older)</option>
                                            @endforeach
                                        </select>
                                        @if ($prev || $prevSrv)
                                        <div style="font-size:10px;color:var(--text-muted);margin-top:3px">
                                            <i class="bi bi-clock-history"></i> Last:
                                            <span style="color:#c4b5fd">{{ $prev['zip_name'] ?? '—' }}</span>@if ($prevSrv) on <span style="color:#7dd3fc">{{ $prevSrv['ssh_host'] ?? '—' }}</span>@endif
                                        </div>
                                        @else
                                        <div style="font-size:10px;color:#fbbf24;margin-top:3px">
                                            <i class="bi bi-hourglass-split"></i> Deployment pending
                                        </div>
                                        @endif
                                    </td>
                                    <td style="font-size:11px;white-space:nowrap;color:{{ $isComRow ? '#7dd3fc' : '#c4b5fd' }}">
                                        @if ($isComRow)
                                            Main site <small style="color:var(--text-muted)">(AD_UNIT: <code style="color:#7dd3fc">{{ resolveAdUnit($row['host']) ?: '—' }}</code>)</small>
                                        @else
                                            Sub-site <small style="color:var(--text-muted)">(MAIN_URL: <code style="color:#c4b5fd">{{ $modalMainUrl ?: '—' }}</code>)</small>
                                        @endif
                                    </td>
                                </tr>
                                @endforeach
                                </tbody>
                            </table>
                            <div style="margin-top:8px;font-size:11px;color:var(--text-muted)">
                                <i class="bi bi-info-circle"></i> Only rows where you pick a zip are deployed — leave a row blank to skip it.
                            </div>
                            @if (empty($zips))
                            <div style="margin-top:10px;text-align:center;color:var(--text-muted);font-size:12px">
                                No active zips. <a href="{{ url('/zip-masters') }}" style="color:#a78bfa">Upload or activate one →</a>
                            </div>
                            @endif
                        </div>
                        <div class="modal-footer" style="border-color:#2e2e5a">
                            <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" id="deployBtn-{{ $l['id'] }}" class="btn-primary-custom" disabled>
                                <i class="bi bi-check-circle"></i> Deploy
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        @endforeach
    </div>
</div>

<!-- Help Box -->
<details class="data-card" style="padding:14px 18px;color:var(--text-muted);font-size:12px">
    <summary style="cursor:pointer;color:#a78bfa"><i class="bi bi-info-circle"></i> How it works</summary>
    <div style="line-height:1.8;margin-top:8px">
        • <strong>Domain</strong> (managed on the <a href="{{ url('/domains') }}" style="color:#a78bfa">Domains</a> page) groups subdomains and carries the <strong>Approved</strong> status + ADX.<br>
        • <strong>Campaigns</strong> — exact Meta CSV names; spend is summed across all of them (matched by name).<br>
        • <strong>GAM URL(s)</strong> — exact GAM site hosts; revenue is summed across all of them.<br>
        • P/L = <code style="background:#1a1a42;padding:0 4px;border-radius:3px">GAM Revenue (USD × rate) − Meta Spend − GST</code>
    </div>
</details>

<!-- Add Subdomain Modal -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add Subdomain</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ $lpUrl }}" id="addForm">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        {!! ownerSelectHTML() !!}
                        <div class="col-md-6">
                            <label class="form-label">Main Domain <small style="color:#7dd3fc;font-weight:400">(optional — auto-detected from GAM URL)</small></label>
                            @include('partials.domain_combo', ['domains' => $domains, 'selected' => $addDomainId, 'wrapId' => 'addDomainCombo'])
                        </div>
                        <div class="col-md-6 js-adx-wrap">
                            <label class="form-label">ADX Network <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— replaces [ADX] on .com sites</small></label>
                            <select name="adx_id" class="form-select js-adx-select">
                                <option value="">— None —</option>
                                @foreach ($adxOptions as $ax)
                                <option value="{{ $ax['id'] }}">{{ $ax['name'] }}</option>
                                @endforeach
                            </select>
                            <div class="js-adx-inherited" style="display:none;font-size:12px;color:#4ade80;margin-top:6px">
                                <i class="bi bi-check-circle"></i> Using the domain's ADX: <strong class="js-adx-name"></strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign Customer <small style="color:#7dd3fc;font-weight:400">(optional)</small></label>
                            <select name="customer_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach ($customerOptions as $cu)
                                <option value="{{ $cu['id'] }}" >{{ $cu['name'] }} ({{ $cu['login_id'] }}){{ $cu['active'] ? '' : ' — inactive' }}</option>
                                @endforeach
                            </select>
                        </div>
                        @include('partials.new_domain_box', ['prefix' => 'add', 'adxOptions' => $adxOptions])
                        @if (showMeta())
                        <div class="col-12">
                            <label class="form-label">
                                Meta Campaign(s) *
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— type a name and press <kbd>Enter</kbd> or <kbd>,</kbd> to add. Multiple allowed.</small>
                            </label>
                            <input type="hidden" name="meta_campaign" id="addCampaignValue">
                            <div class="tag-input-wrap" id="addTagWrap">
                                <input type="text" class="tag-text-input" id="addTagInput" placeholder="e.g. ewire1 — press Enter or comma to add">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Meta URL *</label>
                            <input type="text" name="meta_url" class="form-control" placeholder="ewire1.blogonbudget.xyz" required>
                        </div>
                        @endif
                        <div class="{{ showMeta() ? 'col-md-6' : 'col-12' }}">
                            <label class="form-label">
                                GAM URL / Site Host(s) *
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— exact match from GAM CSV; press <kbd>Enter</kbd> or <kbd>,</kbd> to add multiple</small>
                            </label>
                            <input type="hidden" name="gam_url" id="addGamValue">
                            <div class="tag-input-wrap" id="addGamWrap">
                                <input type="text" class="tag-text-input" id="addGamInput" placeholder="e.g. ew1.erpnewswire.com">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" placeholder="Optional notes">
                        </div>
                        <div class="col-12">
                            <label class="form-label">
                                <i class="bi bi-hdd-network"></i> Create DNS records now
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— optional: adds a name.com A record for {{ showMeta() ? 'the Meta URL and each GAM host' : 'each GAM host' }}, pointing at the chosen server, so DNS propagates before you deploy</small>
                            </label>
                            @if (empty($servers))
                                <div style="font-size:12px;color:#fbbf24">
                                    No active servers. <a href="{{ url('/server-masters') }}" style="color:#a78bfa">Add one →</a>
                                </div>
                            @else
                                <select name="dns_server_id" class="form-select">
                                    <option value="">— Skip DNS (create records at deploy time) —</option>
                                    @foreach ($servers as $sv)
                                    <option value="{{ $sv['id'] }}">{{ $sv['name'] }} ({{ $sv['ssh_host'] }})</option>
                                    @endforeach
                                </select>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Subdomain</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Subdomain Modal -->
@if ($editLink)
@php
    $editCampaigns = array_filter(array_map('trim', explode(',', $editLink['meta_campaign'])));
    $editGamSites  = array_filter(array_map('trim', explode(',', $editLink['gam_url'])));
@endphp
<div class="modal fade show" id="editModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Subdomain</h5>
                <a href="{{ $lpUrl }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ $lpUrl }}" id="editForm">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editLink['id'] }}">
                <div class="modal-body">
                    <div class="row g-3">
                        {!! ownerSelectHTML((int)($editLink['user_id'] ?? 0)) !!}
                        <div class="col-md-6">
                            <label class="form-label">Main Domain <small style="color:#7dd3fc;font-weight:400">(optional — auto-detected from GAM URL)</small></label>
                            @include('partials.domain_combo', ['domains' => $domains, 'selected' => (int)($editLink['domain_id'] ?? 0), 'wrapId' => 'editDomainCombo'])
                        </div>
                        <div class="col-md-6 js-adx-wrap">
                            <label class="form-label">ADX Network <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— replaces [ADX] on .com sites</small></label>
                            @php $curAdx = (int) ($editLink['adx_id'] ?? 0); @endphp
                            <select name="adx_id" class="form-select js-adx-select">
                                <option value="">— None —</option>
                                @foreach ($adxOptions as $ax)
                                <option value="{{ $ax['id'] }}" {{ $curAdx === (int) $ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
                                @endforeach
                            </select>
                            <div class="js-adx-inherited" style="display:none;font-size:12px;color:#4ade80;margin-top:6px">
                                <i class="bi bi-check-circle"></i> Using the domain's ADX: <strong class="js-adx-name"></strong>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign Customer <small style="color:#7dd3fc;font-weight:400">(optional)</small></label>
                            <select name="customer_id" class="form-select">
                                <option value="">— None —</option>
                                @foreach ($customerOptions as $cu)
                                <option value="{{ $cu['id'] }}" {{ (int) ($editLink['customer_id'] ?? 0) === (int) $cu['id'] ? 'selected' : '' }}>{{ $cu['name'] }} ({{ $cu['login_id'] }}){{ $cu['active'] ? '' : ' — inactive' }}</option>
                                @endforeach
                            </select>
                        </div>
                        @include('partials.new_domain_box', ['prefix' => 'edit', 'adxOptions' => $adxOptions])
                        @if (showMeta())
                        <div class="col-12">
                            <label class="form-label">
                                Meta Campaign(s) *
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— type a name and press <kbd>Enter</kbd> or <kbd>,</kbd> to add. Multiple allowed.</small>
                            </label>
                            <input type="hidden" name="meta_campaign" id="editCampaignValue" value="{{ implode(',', $editCampaigns) }}">
                            <div class="tag-input-wrap" id="editTagWrap">
                                @foreach ($editCampaigns as $cp)
                                <span class="tag-pill">{{ $cp }}<button type="button" class="tag-remove" data-value="{{ $cp }}">×</button></span>
                                @endforeach
                                <input type="text" class="tag-text-input" id="editTagInput" placeholder="Add another campaign…">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Meta URL *</label>
                            <input type="text" name="meta_url" class="form-control" value="{{ $editLink['meta_url'] }}" required>
                        </div>
                        @endif
                        <div class="{{ showMeta() ? 'col-md-6' : 'col-12' }}">
                            <label class="form-label">
                                GAM URL / Site Host(s) *
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— press <kbd>Enter</kbd> or <kbd>,</kbd> to add multiple</small>
                            </label>
                            <input type="hidden" name="gam_url" id="editGamValue" value="{{ implode(',', $editGamSites) }}">
                            <div class="tag-input-wrap" id="editGamWrap">
                                @foreach ($editGamSites as $gs)
                                <span class="tag-pill">{{ $gs }}<button type="button" class="tag-remove" data-value="{{ $gs }}">×</button></span>
                                @endforeach
                                <input type="text" class="tag-text-input" id="editGamInput" placeholder="Add another GAM site…">
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" value="{{ $editLink['notes'] ?? '' }}">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ $lpUrl }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
// ── Auto-detect the main domain for a subdomain from its GAM URL ──
document.addEventListener('DOMContentLoaded', () => {
    const domains = @json(array_map(fn ($d) => ['id' => (int) $d['id'], 'name' => strtolower($d['name'])], $domains));

    // Registrable ("root") domain of a host — mirrors registrableDomain() in PHP.
    function registrable(host) {
        host = (host || '').toLowerCase().trim()
                 .replace(/^https?:\/\//, '').split('/')[0].replace(/^www\./, '');
        if (!host) return '';
        const p = host.split('.');
        if (p.length <= 2) return host;
        const two = ['co', 'com', 'net', 'org', 'gov', 'edu', 'ac'];
        if (two.includes(p[p.length - 2]) && p[p.length - 1].length <= 3) return p.slice(-3).join('.');
        return p.slice(-2).join('.');
    }
    // Existing main domain this host belongs to (host == name or *.name), longest wins.
    function matchDomain(host) {
        let best = null, len = -1;
        for (const d of domains) {
            if (host === d.name || host.endsWith('.' + d.name)) {
                if (d.name.length > len) { best = d; len = d.name.length; }
            }
        }
        return best;
    }
    function firstHost(gamValue) {
        const raw = (gamValue || '').split(',')[0] || '';
        return raw.toLowerCase().trim().replace(/^https?:\/\//, '').split('/')[0];
    }

    function wire(prefix) {
        const gam   = document.getElementById(prefix + 'GamValue');
        const box   = document.getElementById(prefix + 'NewDomainBox');
        const combo = document.getElementById(prefix + 'DomainCombo');
        if (!gam || !box || !combo) return;
        const comboHidden = combo.querySelector('.ss-value');
        const nameEl      = box.querySelector('.js-newdomain-name');

        function update() {
            const host = firstHost(gam.value);
            // User already picked a domain, or no GAM host yet → nothing to prompt.
            if ((comboHidden && comboHidden.value) || !host) { box.style.display = 'none'; return; }

            const existing = matchDomain(host);
            if (existing) {
                // Auto-associate with the domain it belongs to.
                if (window.setSearchableSelect) window.setSearchableSelect(combo, existing.id);
                box.style.display = 'none';
            } else {
                if (nameEl) nameEl.textContent = registrable(host);
                box.style.display = 'block';
            }
        }

        gam.addEventListener('change', update);
        if (comboHidden) comboHidden.addEventListener('change', update);
        update();
    }
    wire('add');
    wire('edit');
});
</script>
@endpush

@push('scripts')
<script>
// ── Deploy modal: enable the button only once every row has a zip picked,
//    then show a spinner while the (synchronous) deploy runs on the server ──
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[id^="uploadZipModal"]').forEach(modal => {
        const id  = modal.id.replace('uploadZipModal', '');
        const btn = document.getElementById('deployBtn-' + id);
        if (!btn) return;

        const serverSel = modal.querySelector('select[name="server_id"]');
        function check() {
            // Deploy only the rows the user actually picks a zip for. Enable the
            // button as soon as ANY row has a zip selected AND a server is chosen.
            const selects = [...modal.querySelectorAll('select[name^="rows["]')];
            const anyPicked = selects.some(s => s.value !== '');
            const serverOk  = !serverSel || serverSel.value !== '';
            btn.disabled = !anyPicked || !serverOk;
        }
        // A host recorded on another server is skipped unless the move box is used —
        // show that box only for the rows actually picked, against the chosen server.
        const moveBox    = modal.querySelector('.deploy-move-box');
        const moveToggle = modal.querySelector('.deploy-move-toggle');
        const moveMode   = modal.querySelector('.deploy-move-mode');
        const moveHint   = modal.querySelector('.deploy-move-hint');
        function checkMove() {
            if (!moveBox) return;
            const target = serverSel ? serverSel.value : '';
            const stale = [...modal.querySelectorAll('select[name^="rows["]')].filter(sel => {
                if (sel.value === '') return false;                  // row not being deployed
                const row  = sel.closest('tr');
                const host = row && row.querySelector('.deploy-row-host');
                const on   = host ? host.dataset.currentServer : '0';
                return on !== '0' && on !== '' && target !== '' && on !== target;
            }).map(sel => {
                const host = sel.closest('tr').querySelector('.deploy-row-host');
                return host.value + " (on '" + (host.dataset.currentServerName || 'another server') + "')";
            });

            moveBox.style.display = stale.length ? '' : 'none';
            if (stale.length) {
                moveHint.textContent = stale.join(', ') + (stale.length > 1 ? ' are' : ' is') + ' deployed on another server.';
            } else if (moveToggle) {
                moveToggle.checked = false;                          // never submit a stale tick
                if (moveMode) moveMode.disabled = true;
            }
        }
        if (moveToggle) moveToggle.addEventListener('change', () => { if (moveMode) moveMode.disabled = !moveToggle.checked; });

        modal.querySelectorAll('select[name^="rows["]').forEach(s => s.addEventListener('change', () => { check(); checkMove(); }));
        if (serverSel) serverSel.addEventListener('change', () => { check(); checkMove(); });
        modal.addEventListener('shown.bs.modal', () => { check(); checkMove(); });

        // "Show older versions" — reveal the archived-version options in this modal's selects.
        const olderToggle = modal.querySelector('.deploy-older-toggle');
        if (olderToggle) {
            olderToggle.addEventListener('change', () => {
                modal.querySelectorAll('option.deploy-older-opt').forEach(opt => {
                    opt.hidden = !olderToggle.checked;
                    opt.disabled = !olderToggle.checked;
                    // If hiding the currently-selected older version, reset that select.
                    if (!olderToggle.checked && opt.selected) { opt.parentElement.value = ''; check(); }
                });
            });
        }

        btn.closest('form').addEventListener('submit', () => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Deploying… please wait';

            // Lock the modal until the deploy finishes (page reloads on completion):
            // no backdrop-click / ESC close, and disable the X and Cancel buttons.
            const inst = window.bootstrap && bootstrap.Modal.getOrCreateInstance(modal);
            if (inst) {
                inst._config.backdrop = 'static';
                inst._config.keyboard = false;
            }
            modal.querySelectorAll('[data-bs-dismiss="modal"]').forEach(el => {
                el.disabled = true;
                el.style.pointerEvents = 'none';
                el.style.opacity = '0.5';
            });
        });
    });
});
</script>
@endpush

@if ($openAdd)
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('addModal');
    if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
});
</script>
@endpush
@endif

@if (!empty($openDeploy))
@push('scripts')
<script>
// Straight from "Add Subdomain" into that subdomain's Deploy modal. The modal only
// exists if the new row survived the active filter — if it didn't, do nothing.
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('uploadZipModal{{ (int) $openDeploy }}');
    if (el && window.bootstrap) bootstrap.Modal.getOrCreateInstance(el).show();
});
</script>
@endpush
@endif

@push('scripts')
<script>
// ── Bulk select → pause / delete selected subdomains ──
document.addEventListener('DOMContentLoaded', () => {
    const selAll  = document.getElementById('lpSelAll');
    const bar     = document.getElementById('lpBulkBar');
    const countEl = document.getElementById('lpSelCount');
    const boxes   = () => [...document.querySelectorAll('.lp-sel')];

    function refresh() {
        const checked = boxes().filter(b => b.checked);
        if (countEl) countEl.textContent = checked.length;
        if (bar) bar.style.display = checked.length ? 'flex' : 'none';
        if (selAll) {
            const all = boxes();
            selAll.checked = all.length > 0 && checked.length === all.length;
            selAll.indeterminate = checked.length > 0 && checked.length < all.length;
        }
    }
    if (selAll) selAll.addEventListener('change', () => { boxes().forEach(b => b.checked = selAll.checked); refresh(); });
    boxes().forEach(b => b.addEventListener('change', refresh));

    function submitBulk(action, msg) {
        const ids = boxes().filter(b => b.checked).map(b => b.value);
        if (!ids.length) return;
        if (!confirm(msg.replace('%d', ids.length))) return;
        document.getElementById('lpBulkAction').value = action;
        const holder = document.getElementById('lpBulkIds');
        holder.innerHTML = '';
        ids.forEach(id => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = 'ids[]'; i.value = id;
            holder.appendChild(i);
        });
        document.getElementById('lpBulkForm').submit();
    }
    const pauseBtn = document.getElementById('lpBulkPause');
    const delBtn   = document.getElementById('lpBulkDelete');
    if (pauseBtn) pauseBtn.addEventListener('click', () => submitBulk('bulk_pause', 'Pause %d selected subdomain(s)? Their aaPanel sites will be stopped.'));
    if (delBtn)   delBtn.addEventListener('click',   () => submitBulk('bulk_delete', 'Delete %d selected subdomain(s)? This cannot be undone.'));
    refresh();
});
</script>
@endpush

@php
    // domain id → its ADX (id + name), so a subdomain can inherit it in the UI.
    $adxNameById = [];
    foreach ($adxOptions as $ax) $adxNameById[(int) $ax['id']] = $ax['name'];
    $domainAdxMap = [];
    foreach ($domains as $d) {
        $aid = (int) ($d['adx_id'] ?? 0);
        if ($aid > 0) $domainAdxMap[(string) $d['id']] = ['id' => $aid, 'name' => $adxNameById[$aid] ?? ('ADX #' . $aid)];
    }
@endphp
@push('scripts')
<script>
// ── ADX picker: only shown when the selected domain has no ADX of its own ──
document.addEventListener('DOMContentLoaded', () => {
    const DOMAIN_ADX = @json($domainAdxMap);

    document.querySelectorAll('#addForm, #editForm').forEach(form => {
        const combo   = form.querySelector('.ss-value');       // hidden domain_id
        const wrap    = form.querySelector('.js-adx-wrap');
        const select  = form.querySelector('.js-adx-select');
        const note     = form.querySelector('.js-adx-inherited');
        const noteName = note ? note.querySelector('.js-adx-name') : null;
        const newBox  = form.querySelector('.js-newdomain-box');
        if (!combo || !wrap) return;

        function update() {
            const newVisible = newBox && getComputedStyle(newBox).display !== 'none';
            const dom = DOMAIN_ADX[combo.value || ''];
            if (newVisible) {
                // A new domain will be created — its ADX comes from the box above.
                wrap.style.display = 'none';
                if (select) select.disabled = true;
            } else if (dom) {
                // Existing domain already has an ADX → subdomain inherits it.
                wrap.style.display = '';
                if (select) { select.style.display = 'none'; select.disabled = true; }
                if (note) note.style.display = '';
                if (noteName) noteName.textContent = dom.name;
            } else {
                // Ungrouped, or a domain without an ADX → let the user pick one.
                wrap.style.display = '';
                if (select) { select.style.display = ''; select.disabled = false; }
                if (note) note.style.display = 'none';
            }
        }

        combo.addEventListener('change', update);
        // React when the "new domain" box is toggled by the domain-detect logic.
        if (newBox) new MutationObserver(update).observe(newBox, { attributes: true, attributeFilter: ['style'] });
        update();
    });
});
</script>
@endpush

@endsection