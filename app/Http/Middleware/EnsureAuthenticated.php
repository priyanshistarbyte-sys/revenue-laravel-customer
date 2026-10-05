<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Port of config.php requireAuth(): requires a valid, unexpired PIN session and
 * re-validates the user against the DB on every request so a deactivated or
 * deleted user is locked out immediately.
 */
class EnsureAuthenticated
{
    public function handle(Request $request, Closure $next): Response
    {
        // No hasUsers() COUNT here: the active-user lookup below already fails
        // when the table is empty, so it would be one wasted query per request.
        $ok = isAuthenticated();

        if ($ok) {
            try {
                $stmt = getDB()->prepare("SELECT * FROM users WHERE id = ? AND active = 1");
                $stmt->execute([currentUserId()]);
                $user = $stmt->fetch();
                if (!$user) {
                    $ok = false;
                } else {
                    // Keep session name/admin flag fresh (admin may have edited this user)
                    session([
                        'user_name' => $user['name'],
                        'is_admin'  => (int) $user['is_admin'] === 1,
                    ]);
                }
            } catch (\Throwable $e) {
                $ok = false;
            }
        }

        if (!$ok) {
            authLogout();
            session(['redirect_after_login' => $request->getRequestUri()]);
            return redirect('/login');
        }

        return $next($request);
    }
}
