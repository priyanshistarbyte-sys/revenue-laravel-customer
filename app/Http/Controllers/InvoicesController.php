<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

/**
 * Port of invoices.php + invoice_download.php + invoice_preview.php — invoice
 * upload/management with single/monthly/selected ZIP download and inline preview.
 * Admin only. Files are stored under storage/app/invoices.
 */
class InvoicesController extends Controller
{
    const MAX_SIZE_MB = 20;

    private array $allowedTypes = [
        'application/pdf'  => 'pdf',
        'image/jpeg'       => 'jpg',
        'image/png'        => 'png',
        'image/webp'       => 'webp',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];

    private function uploadDir(): string
    {
        $dir = storage_path('app/invoices');
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . DIRECTORY_SEPARATOR;
    }

    public function index(Request $request)
    {
        $pageTitle  = 'Invoices';
        $activePage = 'invoices';
        $pdo   = getDB();
        $flash = null;

        if ($request->isMethod('post')) {
            $flash = $this->handlePost($request, $pdo);
        }

        // ── Filters ──
        $filterMonth   = trim((string) $request->query('month', ''));
        $filterTitle   = trim((string) $request->query('title', ''));
        $filterAccount = trim((string) $request->query('account', ''));
        $where = []; $params = [];
        if ($filterMonth)   { $where[] = "DATE_FORMAT(date,'%Y-%m') = ?"; $params[] = $filterMonth; }
        if ($filterTitle)   { $where[] = "(title LIKE ? OR original_name LIKE ?)"; $params[] = "%$filterTitle%"; $params[] = "%$filterTitle%"; }
        if ($filterAccount) { $where[] = "account = ?"; $params[] = $filterAccount; }
        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare("SELECT * FROM invoices $whereSQL ORDER BY date DESC, id DESC");
        $stmt->execute($params);
        $invoices = $stmt->fetchAll();

        $editInv = null;
        if ($request->has('edit')) {
            $eStmt = $pdo->prepare("SELECT * FROM invoices WHERE id=?");
            $eStmt->execute([(int) $request->query('edit')]);
            $editInv = $eStmt->fetch() ?: null;
        }

        // ── Account options from meta_accounts ──
        $accountOptions = [];
        foreach ($pdo->query("SELECT name, ad_account_ids FROM meta_accounts WHERE active=1 ORDER BY name")->fetchAll() as $acc) {
            $ids = array_filter(array_map('trim', explode(',', $acc['ad_account_ids'] ?? '')));
            if (!empty($ids)) {
                foreach ($ids as $adId) $accountOptions[] = $adId . ' — ' . $acc['name'];
            } else {
                $accountOptions[] = $acc['name'];
            }
        }

        // ── Monthly sidebar ──
        $sideWhere = []; $sideParams = [];
        if ($filterAccount) { $sideWhere[] = "account = ?"; $sideParams[] = $filterAccount; }
        if ($filterTitle)   { $sideWhere[] = "(title LIKE ? OR original_name LIKE ?)"; $sideParams[] = "%$filterTitle%"; $sideParams[] = "%$filterTitle%"; }
        $sideSQL = "SELECT DATE_FORMAT(date,'%Y-%m') AS ym, DATE_FORMAT(MIN(date),'%M %Y') AS label, COUNT(*) AS cnt, SUM(file_size) AS total_bytes
                    FROM invoices" . ($sideWhere ? ' WHERE ' . implode(' AND ', $sideWhere) : '')
                 . " GROUP BY DATE_FORMAT(date,'%Y-%m') ORDER BY ym DESC";
        $sideStmt = $pdo->prepare($sideSQL);
        $sideStmt->execute($sideParams);
        $months = $sideStmt->fetchAll();

        $totalCount = count($invoices);
        $totalBytes = array_sum(array_column($invoices, 'file_size'));

        // Group by date
        $grouped = [];
        foreach ($invoices as $inv) { $grouped[$inv['date']][] = $inv; }

        return view('invoices', compact(
            'pageTitle', 'activePage', 'flash', 'invoices', 'grouped', 'editInv',
            'accountOptions', 'months', 'totalCount', 'totalBytes',
            'filterMonth', 'filterTitle', 'filterAccount'
        ) + ['maxSizeMb' => self::MAX_SIZE_MB]);
    }

