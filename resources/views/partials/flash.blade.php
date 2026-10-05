@if (!empty($flash))
@php
    $flashIcon = match ($flash['type']) {
        'success' => 'check-circle',
        'warning' => 'exclamation-triangle',
        'info'    => 'info-circle',
        default   => 'exclamation-circle',
    };
@endphp
{{-- success/info fade out on their own; warning/error stay until dismissed (assets/js/app.js) --}}
<div class="alert-custom alert-{{ $flash['type'] }}" role="alert">
    <i class="bi bi-{{ $flashIcon }}"></i>
    {{ $flash['msg'] }}
</div>
@endif
