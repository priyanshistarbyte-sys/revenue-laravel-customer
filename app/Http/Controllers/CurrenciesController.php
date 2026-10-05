<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of currencies.php — Currency Master (admin only).
 */
class CurrenciesController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Currency Master';
        $activePage = 'currencies';
        $pdo   = getDB();
        $flash = null;

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'set_default' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('currencies', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' currencies.');
            }

            if ($action === 'add') {
                $code = strtoupper(trim((string) $request->input('code', '')));
                $sym  = trim((string) $request->input('symbol', ''));
                $name = trim((string) $request->input('name', ''));
                $rate = (float) $request->input('rate_to_default', 1);

                if (!preg_match('/^[A-Z]{3}$/', $code)) {
                    $flash = ['type' => 'error', 'msg' => 'Currency code must be exactly 3 letters (e.g. USD, EUR).'];
                } elseif (!$sym || !$name) {
                    $flash = ['type' => 'error', 'msg' => 'Symbol and name are required.'];
                } elseif ($rate <= 0) {
                    $flash = ['type' => 'error', 'msg' => 'Rate must be greater than 0.'];
                } else {
                    try {
                        $pdo->prepare("INSERT INTO currencies (code, symbol, name, rate_to_default, is_default, active, created_at, updated_at) VALUES (?,?,?,?,0,1,NOW(),NOW())")
                            ->execute([$code, $sym, $name, $rate]);
                        $flash = ['type' => 'success', 'msg' => "Currency '$code' added."];
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'Could not add currency — code may already exist.'];
                    }
                }
            }

            if ($action === 'edit') {
                $id   = (int) $request->input('id', 0);
                $sym  = trim((string) $request->input('symbol', ''));
                $name = trim((string) $request->input('name', ''));
                $rate = (float) $request->input('rate_to_default', 1);
                $stmt = $pdo->prepare("SELECT is_default FROM currencies WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();

                if ($id && $sym && $name) {
                    if ($row && (int) $row['is_default'] === 1) { $rate = 1; }
                    if ($rate <= 0) $rate = 1;
                    $pdo->prepare("UPDATE currencies SET symbol=?, name=?, rate_to_default=?, updated_at=NOW() WHERE id=?")
                        ->execute([$sym, $name, $rate, $id]);
                    $flash = ['type' => 'success', 'msg' => 'Currency updated.'];
                }
            }

            if ($action === 'toggle') {
                $id  = (int) $request->input('id', 0);
                $stmt = $pdo->prepare("SELECT is_default FROM currencies WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row && (int) $row['is_default'] === 1) {
                    $flash = ['type' => 'error', 'msg' => 'Cannot deactivate the default currency.'];
                } elseif ($id) {
                    $pdo->prepare("UPDATE currencies SET active = 1 - active WHERE id=?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Status toggled.'];
                }
            }

            if ($action === 'delete') {
                $id  = (int) $request->input('id', 0);
                $stmt = $pdo->prepare("SELECT is_default, code FROM currencies WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                if ($row && (int) $row['is_default'] === 1) {
                    $flash = ['type' => 'error', 'msg' => 'Cannot delete the default currency. Set another currency as default first.'];
                } elseif ($id) {
                    $pdo->prepare("DELETE FROM currencies WHERE id=?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Currency deleted.'];
                }
            }

            if ($action === 'set_default') {
                $id = (int) $request->input('id', 0);
                try {
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare("SELECT * FROM currencies WHERE id=?");
                    $stmt->execute([$id]);
                    $target = $stmt->fetch();

                    if (!$target) {
                        throw new \Exception('Currency not found.');
                    }
                    if ((int) $target['is_default'] === 1) {
                        $pdo->rollBack();
                        $flash = ['type' => 'error', 'msg' => "{$target['code']} is already the default currency."];
                    } else {
                        $pivotRate = (float) $target['rate_to_default'];
                        if ($pivotRate <= 0) $pivotRate = 1;
                        $all = $pdo->query("SELECT id, rate_to_default FROM currencies")->fetchAll();
                        $upd = $pdo->prepare("UPDATE currencies SET rate_to_default = ?, is_default = ? WHERE id = ?");
                        foreach ($all as $c) {
                            $newRate = ((float) $c['rate_to_default']) / $pivotRate;
                            $upd->execute([$newRate, ((int) $c['id'] === $id) ? 1 : 0, $c['id']]);
                        }
                        $pdo->commit();
                        $flash = ['type' => 'success', 'msg' => "{$target['code']} is now the default currency. All rates rebased automatically."];
                    }
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    $flash = ['type' => 'error', 'msg' => 'Could not set default: ' . $e->getMessage()];
                }
            }
        }

        $currencies = $pdo->query("SELECT * FROM currencies ORDER BY is_default DESC, code ASC")->fetchAll();
        $defaultCur = getDefaultCurrency();

        $editCur = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($currencies as $c) { if ($c['id'] == $eid) { $editCur = $c; break; } }
        }

        return view('currencies', compact('pageTitle', 'activePage', 'flash', 'currencies', 'defaultCur', 'editCur'));
    }
}
