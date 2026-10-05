@php
    /** Searchable domain picker. Params: $domains, $selected (int), $wrapId (string). */
    $selected = $selected ?? 0;
    $wrapId   = $wrapId ?? '';
    $selLabel = '— Ungrouped —';
    $rows = [['id' => '', 'label' => '— Ungrouped —']];
    foreach ($domains as $d) {
        $label = $d['name'];
        if ((int) $d['id'] === (int) $selected) $selLabel = $label;
        $rows[] = ['id' => (int) $d['id'], 'label' => $label];
    }
@endphp
<div class="ss-wrap" @if($wrapId) id="{{ $wrapId }}" @endif>
    <input type="hidden" name="domain_id" class="ss-value" value="{{ $selected ?: '' }}">
    <input type="text" class="form-control ss-search" placeholder="Search domain…" autocomplete="off" value="{{ $selLabel }}">
    <div class="ss-list">
        @foreach ($rows as $r)
        <div class="ss-opt" data-id="{{ $r['id'] }}" data-label="{{ $r['label'] }}">{{ $r['label'] }}</div>
        @endforeach
    </div>
</div>
