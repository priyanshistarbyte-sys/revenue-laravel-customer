/* Meta Campaigns — bulk builder grid with nested ad sets / ads (draft only). */
(function () {
    const MC = window.MC || {};
    const body = document.getElementById('mcBody');
    if (!body) return;

    const asOpts = (src) => Array.isArray(src)
        ? src.map(o => [o.value ?? o.id ?? o.act_id ?? '', o.label ?? o.name ?? ''])
        : Object.entries(src || {});

    // Asset labels mirror the reference: "Name (id)" for pages/pixels, "Name (act)"
    // for accounts (already built server-side), "Name (CC)" for countries.
    const pageLbl  = (p) => (p.name || p.id) + ' (' + p.id + ')';
    const pixelLbl = (p) => (p.name || p.id) + ' (' + p.id + ')';

    const ACCTS  = MC.adAccounts.map(a => [a.act_id, a.label]);
    const PAGES  = MC.pages.map(p => [p.id, pageLbl(p)]);
    const PIXELS = MC.pixels.map(p => [p.id, pixelLbl(p)]);
    const COUNTRIES = asOpts(MC.countries).map(([v, l]) => [v, l + ' (' + v + ')']);
    const LANGS = asOpts(MC.languages).map(([v, l]) => [v, l + ' (' + String(v).toUpperCase() + ')']);
    const SAFE_VIDEO = [['safe', 'safe video'], ['original', 'original']];
    const L = MC.lists || {};

    const AI_KEYS = ['adv_audience', 'adv_age', 'adv_gender', 'multi_advertiser', 'adv_creative', 'dynamic_creative'];

    // Each field carries its block group: c = campaign, s = ad set, r = creative.
    const FIELDS = [
        { k: 'account', g: 'c', t: 'sel', o: ACCTS, first: 'Select account' },
        { k: 'page', g: 'c', t: 'sel', o: PAGES, first: 'Select page' },
        { k: 'pixel', g: 'c', t: 'sel', o: PIXELS, first: 'None (no pixel)' },
        { k: 'camp_name', g: 'c', t: 'txt', ph: 'Campaign name', w: 120 },
        { k: 'objective', g: 'c', t: 'sel', o: asOpts(MC.objectives) },
        { k: 'special_category', g: 'c', t: 'sel', o: asOpts(L.special_category) },
        { k: 'budget_mode', g: 'c', t: 'sel', o: asOpts(L.budget_mode), first: 'CBO / ABO', w: 90 },
        { k: 'budget', g: 'c', t: 'num', ph: '$', w: 55 },
        { k: 'budget_type', g: 'c', t: 'sel', o: asOpts(L.budget_type) },
        { k: 'camp_bid_strategy', g: 'c', t: 'sel', o: asOpts(MC.bids) },
        { k: 'camp_bid_roas', g: 'c', t: 'num', ph: '$', w: 55 },
        { k: 'accelerated', g: 'c', t: 'chk' },
        { k: 'adv_campaign', g: 'c', t: 'chk' },
        { k: 'adset_name', g: 's', t: 'txt', ph: 'AdSet 1', w: 90 },
        { k: 'adset_budget', g: 's', t: 'num', ph: '$', w: 55 },
        { k: 'spend_pct', g: 's', t: 'num', ph: '%', w: 45 },
        { k: 'bid_strategy', g: 's', t: 'sel', o: asOpts(MC.bids) },
        { k: 'bid_roas', g: 's', t: 'num', ph: '$', w: 55 },
        { k: 'conv_location', g: 's', t: 'sel', o: asOpts(L.conv_location) },
        { k: 'performance_goal', g: 's', t: 'sel', o: asOpts(L.performance_goal) },
        { k: 'cost_per_result', g: 's', t: 'num', ph: '$X.XX', w: 70 },
        { k: 'billing', g: 's', t: 'sel', o: asOpts(L.billing) },
        { k: 'event_type', g: 's', t: 'sel', o: asOpts(L.event_type) },
        { k: 'attribution', g: 's', t: 'sel', o: asOpts(L.attribution) },
        { k: 'start_date', g: 's', t: 'date', w: 120 },
        { k: 'start_time', g: 's', t: 'time', w: 85 },
        { k: 'end_date', g: 's', t: 'date', w: 120 },
        { k: 'end_time', g: 's', t: 'time', w: 85 },
        { k: 'country', g: 's', t: 'sel', multi: true, o: COUNTRIES, first: 'Select countries', w: 150 },
        { k: 'regions_cities', g: 's', t: 'txt', ph: 'Search region/city', w: 130 },
        { k: 'age_min', g: 's', t: 'num', w: 42 },
        { k: 'age_max', g: 's', t: 'num', w: 42 },
        { k: 'gender', g: 's', t: 'sel', o: [['all', 'All'], ['male', 'Male'], ['female', 'Female']] },
        { k: 'device', g: 's', t: 'sel', o: asOpts(L.device) },
        { k: 'os', g: 's', t: 'sel', o: asOpts(L.os) },
        { k: 'placement', g: 's', t: 'sel', o: asOpts(L.placement) },
        { k: 'platforms', g: 's', t: 'sel', o: asOpts(L.platforms) },
        { k: 'fb_positions', g: 's', t: 'sel', multi: true, o: asOpts(L.fb_positions), first: 'All', w: 130 },
        { k: 'ig_positions', g: 's', t: 'sel', multi: true, o: asOpts(L.ig_positions), first: 'All', w: 130 },
        { k: 'an_positions', g: 's', t: 'sel', multi: true, o: asOpts(L.an_positions), first: 'All', w: 130 },
        { k: 'msg_positions', g: 's', t: 'sel', multi: true, o: asOpts(L.msg_positions), first: 'All', w: 140 },
        { k: 'threads_positions', g: 's', t: 'sel', multi: true, o: asOpts(L.threads_positions), first: 'All', w: 130 },
        { k: 'include_audience', g: 's', t: 'txt', ph: '+ audience', w: 110 },
        { k: 'exclude_audience', g: 's', t: 'txt', ph: '+ audience', w: 110 },
        { k: 'ad_name', g: 'r', t: 'txt', ph: 'Ad 1', w: 80 },
        { k: 'post', g: 'r', t: 'sel', o: asOpts(L.post) },
        { k: 'catalog', g: 'r', t: 'chk' },
        { k: 'creatives', g: 'r', t: 'chk' },
        { k: 'stories_reels', g: 'r', t: 'chk' },
        { k: 'cta', g: 'r', t: 'sel', o: asOpts(MC.ctas) },
        { k: 'video', g: 'r', t: 'media', media: 'video', w: 130 },
        { k: 'image', g: 'r', t: 'media', media: 'image', w: 150 },
        { k: 'dest_url', g: 'r', t: 'txt', ph: 'https://', w: 130 },
        { k: 'url_params', g: 'r', t: 'txt', ph: 'utm_source=…', w: 130 },
        { k: 'message', g: 'r', t: 'multitext', ph: 'Ad text', w: 120 },
        { k: 'title', g: 'r', t: 'multitext', ph: 'Title', w: 100 },
        { k: 'description', g: 'r', t: 'multitext', ph: 'Description', w: 120 },
        { k: 'ai', g: 's', t: 'ai' },
        { k: 'adv_audience', g: 's', t: 'chk' },
        { k: 'adv_age', g: 's', t: 'chk' },
        { k: 'adv_gender', g: 's', t: 'chk' },
        { k: 'multi_advertiser', g: 's', t: 'chk' },
        { k: 'adv_creative', g: 's', t: 'chk' },
        { k: 'dynamic_creative', g: 's', t: 'chk' },
        { k: 'ml', g: 'c', t: 'chk' },
        { k: 'default_language', g: 'c', t: 'sel', o: LANGS, first: 'Language', w: 120 },
        { k: 'secondary_languages', g: 'c', t: 'ml_secondary', o: LANGS, first: 'Languages', w: 150 },
        { k: 'ml_video', g: 'c', t: 'media', media: 'video', w: 130 },
        { k: 'ml_image', g: 'c', t: 'media', media: 'image', w: 130 },
        { k: 'ml_link', g: 'c', t: 'txt', ph: 'https://', w: 120 },
        { k: 'ml_message', g: 'c', t: 'txt', ph: 'Ad text', w: 110 },
        { k: 'ml_title', g: 'c', t: 'txt', ph: 'Title', w: 100 },
        { k: 'ml_desc', g: 'c', t: 'txt', ph: 'Description', w: 110 },
    ];
    const FG = {}; FIELDS.forEach(f => FG[f.k] = f.g);
    // ml_default_video lives inside the composite Secondary-languages cell (not its
    // own FIELDS entry), so register its field group manually for readRow().
    FG['ml_default_video'] = 'c';

    // Linked Meta ids (from pull / previous publish) ride along as hidden fields so
    // a re-publish updates the same objects in place instead of creating new ones.
    const SRC_FIELDS = [
        { k: '_src_campaign_id', g: 'c' },
        { k: '_src_adset_id',    g: 's' },
        { k: '_src_ad_id',       g: 'r' },
        { k: '_src_creative_id', g: 'r' },
    ];
    SRC_FIELDS.forEach(f => FG[f.k] = f.g);

    // Visual column groups for collapse/expand: [name, firstFieldKey, lastFieldKey].
    // ML is a single column — kept here for header sequencing but never collapsible.
    const VGROUPS = [
        ['campaign', 'camp_name', 'adv_campaign'],
        ['adset', 'adset_name', 'end_time'],
        ['targeting', 'country', 'exclude_audience'],
        ['creative', 'ad_name', 'description'],
        ['metaai', 'ai', 'dynamic_creative'],
        ['multilanguage', 'ml', 'ml_desc'],
    ];
    // field key → { group, first } — first sub-column of a group stays visible when collapsed.
    const FVG = {};
    VGROUPS.forEach(([name, sk, ek]) => {
        const si = FIELDS.findIndex(f => f.k === sk), ei = FIELDS.findIndex(f => f.k === ek);
        for (let i = si; i >= 0 && i <= ei; i++) FVG[FIELDS[i].k] = { group: name, first: i === si };
    });

    // Which groups a row type renders. campaign=all, adset=s+r, ad=r only.
    const SHOW = { campaign: 'csr', adset: 'sr', ad: 'r' };

    const DEFAULTS = {
        objective: 'OUTCOME_TRAFFIC', special_category: 'NONE', budget_mode: 'ABO', budget_type: 'DAILY',
        camp_bid_strategy: 'LOWEST_COST_WITHOUT_CAP', adset_name: 'AdSet 1',
        bid_strategy: 'LOWEST_COST_WITHOUT_CAP', conv_location: 'WEBSITE', performance_goal: 'LANDING_PAGE_VIEWS',
        billing: 'IMPRESSIONS', attribution: '7d_click_1d_view', start_time: '12:00',
        country: 'US', age_min: 18, age_max: 65, gender: 'male', device: 'all', os: 'all', placement: 'automatic',
        platforms: 'all', multi_advertiser: true, ad_name: 'Ad 1', post: 'NEW', cta: 'WATCH_MORE',
    };

    let cidSeq = 0, asidSeq = 0;
    const esc = (s) => String(s == null ? '' : s).replace(/"/g, '&quot;');

    // ── Account-aware Page / Pixel scoping ──
    // A page belongs to a Meta business (meta_account_id / "ma"); a pixel belongs
    // to a specific ad account (act_id). Once an account is picked, its row's Page
    // and Pixel dropdowns show only that account's assets.
    const accountMa = (act) => { const a = MC.adAccounts.find(x => x.act_id === act); return a ? a.ma : null; };
    // Pages scoped to the selected ad account: same business, and either global
    // ('' act = directly-managed) or specifically derived from THIS ad account's ads.
    // Graceful fallbacks so the dropdown is never wrongly empty.
    const pagesFor = (act) => {
        if (!act) return MC.pages.map(p => [p.id, pageLbl(p)]);
        const ma = accountMa(act);
        // Pages tied to THIS ad account only. Fall back to the account's business, then
        // to everything, so the dropdown is never wrongly empty when act data is absent.
        let scoped = MC.pages.filter(p => p.act === act);
        if (!scoped.length) scoped = MC.pages.filter(p => p.ma === ma);
        if (!scoped.length) scoped = MC.pages;
        // De-dupe by page id (the same page can appear once per account).
        const seen = new Set();
        scoped = scoped.filter(p => { const k = String(p.id); if (seen.has(k)) return false; seen.add(k); return true; });
        return scoped.map(p => [p.id, pageLbl(p)]);
    };
    const pixelsFor = (act) => (act
        ? MC.pixels.filter(p => p.act === act)
        : MC.pixels).map(p => [p.id, pixelLbl(p)]);

    // ── Searchable dropdowns (combobox) ──
    // Each `sel` field renders a wrapper [data-combo] holding a hidden
    // <input data-field> (the real value read on save) plus a button that opens a
    // type-to-filter popover. Options live in OPTS; page/pixel get a per-row
    // override (wrap._opts) from applyAccountFilter.
    const OPTS = {}, PH = {};
    FIELDS.forEach(f => { if (f.t === 'sel') { OPTS[f.k] = f.o || []; PH[f.k] = f.first; } });
    // Comboboxes rendered inside the composite Secondary-languages cell.
    OPTS['secondary_languages'] = LANGS;    PH['secondary_languages'] = 'Languages';
    OPTS['ml_default_video']    = SAFE_VIDEO; PH['ml_default_video']    = 'safe video';

    const comboEl    = (row, field) => row.querySelector(`[data-combo="${field}"]`);
    const comboInput = (wrap) => wrap && wrap.querySelector('input[data-field]');
    const comboValue = (row, field) => { const i = comboInput(comboEl(row, field)); return i ? i.value : ''; };
    const comboOpts  = (wrap) => wrap._opts || OPTS[wrap.dataset.combo] || [];

    const labelFor = (opts, val, ph) => {
        if (val === '' || val == null) return ph || 'Select…';
        const hit = opts.find(([v]) => String(v) === String(val));
        return hit ? hit[1] : String(val);
    };
    // Multi-select value is a comma-joined list of codes ("IN,US"). Show up to two
    // names, then "N selected".
    const csvVals = (val) => String(val || '').split(',').map(s => s.trim()).filter(Boolean);
    const multiLabel = (opts, val, ph) => {
        const vals = csvVals(val);
        if (!vals.length) return ph || 'Select…';
        const names = vals.map(v => { const h = opts.find(([o]) => String(o) === v); return h ? h[1] : v; });
        return names.length <= 2 ? names.join(', ') : names.length + ' selected';
    };
    function updateComboLabel(wrap) {
        const lbl = wrap.querySelector('.mc-combo-label');
        const val = comboInput(wrap).value;
        const opts = comboOpts(wrap), ph = PH[wrap.dataset.combo];
        if (wrap.dataset.multi) {
            lbl.textContent = multiLabel(opts, val, ph);
            lbl.classList.toggle('is-ph', csvVals(val).length === 0);
        } else {
            lbl.textContent = labelFor(opts, val, ph);
            lbl.classList.toggle('is-ph', val === '' || val == null);
        }
    }
    // Replace a combobox's option set, dropping the value if no longer offered.
    function setComboOptions(wrap, opts) {
        if (!wrap) return;
        wrap._opts = opts;
        const inp = comboInput(wrap);
        if (inp.value && !opts.some(([v]) => String(v) === String(inp.value))) inp.value = '';
        updateComboLabel(wrap);
    }

    // Rescope one campaign row's Page/Pixel dropdowns to its selected account.
    function applyAccountFilter(row) {
        if (!row || row.dataset.rowtype !== 'campaign') return;
        const act = comboValue(row, 'account');
        setComboOptions(comboEl(row, 'page'), pagesFor(act));
        setComboOptions(comboEl(row, 'pixel'), pixelsFor(act));
    }

    // Keep a pulled page/pixel value even when it isn't in the synced asset list —
    // applyAccountFilter() would otherwise drop it. Adds it as an option and selects it.
    function keepImportedAsset(row, data, field) {
        const want = data && data[field];
        if (!want) return;
        const wrap = comboEl(row, field), inp = wrap && comboInput(wrap);
        if (!inp || String(inp.value) === String(want)) return;
        if (!(wrap._opts || []).some(([v]) => String(v) === String(want))) {
            wrap._opts = (wrap._opts || []).concat([[String(want), String(want)]]);
        }
        inp.value = String(want);
        updateComboLabel(wrap);
    }

    // ── Cascading enablement ── Page/Pixel need an Account.
    const DEP = { page: 'account', pixel: 'account' };
    function updateCascade(row, clear) {
        if (!row || row.dataset.rowtype !== 'campaign') return;
        for (const field in DEP) {
            const wrap = comboEl(row, field); if (!wrap) continue;
            const off = !comboValue(row, DEP[field]);
            wrap.querySelector('.mc-combo-btn').disabled = off;
            wrap.classList.toggle('is-disabled', off);
            if (off && clear && comboInput(wrap).value) { comboInput(wrap).value = ''; updateComboLabel(wrap); }
        }
    }

    // ── Media cell (Video / Image) ── opens the Creative-Hub picker modal.
    const MEDIA_ICON = { video: 'bi-camera-video', image: 'bi-image' };
    function mediaBtnInner(kind, val) {
        const ids = csvVals(val);
        if (!ids.length) return `<i class="bi ${MEDIA_ICON[kind]}"></i> <span class="mc-media-ph">Select ${kind}</span>`;
        return `<span class="mc-media-count">${ids.length} ${kind}${ids.length > 1 ? 's' : ''}</span><span class="mc-media-add">+ Add</span>`;
    }
    function updateMediaBtn(wrap) {
        const inp = wrap.querySelector('input[data-field]');
        wrap.querySelector('.mc-media-btn').innerHTML = mediaBtnInner(wrap.dataset.media, inp.value);
    }

    function control(f, val, data) {
        // Controls fill their cell; the column's min-width is set on the <td> in
        // buildRow (from f.w), so widths stay sensible without padding around them.
        const w = 'width:100%';
        if (f.t === 'media') {
            return `<div class="mc-media" data-media="${f.media}" style="${w}">`
                 + `<input type="hidden" data-field="${f.k}" value="${esc(val)}">`
                 + `<button type="button" class="mc-media-btn">${mediaBtnInner(f.media, val)}</button></div>`;
        }
        if (f.t === 'chk') return `<input type="checkbox" data-field="${f.k}" ${val ? 'checked' : ''} style="accent-color:var(--purple)">`;
        if (f.t === 'ai') return `<span class="mc-ai" style="color:#a78bfa;font-variant-numeric:tabular-nums">0/6</span>`;
        // Composite Secondary-languages cell: multi language combo + a "Meta layout"
        // badge and a "Default video" combo, shown when ML (multilanguage) is on.
        if (f.t === 'ml_secondary') {
            const sec = control({ k: 'secondary_languages', t: 'sel', multi: true, o: LANGS, first: 'Languages', w: f.w }, val);
            const vid = control({ k: 'ml_default_video', t: 'sel', o: SAFE_VIDEO, first: 'safe video', w: 90 }, (data && data.ml_default_video) || '');
            return `<div class="mc-ml-sec">${sec}`
                 + `<div class="mc-ml-extra" style="display:none">`
                 + `<span class="mc-ml-layout">Meta layout: <b>—</b></span>`
                 + `<span class="mc-ml-video">Default: ${vid}</span>`
                 + `</div></div>`;
        }
        // Multiple text variations (Message / Title / Description "+"). The hidden
        // input stores all variations newline-joined; the visible input edits the
        // first, and the "+" opens a small editor for the rest.
        if (f.t === 'multitext') {
            const lines = String(val || '').split('\n');
            const cnt = lines.filter(x => x.trim()).length;
            return `<div class="mc-multi" style="${w}">`
                 + `<input type="hidden" data-field="${f.k}" value="${esc(val)}">`
                 + `<input type="text" class="mc-mt-first" value="${esc(lines[0] || '')}" placeholder="${esc(f.ph || '')}">`
                 + `<button type="button" class="mc-mt-add" title="Add variation">+<span class="mc-mt-badge">${cnt > 1 ? ('+' + (cnt - 1)) : ''}</span></button>`
                 + `</div>`;
        }
        if (f.t === 'sel') {
            const isPh = f.multi ? csvVals(val).length === 0 : (val === '' || val == null);
            const lbl = f.multi ? multiLabel(f.o || [], val, f.first) : labelFor(f.o || [], val, f.first);
            return `<div class="mc-combo" data-combo="${f.k}"${f.multi ? ' data-multi="1"' : ''} style="${w}">`
                 + `<input type="hidden" data-field="${f.k}" value="${esc(val)}">`
                 + `<button type="button" class="mc-combo-btn">`
                 + `<span class="mc-combo-label ${isPh ? 'is-ph' : ''}">${esc(lbl)}</span>`
                 + `<span class="mc-combo-caret">▾</span></button></div>`;
        }
        if (f.t === 'date' || f.t === 'time') return `<input type="${f.t}" data-field="${f.k}" value="${esc(val)}" class="form-control" style="font-size:11px;padding:1px 3px;${w}">`;
        const type = f.t === 'num' ? 'number' : 'text';
        return `<input type="${type}" data-field="${f.k}" value="${esc(val)}" placeholder="${esc(f.ph || '')}" class="form-control" style="font-size:11px;padding:1px 4px;${w}">`;
    }

    function actionsHtml(type) {
        const set = `<button type="button" class="icon-btn mc-addset" title="Add ad set">+SET</button>`;
        const ad  = `<button type="button" class="icon-btn mc-addad" title="Add ad">+AD</button>`;
        const pub = `<button type="button" class="icon-btn mc-pub" title="Publish to Meta (goes live/ACTIVE on approval)" style="color:#4ade80"><i class="bi bi-rocket-takeoff"></i></button>`;
        const del = `<button type="button" class="icon-btn danger mc-del" title="Delete"><i class="bi bi-trash3"></i></button>`;
        if (type === 'campaign') return set + ad + pub + del;
        if (type === 'adset') return ad + del;
        return del;
    }

    function buildRow(type, cid, asid, data) {
        data = Object.assign({}, DEFAULTS, data || {});
        const show = SHOW[type];
        const tr = document.createElement('tr');
        tr.dataset.rowtype = type; tr.dataset.cid = cid; tr.dataset.asid = asid;
        if (type !== 'campaign') tr.style.background = 'rgba(167,139,250,.04)';
        if (type === 'campaign' && data._status === 'published') {
            tr.dataset.pubStatus = 'published';
            if (data._meta) tr.dataset.pubMeta = JSON.stringify(data._meta);
            tr.dataset.pubInfo = 'Meta campaign ' + ((data._meta && data._meta.campaign_id) || '?');
        } else if (type === 'campaign' && data._status === 'failed') {
            tr.dataset.pubStatus = 'failed';
            tr.dataset.pubInfo = data._error || 'Publish failed';
        }

        const label = type === 'campaign'
            ? `<span class="mc-status" style="font-size:10px;color:var(--text-muted)">Empty</span>`
            : `<span style="font-size:10px;color:#a78bfa">↳ ${type === 'adset' ? 'Set' : 'Ad'}</span>`;

        let hiddenSrc = '';
        SRC_FIELDS.forEach(f => { if (show.includes(f.g) && data[f.k]) hiddenSrc += `<input type="hidden" data-field="${f.k}" value="${esc(data[f.k])}">`; });
        const linked = type === 'campaign' && !!data._src_campaign_id;
        if (linked) { tr.dataset.linked = '1'; tr.classList.add('mc-linked'); }
        const linkIcon = linked
            ? ` <i class="bi bi-link-45deg mc-linkicon" title="Linked to Meta — re-publishing updates this campaign in place"></i>`
            : '';

        let html = `<td style="text-align:center"><input type="checkbox" class="mc-sel">${hiddenSrc}</td>`
                 + `<td class="c-muted"><span class="mc-num"></span>${linkIcon}</td>`
                 + `<td>${label}</td>`
                 + `<td class="mc-live" style="text-align:center"></td>`;
        for (const f of FIELDS) {
            const vg = FVG[f.k];
            const cls = (vg && !vg.first) ? ` class="mcx-${vg.group}"` : '';
            const mw = f.w ? `min-width:${f.w}px;` : '';
            html += show.includes(f.g)
                ? `<td${cls}${mw ? ` style="${mw}"` : ''}>${control(f, data[f.k], data)}</td>`
                : `<td${cls} style="${mw}background:rgba(255,255,255,.015)"></td>`;
        }
        html += `<td style="white-space:nowrap;text-align:center">${actionsHtml(type)}</td>`;
        tr.innerHTML = html;
        updateAi(tr);
        updateMlState(tr);
        return tr;
    }

    // ── MULTILANGUAGE ──
    // The ML checkbox is the master toggle: off → the language controls are greyed,
    // and the badge + default-video row is hidden. Enabling seeds sensible defaults.
    const ML_LOCK = ['default_language', 'secondary_languages', 'ml_default_video',
        'ml_video', 'ml_image', 'ml_link', 'ml_message', 'ml_title', 'ml_desc'];
    function updateMlState(row) {
        const ml = row.querySelector('[data-field="ml"]');
        if (!ml) return;                       // only campaign rows carry the ML fields
        const on = ml.checked;
        ML_LOCK.forEach(field => {
            const wrap = comboEl(row, field);
            const media = row.querySelector(`.mc-media input[data-field="${field}"]`);
            if (wrap) {                        // combobox field
                const btn = wrap.querySelector('.mc-combo-btn'); if (btn) btn.disabled = !on;
                wrap.classList.toggle('is-disabled', !on);
                if (!on) { const inp = comboInput(wrap); if (inp && inp.value) { inp.value = ''; updateComboLabel(wrap); } }
            } else if (media) {                // media cell (Video / Image)
                const mw = media.closest('.mc-media');
                mw.classList.toggle('is-disabled', !on);
                if (!on && media.value) { media.value = ''; updateMediaBtn(mw); }
            } else {                           // plain text input
                const inp = row.querySelector(`input[data-field="${field}"]`);
                if (inp) { inp.disabled = !on; inp.classList.toggle('mc-disabled', !on); if (!on && inp.value) inp.value = ''; }
            }
        });
        if (on) {
            const dl = comboEl(row, 'default_language'), dli = dl && comboInput(dl);
            if (dli && !dli.value) { dli.value = 'en'; updateComboLabel(dl); }
            const sv = comboEl(row, 'ml_default_video'), svi = sv && comboInput(sv);
            if (svi && !svi.value) { svi.value = 'safe'; updateComboLabel(sv); }
        }
        const extra = row.querySelector('.mc-ml-extra');
        if (extra) extra.style.display = on ? '' : 'none';
        updateMlLayout(row);
    }

    // "Meta layout: <DEFAULT>-target" badge follows the default language.
    function updateMlLayout(row) {
        const b = row.querySelector('.mc-ml-layout b'); if (!b) return;
        const dl = comboValue(row, 'default_language');
        b.textContent = dl ? (String(dl).toUpperCase() + '-target') : '—';
    }

    function updateAi(tr) {
        const ai = tr.querySelector('.mc-ai'); if (!ai) return;
        let n = 0; AI_KEYS.forEach(k => { const el = tr.querySelector(`[data-field="${k}"]`); if (el && el.checked) n++; });
        ai.textContent = n + '/6';
    }
    // ── Validation ──
    const num = (v) => { const n = parseFloat(v); return isFinite(n) ? n : 0; };
    const isUrl = (v) => /^https?:\/\/.+/i.test(String(v || '').trim());
    const has = (v) => String(v == null ? '' : v).trim() !== '';

    // A campaign counts as "empty" (untouched) until it has an account, a name,
    // or a fan page — so the 50 blank starter rows don't scream errors.
    function isEmptyObj(C) {
        return !has(C.account) && !has(C.camp_name) && !has(C.page);
    }

    // Returns an array of human-readable problems for one campaign object
    // (the nested shape serializeCampaign() produces).
    function validateObj(C) {
        const e = [];
        if (!has(C.account)) e.push('Ad account required');
        if (!has(C.camp_name)) e.push('Campaign name required');
        if (!has(C.page)) e.push('Fan page required');

        const mode = String(C.budget_mode || '').toUpperCase();
        const cbo = mode === 'CBO' ? true : (mode === 'ABO' ? false : num(C.budget) > 0);
        if (cbo && !(num(C.budget) > 0)) e.push('Campaign budget required (CBO)');
        (C.adsets || []).forEach((a, i) => {
            const n = 'Ad set ' + (i + 1) + ': ';
            if (!has(a.adset_name)) e.push(n + 'name required');
            if (!has(a.country)) e.push(n + 'country required');
            const lo = num(a.age_min), hi = num(a.age_max);
            if (lo && hi && lo > hi) e.push(n + 'age min is above age max');
            if (!cbo && !(num(a.adset_budget) > 0)) e.push(n + 'budget required (ABO — set it here or switch to CBO)');

            (a.ads || []).forEach((ad, j) => {
                const m = 'Ad ' + (i + 1) + '.' + (j + 1) + ': ';
                if (!has(ad.ad_name)) e.push(m + 'name required');
                if (!has(ad.dest_url)) e.push(m + 'destination URL required');
                else if (!isUrl(ad.dest_url)) e.push(m + 'URL must start with http:// or https://');
            });
        });
        return e;
    }

    const campRowByCid = (cid) => body.querySelector(`tr[data-rowtype="campaign"][data-cid="${CSS.escape(cid)}"]`);
    // Ads Manager deep-link to a published campaign.
    function adsManagerUrl(act, campaignId) {
        if (!campaignId) return '';
        const num = String(act || '').replace(/^act_/, '');
        if (!num) return '';
        return 'https://business.facebook.com/adsmanager/manage/campaigns?act=' + encodeURIComponent(num) +
               '&selected_campaign_ids=' + encodeURIComponent(campaignId);
    }
    // Editing a published/failed campaign reverts its STATUS to live validation.
    function clearPub(cid) {
        const cr = campRowByCid(cid);
        if (cr) { delete cr.dataset.pubStatus; delete cr.dataset.pubInfo; delete cr.dataset.pubMeta; }
    }
    // Carry a campaign's published/failed state through serialize() so a plain Save
    // doesn't drop it.
    function attachPub(tr, C) {
        if (tr.dataset.pubStatus === 'published') {
            C._status = 'published';
            if (tr.dataset.pubMeta) { try { C._meta = JSON.parse(tr.dataset.pubMeta); } catch (e) {} }
        } else if (tr.dataset.pubStatus === 'failed') {
            C._status = 'failed'; C._error = tr.dataset.pubInfo || '';
        }
    }
    // Drop all Meta linkage from a campaign object so a duplicate publishes as a NEW
    // campaign (rather than updating the original it was copied from).
    function stripMetaLinks(C) {
        delete C._src_campaign_id; delete C._meta; delete C._status; delete C._error; delete C._id; delete C._imported;
        (C.adsets || []).forEach(a => {
            delete a._src_adset_id;
            (a.ads || []).forEach(ad => { delete ad._src_ad_id; delete ad._src_creative_id; });
        });
        return C;
    }

    // Recompute the STATUS badge on the campaign row for `cid`.
    function revalidate(cid) {
        if (!cid) return;
        const campRow = body.querySelector(`tr[data-rowtype="campaign"][data-cid="${CSS.escape(cid)}"]`);
        const st = campRow && campRow.querySelector('.mc-status');
        if (!st) return;
        const live = campRow.querySelector('.mc-live');
        if (live) live.innerHTML = '';   // only published rows show a toggle
        st.style.padding = '1px 6px'; st.style.borderRadius = '4px'; st.style.fontWeight = '600'; st.style.whiteSpace = 'nowrap';
        if (campRow.dataset.pubStatus === 'published') {
            let meta = {}; try { meta = JSON.parse(campRow.dataset.pubMeta || '{}'); } catch (e) {}
            const campId = meta.campaign_id || '';
            const on = String(meta.status || 'ACTIVE').toUpperCase() === 'ACTIVE';
            const url = adsManagerUrl(comboValue(campRow, 'account'), campId);
            const link = url
                ? ` <a href="${url}" target="_blank" rel="noopener" class="mc-pub-link" title="View in Ads Manager"><i class="bi bi-box-arrow-up-right"></i></a>`
                : '';
            // Reasons Meta gave (issues_info / ad_review_feedback); fall back to a bad
            // effective_status so the row still flags a delivery problem.
            let reasons = Array.isArray(meta.issues) ? meta.issues.filter(Boolean) : [];
            const eff = String(meta.effective_status || '').toUpperCase();
            const badEff = eff && !['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'].includes(eff);
            if (!reasons.length && badEff) reasons = [eff.replace(/_/g, ' ')];

            if (reasons.length) {
                st.style.background = 'rgba(251,191,36,.15)'; st.style.color = '#fbbf24'; st.style.cursor = 'help';
                st.classList.add('mc-has-errors'); st.dataset.errs = reasons.join('\n'); st.dataset.tipLabel = 'issue';
                st.removeAttribute('title');
                st.innerHTML = `⚠ ${reasons.length} issue${reasons.length > 1 ? 's' : ''}${link}`;
            } else {
                st.style.background = 'rgba(34,197,94,.15)'; st.style.color = '#4ade80'; st.style.cursor = 'default';
                st.classList.remove('mc-has-errors'); delete st.dataset.errs; delete st.dataset.tipLabel;
                st.title = campRow.dataset.pubInfo || 'Published';
                st.innerHTML = `✓ Published${link}`;
            }
            if (live) {
                live.innerHTML = `<label class="mc-toggle ${on ? 'is-on' : 'is-off'}" title="${on ? 'Active on Meta — click to pause' : 'Paused on Meta — click to activate'}">`
                    + `<input type="checkbox" class="mc-toggle-cb"${on ? ' checked' : ''}>`
                    + `<span class="mc-toggle-track"><span class="mc-toggle-thumb"></span></span></label>`;
            }
            updateFooter(); return;
        }
        if (campRow.dataset.pubStatus === 'failed') {
            st.textContent = '⚠ Publish failed'; st.style.background = 'rgba(239,68,68,.15)'; st.style.color = '#f87171';
            st.style.cursor = 'help'; st.removeAttribute('title');
            st.classList.add('mc-has-errors'); st.dataset.errs = campRow.dataset.pubInfo || 'Publish failed';
            updateFooter(); return;
        }
        const C = serializeCampaign(cid);
        st.style.cursor = 'default';
        st.classList.remove('mc-has-errors'); delete st.dataset.errs; st.removeAttribute('title');
        if (!C || isEmptyObj(C)) {
            st.textContent = 'Empty'; st.style.background = 'transparent';
            st.style.color = 'var(--text-muted)'; st.style.cursor = 'default';
        } else {
            const errs = validateObj(C);
            if (errs.length) {
                st.textContent = '⚠ ' + errs.length + (errs.length === 1 ? ' error' : ' errors');
                st.style.background = 'rgba(239,68,68,.15)'; st.style.color = '#f87171'; st.style.cursor = 'help';
                st.classList.add('mc-has-errors'); st.dataset.errs = errs.join('\n');
            } else {
                st.textContent = '✓ Ready';
                st.style.background = 'rgba(34,197,94,.15)'; st.style.color = '#4ade80'; st.style.cursor = 'default';
            }
        }
        updateFooter();
    }

    function revalidateAll() {
        body.querySelectorAll('tr[data-rowtype="campaign"]').forEach(tr => revalidate(tr.dataset.cid));
    }

    // Footer roll-up: how many campaigns are ready / have errors, and the total
    // daily budget across all campaign rows.
    function updateFooter() {
        let ready = 0, err = 0, budget = 0;
        body.querySelectorAll('tr[data-rowtype="campaign"]').forEach(tr => {
            const st = tr.querySelector('.mc-status');
            if (st) { if (st.textContent[0] === '✓') ready++; else if (st.textContent[0] === '⚠') err++; }
            const b = tr.querySelector('[data-field="budget"]');
            if (b) budget += num(b.value);
        });
        const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
        set('mcReady', ready); set('mcErr', err);
        set('mcBudget', '$' + budget.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
    }

    function lastRow(attr, val) {
        const rows = body.querySelectorAll(`[data-${attr}="${CSS.escape(val)}"]`);
        return rows.length ? rows[rows.length - 1] : null;
    }

    // ── Builders ──
    function addCampaign(C) {
        C = C || {};
        const cid = 'c' + (++cidSeq), asid0 = cid + '-a' + (++asidSeq);
        const adsets = (C.adsets && C.adsets.length) ? C.adsets : [{}];
        const a0 = adsets[0] || {};
        const first = Object.assign({}, C, a0, (a0.ads && a0.ads[0]) || {});
        delete first.adsets; delete first.ads;
        const crow = buildRow('campaign', cid, asid0, first);
        body.appendChild(crow);
        applyAccountFilter(crow);
        keepImportedAsset(crow, first, 'page');
        keepImportedAsset(crow, first, 'pixel');
        updateCascade(crow, false);

        ((a0.ads || []).slice(1)).forEach(ad => addAdRow(cid, asid0, ad));
        adsets.slice(1).forEach(A => {
            const asid = cid + '-a' + (++asidSeq);
            const arow = Object.assign({}, A, (A.ads && A.ads[0]) || {}); delete arow.ads;
            insertAfter(lastRow('cid', cid), buildRow('adset', cid, asid, arow));
            ((A.ads || []).slice(1)).forEach(ad => addAdRow(cid, asid, ad));
        });
        renumber();
        revalidate(cid);
    }
    function addAdsetRow(cid) {
        const asid = cid + '-a' + (++asidSeq);
        insertAfter(lastRow('cid', cid), buildRow('adset', cid, asid, {}));
        renumber();
        revalidate(cid);
    }
    function addAdRow(cid, asid, data) {
        insertAfter(lastRow('asid', asid) || lastRow('cid', cid), buildRow('ad', cid, asid, data || {}));
        renumber();
        revalidate(cid);
    }
    function insertAfter(ref, node) { ref && ref.parentNode ? ref.after(node) : body.appendChild(node); }

    // ── Serialize ──
    function readRow(tr) {
        const out = { c: {}, s: {}, r: {} };
        tr.querySelectorAll('[data-field]').forEach(el => {
            out[FG[el.dataset.field]][el.dataset.field] = el.type === 'checkbox' ? el.checked : el.value;
        });
        out.s.ai = AI_KEYS.reduce((a, k) => a + (out.s[k] ? 1 : 0), 0) + '/6';
        return out;
    }
    function serialize() {
        const camps = []; const cMap = {}, aMap = {};
        [...body.querySelectorAll('tr')].forEach(tr => {
            const b = readRow(tr), type = tr.dataset.rowtype, cid = tr.dataset.cid, asid = tr.dataset.asid;
            if (type === 'campaign') {
                const A0 = Object.assign({}, b.s, { ads: [b.r] });
                const C = Object.assign({}, b.c, { adsets: [A0] });
                attachPub(tr, C);
                camps.push(C); cMap[cid] = C; aMap[asid] = A0;
            } else if (type === 'adset') {
                const A = Object.assign({}, b.s, { ads: [b.r] });
                (cMap[cid] || { adsets: [] }).adsets.push(A); aMap[asid] = A;
            } else if (type === 'ad') {
                (aMap[asid] || { ads: [] }).ads.push(b.r);
            }
        });
        return camps;
    }

    // Serialize a single campaign (by cid) into the same nested shape addCampaign()
    // consumes — used to duplicate a campaign with all its ad sets / ads.
    function serializeCampaign(cid) {
        let C = null; const aMap = {};
        body.querySelectorAll(`tr[data-cid="${CSS.escape(cid)}"]`).forEach(tr => {
            const b = readRow(tr), type = tr.dataset.rowtype, asid = tr.dataset.asid;
            if (type === 'campaign') {
                const A0 = Object.assign({}, b.s, { ads: [b.r] });
                C = Object.assign({}, b.c, { adsets: [A0] });
                attachPub(tr, C);
                aMap[asid] = A0;
            } else if (type === 'adset' && C) {
                const A = Object.assign({}, b.s, { ads: [b.r] });
                C.adsets.push(A); aMap[asid] = A;
            } else if (type === 'ad') {
                (aMap[asid] || { ads: [] }).ads.push(b.r);
            }
        });
        return C;
    }

    // ── Autosave ── snapshot the grid to localStorage so a page refresh restores
    // in-progress work even without clicking "Save drafts". Cleared on server save.
    const AUTOSAVE_KEY = 'mc-autosave';
    let autosaveT = null;
    function autosave() {
        clearTimeout(autosaveT);
        autosaveT = setTimeout(() => { try { localStorage.setItem(AUTOSAVE_KEY, JSON.stringify(serialize())); } catch (e) {} }, 400);
    }

    function renumber() {
        let n = 0;
        [...body.querySelectorAll('tr')].forEach(tr => {
            tr.querySelector('.mc-num').textContent = tr.dataset.rowtype === 'campaign' ? (++n) : '';
        });
        document.getElementById('mcCount').textContent = n;
        updateSelCount();
        updateFreezeOffsets();
        autosave();
    }

    // Frozen columns (checkbox / # / status) need exact left offsets. Their widths
    // depend on content, so measure the header cells and expose the cumulative
    // offsets as CSS variables the stylesheet reads for columns 2 & 3.
    function updateFreezeOffsets() {
        const grid = document.getElementById('mcGrid');
        const head = grid && grid.tHead;
        if (!head || !head.rows[0]) return;
        const cells = head.rows[0].cells;
        const w1 = cells[0] ? cells[0].offsetWidth : 0;
        const w2 = cells[1] ? cells[1].offsetWidth : 0;
        const w3 = cells[2] ? cells[2].offsetWidth : 0;   // STATUS column
        grid.style.setProperty('--mc-fz2', w1 + 'px');
        grid.style.setProperty('--mc-fz3', (w1 + w2) + 'px');
        grid.style.setProperty('--mc-fz4', (w1 + w2 + w3) + 'px');   // LIVE column
    }
    window.addEventListener('resize', updateFreezeOffsets);

    // ── Collapsible column groups ──
    // Tag the second header row's non-first sub-cells so CSS can hide them.
    function tagHeaderGroups() {
        const grid = document.getElementById('mcGrid');
        const row2 = grid && grid.tHead && grid.tHead.rows[1];
        if (!row2) return;
        let idx = 0;
        VGROUPS.forEach(([name, sk, ek]) => {
            const si = FIELDS.findIndex(f => f.k === sk), ei = FIELDS.findIndex(f => f.k === ek);
            const n = ei - si + 1;
            for (let i = 0; i < n; i++) {
                const cell = row2.cells[idx++];
                if (cell && i > 0) cell.classList.add('mcx-' + name);
            }
        });
    }

    const GROUP_LS_KEY = 'mc-collapsed-groups';
    const allGroups = () => [...document.getElementById('mcGrid').tHead.querySelectorAll('th[data-group]')].map(th => th.dataset.group);
    const isCollapsed = (g) => document.getElementById('mcGrid').classList.contains('mcc-' + g);

    // Set one group's collapsed state (no toggle) — shared by click, collapse-all, and restore.
    function setGroupCollapsed(group, collapsed) {
        const grid = document.getElementById('mcGrid');
        const th = grid.tHead.querySelector('th[data-group="' + group + '"]');
        grid.classList.toggle('mcc-' + group, collapsed);
        if (th) {
            th.colSpan = collapsed ? 1 : (parseInt(th.dataset.fullColspan, 10) || 1);
            const tog = th.querySelector('.mc-grp-tog');
            if (tog) {
                tog.classList.toggle('bi-chevron-down', !collapsed);
                tog.classList.toggle('bi-chevron-right', collapsed);
            }
        }
    }

    function saveCollapsed() {
        try { localStorage.setItem(GROUP_LS_KEY, JSON.stringify(allGroups().filter(isCollapsed))); } catch (e) {}
    }
    function loadCollapsed() {
        try { return new Set(JSON.parse(localStorage.getItem(GROUP_LS_KEY) || '[]')); } catch (e) { return new Set(); }
    }

    // Reflect state on the "Collapse all / Expand all" toolbar button.
    function updateCollapseAllBtn() {
        const btn = document.getElementById('mcCollapseAll'); if (!btn) return;
        const groups = allGroups();
        const allDone = groups.length > 0 && groups.every(isCollapsed);
        btn.innerHTML = allDone
            ? '<i class="bi bi-arrows-angle-expand"></i> Expand all'
            : '<i class="bi bi-arrows-angle-contract"></i> Collapse all';
    }

    function toggleGroup(group) {
        setGroupCollapsed(group, !isCollapsed(group));
        saveCollapsed();
        updateCollapseAllBtn();
        updateFreezeOffsets();
    }

    (function () {
        const grid = document.getElementById('mcGrid');
        if (grid && grid.tHead) {
            grid.tHead.addEventListener('click', (e) => {
                const th = e.target.closest('th[data-group]');
                if (th) toggleGroup(th.dataset.group);
            });
        }
        tagHeaderGroups();

        // Restore collapsed groups from the last visit.
        const saved = loadCollapsed();
        allGroups().forEach(g => { if (saved.has(g)) setGroupCollapsed(g, true); });
        updateCollapseAllBtn();

        // Collapse all / Expand all toolbar button.
        const caBtn = document.getElementById('mcCollapseAll');
        if (caBtn) caBtn.addEventListener('click', () => {
            const groups = allGroups();
            const collapse = !groups.every(isCollapsed);   // if any expanded → collapse all; else expand all
            groups.forEach(g => setGroupCollapsed(g, collapse));
            saveCollapsed();
            updateCollapseAllBtn();
            updateFreezeOffsets();
        });
    })();
    function updateSelCount() {
        const n = body.querySelectorAll('.mc-sel:checked').length;
        document.getElementById('mcSelCount').textContent = n;
        const acts = document.getElementById('mcSelActions');
        if (acts) acts.style.display = n > 0 ? 'flex' : 'none';
    }

    // ── Toolbar ──
    document.getElementById('mcAddRow').addEventListener('click', () => addCampaign());
    document.getElementById('mcAdd5').addEventListener('click', () => { for (let i = 0; i < 5; i++) addCampaign(); });
    document.getElementById('mcSelAll').addEventListener('change', function () {
        body.querySelectorAll('.mc-sel').forEach(c => c.checked = this.checked); updateSelCount();
    });
    document.getElementById('mcDelSel').addEventListener('click', () => {
        // delete selected campaign rows with all their children
        const selected = [...body.querySelectorAll('tr[data-rowtype="campaign"]')]
            .filter(tr => tr.querySelector('.mc-sel:checked'));
        if (!selected.length) return;
        if (!confirm('Delete ' + selected.length + ' selected campaign' + (selected.length > 1 ? 's' : '') + '? This cannot be undone.')) return;
        selected.forEach(tr => body.querySelectorAll(`[data-cid="${CSS.escape(tr.dataset.cid)}"]`).forEach(r => r.remove()));
        renumber();
        updateSelCount();
        autosave();
    });

    document.getElementById('mcDup').addEventListener('click', () => {
        // duplicate each selected campaign (with its ad sets / ads) N times,
        // appending -1, -2, … to the campaign name of each copy.
        const selected = [...body.querySelectorAll('tr[data-rowtype="campaign"]')]
            .filter(tr => tr.querySelector('.mc-sel:checked'));
        if (!selected.length) return;

        const ans = prompt('Duplicate each selected campaign how many times?', '1');
        if (ans === null) return;
        const times = Math.max(1, Math.min(100, parseInt(ans, 10) || 0));
        if (!times) return;

        selected.forEach(tr => {
            const data = serializeCampaign(tr.dataset.cid);
            if (!data) return;
            const base = String(data.camp_name || '').trim();
            for (let i = 1; i <= times; i++) {
                const copy = stripMetaLinks(JSON.parse(JSON.stringify(data)));   // a duplicate is a NEW campaign
                copy.camp_name = base + '-' + i;
                addCampaign(copy);
            }
        });
        renumber();
    });

    body.addEventListener('click', (e) => {
        const tr = e.target.closest('tr'); if (!tr) return;
        if (e.target.closest('.mc-addset')) addAdsetRow(tr.dataset.cid);
        else if (e.target.closest('.mc-addad')) addAdRow(tr.dataset.cid, tr.dataset.asid);
        else if (e.target.closest('.mc-pub')) { const ci = campIndex(tr); if (ci >= 0) doPublish([ci]); }
        else if (e.target.closest('.mc-del')) {
            const type = tr.dataset.rowtype, cid = tr.dataset.cid;
            if (type === 'campaign') body.querySelectorAll(`[data-cid="${CSS.escape(cid)}"]`).forEach(r => r.remove());
            else if (type === 'adset') body.querySelectorAll(`[data-asid="${CSS.escape(tr.dataset.asid)}"]`).forEach(r => r.remove());
            else tr.remove();
            renumber();
            if (type !== 'campaign') revalidate(cid); else updateFooter();
            autosave();
        }
    });
    body.addEventListener('change', (e) => {
        const tr = e.target.closest('tr'); if (!tr) return;
        if (e.target.classList.contains('mc-toggle-cb')) { toggleCampaign(tr, e.target); return; }
        if (e.target.classList.contains('mc-sel')) { updateSelCount(); return; }
        const f = e.target.dataset.field;
        if (AI_KEYS.includes(f)) updateAi(tr);
        if (f === 'account') applyAccountFilter(tr);
        if (f === 'account') updateCascade(tr, true);
        if (f === 'ml') updateMlState(tr);
        if (f === 'default_language') updateMlLayout(tr);
        if (f) { clearPub(tr.dataset.cid); revalidate(tr.dataset.cid); }
        autosave();
    });
    // Mirror the campaign name into the ad set name + ad name while they're still
    // "linked" (empty or the default). Editing either name unlinks just that field,
    // and imported/custom names are left untouched.
    function mirrorCampName(tr) {
        if (tr.dataset.rowtype !== 'campaign') return;
        const src = tr.querySelector('[data-field="camp_name"]'); if (!src) return;
        const name = src.value;
        [['adset_name', DEFAULTS.adset_name], ['ad_name', DEFAULTS.ad_name]].forEach(([f, def]) => {
            const inp = tr.querySelector('[data-field="' + f + '"]'); if (!inp) return;
            if (inp.dataset.mcLink === undefined) inp.dataset.mcLink = (inp.value === '' || inp.value === def) ? '1' : '0';
            if (inp.dataset.mcLink === '1') inp.value = name;
        });
    }
    body.addEventListener('input', (e) => {
        const tr = e.target.closest('tr'); if (!tr) return;
        if (e.target.classList.contains('mc-mt-first')) syncMultiFirst(e.target);
        const f = e.target.dataset.field;
        if (f === 'camp_name') mirrorCampName(tr);
        else if (f === 'adset_name' || f === 'ad_name') e.target.dataset.mcLink = '0';
        if (f) { clearPub(tr.dataset.cid); revalidate(tr.dataset.cid); }
        if (f || e.target.classList.contains('mc-mt-first')) autosave();
    });

    document.getElementById('mcSave').addEventListener('click', () => {
        document.getElementById('mcAction').value = 'save';
        document.getElementById('mcGridData').value = JSON.stringify(serialize());
        try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}   // server is now the source of truth
        document.getElementById('mcSaveForm').submit();
    });

    // ── Publish to Meta ── (creates everything ACTIVE — goes live on approval)
    const campIndex = (campRow) => [...body.querySelectorAll('tr[data-rowtype="campaign"]')].indexOf(campRow);
    function doPublish(indices) {
        if (!indices || !indices.length) { alert('Select at least one campaign to publish.'); return; }
        const rows = [...body.querySelectorAll('tr[data-rowtype="campaign"]')];
        const notReady = indices.filter(i => { const st = rows[i] && rows[i].querySelector('.mc-status'); return !st || st.textContent[0] !== '✓'; });
        const linked = indices.filter(i => rows[i] && rows[i].dataset.linked).length;
        const fresh = indices.length - linked;
        let msg = 'Publish ' + indices.length + ' campaign(s) to Meta?';
        if (fresh) msg += `\n\n⚠ ${fresh} new campaign(s) will be created ACTIVE and start DELIVERING (spending) as soon as Meta approves them — there is no pause step.`;
        if (linked) msg += `\n\n${linked} already linked to Meta will be UPDATED in place (status unchanged).`;
        if (notReady.length) msg = notReady.length + ' of ' + indices.length + ' still have errors and will fail.\n\n' + msg;
        if (!confirm(msg)) return;
        document.getElementById('mcAction').value = 'publish';
        document.getElementById('mcGridData').value = JSON.stringify(serialize());
        document.getElementById('mcPublishIdx').value = JSON.stringify(indices);
        try { localStorage.removeItem(AUTOSAVE_KEY); } catch (e) {}
        document.getElementById('mcSaveForm').submit();
    }
    document.getElementById('mcPubSel').addEventListener('click', () => {
        const rows = [...body.querySelectorAll('tr[data-rowtype="campaign"]')];
        doPublish(rows.map((r, i) => r.querySelector('.mc-sel:checked') ? i : -1).filter(i => i >= 0));
    });

    // ── Activate / pause a published campaign on Meta (live toggle) ──
    function toggleCampaign(campRow, cb) {
        let meta = {}; try { meta = JSON.parse(campRow.dataset.pubMeta || '{}'); } catch (e) {}
        const campId  = meta.campaign_id || '';
        const account = comboValue(campRow, 'account');
        const status  = cb.checked ? 'ACTIVE' : 'PAUSED';
        if (!campId || !account) { alert('Missing Meta campaign id or ad account.'); cb.checked = !cb.checked; return; }

        const label = campRow.querySelector('.mc-toggle');
        cb.disabled = true; if (label) label.classList.add('is-busy');
        const fd = new FormData();
        fd.append('action', 'toggle');
        fd.append('campaign_id', campId);
        fd.append('account', account);
        fd.append('status', status);

        fetch(location.pathname, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: fd })
            .then(r => r.json())
            .then(j => {
                cb.disabled = false; if (label) label.classList.remove('is-busy');
                if (!j || !j.ok) { alert('Could not change status: ' + ((j && j.error) || 'unknown error')); cb.checked = !cb.checked; return; }
                meta.status = j.status; campRow.dataset.pubMeta = JSON.stringify(meta);
                revalidate(campRow.dataset.cid);
            })
            .catch(() => {
                cb.disabled = false; if (label) label.classList.remove('is-busy');
                alert('Network error — status not changed.'); cb.checked = !cb.checked;
            });
    }

    // ── Pull all campaigns from Meta (multi-account picker) ──
    let pullPop = null;
    function closePullPop() {
        if (pullPop) { pullPop.remove(); pullPop = null; document.removeEventListener('mousedown', pullOutside, true); }
    }
    function pullOutside(e) {
        const btn = document.getElementById('mcPull');
        if (pullPop && !pullPop.contains(e.target) && !btn.contains(e.target)) closePullPop();
    }
    // Merge pages the pull discovered into MC.pages, then refresh existing rows'
    // Fan Page options so the newly-known pages are selectable immediately.
    function mergePages(pages) {
        const have = new Set((MC.pages || []).map(p => String(p.id)));
        pages.forEach(p => {
            const id = String(p.id || '');
            if (id && !have.has(id)) { MC.pages.push({ id, name: p.name || id, ma: p.ma, act: p.act || '' }); have.add(id); }
        });
        body.querySelectorAll('tr[data-rowtype="campaign"]').forEach(tr => applyAccountFilter(tr));
    }
    function doPull(acts) {
        const btn = document.getElementById('mcPull'), orig = btn.innerHTML;
        btn.disabled = true; btn.textContent = 'Pulling…';
        const fd = new FormData();
        fd.append('action', 'pull');
        fd.append('acts', JSON.stringify(acts || []));
        fetch('meta_campaigns', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(j => {
                btn.disabled = false; btn.innerHTML = orig;
                if (j && j.ok && Array.isArray(j.campaigns)) {
                    if (Array.isArray(j.pages) && j.pages.length) mergePages(j.pages);
                    j.campaigns.forEach(c => addCampaign(c));
                    renumber();
                    alert(j.msg || ('Pulled ' + j.campaigns.length + ' campaign(s).'));
                } else { alert((j && j.msg) || 'Pull failed.'); }
            })
            .catch(() => { btn.disabled = false; btn.innerHTML = orig; alert('Pull failed.'); });
    }
    function openPullPicker() {
        closePullPop();
        const accts = MC.adAccounts || [];
        pullPop = document.createElement('div');
        pullPop.className = 'mc-pop mc-pullpop';
        if (!accts.length) {
            pullPop.innerHTML = `<div class="mc-pull-head">No synced ad accounts</div>`
                + `<div style="font-size:11px;color:var(--text-muted,#8b8b96);max-width:220px">Click <b>Sync assets</b> first to load your Meta ad accounts.</div>`;
        } else {
            pullPop.innerHTML = `<div class="mc-pull-head">Pull all campaigns from</div>`
                + `<label class="mc-pull-opt mc-pull-allrow"><input type="checkbox" class="mc-pull-all"> <b>All accounts</b></label>`
                + `<div class="mc-pull-list">`
                + accts.map(a => `<label class="mc-pull-opt"><input type="checkbox" class="mc-pull-cb" value="${esc(a.act_id)}"> <span title="${esc(a.label)}">${esc(a.label)}</span></label>`).join('')
                + `</div>`
                + `<div class="mc-pull-foot"><button type="button" class="mc-pull-cancel">Cancel</button><button type="button" class="mc-pull-go">Pull</button></div>`;
        }
        document.body.appendChild(pullPop);
        const btn = document.getElementById('mcPull'), r = btn.getBoundingClientRect();
        pullPop.style.left = (r.left + window.scrollX) + 'px';
        pullPop.style.top = (r.bottom + window.scrollY + 4) + 'px';
        setTimeout(() => document.addEventListener('mousedown', pullOutside, true), 0);
        if (!accts.length) return;

        const cbs = () => [...pullPop.querySelectorAll('.mc-pull-cb')];
        const allCb = pullPop.querySelector('.mc-pull-all');
        allCb.addEventListener('change', () => cbs().forEach(c => c.checked = allCb.checked));
        pullPop.querySelector('.mc-pull-list').addEventListener('change', () => { allCb.checked = cbs().every(c => c.checked); });
        pullPop.querySelector('.mc-pull-cancel').addEventListener('click', closePullPop);
        pullPop.querySelector('.mc-pull-go').addEventListener('click', () => {
            const acts = cbs().filter(c => c.checked).map(c => c.value);
            if (!acts.length) { alert('Pick at least one ad account.'); return; }
            closePullPop();
            doPull(acts);
        });
    }
    document.getElementById('mcPull').addEventListener('click', () => { if (pullPop) closePullPop(); else openPullPicker(); });

    // ── Combobox popover (search + pick; multi keeps the popover open) ──
    let popWrap = null, popEl = null, popRender = null, popSearch = null;
    // Close on scroll of the page/grid, but NOT when scrolling the popover's own list.
    function popScroll(e) { if (popEl && popEl.contains(e.target)) return; closePop(); }
    function closePop() {
        if (popEl) popEl.remove();
        popEl = null; popWrap = null; popRender = null; popSearch = null;
        document.removeEventListener('scroll', popScroll, true);
    }
    function openPop(wrap) {
        closePop();
        const btn = wrap.querySelector('.mc-combo-btn');
        if (btn.disabled) return;
        popWrap = wrap;
        const field = wrap.dataset.combo;
        const multi = !!wrap.dataset.multi;
        const opts = comboOpts(wrap).slice();
        if (!multi && PH[field] !== undefined) opts.unshift(['', PH[field]]);

        popEl = document.createElement('div');
        popEl.className = 'mc-pop';
        popEl.innerHTML = `<input class="mc-pop-search" placeholder="Search…" autocomplete="off"><div class="mc-pop-list"></div>`;
        document.body.appendChild(popEl);
        const list = popEl.querySelector('.mc-pop-list');
        popSearch = popEl.querySelector('.mc-pop-search');
        popRender = (q) => {
            q = q.trim().toLowerCase();
            const sel = multi ? new Set(csvVals(comboInput(wrap).value)) : null;
            const cur = comboInput(wrap).value;
            const filtered = opts.filter(([, l]) => l.toLowerCase().includes(q));
            list.innerHTML = '';
            if (multi) {
                const allOn = filtered.length && filtered.every(([v]) => sel.has(String(v)));
                const allRow = document.createElement('div');
                allRow.className = 'mc-pop-opt mc-pop-all' + (allOn ? ' is-sel' : '');
                allRow.textContent = (allOn ? '✓ ' : '  ') + 'Select all';
                allRow.dataset.all = '1';
                list.appendChild(allRow);
            }
            filtered.forEach(([v, l]) => {
                const on = multi ? sel.has(String(v)) : String(v) === String(cur);
                const o = document.createElement('div');
                o.className = 'mc-pop-opt' + (on ? ' is-sel' : '');
                o.textContent = (multi ? (on ? '✓ ' : '  ') : '') + l;
                o.dataset.val = v;
                list.appendChild(o);
            });
        };
        popRender('');

        const r = btn.getBoundingClientRect();
        popEl.style.left = (r.left + window.scrollX) + 'px';
        popEl.style.top = (r.bottom + window.scrollY + 2) + 'px';
        popEl.style.minWidth = Math.max(r.width, 170) + 'px';

        popSearch.addEventListener('input', () => popRender(popSearch.value));
        popSearch.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') { closePop(); btn.focus(); }
            else if (e.key === 'Enter') { const first = list.querySelector('.mc-pop-opt'); if (first) pickOpt(first.dataset.val); e.preventDefault(); }
        });
        list.addEventListener('mousedown', (e) => {
            const o = e.target.closest('.mc-pop-opt'); if (!o) return;
            e.preventDefault();
            if (o.dataset.all) pickAllVisible(); else pickOpt(o.dataset.val);
        });
        setTimeout(() => { if (popSearch) popSearch.focus(); }, 0);   // may have closed before the tick
        document.addEventListener('scroll', popScroll, true);
    }
    function pickOpt(val) {
        if (!popWrap) return;
        const inp = comboInput(popWrap);
        if (popWrap.dataset.multi) {
            const set = new Set(csvVals(inp.value));
            const key = String(val), nowOn = !set.has(key);
            if (nowOn) set.add(key); else set.delete(key);
            inp.value = [...set].join(',');
            updateComboLabel(popWrap);
            inp.dispatchEvent(new Event('change', { bubbles: true }));
            // Toggle the clicked row in place — rebuilding the list mid-click would
            // detach the event target and trip the outside-click close.
            if (popEl) {
                const opt = [...popEl.querySelectorAll('.mc-pop-opt:not(.mc-pop-all)')].find(o => o.dataset.val === key);
                if (opt) { opt.classList.toggle('is-sel', nowOn); opt.textContent = (nowOn ? '✓ ' : '  ') + opt.textContent.replace(/^[✓\s]+/, ''); }
                syncSelectAllRow(set);
            }
        } else {
            inp.value = val;
            updateComboLabel(popWrap);
            closePop();
            inp.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
    // Reflect the "Select all" row's checked state from the current selection.
    function syncSelectAllRow(set) {
        const allRow = popEl && popEl.querySelector('.mc-pop-all'); if (!allRow) return;
        const rows = [...popEl.querySelectorAll('.mc-pop-opt:not(.mc-pop-all)')];
        const on = rows.length && rows.every(r => set.has(String(r.dataset.val)));
        allRow.classList.toggle('is-sel', on);
        allRow.textContent = (on ? '✓ ' : '  ') + 'Select all';
    }
    // Toggle every currently-visible option (respects the search filter).
    function pickAllVisible() {
        if (!popWrap || !popWrap.dataset.multi || !popEl) return;
        const inp = comboInput(popWrap);
        const set = new Set(csvVals(inp.value));
        const rows = [...popEl.querySelectorAll('.mc-pop-opt:not(.mc-pop-all)')];
        const vals = rows.map(r => String(r.dataset.val));
        const turnOn = !(vals.length && vals.every(v => set.has(v)));
        vals.forEach(v => { if (turnOn) set.add(v); else set.delete(v); });
        inp.value = [...set].join(',');
        updateComboLabel(popWrap);
        inp.dispatchEvent(new Event('change', { bubbles: true }));
        rows.forEach(r => { r.classList.toggle('is-sel', turnOn); r.textContent = (turnOn ? '✓ ' : '  ') + r.textContent.replace(/^[✓\s]+/, ''); });
        syncSelectAllRow(set);
    }
    document.addEventListener('mousedown', (e) => {
        if (!popEl) return;
        if (popEl.contains(e.target)) return;
        if (popWrap && popWrap.contains(e.target)) return; // let the button handler toggle
        closePop();
    });
    body.addEventListener('click', (e) => {
        const btn = e.target.closest('.mc-combo-btn');
        if (!btn) return;
        const wrap = btn.closest('.mc-combo');
        if (popWrap === wrap) closePop(); else openPop(wrap);
    });

    // ── Multi-variation text editor (Message / Title / Description "+") ──
    let mtPop = null, mtWrap = null;
    function mtScroll(e) { if (mtPop && mtPop.contains(e.target)) return; closeMtPop(); }
    function closeMtPop() { if (mtPop) mtPop.remove(); mtPop = null; mtWrap = null; document.removeEventListener('scroll', mtScroll, true); }
    function updateMtBadge(wrap) {
        const h = wrap.querySelector('input[data-field]');
        const cnt = String(h.value || '').split('\n').filter(x => x.trim()).length;
        const b = wrap.querySelector('.mc-mt-badge'); if (b) b.textContent = cnt > 1 ? ('+' + (cnt - 1)) : '';
    }
    function syncMultiFirst(input) {
        const wrap = input.closest('.mc-multi'); if (!wrap) return;
        const h = wrap.querySelector('input[data-field]');
        const lines = String(h.value || '').split('\n');
        lines[0] = input.value; h.value = lines.join('\n');
        updateMtBadge(wrap);
    }
    function openMtPop(wrap) {
        closeMtPop(); mtWrap = wrap;
        const h = wrap.querySelector('input[data-field]');
        mtPop = document.createElement('div'); mtPop.className = 'mc-pop mc-mtpop';
        mtPop.innerHTML = `<div class="mc-mt-hint">One variation per line — Meta rotates them.</div><textarea class="mc-mt-area" rows="5"></textarea>`;
        document.body.appendChild(mtPop);
        const area = mtPop.querySelector('.mc-mt-area'); area.value = h.value;
        const btn = wrap.querySelector('.mc-mt-add'); const r = btn.getBoundingClientRect();
        mtPop.style.left = Math.max(8, r.right + window.scrollX - 240) + 'px';
        mtPop.style.top = (r.bottom + window.scrollY + 2) + 'px';
        mtPop.style.width = '240px';
        area.addEventListener('input', () => {
            h.value = area.value;
            const f = wrap.querySelector('.mc-mt-first'); if (f) f.value = (area.value.split('\n')[0] || '');
            updateMtBadge(wrap);
        });
        setTimeout(() => area.focus(), 0);
        document.addEventListener('scroll', mtScroll, true);
    }
    document.addEventListener('mousedown', (e) => {
        if (!mtPop) return;
        if (mtPop.contains(e.target) || (mtWrap && mtWrap.contains(e.target))) return;
        closeMtPop();
    });
    body.addEventListener('click', (e) => {
        const add = e.target.closest('.mc-mt-add'); if (!add) return;
        const wrap = add.closest('.mc-multi');
        if (mtWrap === wrap) closeMtPop(); else openMtPop(wrap);
    });

    // ── Media picker modal (Creative Hub) ──
    // Server-provided library: source 'meta' → Assets tab, 'upload' → Files tab.
    const MEDIA = { video: (MC.media && MC.media.video) || [], image: (MC.media && MC.media.image) || [] };
    function mediaLib(kind, tab) {
        const all = MEDIA[kind] || [];
        if (tab === 'assets') return all.filter(m => m.source !== 'upload');
        if (tab === 'files') return all.filter(m => m.source === 'upload');
        return all;
    }
    const mediaItem = (kind, id) => (MEDIA[kind] || []).find(m => String(m.id) === String(id));

    let mediaModal = null;
    function closeMediaModal() { if (mediaModal) mediaModal.remove(); mediaModal = null; }
    function openMediaModal(wrap) {
        closeMediaModal();
        const kind = wrap.dataset.media;
        const inp = wrap.querySelector('input[data-field]');
        const selected = new Set(csvVals(inp.value));
        let tab = 'assets', q = '', previewId = null;
        const Kind = kind[0].toUpperCase() + kind.slice(1);

        mediaModal = document.createElement('div');
        mediaModal.className = 'mc-modal-overlay';
        mediaModal.innerHTML = `
          <div class="mc-modal">
            <div class="mc-modal-head">
              <div><div class="mc-modal-title">Select ${Kind}</div><div class="mc-modal-sub">Choose a file from Creative Hub</div></div>
              <button type="button" class="mc-modal-x" title="Close">&times;</button>
            </div>
            <div class="mc-modal-tabs">
              <button type="button" class="mc-tab is-on" data-tab="assets">Assets</button>
              <button type="button" class="mc-tab" data-tab="files">Files</button>
              <div class="mc-modal-toolbar">
                <button type="button" class="mc-upload" style="display:none"><i class="bi bi-upload"></i> Upload</button>
                <input class="mc-modal-search" placeholder="Search…" autocomplete="off">
              </div>
            </div>
            <div class="mc-modal-body">
              <div class="mc-modal-grid"></div>
              <div class="mc-modal-preview"></div>
            </div>
            <div class="mc-modal-foot"><button type="button" class="mc-modal-cancel">Cancel</button><button type="button" class="mc-modal-select">Select</button></div>
          </div>`;
        document.body.appendChild(mediaModal);

        const grid = mediaModal.querySelector('.mc-modal-grid');
        const preview = mediaModal.querySelector('.mc-modal-preview');
        const search = mediaModal.querySelector('.mc-modal-search');
        const uploadBtn = mediaModal.querySelector('.mc-upload');

        function renderGrid() {
            const items = mediaLib(kind, tab).filter(m => (m.name || '').toLowerCase().includes(q.toLowerCase()));
            if (!items.length) {
                grid.innerHTML = `<div class="mc-media-empty"><i class="bi bi-file-earmark-break"></i><div>No ${kind}s in your library</div></div>`;
                return;
            }
            grid.innerHTML = '';
            items.forEach(m => {
                const on = selected.has(String(m.id));
                const card = document.createElement('div');
                card.className = 'mc-media-card' + (on ? ' is-sel' : '');
                card.dataset.id = m.id;
                const thumb = (kind === 'image' && m.url)
                    ? `<img src="${esc(m.url)}" alt="" loading="lazy">`
                    : `<div class="mc-media-thumbicon"><i class="bi ${MEDIA_ICON[kind]}"></i></div>`;
                card.innerHTML = `<div class="mc-media-thumb">${thumb}${on ? '<span class="mc-media-check"><i class="bi bi-check-lg"></i></span>' : ''}</div>`
                    + `<div class="mc-media-name" title="${esc(m.name || '')}">${esc(m.name || m.id)}</div>`
                    + `<div class="mc-media-size">${esc(m.size || 'Img')}</div>`;
                grid.appendChild(card);
            });
        }
        function renderPreview() {
            const m = previewId && mediaItem(kind, previewId);
            if (!m) { preview.innerHTML = `<div class="mc-preview-empty">Click a file to see preview</div>`; return; }
            preview.innerHTML = (kind === 'image' && m.url)
                ? `<img src="${esc(m.url)}" alt=""><div class="mc-preview-name">${esc(m.name || '')}</div>`
                : `<div class="mc-preview-empty"><i class="bi ${MEDIA_ICON[kind]}" style="font-size:34px"></i><div>${esc(m.name || m.id)}</div></div>`;
        }
        function setTab(t) {
            tab = t;
            mediaModal.querySelectorAll('.mc-tab').forEach(b => b.classList.toggle('is-on', b.dataset.tab === t));
            uploadBtn.style.display = (t === 'files' && kind === 'image') ? '' : 'none';
            renderGrid();
        }

        mediaModal.querySelector('.mc-modal-x').onclick = closeMediaModal;
        mediaModal.querySelector('.mc-modal-cancel').onclick = closeMediaModal;
        mediaModal.addEventListener('mousedown', (e) => { if (e.target === mediaModal) closeMediaModal(); });
        mediaModal.querySelectorAll('.mc-tab').forEach(b => b.onclick = () => setTab(b.dataset.tab));
        search.addEventListener('input', () => { q = search.value; renderGrid(); });
        grid.addEventListener('click', (e) => {
            const card = e.target.closest('.mc-media-card'); if (!card) return;
            const id = card.dataset.id, on = !selected.has(id);
            if (on) selected.add(id); else selected.delete(id);
            card.classList.toggle('is-sel', on);            // toggle in place (no full re-render)
            const thumb = card.querySelector('.mc-media-thumb'), check = thumb.querySelector('.mc-media-check');
            if (on && !check) thumb.insertAdjacentHTML('beforeend', '<span class="mc-media-check"><i class="bi bi-check-lg"></i></span>');
            else if (!on && check) check.remove();
            previewId = id; renderPreview();
        });
        uploadBtn.onclick = () => {
            const fi = document.createElement('input');
            fi.type = 'file'; fi.accept = 'image/*'; fi.multiple = true;
            fi.onchange = () => {
                const files = [...(fi.files || [])]; if (!files.length) return;
                uploadBtn.disabled = true; const orig = uploadBtn.innerHTML; uploadBtn.textContent = 'Uploading…';
                Promise.all(files.map(file => {
                    const fd = new FormData(); fd.append('file', file); fd.append('kind', kind);
                    return fetch('meta_media_upload', { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } })
                        .then(r => r.json()).catch(() => ({ ok: false }));
                })).then(results => {
                    uploadBtn.disabled = false; uploadBtn.innerHTML = orig;
                    let failed = 0, lastId = null;
                    results.forEach(j => {
                        if (j && j.ok && j.item) { MEDIA[kind].push(j.item); selected.add(String(j.item.id)); lastId = String(j.item.id); }
                        else failed++;
                    });
                    if (lastId) previewId = lastId;
                    setTab('files'); renderPreview();
                    if (failed) alert(failed + ' file(s) failed to upload.');
                });
            };
            fi.click();
        };
        mediaModal.querySelector('.mc-modal-select').onclick = () => {
            inp.value = [...selected].join(',');
            updateMediaBtn(wrap);
            inp.dispatchEvent(new Event('change', { bubbles: true }));
            closeMediaModal();
        };

        setTab('assets'); renderPreview();
    }
    body.addEventListener('click', (e) => {
        const btn = e.target.closest('.mc-media-btn'); if (!btn) return;
        const wrap = btn.closest('.mc-media');
        if (!wrap.classList.contains('is-disabled')) openMediaModal(wrap);
    });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && mediaModal) closeMediaModal(); });

    // ── STATUS error tooltip (hover the badge to see the full list) ──
    let statusTip = null;
    function hideStatusTip() {
        if (statusTip) { statusTip.remove(); statusTip = null; document.removeEventListener('scroll', hideStatusTip, true); }
    }
    body.addEventListener('mouseover', (e) => {
        const st = e.target.closest('.mc-has-errors'); if (!st) return;
        const errs = (st.dataset.errs || '').split('\n').filter(Boolean); if (!errs.length) return;
        hideStatusTip();
        statusTip = document.createElement('div');
        statusTip.className = 'mc-tip';
        const head = document.createElement('div');
        head.className = 'mc-tip-head';
        const word = st.dataset.tipLabel || 'error';
        head.textContent = errs.length + ' ' + word + (errs.length === 1 ? '' : 's');
        statusTip.appendChild(head);
        errs.forEach(x => { const row = document.createElement('div'); row.className = 'mc-tip-row'; row.textContent = x; statusTip.appendChild(row); });
        document.body.appendChild(statusTip);
        const r = st.getBoundingClientRect();
        statusTip.style.left = (r.left + window.scrollX) + 'px';
        statusTip.style.top = (r.bottom + window.scrollY + 4) + 'px';
        document.addEventListener('scroll', hideStatusTip, true);
    });
    body.addEventListener('mouseout', (e) => {
        const st = e.target.closest('.mc-has-errors'); if (!st) return;
        if (e.relatedTarget && st.contains(e.relatedTarget)) return;
        hideStatusTip();
    });

    (function injectCss() {
        if (document.getElementById('mc-combo-css')) return;
        const s = document.createElement('style');
        s.id = 'mc-combo-css';
        s.textContent = `
        .mc-combo{position:relative;width:100%}
        .mc-combo-btn{display:flex;align-items:center;justify-content:space-between;gap:4px;width:100%;min-width:120px;
            font-size:11px;padding:9px;line-height:1.3;color:inherit;text-align:left;cursor:pointer;
            background: rgb(127 127 127 / 0%);border: 0;border-radius: 0;}
        .mc-combo-btn:hover{border-color:var(--purple,#8b5cf6)}
        .mc-combo-label{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .mc-combo-label.is-ph{color:var(--text-muted,#8b8b96)}
        .mc-combo-caret{opacity:.5;font-size:9px;flex:none}
        .mc-combo.is-disabled .mc-combo-btn{opacity:.45;cursor:not-allowed;background:rgba(127,127,127,.14)}
        .mc-pop{position:absolute;z-index:9999;background:Canvas;color:CanvasText;border:1px solid rgba(127,127,127,.45);
            border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.35);padding:4px;max-width:300px}
        .mc-pop-search{width:100%;box-sizing:border-box;font-size:11px;padding:4px 6px;margin-bottom:4px;color:inherit;
            background:rgba(127,127,127,.08);border:1px solid rgba(127,127,127,.35);border-radius:6px;outline:none}
        .mc-pop-list{max-height:220px;overflow:auto}
        .mc-pop-opt{font-size:11px;padding:4px 8px;border-radius:5px;cursor:pointer;white-space:nowrap}
        .mc-pop-opt:hover{background:rgba(139,92,246,.18)}
        .mc-pop-opt.is-sel{background:rgba(139,92,246,.30)}
        .mc-multi{position:relative;display:flex;align-items:center;gap:2px;width:100%}
        .mc-multi .mc-mt-first{flex:1;min-width:0;font-size:11px;padding:1px 4px;color:inherit;background:transparent;border:0;outline:none}
        .mc-mt-add{flex:none;font-size:11px;line-height:1;padding:1px 4px;cursor:pointer;color:var(--purple,#8b5cf6);
            background:transparent;border:1px solid rgba(127,127,127,.3);border-radius:4px}
        .mc-mt-add:hover{background:rgba(139,92,246,.15)}
        .mc-mt-badge{font-size:9px;color:var(--purple,#8b5cf6)}
        .mc-mtpop{padding:6px}
        .mc-mt-hint{font-size:10px;color:var(--text-muted,#8b8b96);margin-bottom:4px}
        .mc-mt-area{width:100%;box-sizing:border-box;font-size:11px;padding:4px 6px;color:inherit;
            background:rgba(127,127,127,.08);border:1px solid rgba(127,127,127,.35);border-radius:6px;outline:none;resize:vertical}
        .mc-disabled{opacity:.45}
        .mc-tip{position:absolute;z-index:10001;max-width:360px;background:Canvas;color:CanvasText;
            border:1px solid rgba(239,68,68,.45);border-radius:8px;box-shadow:0 8px 24px rgba(0,0,0,.4);
            padding:8px 10px;font-size:11px;pointer-events:none;line-height:1.5}
        .mc-tip-head{font-weight:700;color:#ef4444;margin-bottom:4px}
        .mc-tip-row{position:relative;padding:1px 0 1px 14px;white-space:normal}
        .mc-tip-row::before{content:"•";position:absolute;left:2px;color:#ef4444}
        .mc-pub-link{color:#4ade80;margin-left:3px;text-decoration:none}
        .mc-pub-link:hover{color:#22c55e}
        .mc-toggle{display:inline-flex;align-items:center;gap:5px;cursor:pointer;user-select:none;font-weight:600}
        .mc-toggle-cb{position:absolute;opacity:0;width:0;height:0}
        .mc-toggle-track{position:relative;width:26px;height:15px;flex:none;border-radius:9px;background:rgba(127,127,127,.45);transition:background .15s}
        .mc-toggle-thumb{position:absolute;top:2px;left:2px;width:11px;height:11px;border-radius:50%;background:#fff;transition:transform .15s}
        .mc-toggle.is-on .mc-toggle-track{background:#22c55e}
        .mc-toggle.is-on .mc-toggle-thumb{transform:translateX(11px)}
        .mc-toggle.is-busy{opacity:.5;pointer-events:none}
        .mc-pullpop{padding:8px;min-width:230px;max-width:320px}
        .mc-pull-head{font-weight:700;font-size:11px;margin-bottom:6px}
        .mc-pull-opt{display:flex;align-items:center;gap:6px;font-size:11px;padding:3px 4px;border-radius:5px;cursor:pointer}
        .mc-pull-opt:hover{background:rgba(139,92,246,.12)}
        .mc-pull-opt span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .mc-pull-allrow{border-bottom:1px solid rgba(127,127,127,.25);margin-bottom:4px;padding-bottom:6px}
        .mc-pull-list{max-height:200px;overflow:auto}
        .mc-pull-foot{display:flex;justify-content:flex-end;gap:8px;margin-top:8px;border-top:1px solid rgba(127,127,127,.25);padding-top:8px}
        .mc-pull-cancel{font-size:11px;padding:4px 10px;border-radius:6px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;cursor:pointer}
        .mc-pull-go{font-size:11px;padding:4px 14px;border-radius:6px;border:0;background:var(--purple,#8b5cf6);color:#fff;cursor:pointer}
        .mc-linked td{background-color:rgba(56,189,248,.06)}
        .mc-linked td:first-child{box-shadow:inset 3px 0 0 #38bdf8}
        .mc-linkicon{color:#38bdf8;font-size:11px;vertical-align:middle}
        .mc-media{width:100%}
        .mc-media-btn{display:flex;align-items:center;gap:5px;width:100%;font-size:11px;padding:2px 4px;color:inherit;
            background:transparent;border:0;cursor:pointer;text-align:left;white-space:nowrap;overflow:hidden}
        .mc-media-btn .bi{opacity:.6;flex:none}
        .mc-media-ph{color:var(--text-muted,#8b8b96)}
        .mc-media-count{color:inherit;font-weight:600}
        .mc-media-add{color:var(--purple,#8b5cf6)}
        .mc-media.is-disabled .mc-media-btn{opacity:.45;cursor:not-allowed}
        .mc-modal-overlay{position:fixed;inset:0;z-index:10000;background:rgba(15,15,20,.55);display:flex;align-items:flex-start;justify-content:center;padding:5vh 12px}
        .mc-modal{width:min(920px,96vw);max-height:88vh;display:flex;flex-direction:column;background:Canvas;color:CanvasText;
            border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.5);overflow:hidden;font-size:13px}
        .mc-modal-head{display:flex;justify-content:space-between;align-items:flex-start;padding:16px 18px 10px}
        .mc-modal-title{font-size:16px;font-weight:700}
        .mc-modal-sub{font-size:12px;color:var(--text-muted,#8b8b96);margin-top:2px}
        .mc-modal-x{background:transparent;border:0;font-size:22px;line-height:1;color:inherit;cursor:pointer;opacity:.6}
        .mc-modal-x:hover{opacity:1}
        .mc-modal-tabs{display:flex;align-items:center;gap:6px;padding:0 18px 10px;border-bottom:1px solid rgba(127,127,127,.25)}
        .mc-tab{font-size:12px;padding:4px 12px;border-radius:7px;border:0;background:transparent;color:inherit;cursor:pointer;opacity:.7}
        .mc-tab.is-on{background:rgba(139,92,246,.18);color:var(--purple,#8b5cf6);opacity:1;font-weight:600}
        .mc-modal-toolbar{margin-left:auto;display:flex;gap:8px;align-items:center}
        .mc-upload{font-size:12px;padding:5px 12px;border-radius:7px;border:0;background:var(--purple,#8b5cf6);color:#fff;cursor:pointer}
        .mc-modal-search{font-size:12px;padding:5px 10px;border-radius:7px;border:1px solid rgba(127,127,127,.35);background:rgba(127,127,127,.08);color:inherit;outline:none;min-width:200px}
        .mc-modal-body{flex:1;min-height:340px;display:grid;grid-template-columns:1fr 300px;overflow:hidden}
        .mc-modal-grid{overflow:auto;padding:14px;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:12px;align-content:start}
        .mc-media-empty{grid-column:1/-1;align-self:center;justify-self:center;text-align:center;color:var(--text-muted,#8b8b96);padding:40px}
        .mc-media-empty .bi{font-size:34px;opacity:.5;display:block;margin-bottom:8px}
        .mc-media-card{cursor:pointer;border-radius:8px;padding:4px;border:2px solid transparent}
        .mc-media-card.is-sel{border-color:var(--purple,#8b5cf6)}
        .mc-media-thumb{position:relative;aspect-ratio:1;border-radius:6px;overflow:hidden;background:rgba(127,127,127,.12);display:flex;align-items:center;justify-content:center}
        .mc-media-thumb img{width:100%;height:100%;object-fit:cover}
        .mc-media-thumbicon .bi{font-size:26px;opacity:.5}
        .mc-media-check{position:absolute;top:4px;right:4px;background:var(--purple,#8b5cf6);color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:11px}
        .mc-media-name{font-size:11px;margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .mc-media-size{font-size:10px;color:var(--text-muted,#8b8b96)}
        .mc-modal-preview{border-left:1px solid rgba(127,127,127,.25);padding:16px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;overflow:auto}
        .mc-modal-preview img{max-width:100%;max-height:220px;border-radius:8px}
        .mc-preview-name{font-size:12px;margin-top:8px}
        .mc-preview-empty{color:var(--text-muted,#8b8b96);font-size:12px}
        .mc-modal-foot{display:flex;justify-content:flex-end;gap:10px;padding:12px 18px;border-top:1px solid rgba(127,127,127,.25)}
        .mc-modal-cancel{font-size:12px;padding:6px 16px;border-radius:8px;border:1px solid rgba(127,127,127,.35);background:transparent;color:inherit;cursor:pointer}
        .mc-modal-select{font-size:12px;padding:6px 18px;border-radius:8px;border:0;background:var(--purple,#8b5cf6);color:#fff;cursor:pointer}
        `;
        document.head.appendChild(s);
    })();

    // ── Load ── prefer an unsaved autosave snapshot (survives refresh), then the
    // server drafts, then blank starter rows. A snapshot that exists but is empty is
    // an intentionally-cleared grid, so it wins over the server drafts (don't resurrect
    // deleted rows on refresh).
    let snap = null, hasSnap = false;
    try { const s = localStorage.getItem(AUTOSAVE_KEY); if (s !== null) { snap = JSON.parse(s); hasSnap = Array.isArray(snap); } } catch (e) { hasSnap = false; }
    if (hasSnap) snap.forEach(d => addCampaign(d));
    else if (MC.drafts && MC.drafts.length) MC.drafts.forEach(d => addCampaign(d));
    else for (let i = 0; i < 3; i++) addCampaign();
})();
