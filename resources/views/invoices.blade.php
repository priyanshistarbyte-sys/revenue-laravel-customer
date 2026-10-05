@extends('layouts.app')

@section('content')

@include('partials.flash')

<!-- Page Header -->
<div class="dash-header" style="margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
        <div class="dash-title">INVOICES</div>
        <div class="dash-subtitle">Upload, manage and download date-wise invoices by month</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <form method="get" action="{{ url('/invoices') }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <div class="filter-input-wrap">
                <i class="bi bi-search fi-icon"></i>
                <input type="text" name="title" class="form-control" placeholder="Search title / filename…" value="{{ $filterTitle }}" style="width:190px">
            </div>
            <div class="filter-input-wrap">
                <i class="bi bi-person-badge fi-icon"></i>
                <select name="account" class="form-select" style="width:210px">
                    <option value="">All Accounts</option>
                    @foreach ($accountOptions as $opt)
                    <option value="{{ $opt }}" {{ $filterAccount === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                    @endforeach
                </select>
            </div>
            <div class="filter-input-wrap">
                <i class="bi bi-calendar3 fi-icon"></i>
                <input type="month" name="month" class="form-control" value="{{ $filterMonth }}" style="width:160px">
            </div>
            <button type="submit" class="btn-primary-custom"><i class="bi bi-funnel-fill"></i> Filter</button>
            @if ($filterMonth || $filterTitle || $filterAccount)
            <a href="{{ url('/invoices') }}" class="btn-sm-custom" style="padding:7px 10px"><i class="bi bi-x-circle"></i> Clear</a>
            @endif
        </form>
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#uploadModal">
            <i class="bi bi-cloud-upload"></i> Upload Invoice
        </button>
    </div>
</div>

<!-- KPI Row -->
<div class="kpi-row" style="margin-bottom:20px">
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-file-earmark-text"></i> Total Invoices</div>
        <div class="kpi-value white">{{ $totalCount }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-hdd"></i> Total Size</div>
        <div class="kpi-value gold">{{ fmtBytes($totalBytes) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-calendar3"></i> Months Covered</div>
        <div class="kpi-value purple">{{ count($months) }}</div>
    </div>
    @if ($filterMonth)
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-download"></i> Download Month</div>
        <a href="{{ url('/invoices/download') }}?month={{ urlencode($filterMonth) }}{{ $filterAccount ? '&account='.urlencode($filterAccount) : '' }}"
           class="btn-primary-custom" style="margin-top:6px;display:inline-flex;align-items:center;gap:6px">
            <i class="bi bi-file-zip"></i> {{ $filterMonth }} ZIP
        </a>
    </div>
    @endif
</div>

<div style="display:grid;grid-template-columns:220px 1fr;gap:16px;align-items:start">

    <!-- Monthly Sidebar -->
    <div class="data-card" style="padding:0;overflow:hidden">
        <div class="data-card-header" style="border-radius:10px 10px 0 0">
            <i class="bi bi-calendar2-month"></i> By Month
        </div>
        <div style="max-height:500px;overflow-y:auto">
            @if (empty($months))
            <div style="padding:16px;color:var(--text-muted);font-size:12px;text-align:center">No invoices yet</div>
            @endif
            @foreach ($months as $m)
                @php
                    $isActive  = $filterMonth === $m['ym'];
                    $monthHref = '?month=' . urlencode($m['ym']);
                    if ($filterAccount) $monthHref .= '&account=' . urlencode($filterAccount);
                    if ($filterTitle)   $monthHref .= '&title=' . urlencode($filterTitle);
                @endphp
            <div style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-bottom:1px solid var(--border);background:{{ $isActive ? 'var(--navy-mid)' : 'transparent' }};transition:background .15s">
                <div>
                    <a href="{{ $monthHref }}" style="color:{{ $isActive ? '#fff' : 'var(--text-muted)' }};text-decoration:none;font-size:12px;font-weight:{{ $isActive ? '600' : '400' }}">
                        <i class="bi bi-folder2-open me-1" style="color:var(--gold)"></i>
                        {{ $m['label'] }}
                    </a>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:2px">
                        {{ $m['cnt'] }} file{{ $m['cnt']!=1?'s':'' }} · {{ fmtBytes((int)$m['total_bytes']) }}
                    </div>
                </div>
                <a href="{{ url('/invoices/download') }}?month={{ urlencode($m['ym']) }}{{ $filterAccount ? '&account='.urlencode($filterAccount) : '' }}"
                   title="Download {{ $m['label'] }} as ZIP"
                   style="color:var(--text-muted);font-size:15px;padding:4px 6px;border-radius:5px;border:1px solid var(--border);text-decoration:none;transition:all .15s"
                   onmouseover="this.style.color='#fff';this.style.background='var(--purple)'"
                   onmouseout="this.style.color='var(--text-muted)';this.style.background='transparent'">
                    <i class="bi bi-file-zip"></i>
                </a>
            </div>
            @endforeach
        </div>
    </div>

    <!-- Invoice Table -->
    <div class="data-card sticky-card">
        <div class="data-card-header">
            <i class="bi bi-receipt"></i> Invoice List
            @if ($filterMonth || $filterTitle)<span style="margin-left:8px;font-size:11px;color:#fbbf24">(filtered)</span>@endif
            <span style="margin-left:auto;display:flex;align-items:center;gap:10px">
                <span style="font-size:11px;color:var(--text-muted);font-weight:400">{{ $totalCount }} invoice{{ $totalCount!=1?'s':'' }}</span>
                @if (!empty($invoices))
                <label style="display:flex;align-items:center;gap:5px;font-size:11px;color:var(--text-muted);cursor:pointer;font-weight:400">
                    <input type="checkbox" id="chkAll" style="accent-color:#a78bfa;width:14px;height:14px"> Select all
                </label>
                @endif
            </span>
        </div>

        <div id="bulkBar" style="display:none;padding:8px 16px;background:rgba(109,63,197,.15);border-bottom:1px solid var(--border);align-items:center;gap:10px;flex-wrap:wrap">
            <span id="selCount" style="font-size:12px;color:#a78bfa;font-weight:600"></span>
            <span style="color:var(--text-muted);font-size:11px">selected</span>
            <div style="margin-left:auto;display:flex;gap:8px">
                <button type="button" id="btnBulkDeselect" class="btn-sm-custom" style="padding:5px 10px;font-size:11px"><i class="bi bi-x"></i> Deselect</button>
                <a id="btnBulkZip" href="#" class="btn-sm-custom" style="padding:5px 12px;font-size:11px;text-decoration:none;background:rgba(109,63,197,.25);border-color:rgba(109,63,197,.5)"><i class="bi bi-file-zip"></i> Download ZIP</a>
                <button type="button" id="btnBulkDelete" class="btn-danger-custom" style="padding:5px 12px;font-size:11px"><i class="bi bi-trash3"></i> Delete Selected</button>
            </div>
        </div>

        <form method="post" action="{{ url('/invoices') }}" id="multiDeleteForm" style="display:none">
            <input type="hidden" name="action" value="multi_delete">
            <div id="multiDeleteIds"></div>
        </form>

        <div class="table-wrap">
            <table class="ledger" id="invTable">
                <thead>
                    <tr>
                        <th style="width:30px;text-align:center"></th>
                        <th style="width:110px">Date <span class="sort-btn">⇅</span></th>
                        <th style="text-align:left">Account <span class="sort-btn">⇅</span></th>
                        <th style="text-align:left">Title / File <span class="sort-btn">⇅</span></th>
                        <th>Type</th>
                        <th>Size <span class="sort-btn">⇅</span></th>
                        <th style="text-align:left">Notes</th>
                        <th style="text-align:center;width:130px">Actions</th>
                    </tr>
                </thead>
                <tbody>
                @if (empty($invoices))
                <tr><td colspan="8" style="text-align:center;color:var(--text-muted);padding:40px">
                    No invoices found.
                    <a href="#" data-bs-toggle="modal" data-bs-target="#uploadModal" style="color:#a78bfa">Upload first invoice →</a>
                </td></tr>
                @endif

                @foreach ($grouped as $date => $dateInvs)
                    @php $dateLabel = date('d-M-Y', strtotime($date)); $dateCount = count($dateInvs); @endphp
                <tr class="date-group-row" data-date="{{ $date }}" style="background:var(--navy-mid)">
                    <td style="text-align:center">
                        <input type="checkbox" class="chk-date" data-date="{{ $date }}" style="accent-color:#fbbf24;width:14px;height:14px" title="Select all for {{ $dateLabel }}">
                    </td>
                    <td colspan="5" style="text-align:left;padding:7px 10px">
                        <span style="font-weight:700;color:#fbbf24;font-size:12px"><i class="bi bi-calendar-date me-1"></i>{{ $dateLabel }}</span>
                        <span style="color:var(--text-muted);font-size:11px;margin-left:8px">{{ $dateCount }} file{{ $dateCount!=1?'s':'' }}</span>
                    </td>
                    <td></td>
                    <td style="text-align:center">
                        <form method="post" action="{{ url('/invoices') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete_date">
                            <input type="hidden" name="del_date" value="{{ $date }}">
                            <button class="btn-danger-custom" style="padding:3px 8px;font-size:11px"
                                    data-confirm="Delete all {{ $dateCount }} invoice{{ $dateCount!=1?'s':'' }} for {{ $dateLabel }}? Files will be permanently removed.">
                                <i class="bi bi-trash3"></i> All
                            </button>
                        </form>
                    </td>
                </tr>

                @foreach ($dateInvs as $inv)
                    @php
                        $icon = iconForMime($inv['mime_type']);
                        $ext  = strtoupper(pathinfo($inv['original_name'], PATHINFO_EXTENSION));
                        $iconColor = str_contains($icon,'pdf') ? '#ef4444'
                                   : (str_contains($icon,'image') ? '#3b82f6'
                                   : (str_contains($icon,'word')  ? '#2563eb'
                                   : (str_contains($icon,'excel') ? '#16a34a' : '#8888aa')));
                    @endphp
                <tr class="inv-row" data-date="{{ $inv['date'] }}" data-id="{{ $inv['id'] }}">
                    <td style="text-align:center">
                        <input type="checkbox" class="chk-row" data-id="{{ $inv['id'] }}" style="accent-color:#a78bfa;width:14px;height:14px">
                    </td>
                    <td style="white-space:nowrap;font-size:11px;color:var(--text-muted)">—</td>
                    <td style="text-align:left;max-width:160px">
                        @if (!empty($inv['account']))
                        <span style="font-size:10px;color:#a78bfa;background:rgba(109,63,197,.15);border:1px solid rgba(109,63,197,.35);border-radius:4px;padding:2px 6px;display:inline-block;max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="{{ $inv['account'] }}">{{ $inv['account'] }}</span>
                        @else
                        <span style="font-size:10px;color:var(--text-muted)">—</span>
                        @endif
                    </td>
                    <td style="text-align:left">
                        <div style="font-weight:600;color:#c4b5fd;font-size:13px">
                            <i class="bi {{ $icon }}" style="color:{{ $iconColor }}"></i>
                            {{ $inv['title'] ?: pathinfo($inv['original_name'], PATHINFO_FILENAME) }}
                        </div>
                        <div style="font-size:10px;color:var(--text-muted);margin-top:2px">{{ $inv['original_name'] }}</div>
                    </td>
                    <td>
                        <span style="background:var(--navy-mid);border:1px solid var(--border);border-radius:4px;padding:2px 6px;font-size:10px;color:var(--text-muted)">{{ $ext }}</span>
                    </td>
                    <td style="font-size:11px;color:var(--text-muted)">{{ fmtBytes((int)$inv['file_size']) }}</td>
                    <td style="text-align:left;font-size:11px;color:var(--text-muted)">{{ $inv['notes'] ?? '' }}</td>
                    <td style="text-align:center">
                        <div style="display:flex;gap:4px;justify-content:center;flex-wrap:wrap">
                            <a href="{{ url('/invoices/download') }}?id={{ $inv['id'] }}" class="btn-sm-custom" title="Download" style="padding:5px 8px"><i class="bi bi-download"></i></a>
                            @if ($inv['mime_type'] === 'application/pdf' || str_starts_with($inv['mime_type'], 'image'))
                            <a href="{{ url('/invoices/preview') }}?id={{ $inv['id'] }}" target="_blank" class="btn-sm-custom" title="Preview" style="padding:5px 8px"><i class="bi bi-eye"></i></a>
                            @endif
                            <a href="{{ url('/invoices') }}?edit={{ $inv['id'] }}{{ $filterMonth ? '&month='.$filterMonth : '' }}" class="btn-sm-custom" title="Edit" style="padding:5px 8px"><i class="bi bi-pencil"></i></a>
                            <form method="post" action="{{ url('/invoices') }}" style="display:inline">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="{{ $inv['id'] }}">
                                <button class="btn-danger-custom" style="padding:5px 8px" data-confirm="Delete this invoice? The file will be permanently removed."><i class="bi bi-trash3"></i></button>
                            </form>
                        </div>
                    </td>
                </tr>
                @endforeach
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Upload Modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-cloud-upload-fill" style="color:#a78bfa"></i> Upload Invoice</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/invoices') }}" enctype="multipart/form-data">
                <input type="hidden" name="action" value="upload">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Date *</label>
                            <input type="date" name="date" class="form-control" value="{{ date('Y-m-d') }}" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Account <small style="color:var(--text-muted);font-weight:400">(applied to all files)</small></label>
                            <select name="account" class="form-select">
                                <option value="">— Select Account —</option>
                                @foreach ($accountOptions as $opt)
                                <option value="{{ $opt }}">{{ $opt }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Notes <small style="color:var(--text-muted);font-weight:400">(optional)</small></label>
                            <input type="text" name="notes" class="form-control" placeholder="e.g. Meta Invoice Oct 2025">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Invoice Files * <small style="color:var(--text-muted);font-weight:400">— select or drop multiple files</small></label>
                            <div id="dropZoneInv" style="border:2px dashed var(--border);border-radius:10px;padding:28px 32px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:rgba(13,13,34,.6)">
                                <i class="bi bi-cloud-upload-fill" style="font-size:2.4rem;color:#a78bfa"></i>
                                <div style="color:#c4b5fd;margin-top:10px;font-size:13px;font-weight:500">
                                    Drag &amp; drop files here, or
                                    <span style="color:#fbbf24;text-decoration:underline;cursor:pointer" onclick="document.getElementById('invFileInput').click()">browse</span>
                                </div>
                                <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
                                    PDF, JPG, PNG, WEBP, XLS, XLSX, DOC, DOCX — max {{ $maxSizeMb }} MB each
                                </div>
                            </div>
                            <input type="file" id="invFileInput" name="invoice_file[]" accept=".pdf,.jpg,.jpeg,.png,.webp,.xls,.xlsx,.doc,.docx" multiple required style="display:none">
                            <div id="fileList" style="margin-top:10px;display:none">
                                <div style="font-size:11px;color:var(--text-muted);margin-bottom:6px">Selected files: <span id="fileCount" style="color:#a78bfa;font-weight:600"></span></div>
                                <div id="fileItems" style="display:flex;flex-direction:column;gap:4px;max-height:180px;overflow-y:auto"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom" id="uploadBtn"><i class="bi bi-upload"></i> Upload</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
@if ($editInv)
<div class="modal fade show" id="editInvModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil-fill" style="color:#a78bfa"></i> Edit Invoice</h5>
                <a href="{{ url('/invoices') }}{{ $filterMonth ? '?month='.$filterMonth : '' }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/invoices') }}">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editInv['id'] }}">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Date *</label>
                            <input type="date" name="date" class="form-control" value="{{ $editInv['date'] }}" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Title</label>
                            <input type="text" name="title" class="form-control" value="{{ $editInv['title'] }}" placeholder="Invoice title">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Current File</label>
                            <div style="background:var(--header-bg);border:1px solid var(--border);border-radius:6px;padding:10px 14px;display:flex;align-items:center;gap:10px">
                                <i class="bi {{ iconForMime($editInv['mime_type']) }}" style="font-size:1.4rem;color:var(--purple)"></i>
                                <div>
                                    <div style="font-weight:600;color:#c4b5fd">{{ $editInv['original_name'] }}</div>
                                    <div style="font-size:11px;color:var(--text-muted)">{{ fmtBytes((int)$editInv['file_size']) }} · {{ date('d-M-Y', strtotime($editInv['date'])) }}</div>
                                </div>
                                <a href="{{ url('/invoices/download') }}?id={{ $editInv['id'] }}" class="btn-sm-custom ms-auto" style="padding:5px 10px"><i class="bi bi-download"></i> Download</a>
                            </div>
                            <small style="color:var(--text-muted)">To replace the file, delete this entry and re-upload.</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Account</label>
                            <select name="account" class="form-select">
                                <option value="">— Select Account —</option>
                                @foreach ($accountOptions as $opt)
                                <option value="{{ $opt }}" {{ ($editInv['account'] ?? '') === $opt ? 'selected' : '' }}>{{ $opt }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" value="{{ $editInv['notes'] ?? '' }}" placeholder="Optional">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/invoices') }}{{ $filterMonth ? '?month='.$filterMonth : '' }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-floppy"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@push('scripts')
<script>
(function(){
    const stickyHead = document.querySelector('.sticky-card .data-card-header');
    const stickyBar  = document.getElementById('bulkBar');
    function recalcStickyOffsets() {
        if (!stickyHead) return;
        const headH = stickyHead.offsetHeight;
        const barH  = (stickyBar && stickyBar.style.display !== 'none') ? stickyBar.offsetHeight : 0;
        const root  = document.documentElement.style;
        root.setProperty('--sticky-heading-h', headH + 'px');
        root.setProperty('--sticky-head-h', (headH + barH) + 'px');
    }
    recalcStickyOffsets();
    window.addEventListener('resize', recalcStickyOffsets);

    const chkAll = document.getElementById('chkAll');
    const bulkBar = document.getElementById('bulkBar');
    const selCount = document.getElementById('selCount');
    const btnBulkDelete = document.getElementById('btnBulkDelete');
    const btnBulkZip = document.getElementById('btnBulkZip');
    const btnDeselect = document.getElementById('btnBulkDeselect');
    const multiForm = document.getElementById('multiDeleteForm');
    const multiIds = document.getElementById('multiDeleteIds');
    const rootUrl = document.documentElement.dataset.rootUrl || '';

    function getChecked() { return Array.from(document.querySelectorAll('.chk-row:checked')); }

    function updateBulkBar() {
        const checked = getChecked();
        const n = checked.length;
        if (bulkBar) {
            bulkBar.style.display = n > 0 ? 'flex' : 'none';
            if (selCount) selCount.textContent = n;
            recalcStickyOffsets();
        }
        if (btnBulkZip) {
            const ids = checked.map(c => c.dataset.id).join(',');
            btnBulkZip.href = rootUrl + '/invoices/download?ids=' + ids;
        }
        const all = document.querySelectorAll('.chk-row');
        if (chkAll) {
            chkAll.indeterminate = n > 0 && n < all.length;
            chkAll.checked = n > 0 && n === all.length;
        }
        document.querySelectorAll('.chk-date').forEach(dc => {
            const d = dc.dataset.date;
            const inDate = Array.from(document.querySelectorAll(`.inv-row[data-date="${d}"] .chk-row`));
            const checkedInDate = inDate.filter(c => c.checked);
            dc.indeterminate = checkedInDate.length > 0 && checkedInDate.length < inDate.length;
            dc.checked = inDate.length > 0 && checkedInDate.length === inDate.length;
        });
    }

    if (chkAll) {
        chkAll.addEventListener('change', () => {
            document.querySelectorAll('.chk-row').forEach(c => c.checked = chkAll.checked);
            document.querySelectorAll('.chk-date').forEach(c => c.checked = chkAll.checked);
            updateBulkBar();
        });
    }
    document.querySelectorAll('.chk-date').forEach(dc => {
        dc.addEventListener('change', () => {
            const d = dc.dataset.date;
            document.querySelectorAll(`.inv-row[data-date="${d}"] .chk-row`).forEach(c => c.checked = dc.checked);
            updateBulkBar();
        });
    });
    document.querySelectorAll('.chk-row').forEach(c => c.addEventListener('change', updateBulkBar));
    if (btnDeselect) {
        btnDeselect.addEventListener('click', () => {
            document.querySelectorAll('.chk-row, .chk-date').forEach(c => c.checked = false);
            if (chkAll) { chkAll.checked = false; chkAll.indeterminate = false; }
            updateBulkBar();
        });
    }
    if (btnBulkDelete) {
        btnBulkDelete.addEventListener('click', () => {
            const checked = getChecked();
            if (!checked.length) return;
            if (!confirm(`Permanently delete ${checked.length} selected invoice${checked.length > 1 ? 's' : ''}? This cannot be undone.`)) return;
            multiIds.innerHTML = '';
            checked.forEach(c => {
                const inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'del_ids[]'; inp.value = c.dataset.id;
                multiIds.appendChild(inp);
            });
            multiForm.submit();
        });
    }

    const zone = document.getElementById('dropZoneInv');
    const input = document.getElementById('invFileInput');
    const fileList = document.getElementById('fileList');
    const fileItems = document.getElementById('fileItems');
    const fileCount = document.getElementById('fileCount');
    const extIcon = ext => ({
        pdf:'bi-file-earmark-pdf-fill', jpg:'bi-file-earmark-image-fill', jpeg:'bi-file-earmark-image-fill',
        png:'bi-file-earmark-image-fill', webp:'bi-file-earmark-image-fill', xls:'bi-file-earmark-excel-fill',
        xlsx:'bi-file-earmark-excel-fill', doc:'bi-file-earmark-word-fill', docx:'bi-file-earmark-word-fill'
    }[ext.toLowerCase()] || 'bi-file-earmark-fill');
    function renderFiles(files) {
        if (!files || files.length === 0) { fileList.style.display = 'none'; return; }
        fileList.style.display = 'block';
        fileCount.textContent = files.length + ' file' + (files.length > 1 ? 's' : '');
        fileItems.innerHTML = '';
        Array.from(files).forEach(f => {
            const ext = f.name.split('.').pop();
            const kb = f.size < 1048576 ? (f.size/1024).toFixed(1)+' KB' : (f.size/1048576).toFixed(1)+' MB';
            const row = document.createElement('div');
            row.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 10px;background:var(--header-bg);border:1px solid var(--border);border-radius:6px;font-size:12px';
            row.innerHTML = `<i class="bi ${extIcon(ext)}" style="color:#a78bfa"></i><span style="flex:1;color:var(--text);overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${f.name}</span><span style="color:var(--text-muted);flex-shrink:0">${kb}</span>`;
            fileItems.appendChild(row);
        });
        zone.style.borderColor = '#a78bfa';
        zone.style.background = 'rgba(109,63,197,.08)';
    }
    function mergeFiles(newFiles) {
        const dt = new DataTransfer();
        Array.from(input.files).forEach(f => dt.items.add(f));
        const existing = new Set(Array.from(input.files).map(f => f.name + f.size));
        Array.from(newFiles).forEach(f => { if (!existing.has(f.name + f.size)) dt.items.add(f); });
        input.files = dt.files;
        renderFiles(input.files);
    }
    if (zone && input) {
        zone.addEventListener('click', () => input.click());
        zone.addEventListener('dragover', e => { e.preventDefault(); zone.style.borderColor = '#a78bfa'; });
        zone.addEventListener('dragleave', () => { if (!input.files.length) zone.style.borderColor = 'var(--border)'; });
        zone.addEventListener('drop', e => { e.preventDefault(); mergeFiles(e.dataTransfer.files); });
        input.addEventListener('change', () => renderFiles(input.files));
    }

    const table = document.getElementById('invTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    let sortCol = null, sortDir = 1;
    function cellVal(row, col, type) {
        const cell = row.cells[col];
        if (!cell) return '';
        const t = cell.innerText.trim();
        if (type === 'num') {
            const m = t.match(/([\d.]+)\s*(MB|KB|B)?/i);
            if (!m) return -Infinity;
            const n = parseFloat(m[1]);
            const u = (m[2]||'B').toUpperCase();
            return u==='MB' ? n*1048576 : u==='KB' ? n*1024 : n;
        }
        return t.toLowerCase();
    }
    table.querySelectorAll('thead th .sort-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const th = btn.closest('th');
            const col = [...th.parentElement.children].indexOf(th);
            const type = col === 4 ? 'num' : 'str';
            sortDir = sortCol === col ? sortDir * -1 : (type === 'num' ? -1 : 1);
            sortCol = col;
            table.querySelectorAll('thead th').forEach(h => h.classList.remove('sort-asc','sort-desc'));
            th.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');
            const groups = [];
            let current = null;
            Array.from(tbody.children).forEach(r => {
                if (r.classList.contains('date-group-row')) { current = { header: r, rows: [] }; groups.push(current); }
                else if (current) { current.rows.push(r); }
            });
            groups.sort((a, b) => {
                const aFirst = a.rows[0], bFirst = b.rows[0];
                if (!aFirst || !bFirst) return 0;
                const av = cellVal(aFirst, col, type), bv = cellVal(bFirst, col, type);
                return av < bv ? -sortDir : av > bv ? sortDir : 0;
            });
            groups.forEach(g => { tbody.appendChild(g.header); g.rows.forEach(r => tbody.appendChild(r)); });
        });
    });
})();
</script>
@endpush

@endsection
