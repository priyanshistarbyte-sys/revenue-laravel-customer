<!DOCTYPE html>
<html lang="en" data-root-url="{{ rtrim(url('/'), '/') }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Customer' }} · Amaira</title>
    <link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>
    <link rel="icon" type="image/svg+xml" href="{{ asset('assets/favicon.svg') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="{{ asset('assets/css/style.css') }}">
</head>
<body>
{{-- Same navbar as the admin layout (layouts/app.blade.php), with the customer menu. --}}
@php
    $active      = $activePage ?? '';
    $reportPages = ['site-wise', 'hourly-wise', 'country-wise'];
@endphp
<nav class="navbar navbar-dark navbar-main">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="{{ url('/reports/site-wise') }}">
            <i class="bi bi-graph-up-arrow me-2"></i>Amaira
        </a>
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
            {{-- Report --}}
            <div class="nav-dropdown dropdown">
                <a href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"
                   class="nav-btn dropdown-toggle {{ in_array($active, $reportPages) ? 'active':'' }}">
                    <i class="bi bi-bar-chart-line"></i> Report
                </a>
                <ul class="dropdown-menu nav-dropdown-menu">
                    <li><a class="dropdown-item {{ $active==='site-wise' ? 'active':'' }}" href="{{ url('/reports/site-wise') }}"><i class="bi bi-globe2"></i> Site Wise</a></li>
                    <li><a class="dropdown-item {{ $active==='hourly-wise' ? 'active':'' }}" href="{{ url('/reports/hourly-wise') }}"><i class="bi bi-clock-history"></i> Hourly Wise</a></li>
                    <li><a class="dropdown-item {{ $active==='country-wise' ? 'active':'' }}" href="{{ url('/reports/country-wise') }}"><i class="bi bi-flag"></i> Country Wise</a></li>
                </ul>
            </div>
        </div>
        <div class="nav-user">
            <span class="nav-btn nav-user-badge" title="Signed in as {{ currentCustomerName() }}">
                <i class="bi bi-person-circle"></i> {{ currentCustomerName() }}
            </span>
            <a href="{{ url('/logout') }}" class="nav-btn nav-btn-lock nav-btn-icon" title="Sign out" aria-label="Sign out">
                <i class="bi bi-lock"></i>
            </a>
        </div>
        </div><!-- /offcanvas-body -->
        </div><!-- /offcanvas -->
    </div>
</nav>
<div class="page-wrap">
@yield('content')
</div><!-- /page-wrap -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
@stack('scripts')
</body>
</html>
