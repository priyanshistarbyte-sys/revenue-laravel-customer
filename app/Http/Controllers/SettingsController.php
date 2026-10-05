<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of settings.php — financial settings (admin), change-my-PIN (all users),
 * and two-step (second PIN) management (all users).
 */
class SettingsController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Settings';
        $activePage = 'settings';
        $flash = null;

        if ($request->isMethod('post')) {
            $formType = $request->input('form', 'financial');

            if ($formType === 'financial' && userCan('settings', 'edit')) {
                try {
                    $pdo  = getDB();
                    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value, created_at, updated_at) VALUES (?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
                    $gstRate = (float) $request->input('gst_rate', 18);
                    if ($gstRate < 0 || $gstRate > 50) throw new \Exception('GST rate must be between 0 and 50.');
                    $stmt->execute(['gst_rate', number_format($gstRate, 2, '.', '')]);
                    $flash = ['type' => 'success', 'msg' => 'Settings saved successfully.'];
                } catch (\Throwable $e) {
                    $flash = ['type' => 'error', 'msg' => $e->getMessage()];
                }
            }

            if ($formType === 'change_pin') {
                $current = trim((string) $request->input('current_pin', ''));
                $newPin  = trim((string) $request->input('new_pin', ''));
                $confirm = trim((string) $request->input('new_pin_confirm', ''));

                $stmt = getDB()->prepare("SELECT * FROM users WHERE id = ?");
                $stmt->execute([currentUserId()]);
                $me = $stmt->fetch();

                if (!$me || !password_verify($current, $me['pin_hash'])) {
                    $flash = ['type' => 'error', 'msg' => 'Current PIN is incorrect.'];
                } elseif (!preg_match('/^\d{6}$/', $newPin)) {
                    $flash = ['type' => 'error', 'msg' => 'New PIN must be exactly 6 digits.'];
                } elseif ($newPin !== $confirm) {
                    $flash = ['type' => 'error', 'msg' => 'New PINs do not match.'];
                } elseif (pinInUse($newPin, currentUserId())) {
                    $flash = ['type' => 'error', 'msg' => 'This PIN is already used by another user. Choose a different one.'];
                } else {
                    getDB()->prepare("UPDATE users SET pin_hash = ? WHERE id = ?")
                           ->execute([password_hash($newPin, PASSWORD_BCRYPT), currentUserId()]);
                    $flash = ['type' => 'success', 'msg' => 'Your PIN was updated successfully.'];
                }
            }

            if ($formType === 'pin2') {
                $me      = userById(currentUserId());
                $cur     = trim((string) $request->input('current_pin', ''));
                $curP2   = trim((string) $request->input('current_pin2', ''));
                $new     = trim((string) $request->input('new_pin2', ''));
                $confirm = trim((string) $request->input('new_pin2_confirm', ''));
                $disable = $request->has('disable');

                if (!$me || !password_verify($cur, $me['pin_hash'])) {
                    $flash = ['type' => 'error', 'msg' => 'Your current login PIN is incorrect.'];
                } elseif (hasSecondPin($me) && !verifySecondPin($me, $curP2)) {
                    $flash = ['type' => 'error', 'msg' => 'Your current second PIN is incorrect.'];
                } elseif ($disable) {
                    setSecondPin(currentUserId(), null);
                    $flash = ['type' => 'success', 'msg' => 'Two-step verification disabled — you now sign in with one PIN.'];
                } elseif (!preg_match('/^\d{6}$/', $new)) {
                    $flash = ['type' => 'error', 'msg' => 'Second PIN must be exactly 6 digits.'];
                } elseif ($new !== $confirm) {
                    $flash = ['type' => 'error', 'msg' => 'Second PINs do not match.'];
                } elseif ($new === $cur) {
                    $flash = ['type' => 'error', 'msg' => 'Your second PIN must be different from your login PIN.'];
                } else {
                    $wasOn = hasSecondPin($me);
                    setSecondPin(currentUserId(), $new);
                    $flash = ['type' => 'success', 'msg' => $wasOn
                        ? 'Second PIN updated.'
                        : 'Two-step verification enabled — you\'ll be asked for this second PIN at every login.'];
                }
            }
        }

        $usdRate    = getCurrencyRate('USD');
        $gstRate    = getSetting('gst_rate', '18');
        $defaultCur = getDefaultCurrency();

        // DB info (system settings)
        $dbInfo = null; $dbErr = null;
        if (userCan('settings', 'view')) {
            try {
                $pdo   = getDB();
                $scope = scopeSQL();
                $dbInfo = [
                    'mCount' => $pdo->query("SELECT COUNT(*) FROM meta_data WHERE $scope")->fetchColumn(),
                    'gCount' => $pdo->query("SELECT COUNT(*) FROM gam_data WHERE $scope")->fetchColumn(),
                    'lCount' => $pdo->query("SELECT COUNT(*) FROM links WHERE $scope")->fetchColumn(),
                    'mDates' => $pdo->query("SELECT COUNT(DISTINCT date) FROM meta_data WHERE $scope")->fetchColumn(),
                    'gDates' => $pdo->query("SELECT COUNT(DISTINCT date) FROM gam_data WHERE $scope")->fetchColumn(),
                ];
            } catch (\Throwable $e) {
                $dbErr = $e->getMessage();
            }
        }

        $me2  = userById(currentUserId());
        $has2 = $me2 ? hasSecondPin($me2) : false;

        return view('settings', compact(
            'pageTitle', 'activePage', 'flash', 'usdRate', 'gstRate', 'defaultCur',
            'dbInfo', 'dbErr', 'has2'
        ));
    }
}
