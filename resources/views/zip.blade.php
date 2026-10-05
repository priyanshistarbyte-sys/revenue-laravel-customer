@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">ZIP Master</div>
        <div class="dash-subtitle"></div>
    </div>
    <button class="btn-primary-custom ms-auto" data-bs-toggle="modal" data-bs-target="#addZipModal">
        <i class="bi bi-plus-circle"></i> Add Zip
    </button>
</div>

<div class="data-card">
    <div class="data-card-header">
        @php $inactiveCount = count(array_filter($zips, fn ($z) => !$z['active'])); @endphp
        <i class="bi bi-person-lock"></i> Zip ({{ count($zips) }})
        @if ($inactiveCount)<span style="font-weight:400;font-size:11px;color:var(--text-muted);margin-left:6px">· {{ $inactiveCount }} inactive</span>@endif
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">Name</th>
                    <th style="text-align:left">Type</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse ($zips as $zip)
                <tr class="{{ $zip['active'] ? '' : 'row-nodata' }}">
                    <td style="text-align:left">{{ $zip['name'] }}</td>
                    <td style="text-align:left">{{ $zip['types'] }}</td>
                    <td style="text-align:center">
                        {!! $zip['active'] ? '<span class="chip-mapped">Active</span>' : '<span class="chip-unmapped">Inactive</span>' !!}
                    </td>
                    <td style="text-align:center">
                        @php $zVers = $versions[$zip['id']] ?? []; @endphp
                        <div style="display:flex;gap:6px;justify-content:center">
                            <form method="post" action="{{ url('/zip-masters') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="action" value="toggle">
                                <input type="hidden" name="id" value="{{ $zip['id'] }}">
                                <button type="submit" class="btn-sm-custom"
                                        title="{{ $zip['active'] ? 'Deactivate (hide from Deploy)' : 'Activate' }}">
                                    <i class="bi bi-{{ $zip['active'] ? 'pause' : 'play' }}-circle"></i>
                                </button>
                            </form>
                            @if (!empty($zip['file']))
                            <a href="{{ url('/zip-masters') }}?download={{ $zip['id'] }}" class="btn-sm-custom" title="Download {{ $zip['original_name'] ?? 'zip' }}">
                                <i class="bi bi-download"></i>
                            </a>
                            @endif
                            @if (count($zVers))
                            <button type="button" class="btn-sm-custom" data-bs-toggle="modal" data-bs-target="#versionsModal{{ $zip['id'] }}" title="Older versions">
                                <i class="bi bi-clock-history"></i> {{ count($zVers) }}
                            </button>
                            @endif
                            <a href="{{ url('/zip-masters') }}?edit={{ $zip['id'] }}" class="btn-sm-custom" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="post" action="{{ url('/zip-masters') }}" style="display:inline">
                                @csrf
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="{{ $zip['id'] }}">
                                <button type="submit" class="btn-danger-custom"
                                        data-confirm="Delete zip '{{ $zip['name'] }}'? This cannot be undone." title="Delete">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                 
                </tr>
            @empty
                <tr><td colspan="4" style="text-align:center;color:var(--text-muted);padding:30px">No zips yet. Create one to reuse across users.</td></tr>
            @endforelse
            </tbody>
         </table>
    </div>
</div>

<!-- Older Versions Modals -->
@foreach ($zips as $zip)
    @php $zVers = $versions[$zip['id']] ?? []; @endphp
    @if (count($zVers))
    <div class="modal fade" id="versionsModal{{ $zip['id'] }}" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
                <div class="modal-header" style="border-color:#2e2e5a">
                    <h5 class="modal-title" style="color:#fff"><i class="bi bi-clock-history"></i> Older Versions — {{ $zip['name'] }}</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <table class="ledger" style="width:100%">
                        <thead>
                            <tr>
                                <th style="text-align:left">Version</th>
                                <th style="text-align:left">Saved</th>
                                <th style="text-align:center">Download</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach ($zVers as $v)
                            <tr>
                                <td style="text-align:left">{{ $v['name'] }}</td>
                                <td style="text-align:left;color:var(--text-muted)">{{ $v['created_at'] }}</td>
                                <td style="text-align:center">
                                    <a href="{{ url('/zip-masters') }}?download_version={{ $v['id'] }}" class="btn-sm-custom" title="Download {{ $v['original_name'] ?? 'version' }}">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    @endif
