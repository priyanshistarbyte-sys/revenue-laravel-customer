@php
    /** Account add/edit modal. Params: $mType ('add'|'edit'), $acc (array), $metaEdit, $search. */
    $isEdit  = $mType === 'edit';
    $modalId = $isEdit ? 'editAccModal' : 'accModal';
    $bmTags  = $isEdit ? array_filter(array_map('trim', explode(',', $acc['bm'] ?? ''))) : [];
    $adTags  = $isEdit ? array_filter(array_map('trim', explode(',', $acc['ad_account_ids'] ?? ''))) : [];
    $cancelUrl = url('/accounts') . ($search ? '?q=' . urlencode($search) : '');
@endphp
<div class="modal {{ $isEdit ? 'fade show' : 'fade' }}" id="{{ $modalId }}" tabindex="-1" {!! $isEdit ? 'style="display:block;background:rgba(0,0,0,.75)"' : '' !!}>
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff">
                    <i class="bi bi-{{ $isEdit ? 'pencil' : 'plus-circle' }}"></i>
                    {{ $isEdit ? 'Edit Account — ' . ($acc['name'] ?? '') : 'Add New Account' }}
                </h5>
                @if ($isEdit)
                    <a href="{{ $cancelUrl }}" class="btn-close btn-close-white"></a>
                @else
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                @endif
            </div>
            <form method="post" action="{{ url('/accounts') }}">
                <input type="hidden" name="action" value="{{ $mType }}">
                @if ($isEdit)<input type="hidden" name="id" value="{{ $acc['id'] }}">@endif

                <div class="modal-body">
                    <div class="acc-section-label">Identity</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-3">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" value="{{ $acc['name'] ?? '' }}" required placeholder="e.g. Payal Sarthi">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Username / Phone</label>
                            <input type="text" name="username" class="form-control" value="{{ $acc['username'] ?? '' }}" placeholder="61578267976102">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Password</label>
                            <div style="position:relative">
                                <input type="password" name="password" class="form-control pwd-field" value="{{ $acc['password'] ?? '' }}" placeholder="••••••••" style="padding-right:36px">
                                <button type="button" class="btn-eye-inline" onclick="togglePwd(this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Auth Code</label>
                            <input type="text" name="auth" class="form-control" value="{{ $acc['auth'] ?? '' }}" placeholder="RXOB B5PY 3GRZ …">
                        </div>
                    </div>

                    <div class="acc-section-label">Email</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="{{ $acc['email'] ?? '' }}" placeholder="user@outlook.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Email Password</label>
                            <div style="position:relative">
                                <input type="password" name="email_password" class="form-control pwd-field" value="{{ $acc['email_password'] ?? '' }}" placeholder="••••••••" style="padding-right:36px">
                                <button type="button" class="btn-eye-inline" onclick="togglePwd(this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Recovery Email</label>
                            <input type="email" name="recovery_email" class="form-control" value="{{ $acc['recovery_email'] ?? '' }}" placeholder="recovery@gmail.com">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Recovery Password</label>
                            <div style="position:relative">
                                <input type="password" name="recovery_password" class="form-control pwd-field" value="{{ $acc['recovery_password'] ?? '' }}" placeholder="••••••••" style="padding-right:36px">
                                <button type="button" class="btn-eye-inline" onclick="togglePwd(this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                    </div>

                    <div class="acc-section-label">Ad IDs &amp; Page</div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-5">
                            <label class="form-label">
                                BM ID(s)
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— press <kbd>Enter</kbd> or <kbd>,</kbd> to add multiple</small>
                            </label>
                            <input type="hidden" name="bm" id="{{ $mType }}BmValue" value="{{ $acc['bm'] ?? '' }}">
                            <div class="tag-input-wrap" id="{{ $mType }}BmWrap">
                                @foreach ($bmTags as $t)
                                <span class="tag-pill">{{ $t }}<button type="button" class="tag-remove" data-value="{{ $t }}">×</button></span>
                                @endforeach
                                <input type="text" class="tag-text-input" id="{{ $mType }}BmInput" placeholder="e.g. 915629501071368">
                            </div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">
                                Ad Account ID(s)
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— press <kbd>Enter</kbd> or <kbd>,</kbd> to add multiple</small>
                            </label>
                            <input type="hidden" name="ad_account_ids" id="{{ $mType }}AdValue" value="{{ $acc['ad_account_ids'] ?? '' }}">
                            <div class="tag-input-wrap" id="{{ $mType }}AdWrap">
                                @foreach ($adTags as $t)
                                <span class="tag-pill">{{ $t }}<button type="button" class="tag-remove" data-value="{{ $t }}">×</button></span>
                                @endforeach
                                <input type="text" class="tag-text-input" id="{{ $mType }}AdInput" placeholder="e.g. act_1234567890">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Facebook Page URL</label>
                            <input type="url" name="page_url" class="form-control" value="{{ $acc['page_url'] ?? '' }}" placeholder="https://www.facebook.com/profile.php?id=…">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Notes</label>
                            <input type="text" name="notes" class="form-control" value="{{ $acc['notes'] ?? '' }}" placeholder="Optional notes">
                        </div>
                    </div>

                    @if ($metaEdit)
                    <div class="acc-section-label">API Sync
                        <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— auto-pull daily spend from the Meta Graph API into this owner's P&amp;L</small>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">
                                API Token
                                <small style="color:#7dd3fc;font-weight:400;text-transform:none;letter-spacing:0">— System User token with <code>ads_read</code>{{ $isEdit ? ' · leave blank to keep current' : '' }}</small>
                            </label>
                            <div style="position:relative">
                                <input type="password" name="api_token" class="form-control pwd-field" value="" placeholder="{{ $isEdit && !empty($acc['api_token']) ? '•••••••• (token saved)' : 'EAAG…' }}" style="padding-right:36px" autocomplete="off">
                                <button type="button" class="btn-eye-inline" onclick="togglePwd(this)"><i class="bi bi-eye"></i></button>
                            </div>
                        </div>
                        @if ($isEdit && (!empty($acc['last_sync']) || !empty($acc['last_error'])))
                        <div class="col-12" style="font-size:12px">
                            @if (!empty($acc['last_error']))
                                <span style="color:#f87171"><i class="bi bi-exclamation-triangle"></i> Last error: {{ $acc['last_error'] }}</span>
                            @else
                                <span style="color:var(--text-muted)"><i class="bi bi-check-circle"></i> Last synced: {{ $acc['last_sync'] }}</span>
                            @endif
                        </div>
                        @endif
                    </div>
                    @endif
                </div>

                <div class="modal-footer" style="border-color:#2e2e5a">
                    @if ($isEdit)
                        <a href="{{ $cancelUrl }}" class="btn-sm-custom">Cancel</a>
                    @else
                        <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    @endif
                    <button type="submit" class="btn-primary-custom">
                        <i class="bi bi-{{ $isEdit ? 'floppy' : 'check-circle' }}"></i>
                        {{ $isEdit ? 'Save Changes' : 'Add Account' }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
