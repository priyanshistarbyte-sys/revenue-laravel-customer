@extends('layouts.app')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">CURRENCY MASTER</div>
        <div class="dash-subtitle">
            Manage the default currency and exchange rates — all amounts across Amaira display in
            <strong style="color:#fbbf24">{{ $defaultCur['symbol'] . ' ' . $defaultCur['code'] }}</strong>
        </div>
    </div>
    <div style="margin-left:auto">
        <button class="btn-primary-custom" data-bs-toggle="modal" data-bs-target="#curModal">
            <i class="bi bi-plus-circle"></i> Add Currency
        </button>
    </div>
</div>

<div class="kpi-row" style="margin-bottom:20px">
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-star-fill"></i> Default Currency</div>
        <div class="kpi-value gold">{{ $defaultCur['symbol'] . ' ' . $defaultCur['code'] }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-currency-exchange"></i> Active Currencies</div>
        <div class="kpi-value white">{{ count(array_filter($currencies, fn($c) => $c['active'])) }}</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label"><i class="bi bi-list-ol"></i> Total Configured</div>
        <div class="kpi-value purple">{{ count($currencies) }}</div>
    </div>
</div>

<div class="data-card">
    <div class="data-card-header">
        <i class="bi bi-cash-stack"></i> All Currencies ({{ count($currencies) }})
    </div>
    <div class="table-wrap">
        <table class="ledger">
            <thead>
                <tr>
                    <th style="text-align:left">Code</th>
                    <th style="text-align:left">Symbol</th>
                    <th style="text-align:left">Name</th>
                    <th>Rate → {{ $defaultCur['code'] }}</th>
                    <th style="text-align:center">Default</th>
                    <th style="text-align:center">Status</th>
                    <th style="text-align:center;width:170px">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if (empty($currencies))
            <tr><td colspan="7" style="text-align:center;color:var(--text-muted);padding:36px">
                No currencies yet. <a href="#" data-bs-toggle="modal" data-bs-target="#curModal" style="color:#a78bfa">Add your first →</a>
            </td></tr>
            @endif
            @foreach ($currencies as $c)
            <tr style="{{ !$c['active'] ? 'opacity:.45' : '' }}">
                <td style="font-weight:700;color:#c4b5fd">{{ $c['code'] }}</td>
                <td style="font-size:15px">{{ $c['symbol'] }}</td>
                <td style="text-align:left">{{ $c['name'] }}</td>
                <td>
                    @if ((int)$c['is_default'] === 1)
                    <span style="color:var(--text-muted)">1.000000 <small>(base)</small></span>
                    @else
                    1 {{ $c['code'] }} = {{ number_format((float)$c['rate_to_default'], 4) }} {{ $defaultCur['code'] }}
                    @endif
                </td>
                <td style="text-align:center">
                    @if ((int)$c['is_default'] === 1)
                    <span class="chip-mapped"><i class="bi bi-star-fill"></i> Default</span>
                    @else
                    <form method="post" action="{{ url('/currencies') }}" style="display:inline">
                        <input type="hidden" name="action" value="set_default">
                        <input type="hidden" name="id" value="{{ $c['id'] }}">
                        <button class="btn-sm-custom" style="padding:4px 10px;font-size:11px"
                                data-confirm="Set {{ $c['code'] }} as the default currency? All other rates will be rebased automatically.">
                            Set Default
                        </button>
                    </form>
                    @endif
                </td>
                <td style="text-align:center">
                    {!! $c['active'] ? '<span class="chip-mapped">Active</span>' : '<span class="chip-unmapped">Inactive</span>' !!}
                </td>
                <td style="text-align:center">
                    <div style="display:flex;gap:5px;justify-content:center">
                        <a href="{{ url('/currencies') }}?edit={{ $c['id'] }}" class="btn-sm-custom" title="Edit"><i class="bi bi-pencil"></i></a>
                        @if ((int)$c['is_default'] !== 1)
                        <form method="post" action="{{ url('/currencies') }}" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="{{ $c['id'] }}">
                            <button class="btn-sm-custom" title="Toggle status"><i class="bi bi-{{ $c['active'] ? 'pause' : 'play' }}-circle"></i></button>
                        </form>
                        <form method="post" action="{{ url('/currencies') }}" style="display:inline">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="{{ $c['id'] }}">
                            <button class="btn-danger-custom" data-confirm="Delete '{{ $c['code'] }}'?"><i class="bi bi-trash3"></i></button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="curModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-plus-circle"></i> Add Currency</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" action="{{ url('/currencies') }}">
                <input type="hidden" name="action" value="add">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Code *</label>
                            <input type="text" name="code" class="form-control" maxlength="3" placeholder="EUR" style="text-transform:uppercase" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Symbol *</label>
                            <input type="text" name="symbol" class="form-control" placeholder="€" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" placeholder="Euro" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">
                                Rate to {{ $defaultCur['code'] }} *
                                <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— 1 unit of this currency = ? {{ $defaultCur['code'] }}</small>
                            </label>
                            <input type="number" name="rate_to_default" class="form-control" step="0.0001" min="0.0001" placeholder="e.g. 90.50" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <button type="button" class="btn-sm-custom" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-check-circle"></i> Add Currency</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
@if ($editCur)
<div class="modal fade show" id="editCurModal" tabindex="-1" style="display:block;background:rgba(0,0,0,.75)">
    <div class="modal-dialog">
        <div class="modal-content" style="background:#12122a;border:1px solid #2e2e5a">
            <div class="modal-header" style="border-color:#2e2e5a">
                <h5 class="modal-title" style="color:#fff"><i class="bi bi-pencil"></i> Edit {{ $editCur['code'] }}</h5>
                <a href="{{ url('/currencies') }}" class="btn-close btn-close-white"></a>
            </div>
            <form method="post" action="{{ url('/currencies') }}">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" value="{{ $editCur['id'] }}">
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Code</label>
                            <input type="text" class="form-control" value="{{ $editCur['code'] }}" disabled>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Symbol *</label>
                            <input type="text" name="symbol" class="form-control" value="{{ $editCur['symbol'] }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Name *</label>
                            <input type="text" name="name" class="form-control" value="{{ $editCur['name'] }}" required>
                        </div>
                        <div class="col-12">
                            @if ((int)$editCur['is_default'] === 1)
                            <label class="form-label">Rate</label>
                            <input type="text" class="form-control" value="1.000000 (base — default currency)" disabled>
                            @else
                            <label class="form-label">
                                Rate to {{ $defaultCur['code'] }} *
                                <small style="color:var(--text-muted);font-weight:400;text-transform:none;letter-spacing:0">— 1 {{ $editCur['code'] }} = ? {{ $defaultCur['code'] }}</small>
                            </label>
                            <input type="number" name="rate_to_default" class="form-control" step="0.0001" min="0.0001" value="{{ $editCur['rate_to_default'] }}" required>
                            @endif
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:#2e2e5a">
                    <a href="{{ url('/currencies') }}" class="btn-sm-custom">Cancel</a>
                    <button type="submit" class="btn-primary-custom"><i class="bi bi-floppy"></i> Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection
