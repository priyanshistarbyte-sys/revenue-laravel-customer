@extends('layouts.app')

@section('content')
@include('partials.flash')

<div class="dash-header" style="margin-bottom:14px;flex-wrap:wrap;gap:10px;padding:14px 18px">
    <div>
        <div class="dash-title" style="font-size:1.15rem"><i class="bi bi-megaphone"></i> Meta Campaigns <span style="color:var(--text-muted);font-weight:400;font-size:.85rem">· bulk builder (draft)</span></div>
        <div class="dash-subtitle">Build campaigns in bulk, then publish to Meta. Draft-only for now — nothing is sent to Meta yet.</div>
    </div>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center">
        <a href="{{ url('/accounts') }}" class="btn-sm-custom"><i class="bi bi-arrow-repeat"></i> Sync assets</a>
        <button type="button" id="mcPull" class="btn-sm-custom"><i class="bi bi-cloud-download"></i> Pull from Meta</button>
        <button type="button" id="mcCollapseAll" class="btn-sm-custom"><i class="bi bi-arrows-angle-contract"></i> Collapse all</button>
        <button type="button" id="mcAddRow" class="btn-sm-custom"><i class="bi bi-plus-lg"></i> Add row</button>
        <button type="button" id="mcAdd5" class="btn-sm-custom">+5</button>
        <button type="button" id="mcSave" class="btn-primary-custom"><i class="bi bi-save"></i> Save drafts</button>
    </div>
</div>

@if (empty($adAccounts))
<div style="background:rgba(251,191,36,.1);border:1px solid rgba(251,191,36,.35);color:#fbbf24;border-radius:10px;padding:12px 16px;margin-bottom:12px;font-size:12px">
    <i class="bi bi-exclamation-triangle-fill"></i> No Meta ad accounts synced yet — the Account/Page/Pixel dropdowns will be empty.
    Add a Meta account with an API token on the <a href="{{ url('/accounts') }}" style="color:#a78bfa">Accounts</a> page, then click <strong>Sync assets</strong>.
</div>
@endif

<div class="data-card" style="padding:0">
    <div class="table-wrap" style="overflow:auto;max-height:72vh">
        <table class="ledger" id="mcGrid" style="font-size:11px;white-space:nowrap">
            <thead>
                <tr>
                    <th rowspan="2" style="text-align:center"><input type="checkbox" id="mcSelAll"></th>
                    <th rowspan="2">#</th>
                    <th rowspan="2" style="text-align:left">STATUS</th>
                    <th rowspan="2" style="text-align:center" title="Activate / pause live Meta campaign">LIVE</th>
                    <th rowspan="2" style="text-align:left">ACCOUNT</th>
                    <th rowspan="2" style="text-align:left">FAN PAGE</th>
                    <th rowspan="2" style="text-align:left">PIXEL</th>
                    <th colspan="10" data-group="campaign"  data-full-colspan="10" class="mc-grp" style="color:#60a5fa"><span class="mc-grp-inner">CAMPAIGN <i class="bi bi-chevron-down mc-grp-tog"></i></span></th>
                    <th colspan="15" data-group="adset"     data-full-colspan="15" class="mc-grp" style="color:#fbbf24"><span class="mc-grp-inner">AD SET <i class="bi bi-chevron-down mc-grp-tog"></i></span></th>
                    <th colspan="16" data-group="targeting" data-full-colspan="16" class="mc-grp" style="color:#34d399"><span class="mc-grp-inner">TARGETING <i class="bi bi-chevron-down mc-grp-tog"></i></span></th>
                    <th colspan="13" data-group="creative"  data-full-colspan="13" class="mc-grp" style="color:#22d3ee"><span class="mc-grp-inner">CREATIVE <i class="bi bi-chevron-down mc-grp-tog"></i></span></th>
                    <th colspan="7"  data-group="metaai"    data-full-colspan="7"  class="mc-grp" style="color:#a78bfa"><span class="mc-grp-inner">META AI <i class="bi bi-chevron-down mc-grp-tog"></i></span></th>
                    <th colspan="9"  data-group="multilanguage" data-full-colspan="9" class="mc-grp" style="color:#f472b6"><span class="mc-grp-inner">MULTILANGUAGE <i class="bi bi-chevron-down mc-grp-tog"></i></span></th>
                    <th rowspan="2" style="text-align:center">+SET / +AD</th>
                </tr>
                <tr>
                    {{-- CAMPAIGN (10) --}}
                    <th style="text-align:left">Camp. name</th><th style="text-align:left">Objective</th><th style="text-align:left">Special cat.</th><th style="text-align:left">Budget mode</th><th style="text-align:left">Budget</th><th style="text-align:left">Budget type</th><th style="text-align:left">Bid strategy</th><th style="text-align:left">Bid/ROAS</th><th title="Accelerated delivery">Accel.</th><th title="Advantage+ campaign">ADV+ Camp</th>
                    {{-- AD SET (15) --}}
                    <th style="text-align:left">Ad set name</th><th style="text-align:left">Adset budget</th><th title="Share of budget">Spend %</th><th style="text-align:left">Bid strategy</th><th style="text-align:left">Bid/ROAS</th><th style="text-align:left">Conv. location</th><th style="text-align:left">Performance goal</th><th title="Cost per result goal (cost cap)">Cost/result</th><th style="text-align:left">Billing</th><th style="text-align:left">Event type</th><th style="text-align:left">Attribution</th><th style="text-align:left">Start date</th><th style="text-align:left">Start time</th><th style="text-align:left">End date</th><th style="text-align:left">End time</th>
                    {{-- TARGETING (16) --}}
                    <th style="text-align:left">Country</th><th style="text-align:left">Regions/Cities</th><th>Age min</th><th>Age max</th><th style="text-align:left">Gender</th><th style="text-align:left">Device</th><th style="text-align:left">OS</th><th style="text-align:left">Placement</th><th style="text-align:left">Platforms</th><th style="text-align:left">FB positions</th><th style="text-align:left">IG positions</th><th style="text-align:left">AN positions</th><th style="text-align:left">MSG positions</th><th style="text-align:left">Threads pos.</th><th style="text-align:left">Incl. audience</th><th style="text-align:left">Excl. audience</th>
                    {{-- CREATIVE (13) --}}
                    <th style="text-align:left">Ad name</th><th style="text-align:left">Post</th><th title="Catalog ad">Catalog</th><th title="Multiple creatives">Creatives</th><th title="Stories/Reels">Stories</th><th style="text-align:left">CTA</th><th style="text-align:left">Video</th><th style="text-align:left">Image</th><th style="text-align:left">Destination URL</th><th style="text-align:left">URL params</th><th style="text-align:left">Message</th><th style="text-align:left">Title</th><th style="text-align:left">Description</th>
                    {{-- META AI (7) --}}
                    <th title="Advantage+ features on">AI</th><th title="Advantage+ Audience">A+ Aud</th><th title="Advantage+ Age">A+ Age</th><th title="Advantage+ Gender">A+ Gen</th><th title="Multi-advertiser ads">Multi</th><th title="Advantage+ Creative">A+ Cr</th><th title="Dynamic creative">Dyn</th>
                    {{-- MULTILANGUAGE (9) --}}
                    <th title="Enable multilanguage">ML</th><th style="text-align:left">Default language</th><th style="text-align:left">Secondary languages</th><th style="text-align:left">Video</th><th style="text-align:left">Image</th><th style="text-align:left">Link</th><th style="text-align:left">Message</th><th style="text-align:left">Title</th><th style="text-align:left">Desc.</th>
                </tr>
            </thead>
            <tbody id="mcBody"></tbody>
        </table>
    </div>
