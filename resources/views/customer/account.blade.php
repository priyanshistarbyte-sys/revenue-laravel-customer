@extends('customer.layout')

@section('content')

@include('partials.flash')

<div class="dash-header" style="margin-bottom:20px">
    <div>
        <div class="dash-title">MY ACCOUNT</div>
        <div class="dash-subtitle">Change the ID and password you sign in with</div>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-6">
        <form method="post" action="{{ url('/account') }}" autocomplete="off">
            @csrf
            <div class="settings-card">
                <h5><i class="bi bi-shield-lock"></i> Change ID / Password ({{ currentCustomerName() }})</h5>
                <p style="color:var(--text-muted);font-size:12px;margin-bottom:16px">
                    Change your ID, your password, or both. Leave the new password blank to keep the current one.
                    Your current password is required to save any change.
                </p>
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <label class="form-label">Login ID</label>
                        <input type="text" name="login_id" class="form-control" value="{{ request('login_id', $customer['login_id']) }}"
                               maxlength="100" pattern="[A-Za-z0-9._@\-]{3,100}" title="3–100 characters: letters, numbers, . _ @ -" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">New Password</label>
                        <input type="password" name="password" class="form-control" minlength="6" placeholder="Leave blank to keep" autocomplete="new-password">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="password_confirm" class="form-control" minlength="6" placeholder="Repeat new password" autocomplete="new-password">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" placeholder="Required to save" autocomplete="current-password" required>
                    </div>
                </div>
                <button type="submit" class="btn-primary-custom"><i class="bi bi-key"></i> Update Account</button>
            </div>
        </form>
    </div>
</div>

@endsection
