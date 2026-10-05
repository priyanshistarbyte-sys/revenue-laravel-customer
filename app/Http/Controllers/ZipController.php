<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ZipController extends Controller
{
    public function index(Request $request)
    {
        $pageTitle  = 'Zip-Masters';
        $activePage = 'zip-masters';
        $pdo   = getDB();
        $flash = session('flash');

        // Download a stored zip with its original filename (original_name column is
        // optional — fall back to the zip's name).
        if ($request->has('download')) {
            $stmt = $pdo->prepare("SELECT * FROM zips WHERE id = ?");
            $stmt->execute([(int) $request->query('download')]);
            $z = $stmt->fetch();
            if (!$z || empty($z['file']) || !Storage::disk('public')->exists($z['file'])) {
                return redirect('/zip-masters')->with('flash', ['type' => 'error', 'msg' => 'Zip file not found.']);
            }
            $downloadName = ($z['original_name'] ?? '') ?: (($z['name'] ?? 'download') . '.zip');
            return Storage::disk('public')->download($z['file'], $downloadName);
        }

        // Download an archived older version by its id.
        if ($request->has('download_version')) {
            $stmt = $pdo->prepare("SELECT * FROM zip_versions WHERE id = ?");
            $stmt->execute([(int) $request->query('download_version')]);
            $v = $stmt->fetch();
            if (!$v || empty($v['file']) || !Storage::disk('public')->exists($v['file'])) {
                return redirect('/zip-masters')->with('flash', ['type' => 'error', 'msg' => 'Version file not found.']);
            }
            $downloadName = ($v['original_name'] ?? '') ?: (($v['name'] ?? 'version') . '.zip');
            return Storage::disk('public')->download($v['file'], $downloadName);
        }

        if ($request->isMethod('post')) {
            $action = $request->input('action', '');

            $need = ['add' => 'add', 'edit' => 'edit', 'toggle' => 'edit', 'delete' => 'delete'];
            if (isset($need[$action]) && !userCan('zip_masters', $need[$action])) {
                abort(403, 'You do not have permission to ' . $need[$action] . ' zips.');
            }

            if ($action === 'add' || $action === 'edit') {
                $id    = (int) $request->input('id', 0);
                $name  = trim((string) $request->input('name', ''));
                $types = trim((string) $request->input('types', ''));

                if ($name === '' || $types === '') {
                    $flash = ['type' => 'error', 'msg' => 'Name and type are required.'];
                } elseif ($action === 'add' && !$request->hasFile('file')) {
                    $flash = ['type' => 'error', 'msg' => 'Please choose a file to upload.'];
                } else {
                    try {
                        $filePath = null;

                        // if ($request->hasFile('file')) {
                        //     $request->validate([
                        //         'file' => 'file|max:10240', // 10MB — adjust as needed
                        //     ]);
                        //     $filePath = $request->file('file')->store('zips', 'public');
                        // }
                        if ($request->hasFile('file')) {
                            $request->validate([
                                'file' => 'file|max:10240',
                            ]);
                            $uploadedFile = $request->file('file');
                            $originalName = $uploadedFile->getClientOriginalName();
                            $filePath = $uploadedFile->store('zips', 'public');
                        }

                        if ($action === 'add') {
                            $pdo->prepare(
                                "INSERT INTO zips (name, types, file, original_name, created_at, updated_at) VALUES (?,?,?,?,NOW(),NOW())"
                            )->execute([$name, $types, $filePath, $originalName]);
                            $msg = "Zip '$name' created.";
                        } else {
                            if ($filePath) {
                                // Current file + its download name (needed both to archive
                                // it as an older version and to clean it up otherwise).
                                $stmt = $pdo->prepare("SELECT file, original_name FROM zips WHERE id=?");
                                $stmt->execute([$id]);
                                $cur = $stmt->fetch();
                                $old         = $cur['file'] ?? null;
                                $oldOrigName = $cur['original_name'] ?? null;

                                $saveOld    = $request->boolean('save_old_version');
                                $oldVerName = trim((string) $request->input('old_version_name', ''));

                                if ($old && $saveOld) {
                                    // Keep the previous file as a named older version.
                                    if ($oldVerName === '') {
                                        $oldVerName = 'Version ' . date('Y-m-d H:i');
                                    }
                                    $pdo->prepare(
                                        "INSERT INTO zip_versions (zip_id, name, file, original_name, created_at, updated_at)
                                         VALUES (?,?,?,?,NOW(),NOW())"
                                    )->execute([$id, $oldVerName, $old, $oldOrigName]);
                                } elseif ($old) {
                                    // Not archiving — remove the replaced file from disk.
                                    Storage::disk('public')->delete($old);
                                }

                                $pdo->prepare(
                                    "UPDATE zips SET name=?, types=?, file=?, original_name=?, updated_at=NOW() WHERE id=?"
                                )->execute([$name, $types, $filePath, $originalName, $id]);
                            } else {
                                $pdo->prepare(
                                    "UPDATE zips SET name=?, types=?, updated_at=NOW() WHERE id=?"
                                )->execute([$name, $types, $id]);
                            }
                            $msg = "Zip '$name' updated.";
                        }
                        return redirect('/zip-masters')->with('flash', ['type' => 'success', 'msg' => $msg]);
                    } catch (\Throwable $e) {
                        $flash = ['type' => 'error', 'msg' => 'Could not save — a zip with that name may already exist.'];
                    }
                }
            }

            if ($action === 'delete') {
                $id = (int) $request->input('id', 0);
                if ($id) {
                    $stmt = $pdo->prepare("SELECT file FROM zips WHERE id=?");
                    $stmt->execute([$id]);
                    $file = $stmt->fetchColumn();
                    if ($file) {
                        Storage::disk('public')->delete($file);
                    }

                    // Remove archived version files + rows for this zip too.
                    $vStmt = $pdo->prepare("SELECT file FROM zip_versions WHERE zip_id=?");
                    $vStmt->execute([$id]);
                    foreach ($vStmt->fetchAll() as $vr) {
                        if (!empty($vr['file'])) {
                            Storage::disk('public')->delete($vr['file']);
                        }
                    }
                    $pdo->prepare("DELETE FROM zip_versions WHERE zip_id=?")->execute([$id]);

                    $pdo->prepare("DELETE FROM zips WHERE id=?")->execute([$id]);
                    return redirect('/zip-masters')->with('flash', ['type' => 'success', 'msg' => 'Zip deleted.']);
                }
            }

            // Active ↔ inactive. Inactive zips are kept but hidden from the Deploy picker.
            if ($action === 'toggle') {
                $id = (int) $request->input('id', 0);
                $stmt = $pdo->prepare("SELECT name, active FROM zips WHERE id=?");
                $stmt->execute([$id]);
                $z = $stmt->fetch();
                if ($z) {
                    $pdo->prepare("UPDATE zips SET active = 1 - active, updated_at = NOW() WHERE id=?")->execute([$id]);
                    $msg = $z['active']
                        ? "Zip '{$z['name']}' deactivated — it no longer shows in Deploy."
                        : "Zip '{$z['name']}' activated.";
                    return redirect('/zip-masters')->with('flash', ['type' => 'success', 'msg' => $msg]);
                }
            }
        }

        $zips = $pdo->query("SELECT * FROM zips ORDER BY active DESC, name")->fetchAll();

        // Archived older versions, grouped by zip id (newest first).
        $versions = [];
        foreach ($pdo->query("SELECT * FROM zip_versions ORDER BY created_at DESC, id DESC")->fetchAll() as $v) {
            $versions[(int) $v['zip_id']][] = $v;
        }

        $editZip = null;
        if ($request->has('edit')) {
            $eid = (int) $request->query('edit');
            foreach ($zips as $z) {
                if ((int) $z['id'] === $eid) { $editZip = $z; break; }
            }
        }

        return view('zip', compact('pageTitle', 'activePage', 'flash', 'zips', 'editZip', 'versions'));
    }
}