    private function handlePost(Request $request, \PDO $pdo): ?array
    {
        $action = $request->input('action', '');

        $need = ['upload' => 'add', 'edit' => 'edit', 'delete' => 'delete', 'multi_delete' => 'delete', 'delete_date' => 'delete'];
        if (isset($need[$action]) && !userCan('invoices', $need[$action])) {
            abort(403, 'You do not have permission to ' . $need[$action] . ' invoices.');
        }

        if ($action === 'upload') {
            $date    = $request->input('date', date('Y-m-d'));
            $notes   = trim((string) $request->input('notes', ''));
            $account = trim((string) $request->input('account', ''));
            $files   = $request->file('invoice_file', []);
            if (!is_array($files)) $files = $files ? [$files] : [];
            $files = array_filter($files, fn ($f) => $f && $f->isValid());

            if (empty($files)) {
                return ['type' => 'error', 'msg' => 'Please choose at least one file to upload.'];
            }
            if (!$date) {
                return ['type' => 'error', 'msg' => 'Date is required.'];
            }

            $stmt = $pdo->prepare("INSERT INTO invoices (date, title, file_name, original_name, file_size, mime_type, notes, account, created_at, updated_at)
                                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())");
            $uploaded = 0; $errors = [];

            foreach ($files as $file) {
                $origName = basename($file->getClientOriginalName());
                $size     = $file->getSize();
                $mime     = $file->getMimeType();
                $title    = pathinfo($origName, PATHINFO_FILENAME);

                if (!isset($this->allowedTypes[$mime])) { $errors[] = "$origName: file type not allowed."; continue; }
                if ($size > self::MAX_SIZE_MB * 1024 * 1024) { $errors[] = "$origName: exceeds " . self::MAX_SIZE_MB . " MB limit."; continue; }

                $ext    = $this->allowedTypes[$mime];
                $stored = date('Y-m-d', strtotime($date)) . '_' . uniqid() . '.' . $ext;

                try {
                    $file->move($this->uploadDir(), $stored);
                } catch (\Throwable $e) {
                    $errors[] = "$origName: failed to save. Check directory permissions.";
                    continue;
                }
                $stmt->execute([$date, $title, $stored, $origName, $size, $mime, $notes, $account]);
                $uploaded++;
            }

            if ($uploaded > 0 && empty($errors)) {
                return ['type' => 'success', 'msg' => "$uploaded invoice" . ($uploaded > 1 ? 's' : '') . " uploaded successfully."];
            } elseif ($uploaded > 0) {
                return ['type' => 'success', 'msg' => "$uploaded uploaded. Errors: " . implode(' | ', $errors)];
            }
            return ['type' => 'error', 'msg' => 'Upload failed. ' . implode(' | ', $errors)];
        }

        if ($action === 'edit') {
            $id      = (int) $request->input('id', 0);
            $date    = $request->input('date', '');
            $title   = trim((string) $request->input('title', ''));
            $notes   = trim((string) $request->input('notes', ''));
            $account = trim((string) $request->input('account', ''));
            if ($id && $date) {
                $pdo->prepare("UPDATE invoices SET date=?, title=?, notes=?, account=?, updated_at=NOW() WHERE id=?")
                    ->execute([$date, $title, $notes, $account, $id]);
                return ['type' => 'success', 'msg' => 'Invoice updated.'];
            }
        }

        if ($action === 'delete') {
            $id = (int) $request->input('id', 0);
            if ($id) {
                $inv = $pdo->prepare("SELECT file_name FROM invoices WHERE id=?");
                $inv->execute([$id]);
                $inv = $inv->fetch();
                if ($inv) {
                    $fp = $this->uploadDir() . $inv['file_name'];
                    if (file_exists($fp)) @unlink($fp);
                    $pdo->prepare("DELETE FROM invoices WHERE id=?")->execute([$id]);
                    return ['type' => 'success', 'msg' => 'Invoice deleted.'];
                }
            }
        }

        if ($action === 'multi_delete') {
            $ids = array_filter(array_map('intval', (array) $request->input('del_ids', [])));
            if (empty($ids)) {
                return ['type' => 'error', 'msg' => 'No invoices selected.'];
            }
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $pdo->prepare("SELECT file_name FROM invoices WHERE id IN ($placeholders)");
            $rows->execute($ids);
            $rows = $rows->fetchAll();
            foreach ($rows as $r) { $fp = $this->uploadDir() . $r['file_name']; if (file_exists($fp)) @unlink($fp); }
            $pdo->prepare("DELETE FROM invoices WHERE id IN ($placeholders)")->execute($ids);
            return ['type' => 'success', 'msg' => count($rows) . ' invoice' . (count($rows) > 1 ? 's' : '') . ' deleted.'];
        }

        if ($action === 'delete_date') {
            $delDate = $request->input('del_date', '');
            if ($delDate) {
                $rows = $pdo->prepare("SELECT file_name FROM invoices WHERE date = ?");
                $rows->execute([$delDate]);
                $rows = $rows->fetchAll();
                foreach ($rows as $r) { $fp = $this->uploadDir() . $r['file_name']; if (file_exists($fp)) @unlink($fp); }
                $pdo->prepare("DELETE FROM invoices WHERE date = ?")->execute([$delDate]);
                return ['type' => 'success', 'msg' => count($rows) . ' invoice' . (count($rows) > 1 ? 's' : '') . ' for ' . date('d-M-Y', strtotime($delDate)) . ' deleted.'];
            }
        }

        return null;
    }

