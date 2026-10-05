<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer panel guard: requires a valid, unexpired customer session and
 * re-checks the customer against the DB on every request so a deactivated or
 * deleted customer is locked out immediately. Separate from the staff PIN
 * session (auth.pin) — the two never grant each other access.
 */
class EnsureCustomer
{
    public function handle(Request $request, Closure $next): Response
    {
        $ok = customerAuthenticated();

        if ($ok) {
            try {
                $stmt = getDB()->prepare("SELECT id, name, login_id FROM customers WHERE id = ? AND active = 1");
                $stmt->execute([currentCustomerId()]);
                $customer = $stmt->fetch();
                if ($customer) {
                    session(['customer_name' => $customer['name']]);
                } else {
                    $ok = false;
                }
            } catch (\Throwable $e) {
                $ok = false;
            }
        }

        if (!$ok) {
            customerLogout();
            return redirect('/customer/login');
        }

        return $next($request);
    }
}