</div>

<div style="display:flex;gap:14px;align-items:center;margin-top:10px;font-size:12px;color:var(--text-muted)">
    <span><strong id="mcCount" style="color:#c4b5fd">0</strong> campaigns</span>
    <span><strong id="mcReady" style="color:#4ade80">0</strong> ready</span>
    <span><strong id="mcErr" style="color:#f87171">0</strong> with errors</span>
    <span>Daily budget: <strong id="mcBudget" style="color:#c4b5fd">$0.00</strong></span>
    <span><strong id="mcSelCount" style="color:#c4b5fd">0</strong> selected</span>
    <div id="mcSelActions" style="margin-left:auto;display:none;gap:8px">
        <button type="button" id="mcPubSel" class="btn-sm-custom" style="color:#4ade80"><i class="bi bi-rocket-takeoff"></i> Publish selected</button>
        <button type="button" id="mcDup" class="btn-sm-custom"><i class="bi bi-copy"></i> Duplicate selected</button>
        <button type="button" id="mcDelSel" class="btn-sm-custom"><i class="bi bi-trash3"></i> Delete selected</button>
    </div>
</div>

<form method="post" action="{{ url('/meta_campaigns') }}" id="mcSaveForm" style="display:none">
    <input type="hidden" name="action" value="save" id="mcAction">
    <input type="hidden" name="grid_data" id="mcGridData">
    <input type="hidden" name="publish_idx" id="mcPublishIdx">
</form>

@push('scripts')
<script>
window.MC = {
    adAccounts: @json($adAccounts),
    pages:      @json($pages),
    pixels:     @json($pixels),
    users:      @json($users),
    countries:  @json($countries),
    languages:  @json($languages),
    media:      @json($media),
    objectives: @json($objectives),
    ctas:       @json($ctas),
    bids:       @json($bidStrategies),
    lists:      @json($lists),
    drafts:     @json($drafts),
};
</script>
<script src="{{ asset('assets/js/meta-campaigns.js') }}?v={{ @filemtime(public_path('assets/js/meta-campaigns.js')) ?: time() }}"></script>
@endpush
@endsection
