{{-- Reusable permission block for the Users add/edit forms.
     Expects (all optional): $scopeVal, $allowedIds[], $roleVal, $permVal[page][action].
     Inherits $adxList and $roles from the parent view. --}}
@php
    $scopeVal   = $scopeVal   ?? 'own';
    $allowedIds = $allowedIds ?? [];
    $roleVal    = (int) ($roleVal ?? 0);
    $permVal    = $permVal    ?? [];
@endphp

<hr style="border-color:#2e2e5a;margin:18px 0 14px">

<div class="mb-3">
    <label class="form-label">Permission template <small style="color:#7dd3fc;font-weight:400">— optional, fills the grid below</small></label>
    <select name="role_id" class="form-select js-perm-template">
        <option value="0">— none —</option>
        @foreach ($roles as $role)
        <option value="{{ (int)$role['id'] }}" {{ $roleVal === (int)$role['id'] ? 'selected' : '' }}>{{ $role['name'] }}</option>
        @endforeach
    </select>
</div>

<div class="mb-3">
    <label class="form-label">Data visibility</label>
    <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:var(--text)">
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="radio" name="data_scope" value="own" class="js-scope-radio" {{ $scopeVal==='own'?'checked':'' }}> Own data</label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="radio" name="data_scope" value="adx" class="js-scope-radio" {{ $scopeVal==='adx'?'checked':'' }}> ADX-wise</label>
        <label style="display:flex;align-items:center;gap:6px;cursor:pointer"><input type="radio" name="data_scope" value="all" class="js-scope-radio" {{ $scopeVal==='all'?'checked':'' }}> All data</label>
    </div>
    <div class="js-adx-scope-box" style="margin-top:10px;{{ $scopeVal==='adx' ? '' : 'display:none' }}">
        <div style="color:var(--text-muted);font-size:11px;margin-bottom:6px">Which ADX networks can this user see?</div>
        @if (empty($adxList))
        <div style="color:var(--text-muted);font-size:11px">No ADX networks yet — add some on the ADX page.</div>
        @else
        <div style="display:flex;flex-wrap:wrap;gap:6px 14px;max-height:140px;overflow:auto;padding:2px">
        @foreach ($adxList as $ax)
            <label style="display:flex;align-items:center;gap:6px;font-size:12px;color:var(--text);cursor:pointer">
                <input type="checkbox" name="allowed_adx[]" value="{{ (int)$ax['id'] }}" {{ in_array((int)$ax['id'], $allowedIds, true) ? 'checked' : '' }}> {{ $ax['name'] }}
            </label>
        @endforeach
        </div>
        @endif
    </div>
</div>

<div class="mb-2">
    <label class="form-label">Page permissions</label>
    @include('partials.perm_grid', ['permVal' => $permVal])
    <div style="color:var(--text-muted);font-size:10px;margin-top:3px">Admin users bypass all permissions.</div>
</div>
