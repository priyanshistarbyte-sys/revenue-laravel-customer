<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Customer sign-in: ID + password (from Admin → Customers), with the same
 * attempt-lockout as the staff PIN login.
 */
class AuthController extends Controller
{
    const MAX_ATTEMPTS    = 5;
    const LOCKOUT_SECONDS = 60;

    public function login(Request $request)
    {
        if (customerAuthenticated()) {
            return redirect('/customer');
        }

        $error    = null;
        $loginId  = trim((string) $request->input('login_id', ''));
        $lockLeft = max(0, (int) session('customer_lock_until', 0) - time());

        if ($request->isMethod('post') && $lockLeft <= 0) {
            $password = (string) $request->input('password', '');

            $customer = null;
            if ($loginId !== '' && $password !== '') {
                $stmt = getDB()->prepare("SELECT * FROM customers WHERE login_id = ? AND active = 1");
                $stmt->execute([$loginId]);
                $row = $stmt->fetch();
                if ($row && password_verify($password, $row['password_hash'])) {
                    $customer = $row;
                }
            }

            if ($customer) {
                session()->forget(['customer_fails', 'customer_lock_until']);
                session()->regenerate();
                customerLogin($customer);
                return redirect('/customer');
            }

            $fails = (int) session('customer_fails', 0) + 1;
            if ($fails >= self::MAX_ATTEMPTS) {
                session(['customer_lock_until' => time() + self::LOCKOUT_SECONDS, 'customer_fails' => 0]);
                $lockLeft = self::LOCKOUT_SECONDS;
                $error = 'Too many failed attempts. Try again in ' . self::LOCKOUT_SECONDS . 's.';
            } else {
                session(['customer_fails' => $fails]);
                $remaining = self::MAX_ATTEMPTS - $fails;
                $error = "Incorrect ID or password. {$remaining} attempt" . ($remaining !== 1 ? 's' : '') . ' left.';
            }
        }

        return view('customer.login', compact('error', 'loginId', 'lockLeft'));
    }

    public function logout()
    {
        customerLogout();
        return redirect('/customer/login');
    }
}
