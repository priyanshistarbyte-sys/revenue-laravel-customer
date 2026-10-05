<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of login.php / logout.php — 6-digit PIN login with lockout and optional
 * two-step (second PIN) verification, available to every user.
 */
class AuthController extends Controller
{
    const AUTH_MAX_ATTEMPTS    = 5;
    const AUTH_LOCKOUT_SECONDS = 60;
    const PIN2_PENDING_TTL     = 300;  // seconds allowed to complete step 2

    public function show(Request $request)
    {
        // Already signed in? go home.
        if (hasUsers() && isAuthenticated()) {
            return redirect('/');
        }
        return $this->render($request, null);
    }

    public function login(Request $request)
    {
        if (session('login_fails') === null)      session(['login_fails' => 0]);
        if (session('login_lock_until') === null) session(['login_lock_until' => 0]);

        $error = null;

        // Restart step-2 flow
        if ($request->has('restart')) {
            session()->forget(['pending_uid', 'pending_at']);
            return redirect('/login');
        }

        if (session('pending_uid') && (time() - (int) session('pending_at', 0)) > self::PIN2_PENDING_TTL) {
            session()->forget(['pending_uid', 'pending_at']);
            $error = 'Two-step verification timed out. Enter your PIN again.';
        }

        $pendingUser = session('pending_uid') ? userById((int) session('pending_uid')) : null;
        if (session('pending_uid') && !$pendingUser) {
            session()->forget(['pending_uid', 'pending_at']);
        }

        $mode = !hasUsers() ? 'setup' : ($pendingUser ? 'verify2' : 'verify');
        $lockSecondsLeft = max(0, (int) session('login_lock_until') - time());

        if ($request->isMethod('post') && $lockSecondsLeft <= 0) {
            if ($mode === 'setup') {
                $name    = trim((string) $request->input('name', ''));
                $pin     = trim((string) $request->input('pin', ''));
                $confirm = trim((string) $request->input('pin_confirm', ''));
                if ($name === '') {
                    $error = 'Please enter your name.';
                } elseif (!preg_match('/^\d{6}$/', $pin)) {
                    $error = 'PIN must be exactly 6 digits.';
                } elseif ($pin !== $confirm) {
                    $error = 'PINs do not match.';
                } else {
                    $userId = createUser($name, $pin, true); // first user is the admin
                    authLogin(userById($userId));
                    return redirect($this->popRedirect());
                }
            } elseif ($mode === 'verify2') {
                $pin = trim((string) $request->input('pin', ''));
                if (preg_match('/^\d{6}$/', $pin) && verifySecondPin($pendingUser, $pin)) {
                    session(['login_fails' => 0]);
                    session()->forget(['pending_uid', 'pending_at']);
                    authLogin($pendingUser);
                    return redirect($this->popRedirect());
                }
                session(['login_fails' => (int) session('login_fails') + 1]);
                if ((int) session('login_fails') >= self::AUTH_MAX_ATTEMPTS) {
                    session()->forget(['pending_uid', 'pending_at']);
                    session(['login_lock_until' => time() + self::AUTH_LOCKOUT_SECONDS, 'login_fails' => 0]);
                    $lockSecondsLeft = self::AUTH_LOCKOUT_SECONDS;
                    $pendingUser = null;
                    $mode = 'verify';
                    $error = 'Too many failed attempts. Try again in ' . self::AUTH_LOCKOUT_SECONDS . 's.';
                } else {
                    $remaining = self::AUTH_MAX_ATTEMPTS - (int) session('login_fails');
                    $error = "Incorrect second PIN. {$remaining} attempt" . ($remaining !== 1 ? 's' : '') . ' left.';
                }
            } else {
                $pin  = trim((string) $request->input('pin', ''));
                $user = preg_match('/^\d{6}$/', $pin) ? verifyUserPin($pin) : null;
                if ($user) {
                    session(['login_fails' => 0]);
                    if (needsSecondPin($user)) {
                        session(['pending_uid' => (int) $user['id'], 'pending_at' => time()]);
                        return redirect('/login'); // GET → renders step 2
                    }
                    authLogin($user);
                    return redirect($this->popRedirect());
                }
                session(['login_fails' => (int) session('login_fails') + 1]);
                if ((int) session('login_fails') >= self::AUTH_MAX_ATTEMPTS) {
                    session(['login_lock_until' => time() + self::AUTH_LOCKOUT_SECONDS, 'login_fails' => 0]);
                    $lockSecondsLeft = self::AUTH_LOCKOUT_SECONDS;
                    $error = 'Too many failed attempts. Try again in ' . self::AUTH_LOCKOUT_SECONDS . 's.';
                } else {
                    $remaining = self::AUTH_MAX_ATTEMPTS - (int) session('login_fails');
                    $error = "Incorrect PIN. {$remaining} attempt" . ($remaining !== 1 ? 's' : '') . ' left.';
                }
            }
        }

        return $this->render($request, $error, $pendingUser, $mode, $lockSecondsLeft);
    }

    public function logout()
    {
        authLogout();
        return redirect('/login');
    }

    private function popRedirect(): string
    {
        $redirect = session('redirect_after_login', '/');
        session()->forget('redirect_after_login');
        return $redirect ?: '/';
    }

    private function render(Request $request, ?string $error, $pendingUser = null, ?string $mode = null, int $lockSecondsLeft = 0)
    {
        if ($mode === null) {
            $pendingUser = session('pending_uid') ? userById((int) session('pending_uid')) : null;
            $mode = !hasUsers() ? 'setup' : ($pendingUser ? 'verify2' : 'verify');
            $lockSecondsLeft = max(0, (int) session('login_lock_until', 0) - time());
        }

        return response()->view('auth.login', [
            'error'           => $error,
            'pendingUser'     => $pendingUser,
            'mode'            => $mode,
            'lockSecondsLeft' => $lockSecondsLeft,
            'PIN2_PENDING_TTL' => self::PIN2_PENDING_TTL,
        ]);
    }
}
