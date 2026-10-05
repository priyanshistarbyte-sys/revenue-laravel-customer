<?php

namespace App\Services;

class DeploymentService
{
    protected SSHService $ssh;
    protected SFTPService $sftp;
    protected AapanelService $aapanel;

    /**
     * @param array $cfg per-server credentials (ssh_host/port/username/password,
     *                   bt_panel_url/key/php_version). Empty → falls back to .env.
     */
    public function __construct(array $cfg = [])
    {
        $this->ssh = new SSHService($cfg);
        $this->sftp = new SFTPService($cfg);
        $this->aapanel = new AapanelService($cfg);
    }

    public function registerSite($domain)
    {
        // Meta-URL sub-site → aaPanel "sub" category.
        return $this->aapanel->addSite($domain, 'sub');
    }

    public function applySsl($domain)
    {
        return $this->aapanel->applySsl($domain);
    }

    public function uploadZip($domain, $localZip)
    {
        if (!file_exists($localZip)) {
            throw new \Exception("Local ZIP file not found: " . $localZip);
        }

        $remoteZip = "/www/wwwroot/$domain/$domain.zip";

        $this->sftp->upload($localZip, $remoteZip);

        return $remoteZip;
    }

    /**
     * Does everything that used to be 4 separate SSH round-trips
     * (create folder, check unzip, extract+flatten, replace
     * placeholder, write rewrite rules) in ONE ssh->execute() call.
     * Each round-trip has real network latency to the VPS even with
     * a reused connection, so collapsing them into a single script
     * is the single biggest speed win available without touching
     * external services like Let's Encrypt.
     *
     * @param string $domain
     * @param string $placeholderToken e.g. "MAIN_URL" (without brackets)
     * @param string $placeholderValue the value to replace [TOKEN] with
     * @param bool   $clean when true, delete the site's existing files first
     *                      (except the uploaded zip and aaPanel's own default
     *                      files) so no stale code with an old placeholder value
     *                      is left behind on a re-deploy.
     */
    public function deployFiles($domain, $placeholderToken, $placeholderValue, $clean = false)
    {
        $remotePath = "/www/wwwroot/$domain";
        $rewriteDir = '/www/server/panel/vhost/rewrite';
        $rewritePath = "{$rewriteDir}/{$domain}.conf";

        $scriptTemplate = <<<'BASH'
        set -uo pipefail

        REMOTE_PATH="__REMOTE_PATH__"
        DOMAIN="__DOMAIN__"
        REWRITE_DIR="__REWRITE_DIR__"
        REWRITE_PATH="__REWRITE_PATH__"

        mkdir -p "$REMOTE_PATH"
        cd "$REMOTE_PATH" || exit 1

        command -v unzip >/dev/null 2>&1 || { echo "unzip not found on server" >&2; exit 1; }

        __CLEAN_BLOCK__

        # aaPanel drops default .htaccess/404.html/502.html/index.html when a site
        # is created — remove them first so they never linger over the real site.
        rm -f ".htaccess" "404.html" "502.html" "index.html"

        unzip -o "$DOMAIN.zip" -x '.user.ini'
        rm -f "$DOMAIN.zip"

        shopt -s dotglob nullglob

        default_files=(".user.ini" ".htaccess" "404.html" "502.html" "index.html")

        is_default() {
        local f="$1"
        for d in "${default_files[@]}"; do
            if [ "$f" == "$d" ]; then
            return 0
            fi
        done
        return 1
        }

        entries=()
        for f in *; do
        if ! is_default "$f"; then
            entries+=("$f")
        fi
        done

        if [ "${#entries[@]}" -eq 1 ] && [ -d "${entries[0]}" ]; then
        inner="${entries[0]}"
        mv "$inner"/* . 2>/dev/null || true
        mv "$inner"/.[!.]* . 2>/dev/null || true
        rmdir "$inner" 2>/dev/null || true
        fi

        find . -type d ! -name '.user.ini' -exec chmod 755 {} \;
        find . -type f ! -name '.user.ini' -exec chmod 644 {} \;

        grep -rFl '[__PLACEHOLDER_TOKEN__]' "$REMOTE_PATH" 2>/dev/null | xargs -r sed -i 's#\[__PLACEHOLDER_TOKEN__\]#__PLACEHOLDER_VALUE__#g'

        mkdir -p "$REWRITE_DIR"
        cat > "$REWRITE_PATH" <<'EOR'
        rewrite ^/blog/([^/]+)/?$ /blog.php?id=$1 break;
        rewrite ^/about-us/?$ /about-us.php break;
        rewrite ^/contact-us/?$ /contact-us.php break;
        rewrite ^/privacy-policy/?$ /privacy-policy.php break;
        rewrite ^/terms-of-service/?$ /terms-of-service.php break;
        EOR

        # Many vhosts => nginx -t opens every site log; lift the SSH shell fd limit (default 1024).
        ulimit -n 65535 2>/dev/null || ulimit -n "$(ulimit -Hn)" 2>/dev/null || true
        nginx -t && nginx -s reload
        BASH;

        // On a clean re-deploy, remove the old site files first — but keep the
        // freshly-uploaded zip and aaPanel's default files intact.
        $cleanBlock = $clean
            ? 'find "$REMOTE_PATH" -mindepth 1 -maxdepth 1 ! -name "$DOMAIN.zip" ! -name ".user.ini" ! -name ".htaccess" ! -name "404.html" ! -name "502.html" ! -name "index.html" -exec rm -rf {} +'
            : ':';

        $script = str_replace(
            [
                '__REMOTE_PATH__',
                '__DOMAIN__',
                '__REWRITE_DIR__',
                '__REWRITE_PATH__',
                '__PLACEHOLDER_TOKEN__',
                '__PLACEHOLDER_VALUE__',
                '__CLEAN_BLOCK__',
            ],
            [
                $remotePath,
                $domain,
                $rewriteDir,
                $rewritePath,
                $placeholderToken,
                $placeholderValue,
                $cleanBlock,
            ],
            $scriptTemplate
        );

        // Defensive: strip Windows-style carriage returns in case
        // this file gets saved with CRLF line endings.
        $script = str_replace("\r", '', $script);

        $encoded = base64_encode($script);

        $command = "echo '{$encoded}' | base64 -d | bash";

        return $this->ssh->execute($command);
    }
}