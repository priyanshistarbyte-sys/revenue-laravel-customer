<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Port of setup.php — runs database migrations. In Laravel the schema lives in
 * migration files, so this simply invokes the migrator. ?reset=1 rebuilds from
 * scratch (drops all tables — destructive, matches the original).
 */
class SetupController extends Controller
{
    public function run(Request $request)
    {
        $reset  = $request->query('reset') === '1';
        $output = '';

        try {
            if ($reset) {
                Artisan::call('migrate:fresh', ['--force' => true]);
                $output .= "<h2 style='font-family:sans-serif;color:green'>✅ Reset complete — all tables rebuilt.</h2>";
            } else {
                Artisan::call('migrate', ['--force' => true]);
            }
            $output .= '<pre style="font-family:monospace;background:#111;color:#8f8;padding:12px;border-radius:6px;white-space:pre-wrap">'
                     . htmlspecialchars(Artisan::output()) . '</pre>';
            $output .= "<p style='font-family:sans-serif'><a href='" . url('/') . "'>Go to Dashboard →</a></p>";
            $output .= "<p style='font-family:sans-serif;color:#666;font-size:12px'>"
                     . "💡 To reset and start fresh, visit <code>/setup?reset=1</code> "
                     . "— ⚠️ this DROPS all tables and deletes existing data.</p>";
        } catch (\Throwable $e) {
            $output = "<h2 style='color:red;font-family:sans-serif'>❌ Error</h2>"
                    . "<p style='color:red;font-family:sans-serif'>" . htmlspecialchars($e->getMessage()) . "</p>"
                    . "<p style='font-family:sans-serif'>Check DB credentials in <code>.env</code>.</p>";
        }

        return response("<div style='padding:20px;font-family:sans-serif'><h2 style='color:#333'>📊 Amaira Database Setup</h2>"
            . "<p><strong>Database:</strong> " . htmlspecialchars(config('database.connections.' . config('database.default') . '.database')) . "</p>"
            . $output . "</div>");
    }
}
