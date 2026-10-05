<?php

namespace App\Http\Controllers;

use App\Services\GamSync;
use App\Services\MetaSync;
use Illuminate\Http\Request;

/**
 * Web endpoints for the GAM / Meta API syncs — the counterparts of the buttons
 * on the ADX, Accounts and Upload pages (originally gam_sync.php / meta_sync.php).
 * Admin only. Diagnostic listings return plain text; a normal run redirects back
 * with a flash message.
 */
class SyncController extends Controller
{
    public function gam(Request $request, GamSync $sync)
    {
        // Runnable from the Upload page (upload.add) or the ADX page (adx.edit).
        if (!$this->canRunGamSync()) {
            abort(403, 'You do not have permission to run the GAM sync.');
        }

        $account = (int) $request->query('account', 0);

        if ($request->has('list_reports')) {
            return response($sync->listReports($account), 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        // Diagnostic: /gam_sync?dump_rows=1 — dump each report's first-page structure + rows.
        if ($request->has('dump_rows')) {
            return response($sync->dumpRows($account), 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        // Test the ad-unit SOAP connection for one network (NetworkService).
        if ($request->has('test_adunit')) {
            $stmt = getDB()->prepare("SELECT name, network_code, key_file FROM adx WHERE id = ?");
            $stmt->execute([$account]);
            $adx = $stmt->fetch();
            if (!$adx) return redirect('/adx')->with('flash', ['type' => 'error', 'msg' => 'ADX network not found.']);
            $res = (new \App\Services\GamAdUnit())->testNetwork($adx);
            return redirect('/adx')->with('flash', ['type' => $res['ok'] ? 'success' : 'error', 'msg' => "[{$adx['name']}] " . $res['msg']]);
        }

        $res = $sync->run($account, $request->has('force'));
        return redirect('/adx')->with('flash', ['type' => $res['ok'] ? 'success' : 'error', 'msg' => $res['msg']]);
    }

    /** True if the current user may run the GAM sync / its diagnostics. */
    private function canRunGamSync(): bool
    {
        return userCan('upload', 'add') || userCan('adx', 'edit');
    }

    public function meta(Request $request, MetaSync $sync)
    {
        // Runnable from the Upload page (upload.add) or the Accounts page (accounts.edit).
        if (!userCan('upload', 'add') && !userCan('accounts', 'edit')) {
            abort(403, 'You do not have permission to run the Meta sync.');
        }

        $account = (int) $request->query('account', 0);

        if ($request->has('list_accounts')) {
            return response($sync->listAdAccounts($account), 200)->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $res = $sync->run($account, $request->has('force'));
        return redirect('/accounts')->with('flash', ['type' => $res['ok'] ? 'success' : 'error', 'msg' => $res['msg']]);
    }

    /** Sync Meta ad accounts / pages / pixels + Creative-Hub media for the builder. */
    public function metaAssets(Request $request, \App\Services\MetaAssetsSync $sync, \App\Services\MetaMediaSync $media)
    {
        if (!userCan('accounts', 'view')) {
            abort(403, 'You do not have permission to sync Meta assets.');
        }

        // Diagnostic: /meta_assets_sync?diag=1 — shows why pages are/aren't syncing.
        if ($request->has('diag')) {
            return response($sync->diagnose((int) $request->query('account', 0)), 200)
                ->header('Content-Type', 'text/plain; charset=utf-8');
        }

        $account = (int) $request->query('account', 0);
        $res  = $sync->run($account);
        $mres = $media->run($account);
        return redirect('/accounts')->with('flash', [
            'type' => ($res['ok'] || $mres['ok']) ? 'success' : 'error',
            'msg'  => $res['msg'] . ' · ' . $mres['msg'],
        ]);
    }

    /**
     * Store an uploaded image into the Creative-Hub media library (the picker's
     * Files tab). Needs Meta Campaigns 'add'; returns JSON for the modal's Upload button.
     */
    public function uploadMedia(Request $request)
    {
        if (!userCan('meta_campaigns', 'add')) abort(403, 'You do not have permission to upload Meta media.');

        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No file received.'], 422);
        }
        if (strpos((string) $file->getMimeType(), 'image/') !== 0) {
            return response()->json(['ok' => false, 'error' => 'Only image files are allowed.'], 422);
        }
        if ($file->getSize() > 15 * 1024 * 1024) {
            return response()->json(['ok' => false, 'error' => 'Image is larger than 15 MB.'], 422);
        }

        $ext  = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $ref  = 'up_' . bin2hex(random_bytes(8));
        $file->storeAs('meta_media', $ref . '.' . $ext, 'public');
        $url  = asset('storage/meta_media/' . $ref . '.' . $ext);
        $name = $file->getClientOriginalName() ?: ($ref . '.' . $ext);
        $size = (int) $file->getSize();

        getDB()->prepare("INSERT INTO meta_media (kind, media_ref, name, url, size_bytes, source, owner_user_id, created_at, updated_at)
                          VALUES ('image', ?, ?, ?, ?, 'upload', ?, NOW(), NOW())")
               ->execute([$ref, $name, $url, $size, currentUserId()]);

        return response()->json(['ok' => true, 'item' => [
            'id' => $ref, 'name' => $name, 'url' => $url, 'size' => humanBytes($size), 'source' => 'upload',
        ]]);
    }
}
