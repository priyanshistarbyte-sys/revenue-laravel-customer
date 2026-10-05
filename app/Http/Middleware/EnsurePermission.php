<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-page permission guard: `->middleware('permission:domains')`.
 *
 * Requires the current user to have 'view' on the given page. Admins bypass
 * (userCan() returns true for them). Write actions (add/edit/delete) are checked
 * separately inside each controller, since one POST route handles several.
 *
 * A route reachable from more than one page can list them pipe-separated
 * (`permission:upload|adx`) — 'view' on any one of them is enough to get in,
 * and the controller still checks the action the user is asking for.
 *
 * Landing grace: a user who can't view the dashboard ('/') is redirected to the
 * first page they *can* see (or Settings) instead of a bare 403 on login.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $page): Response
    {
        $pages = array_filter(array_map('trim', explode('|', $page)));
        foreach ($pages as $p) {
            if (userCan($p, 'view')) {
                return $next($request);
            }
        }
        $page = $pages[0] ?? $page;

        if ($page === 'dashboard') {
            $to  = firstAllowedPath();
            $msg = $to === '/settings'
                ? 'No pages have been assigned to your account yet. Ask an admin to grant access.'
                : 'You don\'t have dashboard access — showing the first page you can see.';
            return redirect($to)->with('flash', ['type' => 'error', 'msg' => $msg]);
        }

        abort(403, 'Access denied — you do not have permission to view this page.');
    }
}
