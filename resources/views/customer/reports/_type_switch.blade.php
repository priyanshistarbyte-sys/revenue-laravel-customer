{{-- Web / App switch for the customer reports (?type=app). Switching drops the site filter and export flag. --}}
<span class="type-switch" role="radiogroup" aria-label="Show web or app" style="margin-left:0">
    @foreach (['web' => ['bi-globe', 'Web'], 'app' => ['bi-phone', 'App']] as $tp => [$icon, $label])
    <a href="{{ request()->fullUrlWithQuery(['type' => $tp === 'app' ? 'app' : null, 'site' => null, 'export' => null]) }}"
       class="type-opt {{ $type === $tp ? 'active' : '' }}" role="radio" aria-checked="{{ $type === $tp ? 'true' : 'false' }}"
       style="text-decoration:none"><i class="bi {{ $icon }}"></i> {{ $label }}</a>
    @endforeach
</span>
