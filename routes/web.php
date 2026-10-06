<?php

use App\Http\Controllers\AdxController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CurrenciesController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainsController;
use App\Http\Controllers\ExpensesController;
use App\Http\Controllers\GamCheckController;
use App\Http\Controllers\LinksController;
use App\Http\Controllers\MonthlyController;
use App\Http\Controllers\SaveValueController;
use App\Http\Controllers\AccountsController;
use App\Http\Controllers\InvoicesController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\UploadController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\ZipController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\CheckerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Amaira routes
|--------------------------------------------------------------------------
| Clean URLs (the original app used index.php / monthly.php / … filenames;
| those map here to /, /monthly, and so on).
*/

// ── Public ──
// Customers sign in at /login (the site's front door); staff use /admin/login.
Route::match(['get', 'post'], '/login', [\App\Http\Controllers\Customer\AuthController::class, 'login'])->name('customer.login');
Route::get('/logout', [\App\Http\Controllers\Customer\AuthController::class, 'logout'])->name('customer.logout');
Route::match(['get', 'post'], '/admin/login', [AuthController::class, 'login'])->name('login');
Route::get('/admin/logout', [AuthController::class, 'logout'])->name('logout');
Route::match(['get', 'post'], '/setup', [SetupController::class, 'run'])->name('setup');

// ── Customer panel (ID + password, separate from the staff PIN login) ──
Route::prefix('customer')->group(function () {
    Route::middleware('auth.customer')->group(function () {
        Route::redirect('/', '/customer/reports/site-wise')->name('customer.home');
        Route::get('/reports/site-wise', [\App\Http\Controllers\Customer\ReportsController::class, 'siteWise'])->name('customer.reports.site-wise');
        Route::get('/reports/hourly-wise', [\App\Http\Controllers\Customer\ReportsController::class, 'hourlyWise'])->name('customer.reports.hourly-wise');
        Route::get('/reports/country-wise', [\App\Http\Controllers\Customer\ReportsController::class, 'countryWise'])->name('customer.reports.country-wise');
    });
});

// ── Authenticated ──
Route::middleware('auth.pin')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard')->name('dashboard');
    Route::get('/monthly', [MonthlyController::class, 'index'])->middleware('permission:monthly')->name('monthly');
    Route::get('/date-wise', [MonthlyController::class, 'dateWise'])->middleware('permission:date_wise')->name('date-wise');
    Route::get('/date-range', [MonthlyController::class, 'dateRange'])->middleware('permission:date_wise')->name('date-range');
    Route::match(['get', 'post'], '/gam_check', [GamCheckController::class, 'index'])->middleware('permission:gam_check')->name('gam_check');
    Route::match(['get', 'post'], '/meta_campaigns', [\App\Http\Controllers\MetaCampaignsController::class, 'index'])->middleware('permission:meta_campaigns')->name('meta_campaigns');
    Route::post('/meta_media_upload', [SyncController::class, 'uploadMedia'])->middleware('permission:meta_campaigns')->name('meta_media_upload');
    Route::get('/link_history', [DashboardController::class, 'history'])->middleware('permission:dashboard')->name('link_history');
    Route::post('/save_value',[SaveValueController::class, 'store'])->middleware('permission:dashboard')->name('save_value');

    // Settings is available to every authenticated user (own PIN); its system
    // section is gated by the 'settings' permission inside the controller.
    Route::match(['get', 'post'], '/settings', [SettingsController::class, 'index'])->name('settings');

    // ── Per-page permissions (view enforced here; write actions checked in controllers) ──
    Route::match(['get', 'post'], '/domains', [DomainsController::class, 'index'])->middleware('permission:domains')->name('domains');
    Route::match(['get', 'post'], '/links', [LinksController::class, 'index'])->middleware('permission:links')->name('links');
    Route::get('/site-checker', [CheckerController::class, 'index'])->middleware('permission:links')->name('site-checker');
    Route::get('/site-checker/check', [CheckerController::class, 'check'])->middleware('permission:links')->name('site-checker.check');
    Route::match(['get', 'post'], '/expenses', [ExpensesController::class, 'index'])->middleware('permission:expenses')->name('expenses');
    Route::match(['get', 'post'], '/adx', [AdxController::class, 'index'])->middleware('permission:adx')->name('adx');
    Route::match(['get', 'post'], '/currencies', [CurrenciesController::class, 'index'])->middleware('permission:currencies')->name('currencies');
    Route::match(['get', 'post'], '/accounts', [AccountsController::class, 'index'])->middleware('permission:accounts')->name('accounts');
    Route::match(['get', 'post'], '/upload', [UploadController::class, 'index'])->middleware('permission:upload')->name('upload');
    Route::post('/delete_data', [UploadController::class, 'delete'])->middleware('permission:upload')->name('delete_data');
    Route::match(['get', 'post'], '/invoices', [InvoicesController::class, 'index'])->middleware('permission:invoices')->name('invoices');
    Route::get('/invoices/download', [InvoicesController::class, 'download'])->middleware('permission:invoices')->name('invoices.download');
    Route::get('/invoices/preview', [InvoicesController::class, 'preview'])->middleware('permission:invoices')->name('invoices.preview');
    // Reachable from Upload and from the ADX page; the controller requires
    // 'upload.add' or 'adx.edit' before anything actually runs.
    Route::get('/gam_sync', [SyncController::class, 'gam'])->middleware('permission:upload|adx')->name('gam_sync');
    Route::get('/meta_sync', [SyncController::class, 'meta'])->middleware('permission:upload|accounts')->name('meta_sync');
    Route::get('/meta_assets_sync', [SyncController::class, 'metaAssets'])->middleware('permission:accounts')->name('meta_assets_sync');

    // ── Users, roles & deployment masters ──
    Route::match(['get', 'post'], '/users', [UsersController::class, 'index'])->middleware('permission:users')->name('users');
    Route::match(['get', 'post'], '/customers', [CustomersController::class, 'index'])->middleware('permission:customers')->name('customers');
    Route::match(['get', 'post'], '/roles', [RolesController::class, 'index'])->middleware('permission:roles')->name('roles');
    Route::match(['get', 'post'], '/zip-masters', [ZipController::class, 'index'])->middleware('permission:zip_masters')->name('zip-masters');
    Route::match(['get', 'post'], '/server-masters', [ServerController::class, 'index'])->middleware('permission:server_masters')->name('server-masters');
});
