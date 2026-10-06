<!DOCTYPE html>
<html lang="en" data-root-url="{{ rtrim(url('/'), '/') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Amaira' }} · Amaira</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="preconnect" href="https://code.jquery.com" crossorigin>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/favicon.svg') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.css">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body>
@php $active = $activePage ?? ''; @endphp
<nav class="navbar navbar-dark navbar-main">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="{{ url('/') }}">
            <i class="bi bi-graph-up-arrow me-2"></i>Amaira
        </a>
        @php
            $managePages  = ['domains', 'links', 'site-checker', 'adx', 'accounts', 'upload'];
            $financePages = ['expenses', 'invoices', 'currencies'];
            $adminPages   = ['users', 'customers', 'roles', 'zip-masters', 'server-masters'];
            $canManage    = userCan('domains') || userCan('links') || userCan('adx') || userCan('accounts') || userCan('upload');
            $canFinance   = userCan('expenses') || userCan('invoices') || userCan('currencies');
            $canAdmin     = userCan('users') || userCan('customers') || userCan('roles') || userCan('zip_masters') || userCan('server_masters');
            
        @endphp
        <button class="nav-toggle" type="button" data-bs-toggle="offcanvas" data-bs-target="#navSidebar"
                aria-controls="navSidebar" aria-label="Open menu">
            <i class="bi bi-list"></i>
        </button>
        <div class="offcanvas-md offcanvas-end nav-collapse" tabindex="-1" id="navSidebar" aria-labelledby="navSidebarLabel">
        <div class="offcanvas-header">
            <h6 class="offcanvas-title" id="navSidebarLabel"><i class="bi bi-graph-up-arrow me-2"></i>Amaira</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#navSidebar" aria-label="Close"></button>
        </div>
        <div class="offcanvas-body">
        <div class="nav-links">
            @if (userCan('dashboard'))
            <a href="{{ url('/') }}"        class="nav-btn {{ $active==='dashboard' ? 'active':'' }}"><i class="bi bi-speedometer2"></i> Dashboard</a>
            @endif
            @if (userCan('monthly') || userCan('date_wise') || userCan('gam_check'))
            {{-- Report --}}
            <div class="nav-dropdown dropdown">
                <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                   class="nav-btn dropdown-toggle {{ in_array($active, ['monthly', 'date-wise', 'date-range', 'gam_check']) ? 'active':'' }}">
                    <i class="bi bi-bar-chart-line"></i> Report
                </a>
                <ul class="dropdown-menu nav-dropdown-menu">
                    @if (userCan('monthly'))
                    <li><a class="dropdown-item {{ $active==='monthly' ? 'active':'' }}" href="{{ url('/monthly') }}"><i class="bi bi-calendar3"></i> Monthly</a></li>
                    @endif
                    @if (userCan('date_wise'))
                    <li><a class="dropdown-item {{ $active==='date-wise' ? 'active':'' }}" href="{{ url('/date-wise') }}"><i class="bi bi-calendar-range"></i> Date Wise</a></li>
                    <li><a class="dropdown-item {{ $active==='date-range' ? 'active':'' }}" href="{{ url('/date-range') }}"><i class="bi bi-calendar-week"></i> Date Range</a></li>
                    @endif
                    @if (userCan('gam_check'))
                    <li><a class="dropdown-item {{ $active==='gam_check' ? 'active':'' }}" href="{{ url('/gam_check') }}"><i class="bi bi-clipboard-pulse"></i> GAM Check</a></li>
                    @endif
                </ul>
            </div>
            @endif
            @if (userCan('meta_campaigns'))
            <a href="{{ url('/meta_campaigns') }}" class="nav-btn {{ $active==='meta_campaigns' ? 'active':'' }}"><i class="bi bi-megaphone"></i> Meta Campaigns</a>
            @endif

            @if ($canManage)
            {{-- Manage --}}
            <div class="nav-dropdown dropdown">
                <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                   class="nav-btn dropdown-toggle {{ in_array($active, $managePages) ? 'active':'' }}">
                    <i class="bi bi-sliders"></i> Manage
                </a>
                <ul class="dropdown-menu nav-dropdown-menu">
                    @if (userCan('domains'))<li><a class="dropdown-item {{ $active==='domains'  ? 'active':'' }}" href="{{ url('/domains') }}"><i class="bi bi-globe2"></i> Domains</a></li>@endif
                    @if (userCan('links'))<li><a class="dropdown-item {{ $active==='links'    ? 'active':'' }}" href="{{ url('/links') }}"><i class="bi bi-link-45deg"></i> Links</a></li>@endif
                    @if (userCan('links'))<li><a class="dropdown-item {{ $active==='site-checker' ? 'active':'' }}" href="{{ url('/site-checker') }}"><i class="bi bi-patch-check"></i> Site Checker</a></li>@endif
                    @if (userCan('adx'))<li><a class="dropdown-item {{ $active==='adx'      ? 'active':'' }}" href="{{ url('/adx') }}"><i class="bi bi-diagram-3"></i> ADX</a></li>@endif
                    @if (userCan('accounts'))<li><a class="dropdown-item {{ $active==='accounts' ? 'active':'' }}" href="{{ url('/accounts') }}"><i class="bi bi-person-badge"></i> Accounts</a></li>@endif
                    @if (userCan('upload'))<li><a class="dropdown-item {{ $active==='upload'   ? 'active':'' }}" href="{{ url('/upload') }}"><i class="bi bi-cloud-upload"></i> Upload</a></li>@endif
                </ul>
            </div>
            @endif

            @if ($canFinance)
            {{-- Finance --}}
            <div class="nav-dropdown dropdown">
                <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                   class="nav-btn dropdown-toggle {{ in_array($active, $financePages) ? 'active':'' }}">
                    <i class="bi bi-cash-stack"></i> Finance
                </a>
                <ul class="dropdown-menu nav-dropdown-menu">
                    @if (userCan('expenses'))<li><a class="dropdown-item {{ $active==='expenses'   ? 'active':'' }}" href="{{ url('/expenses') }}"><i class="bi bi-wallet2"></i> Expenses</a></li>@endif
                    @if (userCan('invoices'))<li><a class="dropdown-item {{ $active==='invoices'   ? 'active':'' }}" href="{{ url('/invoices') }}"><i class="bi bi-file-earmark-text"></i> Invoices</a></li>@endif
                    @if (userCan('currencies'))<li><a class="dropdown-item {{ $active==='currencies' ? 'active':'' }}" href="{{ url('/currencies') }}"><i class="bi bi-currency-exchange"></i> Currency</a></li>@endif
                </ul>
            </div>
            @endif

            @if ($canAdmin)
            {{-- Admin --}}
            <div class="nav-dropdown dropdown">
                <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                   class="nav-btn dropdown-toggle {{ in_array($active, $adminPages) ? 'active':'' }}">
                    <i class="bi bi-shield-lock"></i> Admin
                </a>
                <ul class="dropdown-menu nav-dropdown-menu">
                    @if (userCan('users'))<li><a class="dropdown-item {{ $active==='users' ? 'active':'' }}" href="{{ url('/users') }}"><i class="bi bi-people"></i> Users</a></li>@endif
                    @if (userCan('customers'))<li><a class="dropdown-item {{ $active==='customers' ? 'active':'' }}" href="{{ url('/customers') }}"><i class="bi bi-person-vcard"></i> Customers</a></li>@endif
                    @if (userCan('roles'))<li><a class="dropdown-item {{ $active==='roles' ? 'active':'' }}" href="{{ url('/roles') }}"><i class="bi bi-person-lock"></i> Roles</a></li>@endif
                    @if (userCan('zip_masters'))<li><a class="dropdown-item {{ $active==='zip-masters' ? 'active':'' }}" href="{{ url('/zip-masters') }}"><i class="bi bi-file-zip"></i> Zip</a></li>@endif
                    @if (userCan('server_masters'))<li><a class="dropdown-item {{ $active==='server-masters' ? 'active':'' }}" href="{{ url('/server-masters') }}"><i class="bi bi-hdd-network"></i> Servers</a></li>@endif
                </ul>
            </div>
            @endif

            <a href="{{ url('/settings') }}" class="nav-btn {{ $active==='settings' ? 'active':'' }}"><i class="bi bi-gear"></i> Settings</a>
        </div>
        <div class="nav-user">
            <span class="nav-btn nav-user-badge" title="Signed in as {{ currentUserName() }}">
                <i class="bi bi-person-circle"></i> {{ currentUserName() }}
                @if (isAdmin())<span class="admin-tag">ADMIN</span>@endif
            </span>
            <a href="{{ url('/admin/logout') }}" class="nav-btn nav-btn-lock nav-btn-icon" title="Lock session" aria-label="Lock session">
                <i class="bi bi-lock"></i>
            </a>
        </div>
        </div><!-- /offcanvas-body -->
        </div><!-- /offcanvas -->
    </div>
