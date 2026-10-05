@php
    /** Shared Add/Edit expense form fields. Expects $editExp, $usdRate, $adAccountOptions. */
    $f           = $editExp ?? [];
    $defaultDate = $f['date'] ?? date('Y-m-d', strtotime('yesterday'));
    $currentItem = $f['item_name'] ?? '';
    $matchesOption = false;
    foreach (($adAccountOptions ?? []) as $opt) {
        if ($opt['value'] === $currentItem) { $matchesOption = true; break; }
    }
    $isManual = ($currentItem !== '' && !$matchesOption);
@endphp
<div class="modal-body">
    <div class="row g-3">
        <div class="col-md-3">
            <label class="form-label">Date *</label>
            <input type="date" name="date" class="form-control" value="{{ $defaultDate }}" required>
        </div>

        <div class="col-md-5">
            <label class="form-label">
                Item Name *
                <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— Ad Account ID + Name</small>
            </label>
            <input type="hidden" name="item_name" id="itemNameHidden" value="{{ $currentItem }}">
            <select class="form-select" id="itemNameSelect">
                <option value="">— Select Ad Account ID —</option>
                @foreach (($adAccountOptions ?? []) as $opt)
                <option value="{{ $opt['value'] }}" {{ $opt['value'] === $currentItem ? 'selected' : '' }}>{{ $opt['label'] }}</option>
                @endforeach
                <option value="__manual__" {{ $isManual ? 'selected' : '' }}>✏️ Type manually…</option>
            </select>
            <input type="text" id="itemNameManual" class="form-control mt-2" placeholder="Type item name…"
                   value="{{ $isManual ? $currentItem : '' }}" style="display:{{ $isManual ? 'block' : 'none' }}">
        </div>

        <div class="col-md-4">
            <label class="form-label">Action / Description</label>
            <input type="text" name="action_txt" class="form-control" value="{{ $f['action'] ?? '' }}" placeholder="e.g. Add Balance, Purchase BM…">
        </div>

        <div class="col-md-2">
            <label class="form-label">Currency</label>
            <select name="currency" class="form-select" id="currencySelect">
                <option value="INR" {{ ($f['currency'] ?? 'INR') === 'INR' ? 'selected' : '' }}>₹ INR</option>
                <option value="USD" {{ ($f['currency'] ?? '') === 'USD' ? 'selected' : '' }}>$ USD</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Amount <span id="currLabel">{{ ($f['currency'] ?? 'INR') === 'USD' ? '($)' : '(₹)' }}</span></label>
            <div style="display:flex;align-items:center;gap:6px">
                <span id="currSymbol" style="color:var(--text-muted)">{{ ($f['currency'] ?? 'INR') === 'USD' ? '$' : '₹' }}</span>
                <input type="number" name="price_raw" class="form-control" value="{{ $f['price_raw'] ?? '' }}"
                       min="0" step="0.01" placeholder="0.00" id="priceRawInput" required>
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Price (₹) <small style="color:var(--text-muted);font-weight:400">auto or override</small></label>
            <div style="display:flex;align-items:center;gap:6px">
                <span style="color:var(--text-muted)">₹</span>
                <input type="number" name="price_inr_override" class="form-control" value="{{ $f['price_inr'] ?? '' }}"
                       min="0" step="0.01" placeholder="Auto" id="priceInrInput">
            </div>
            <small style="color:var(--text-muted)">Rate: {{ number_format($usdRate, 2) }} {{ getDefaultCurrency()['code'] }}/USD</small>
        </div>
        <div class="col-md-4">
            <label class="form-label">Notes</label>
            <input type="text" name="notes" class="form-control" value="{{ $f['notes'] ?? '' }}" placeholder="Optional">
        </div>
    </div>
</div>

<script>
(function(){
    const sel     = document.getElementById('itemNameSelect');
    const manual  = document.getElementById('itemNameManual');
    const hidden  = document.getElementById('itemNameHidden');
    function syncItemName() {
        if (!sel) return;
        if (sel.value === '__manual__') { manual.style.display = 'block'; hidden.value = manual.value.trim(); }
        else { manual.style.display = 'none'; hidden.value = sel.value; }
    }
    sel && sel.addEventListener('change', syncItemName);
    manual && manual.addEventListener('input', () => { hidden.value = manual.value.trim(); });
    syncItemName();

    const curSel = document.getElementById('currencySelect');
    const rawInp = document.getElementById('priceRawInput');
    const inrInp = document.getElementById('priceInrInput');
    const label  = document.getElementById('currLabel');
    const symbol = document.getElementById('currSymbol');
    const rate   = {{ (float) $usdRate }};
    function updateCurrency() {
        if (!curSel) return;
        const isUSD = curSel.value === 'USD';
        if (label)  label.textContent  = isUSD ? '($)' : '(₹)';
        if (symbol) symbol.textContent = isUSD ? '$'   : '₹';
    }
    function autoConvert() {
        if (!curSel || !rawInp || !inrInp) return;
        if (curSel.value === 'USD' && rawInp.value && !inrInp.dataset.manual) {
            inrInp.value = (parseFloat(rawInp.value) * rate).toFixed(2);
        }
    }
    curSel && curSel.addEventListener('change', () => { updateCurrency(); autoConvert(); });
    rawInp && rawInp.addEventListener('input', autoConvert);
    inrInp && inrInp.addEventListener('input', () => { inrInp.dataset.manual = '1'; });
    updateCurrency();
})();
</script>
