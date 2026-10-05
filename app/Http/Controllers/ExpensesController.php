<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of expenses.php — expense ledger (admin only).
 */
class ExpensesController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Expenses';
        $activePage = 'expenses';
        $pdo        = getDB();
        $flash      = null;
        $usdRate    = getCurrencyRate('USD');

        // ── Handle POST ──
        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('expenses', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' expenses.');
            }

            if (in_array($action, ['add', 'edit'])) {
                $id        = (int) $request->input('id', 0);
                $date      = $request->input('date', date('Y-m-d'));
                $itemName  = trim((string) $request->input('item_name', ''));
                $actionTxt = trim((string) $request->input('action_txt', ''));
                $currency  = $request->input('currency') === 'USD' ? 'USD' : 'INR';
                $priceRaw  = (float) str_replace(',', '', (string) $request->input('price_raw', 0));
                $priceInr  = $currency === 'USD' ? round($priceRaw * $usdRate, 2) : $priceRaw;
                if ($request->filled('price_inr_override')) {
                    $priceInr = (float) str_replace(',', '', (string) $request->input('price_inr_override'));
                }
                $notes = trim((string) $request->input('notes', ''));

                if (!$itemName || !$date) {
                    $flash = ['type' => 'error', 'msg' => 'Date and Item Name are required.'];
                } else {
                    if ($action === 'add') {
                        $pdo->prepare("INSERT INTO expenses (date, item_name, action, currency, price_raw, price_inr, notes, created_at, updated_at) VALUES (?,?,?,?,?,?,?,NOW(),NOW())")
                            ->execute([$date, $itemName, $actionTxt, $currency, $priceRaw, $priceInr, $notes]);
                        $flash = ['type' => 'success', 'msg' => 'Expense added.'];
                    } else {
                        $pdo->prepare("UPDATE expenses SET date=?, item_name=?, action=?, currency=?, price_raw=?, price_inr=?, notes=?, updated_at=NOW() WHERE id=?")
                            ->execute([$date, $itemName, $actionTxt, $currency, $priceRaw, $priceInr, $notes, $id]);
                        $flash = ['type' => 'success', 'msg' => 'Expense updated.'];
                    }
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id) {
                    $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
                    $flash = ['type' => 'success', 'msg' => 'Expense deleted.'];
                }
            }
        }

        // ── Filters ──
        $filterItem  = trim((string) $request->query('item', ''));
        $filterMonth = trim((string) $request->query('month', ''));
        $filterCur   = trim((string) $request->query('cur', ''));

        $where  = [];
        $params = [];
        if ($filterItem)  { $where[] = 'item_name LIKE ?'; $params[] = "%$filterItem%"; }
        if ($filterMonth) { $where[] = 'DATE_FORMAT(date, "%Y-%m") = ?'; $params[] = $filterMonth; }
        if ($filterCur)   { $where[] = 'currency = ?'; $params[] = $filterCur; }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT * FROM expenses $whereSQL ORDER BY date DESC, id DESC");
        $stmt->execute($params);
        $expenses = $stmt->fetchAll();

        $totalINR = array_sum(array_column($expenses, 'price_inr'));

        // ── Ad Account options ──
        $adAccountOptions = [];
        $accRows = $pdo->query("SELECT name, ad_account_ids FROM meta_accounts WHERE active = 1 ORDER BY name")->fetchAll();
        foreach ($accRows as $acc) {
            $ids = array_filter(array_map('trim', explode(',', $acc['ad_account_ids'] ?? '')));
            if (!empty($ids)) {
                foreach ($ids as $adId) {
                    $adAccountOptions[] = ['value' => $adId . ' — ' . $acc['name'], 'label' => '🆔 ' . $adId . ' — ' . $acc['name']];
                }
            } else {
                $adAccountOptions[] = ['value' => $acc['name'], 'label' => '👤 ' . $acc['name']];
            }
        }

        // ── Edit prefill ──
        $editExp = null;
        if ($request->has('edit')) {
            $eStmt = $pdo->prepare("SELECT * FROM expenses WHERE id=?");
            $eStmt->execute([(int) $request->query('edit')]);
            $editExp = $eStmt->fetch() ?: null;
        }

        return view('expenses', compact(
            'pageTitle', 'activePage', 'flash', 'usdRate', 'expenses', 'totalINR',
            'adAccountOptions', 'editExp', 'filterItem', 'filterMonth', 'filterCur'
        ));
    }
}