@endforeach

<!-- Add Zip Modal -->
<div class="modal fade" id="addZipModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add Zip</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form action="{{ url('/zip-masters') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" placeholder="e.g. Viewer, Editor" required maxlength="100">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <div style="display:flex;gap:24px;padding-top:4px">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#c4b5fd">
                                <input type="radio" name="types" value="sub-site" required> Sub-site
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#7dd3fc">
                                <input type="radio" name="types" value="main-site"> Main-site
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">File</label>
                        <input type="file" name="file" class="form-control" required maxlength="100">
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Zip</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Zip Modal -->
@if($editZip)
<div class="modal fade show" id="editZipModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.7)">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit Zip — {{ $editZip['name'] }}</h5>
                <a href="{{ url('/zip-masters') }}" class="btn-close btn-close-white"></a>
            </div>
            <form action="{{ url('/zip-masters') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editZip['id'] }}">

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" value="{{ $editZip['name'] }}" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        @php
                            $curType = strtolower(ltrim(trim($editZip['types'] ?? ''), '.'));
                            // Normalize legacy values (xyz/com) to the new type labels.
                            $curType = in_array($curType, ['xyz', 'sub-site'], true) ? 'sub-site'
                                     : (in_array($curType, ['com', 'main-site'], true) ? 'main-site' : $curType);
                        @endphp
                        <div style="display:flex;gap:24px;padding-top:4px">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#c4b5fd">
                                <input type="radio" name="types" value="sub-site" required {{ $curType === 'sub-site' ? 'checked' : '' }}> Sub-site
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#7dd3fc">
                                <input type="radio" name="types" value="main-site" {{ $curType === 'main-site' ? 'checked' : '' }}> Main-site
                            </label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">File (leave empty to keep current)</label>
                        <input type="file" name="file" class="form-control" id="editZipFile">
                    </div>

                    {{-- Only relevant when a new file is chosen: keep the current file as an older version. --}}
                    <div id="saveOldVersionWrap" style="display:none">
                        <div class="mb-2">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;color:#e5e7eb">
                                <input type="checkbox" name="save_old_version" value="1" id="saveOldVersionChk"> Save older version
                                <small style="color:var(--text-muted)">— keep the current file before replacing it</small>
                            </label>
                        </div>
                        <div class="mb-3" id="oldVersionNameWrap" style="display:none">
                            <label class="form-label">Older version name</label>
                            <input type="text" name="old_version_name" class="form-control" id="oldVersionName"
                                   placeholder="e.g. v1 — before ADX change" maxlength="100">
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/zip-masters') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var fileInput = document.getElementById('editZipFile');
    var wrap      = document.getElementById('saveOldVersionWrap');
    var chk       = document.getElementById('saveOldVersionChk');
    var nameWrap  = document.getElementById('oldVersionNameWrap');
    var nameInput = document.getElementById('oldVersionName');
    if (!fileInput || !wrap || !chk || !nameWrap) return;

    // Show the "save older version" option only once a replacement file is chosen.
    fileInput.addEventListener('change', function () {
        var hasFile = fileInput.files && fileInput.files.length > 0;
        wrap.style.display = hasFile ? 'block' : 'none';
        if (!hasFile) {
            chk.checked = false;
            nameWrap.style.display = 'none';
            if (nameInput) nameInput.value = '';
        }
    });

    // Reveal the name field only when the user opts to keep the old version.
    chk.addEventListener('change', function () {
        nameWrap.style.display = chk.checked ? 'block' : 'none';
        if (chk.checked && nameInput) nameInput.focus();
    });
})();
</script>
@endif

@endsection