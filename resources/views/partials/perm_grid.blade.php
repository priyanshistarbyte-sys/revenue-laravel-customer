{{-- Page × action permission grid. Expects optional $permVal[page][action].
     Column headers carry a "select all" checkbox; grid JS is shared (rendered once). --}}
@php $permVal = $permVal ?? []; @endphp
<div class="table-wrap" style="max-height:340px;overflow:auto;border:1px solid #2e2e5a;border-radius:8px">
    <table class="ledger perm-grid" style="margin:0">
        <thead>
            <tr>
                <th style="text-align:left">Page</th>
                @foreach (['view' => 'View', 'add' => 'Add', 'edit' => 'Edit', 'delete' => 'Delete'] as $act => $lbl)
                <th style="width:60px">
                    <div>{{ $lbl }}</div>
                    <input type="checkbox" class="perm-all" data-act="{{ $act }}" title="Select all — {{ $lbl }}"
                           style="accent-color:#a78bfa;width:14px;height:14px;margin-top:3px;cursor:pointer">
                </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
        @foreach (permissionPages() as $page => $meta)
            @php $pv = $permVal[$page] ?? []; @endphp
            <tr>
                <td style="text-align:left"><i class="bi {{ $meta['icon'] }}" style="color:#a78bfa"></i> {{ $meta['label'] }}</td>
                @foreach (['view', 'add', 'edit', 'delete'] as $act)
                <td style="text-align:center">
                    @if (in_array($act, $meta['actions'], true))
                    <input type="checkbox" name="perm[{{ $page }}][{{ $act }}]" value="1"
                           class="perm-cb perm-cb-{{ $act }}" style="accent-color:#a78bfa;width:15px;height:15px"
                           {{ !empty($pv[$act]) ? 'checked' : '' }}>
                    @else
                    <span style="color:var(--text-muted)">—</span>
                    @endif
                </td>
                @endforeach
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<div style="color:var(--text-muted);font-size:10px;margin-top:5px">Ticking Add / Edit / Delete auto-grants View.</div>

@once
@push('scripts')
<script>
(function () {
    // Sync a table's column "select all" headers to their column state.
    window.permSyncHeaders = function (table) {
        if (!table) return;
        ['view', 'add', 'edit', 'delete'].forEach(function (act) {
            var boxes = table.querySelectorAll('input.perm-cb-' + act);
            var head  = table.querySelector('input.perm-all[data-act="' + act + '"]');
            if (!head) return;
            if (boxes.length === 0) { head.checked = false; head.indeterminate = false; head.disabled = true; return; }
            var checked = 0;
            boxes.forEach(function (cb) { if (cb.checked) checked++; });
            head.checked = checked === boxes.length;
            head.indeterminate = checked > 0 && checked < boxes.length;
        });
    };

    document.addEventListener('change', function (e) {
        var t = e.target;
        if (!(t instanceof HTMLInputElement)) return;

        // Column "select all"
        if (t.classList.contains('perm-all')) {
            var table = t.closest('table'), act = t.dataset.act;
            if (!table || !act) return;
            table.querySelectorAll('input.perm-cb-' + act).forEach(function (cb) {
                cb.checked = t.checked;
                if (t.checked && act !== 'view') {           // write implies view
                    var v = cb.closest('tr').querySelector('input.perm-cb-view');
                    if (v) v.checked = true;
                }
            });
            window.permSyncHeaders(table);
            return;
        }

        // Individual cell — ticking a write action auto-checks View on its row.
        if (t.classList.contains('perm-cb')) {
            if (t.checked && !t.classList.contains('perm-cb-view')) {
                var v2 = t.closest('tr').querySelector('input.perm-cb-view');
                if (v2) v2.checked = true;
            }
            window.permSyncHeaders(t.closest('table'));
        }
    });

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('table.perm-grid').forEach(function (tbl) { window.permSyncHeaders(tbl); });
    });
})();
</script>
@endpush
@endonce
