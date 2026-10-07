<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Customer panel → My Account: the signed-in customer changes their own login
 * ID and/or password. Both need the current password; the rules match
 * Admin → Customers (CustomersController).
 */
class AccountController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'My Account';
        $activePage = 'account';
        $flash      = null;

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmt->execute([currentCustomerId()]);
        $customer = $stmt->fetch();
        if (!$customer) {
            customerLogout();
            return redirect('/login');
        }

        if ($request->isMethod('post')) {
            $current  = (string) $request->input('current_password', '');
            $loginId  = trim((string) $request->input('login_id', ''));
            $password = (string) $request->input('password', '');
            $confirm  = (string) $request->input('password_confirm', '');

            $idChanged = $loginId !== $customer['login_id'];

            $dup = $pdo->prepare("SELECT COUNT(*) FROM customers WHERE login_id = ? AND id <> ?");
            $dup->execute([$loginId, $customer['id']]);

            if (!password_verify($current, $customer['password_hash'])) {
                $flash = ['type' => 'error', 'msg' => 'Current password is incorrect.'];
            } elseif (!preg_match('/^[A-Za-z0-9._@-]{3,100}$/', $loginId)) {
                $flash = ['type' => 'error', 'msg' => 'ID must be 3–100 characters: letters, numbers, . _ @ -'];
            } elseif ($idChanged && (int) $dup->fetchColumn() > 0) {
                $flash = ['type' => 'error', 'msg' => "The ID '$loginId' is already taken."];
            } elseif ($password !== '' && strlen($password) < 6) {
                $flash = ['type' => 'error', 'msg' => 'New password must be at least 6 characters.'];
            } elseif ($password !== '' && $password !== $confirm) {
                $flash = ['type' => 'error', 'msg' => 'New passwords do not match.'];
            } elseif (!$idChanged && $password === '') {
                $flash = ['type' => 'info', 'msg' => 'Nothing to update — enter a new ID or a new password.'];
            } else {
                if ($password !== '') {
                    $pdo->prepare("UPDATE customers SET login_id=?, password_hash=?, updated_at=NOW() WHERE id=?")
                        ->execute([$loginId, password_hash($password, PASSWORD_BCRYPT), $customer['id']]);
                } else {
                    $pdo->prepare("UPDATE customers SET login_id=?, updated_at=NOW() WHERE id=?")
                        ->execute([$loginId, $customer['id']]);
                }
                $customer['login_id'] = $loginId;

                $changed = array_filter([$idChanged ? 'ID' : null, $password !== '' ? 'password' : null]);
                $flash = ['type' => 'success', 'msg' => ucfirst(implode(' and ', $changed)) . ' updated. Use the new details next time you sign in.'];
            }
        }

        return view('customer.account', compact('pageTitle', 'activePage', 'flash', 'customer'));
    }
}
