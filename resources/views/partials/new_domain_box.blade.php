@php
    /** New-domain ADX prompt. Params: $prefix ('add'|'edit'), $adxOptions, $selectedAdx. */
    $prefix = $prefix ?? 'add';
    $selectedAdx = $selectedAdx ?? null;
@endphp
<div class="col-12 js-newdomain-box" id="{{ $prefix }}NewDomainBox" style="display:none">
    <div style="background:rgba(125,211,252,.08);border:1px solid rgba(125,211,252,.3);border-radius:8px;padding:10px 12px">
        <div style="font-size:12px;color:#7dd3fc;margin-bottom:8px">
            <i class="bi bi-plus-circle"></i>
            New main domain <strong class="js-newdomain-name" style="color:#c4b5fd">—</strong>
            will be added to the Domains list. Pick its ADX network:
        </div>
        <select name="new_domain_adx_id" class="form-select js-newdomain-adx">
            <option value="">— None —</option>
            @foreach ($adxOptions as $ax)
            <option value="{{ $ax['id'] }}" {{ (int) $selectedAdx === (int) $ax['id'] ? 'selected' : '' }}>{{ $ax['name'] }}</option>
            @endforeach
        </select>
    </div>
</div>