    /** Port of invoice_download.php — id | month | ids */
    public function download(Request $request)
    {
        $pdo = getDB();
        $dir = $this->uploadDir();

        if ($request->filled('id')) {
            $inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
            $inv->execute([(int) $request->query('id')]);
            $inv = $inv->fetch();
            if (!$inv) abort(404, 'Invoice not found.');
            $filePath = $dir . $inv['file_name'];
            if (!file_exists($filePath)) abort(404, 'File not found on disk.');
            return response()->download($filePath, $inv['original_name'], ['Content-Type' => $inv['mime_type'] ?: 'application/octet-stream']);
        }

        if ($request->filled('month')) {
            $month   = $request->query('month');
            $account = trim((string) $request->query('account', ''));
            if (!preg_match('/^\d{4}-\d{2}$/', $month)) abort(400, 'Invalid month format. Use YYYY-MM.');
            $sql = "SELECT * FROM invoices WHERE DATE_FORMAT(date,'%Y-%m') = ?";
            $params = [$month];
            if ($account !== '') { $sql .= " AND account = ?"; $params[] = $account; }
            $sql .= " ORDER BY date ASC, id ASC";
            $rows = $pdo->prepare($sql); $rows->execute($params); $rows = $rows->fetchAll();
            if (empty($rows)) abort(404, 'No invoices found for ' . e($month));

            $suffix  = $account ? '_' . preg_replace('/[\/\\\:*?"<>|]/', '_', $account) : '';
            return $this->zipResponse($rows, $dir, 'Invoices_' . $month . $suffix . '.zip');
        }

        if ($request->filled('ids')) {
            $ids = array_values(array_filter(array_map('intval', explode(',', $request->query('ids')))));
            if (empty($ids)) abort(400, 'No valid IDs provided.');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $rows = $pdo->prepare("SELECT * FROM invoices WHERE id IN ($placeholders) ORDER BY date ASC, id ASC");
            $rows->execute($ids);
            $rows = $rows->fetchAll();
            if (empty($rows)) abort(404, 'No invoices found for the given IDs.');
            return $this->zipResponse($rows, $dir, 'Invoices_selected_' . date('Y-m-d') . '.zip');
        }

        abort(400, 'Missing id, ids, or month parameter.');
    }

    private function zipResponse(array $rows, string $dir, string $zipName)
    {
        if (!class_exists('ZipArchive')) abort(500, 'ZipArchive extension not available on this server.');

        $tmpZip = tempnam(sys_get_temp_dir(), 'inv_') . '.zip';
        $zip = new \ZipArchive();
        if ($zip->open($tmpZip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Could not create ZIP archive.');
        }
        $usedNames = [];
        foreach ($rows as $inv) {
            $filePath = $dir . $inv['file_name'];
            if (!file_exists($filePath)) continue;
            $datePrefix = date('Y-m-d', strtotime($inv['date']));
            $acc = $inv['account'] ?? '';
            $folder = $acc !== '' ? preg_replace('/[\/\\\:*?"<>|]/', '_', $acc) : 'Unassigned';
            $base   = $datePrefix . '_' . $inv['original_name'];
            $entry  = $folder . '/' . $base;
            $n = 1;
            while (isset($usedNames[$entry])) {
                $entry = $folder . '/' . pathinfo($base, PATHINFO_FILENAME) . '_' . $n . '.' . pathinfo($base, PATHINFO_EXTENSION);
                $n++;
            }
            $usedNames[$entry] = true;
            $zip->addFile($filePath, $entry);
        }
        $zip->close();

        return response()->download($tmpZip, $zipName, ['Content-Type' => 'application/zip'])->deleteFileAfterSend(true);
    }

    /** Port of invoice_preview.php — inline PDF/image stream. */
    public function preview(Request $request)
    {
        $pdo = getDB();
        $id  = (int) $request->query('id', 0);
        if (!$id) abort(400, 'Missing id.');

        $inv = $pdo->prepare("SELECT * FROM invoices WHERE id = ?");
        $inv->execute([$id]);
        $inv = $inv->fetch();
        if (!$inv) abort(404, 'Invoice not found.');

        $allowed = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        if (!in_array($inv['mime_type'], $allowed)) abort(403, 'Preview not available for this file type. Please download it.');

        $filePath = $this->uploadDir() . $inv['file_name'];
        if (!file_exists($filePath)) abort(404, 'File not found on disk.');

        return response()->file($filePath, [
            'Content-Type'        => $inv['mime_type'],
            'Content-Disposition' => 'inline; filename="' . addslashes($inv['original_name']) . '"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }
}