</nav>
@php $authExp = authExpiresAt(); @endphp
@if ($authExp)
<div id="sessionExpiryBar" style="background:rgba(167,139,250,.08);border-bottom:1px solid var(--border);
     padding:4px 20px;font-size:11px;color:var(--text-muted);text-align:right">
    <i class="bi bi-shield-lock"></i> Session unlocked until
    <span id="sessionExpiryTime">{{ date('h:i A', $authExp) }}</span>
    <span id="sessionCountdown" data-expires="{{ $authExp }}" style="margin-left:6px;color:#a78bfa"></span>
</div>
<script>
(function(){
    const el = document.getElementById('sessionCountdown');
    if (!el) return;
    const expiresAt = parseInt(el.dataset.expires, 10) * 1000;
    function tick() {
        const diff = expiresAt - Date.now();
        if (diff <= 0) { el.textContent = '(expired — reloading…)'; setTimeout(() => location.reload(), 1500); return; }
        const h = Math.floor(diff / 3600000);
        const m = Math.floor((diff % 3600000) / 60000);
        el.textContent = `(${h}h ${m}m left)`;
    }
    tick();
    setInterval(tick, 30000);
})();
</script>
@endif
<div class="page-wrap">
@yield('content')
</div><!-- /page-wrap -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.30.1/moment.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/daterangepicker@3.1.0/daterangepicker.min.js"></script>
<script src="{{ asset('assets/js/app.js') }}"></script>
@stack('scripts')
</body>
</html>
