{{-- Shared Add/Edit fields for a server. $s = row or null, $isEdit = bool --}}
@php $s = $s ?? []; @endphp
<div class="mb-3">
    <label class="form-label">Name</label>
    <input type="text" name="name" class="form-control" placeholder="e.g. Server 1 (US)" value="{{ $s['name'] ?? '' }}" required maxlength="100">
</div>

<div style="display:flex;gap:12px">
    <div class="mb-3" style="flex:2">
        <label class="form-label">SSH Host</label>
        <input type="text" name="ssh_host" class="form-control" placeholder="e.g. 203.0.113.10" value="{{ $s['ssh_host'] ?? '' }}" required>
    </div>
    <div class="mb-3" style="flex:1">
        <label class="form-label">SSH Port</label>
        <input type="number" name="ssh_port" class="form-control" value="{{ $s['ssh_port'] ?? 22 }}" min="1" max="65535" required>
    </div>
</div>

<div style="display:flex;gap:12px">
    <div class="mb-3" style="flex:1">
        <label class="form-label">SSH Username</label>
        <input type="text" name="ssh_username" class="form-control" placeholder="e.g. root" value="{{ $s['ssh_username'] ?? '' }}" required>
    </div>
    <div class="mb-3" style="flex:1">
        <label class="form-label">SSH Password @if($isEdit)<small style="color:var(--text-muted)">(leave blank to keep)</small>@endif</label>
        <input type="password" name="ssh_password" class="form-control" autocomplete="new-password" placeholder="{{ $isEdit ? '••••••••' : '' }}" {{ $isEdit ? '' : 'required' }}>
    </div>
</div>

<hr style="border-color:#2e2e5a">

<div class="mb-3">
    <label class="form-label">aaPanel URL</label>
    <input type="text" name="bt_panel_url" class="form-control" placeholder="e.g. https://203.0.113.10:8888" value="{{ $s['bt_panel_url'] ?? '' }}" required>
</div>

<div style="display:flex;gap:12px">
    <div class="mb-3" style="flex:2">
        <label class="form-label">aaPanel API Key @if($isEdit)<small style="color:var(--text-muted)">(leave blank to keep)</small>@endif</label>
        <input type="password" name="bt_panel_key" class="form-control" autocomplete="new-password" placeholder="{{ $isEdit ? '••••••••' : '' }}" {{ $isEdit ? '' : 'required' }}>
    </div>
    <div class="mb-3" style="flex:1">
        <label class="form-label">PHP Version</label>
        <input type="text" name="bt_panel_php_version" class="form-control" placeholder="e.g. 84" value="{{ $s['bt_panel_php_version'] ?? '84' }}" required>
    </div>
</div>

<div class="mb-1">
    <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#e5e7eb">
        <input type="checkbox" name="active" value="1" {{ (!$isEdit || !empty($s['active'])) ? 'checked' : '' }}> Active (available as a deploy target)
    </label>
</div>

<div style="margin-top:8px;font-size:11px;color:var(--text-muted)">
    <i class="bi bi-shield-lock"></i> The SSH password and panel key are encrypted before saving and never shown again.
</div>
