@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
        <div class="dash-title">EXPENSES</div>
        <div class="dash-subtitle">Track all Meta account purchases, balance top-ups, and other spends</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;flex-wrap:wrap;align-items:center">
        <form method="get" action="{{ url('/expenses') }}" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
            <input type="text"  name="item"  class="form-control" placeholder="Filter by name…" value="{{ $filterItem }}" style="width:160px">
            <input type="month" name="month" class="form-control" value="{{ $filterMonth }}" style="width:150px">
            <select name="cur" class="form-select" style="width:100px">
                <option value="">All</option>
                <option value="USD" {{ $filterCur==='USD'?'selected':'' }}>USD ($)</option>
                <option value="INR" {{ $filterCur==='INR'?'selected':'' }}>INR (₹)</option>
            </select>
            <button type="submit" class="btn-primary-custom"><i class="bi bi-funnel"></i> Filter</button>
            @if ($filterItem || $filterMonth || $filterCur)
            <a href="{{ url('/expenses') }}" class="btn-sm-custom" style="padding:7px 10px"><i class="bi bi-x"></i> Clear</a>
            @endif
        </form>
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#expModal">
            <i class="bi bi-plus-circle"></i> Add Expense
        </button>
    </div>
</div>

<!-- Summary KPIs -->
<div class="kpi-row" style="margin-bottom:20px">
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-receipt"></i> Total Entries</div>
        <div class="kpi-value white">{{ count($expenses) }}</div>
    </div>
    @php $defCur = getDefaultCurrency(); @endphp
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-rupee"></i> Total Spent ({{ $defCur['symbol'] }})</div>
        <div class="kpi-value red">{{ fmtMoney($totalINR) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-dollar"></i> USD/{{ $defCur['code'] }} Rate</div>
        <div class="kpi-value gold">{{ number_format($usdRate, 2) }}</div>
    </div>
    @php
        $byItem = [];
        foreach ($expenses as $e) { $byItem[$e['item_name']] = ($byItem[$e['item_name']] ?? 0) + $e['price_inr']; }
        arsort($byItem);
        $top = array_slice($byItem, 0, 1, true);
    @endphp
    @foreach ($top as $name => $amt)
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-person"></i> Top Spender</div>
        <div class="kpi-value purple" style="font-size:1rem">{{ $name }}</div>
        <div style="color:var(--red);font-size:11px;margin-top:2px">{{ fmtINR($amt) }}</div>
    </div>
    @endforeach
</div>

<!-- Expenses Table -->
<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-wallet2"></i> Expense Ledger
        @if ($filterItem || $filterMonth || $filterCur)
        <span style="margin-left:8px;font-size:11px;color:#fbbf24">(filtered)</span>
        @endif
        <span style="margin-left:auto;font-size:11px;color:var(--text-muted);font-weight:400">Total: {{ fmtINR($totalINR) }}</span>
    </div>
    <div class="table-wrap">
        <table class="ledger" id="expTable">
            <thead>
                <tr>
                    <th style="text-align:left">Date <span class="sort-btn">⇅</span></th>
                    <th style="text-align:left">Item Name <span class="sort-btn">⇅</span></th>
                    <th style="text-align:left">Action</th>
                    <th>Price <span class="sort-btn">⇅</span></th>
                    <th>Price (₹) <span class="sort-btn">⇅</span></th>
                    <th style="text-align:left">Notes</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if (empty($expenses))
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:36px">
                No expenses found. <a href="#" data-bs-toggle="modal" data-bs-target="#expModal" style="color:#a78bfa">Add first expense →</a>
            </td></tr>
            @endif
            @foreach ($expenses as $e)
                @php
                    $isUSD = $e['currency'] === 'USD';
                    $priceDisplay = $isUSD ? '$' . number_format($e['price_raw'], 2) : '₹' . format_inr($e['price_raw']);
                @endphp
            <tr>
                <td style="text-align:left;white-space:nowrap">{{ date('d-M-Y', strtotime($e['date'])) }}</td>
                <td style="text-align:left;font-weight:600;color:#c4b5fd">{{ $e['item_name'] }}</td>
                <td style="text-align:left;max-width:250px;font-size:12px">{{ $e['action'] }}</td>
                <td class="{{ $isUSD ? 'c-green' : 'c-orange' }}" style="font-size:12px">{{ $priceDisplay }}</td>
                <td class="c-red" style="font-weight:600">{{ fmtINR((float)$e['price_inr']) }}</td>
                <td style="text-align:left;color:var(--text-muted);font-size:11px">{{ $e['notes'] ?? '' }}</td>
                <td style="text-align:center">
                    <div style="display:flex;gap:5px;justify-content:center">
                        <a href="{{ url('/expenses') }}?edit={{ $e['id'] }}{{ $filterMonth ? '&month='.$filterMonth : '' }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                        <form method="post" action="{{ url('/expenses') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $e['id'] }}">
                            <button class="btn-danger-custom" data-confirm="Delete this expense?"><i class="bi bi-trash3"></i></button>
                        </form>
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
            @if (!empty($expenses))
            <tfoot>
                <tr>
                    <td colspan="4" style="text-align:left">TOTAL</td>
                    <td class="c-red" style="font-weight:700">{{ fmtINR($totalINR) }}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="expModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add Expense</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/expenses') }}">
                <input type="hidden" name="action" value="add">
                @include('partials.expense_form', ['editExp' => null])
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Expense</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
@if ($editExp)
<div class="modal fade show" id="editExpModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Expense</h5>
                <a href="{{ url('/expenses') }}{{ $filterMonth ? '?month='.$filterMonth : '' }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/expenses') }}">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editExp['id'] }}">
                @include('partials.expense_form')
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/expenses') }}" class="btn-sm-custom">Cancel</a>
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
    const table = document.getElementById('expTable');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    let sortCol = null, sortDir = 1;
    function cellVal(row, col, type) {
        const cell = row.cells[col];
        if (!cell) return '';
        const t = cell.innerText.trim();
        if (type === 'num') { const n = parseFloat(t.replace(/[₹$,%\s,]/g, '')); return isNaN(n) ? -Infinity : n; }
        return t.toLowerCase();
    }
    table.querySelectorAll('thead th .sort-btn').forEach(btn => {
        btn.addEventListener('click', e => {
            e.stopPropagation();
            const th  = btn.closest('th');
            const col = [...th.parentElement.children].indexOf(th);
            const type = col >= 3 && col <= 4 ? 'num' : 'str';
            sortDir = sortCol === col ? sortDir * -1 : (type === 'num' ? -1 : 1);
            sortCol = col;
            table.querySelectorAll('thead th').forEach(h => h.classList.remove('sort-asc','sort-desc'));
            th.classList.add(sortDir === 1 ? 'sort-asc' : 'sort-desc');
            const rows = Array.from(tbody.querySelectorAll('tr'));
            rows.sort((a,b) => { const av = cellVal(a, col, type), bv = cellVal(b, col, type); return av < bv ? -sortDir : av > bv ? sortDir : 0; });
            rows.forEach(r => tbody.appendChild(r));
        });
    });
})();
</script>
@endpush

@endsection
