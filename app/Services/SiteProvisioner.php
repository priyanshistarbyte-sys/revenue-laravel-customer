<?php

namespace App\Services;

/**
 * Creates an aaPanel-style site over SSH — the fallback used when the panel HTTP
 * API is blocked (e.g. by a mandatory security entrance). Writes an aaPanel-format
 * nginx vhost into the panel's vhost dir and reloads nginx; applySsl() issues a
 * Let's Encrypt cert via the bundled acme.sh and swaps the vhost to HTTPS.
 *
 * Sites created this way serve correctly; they just aren't tracked in aaPanel's
 * own website list (that lives in the panel's SQLite DB).
 */
class SiteProvisioner
{
    /** Create (or refresh) the HTTP nginx vhost for $domain. Throws on nginx failure. */
    public function createSite(SSHService $ssh, string $domain, string $phpVersion = '74'): array
    {
        $domain     = trim($domain);
        $phpVersion = preg_replace('/\D/', '', $phpVersion) ?: '74';
        $vhostB64   = base64_encode($this->vhost($domain));

        $script = <<<BASH
        DOMAIN="{$domain}"
        PHPVER="{$phpVersion}"
        ROOT="/www/wwwroot/\$DOMAIN"
        VHOST="/www/server/panel/vhost/nginx/\$DOMAIN.conf"
        REWRITE="/www/server/panel/vhost/rewrite/\$DOMAIN.conf"
        WELLKNOWN="/www/server/panel/vhost/nginx/well-known/\$DOMAIN.conf"
        EXTDIR="/www/server/panel/vhost/nginx/extension/\$DOMAIN"
        NGX="/www/server/nginx/conf"

        mkdir -p "\$ROOT" "\$EXTDIR" /www/wwwlogs \\
                 /www/server/panel/vhost/nginx/well-known /www/server/panel/vhost/rewrite

        [ -f "\$REWRITE" ]   || : > "\$REWRITE"
        [ -f "\$WELLKNOWN" ] || : > "\$WELLKNOWN"

        if [ ! -f "\$NGX/enable-php-\$PHPVER.conf" ]; then
            alt=\$(ls "\$NGX"/enable-php-*.conf 2>/dev/null | head -1)
            [ -n "\$alt" ] && PHPVER=\$(basename "\$alt" | sed 's/enable-php-//; s/\\.conf//')
        fi

        echo '{$vhostB64}' | base64 -d > "\$VHOST"
        sed -i "s/__PHPVER__/\$PHPVER/g" "\$VHOST"

        ulimit -n 65535 2>/dev/null || ulimit -n "\$(ulimit -Hn)" 2>/dev/null || true
        if nginx -t 2>/tmp/aa_ngt.log; then
            nginx -s reload 2>/dev/null || /etc/init.d/nginx reload 2>/dev/null || true
            echo "SITE_OK \$DOMAIN php\$PHPVER"
        else
            rm -f "\$VHOST"
            echo "NGINX_FAIL"; cat /tmp/aa_ngt.log; exit 1
        fi
        BASH;

        $script = str_replace("\r", '', $script);
        $out    = (string) $ssh->execute("echo '" . base64_encode($script) . "' | base64 -d | bash");

        if (strpos($out, 'SITE_OK') === false) {
            throw new \Exception('SSH site creation failed for ' . $domain . ': ' . trim($out));
        }
        return ['status' => true, 'via' => 'ssh', 'already_exists' => false, 'msg' => trim($out)];
    }

    /**
     * Issue a Let's Encrypt cert over SSH (acme.sh, webroot validation) and swap
     * the vhost to HTTPS. Requires the site's DNS to point at this server.
     */
    public function applySsl(SSHService $ssh, string $domain, string $phpVersion = '74'): array
    {
        $domain      = trim($domain);
        $phpVersion  = preg_replace('/\D/', '', $phpVersion) ?: '74';
        $sslVhostB64 = base64_encode($this->vhostSsl($domain));

        $script = <<<BASH
        DOMAIN="{$domain}"
        PHPVER="{$phpVersion}"
        ROOT="/www/wwwroot/\$DOMAIN"
        VHOST="/www/server/panel/vhost/nginx/\$DOMAIN.conf"
        CERTDIR="/www/server/panel/vhost/cert/\$DOMAIN"
        NGX="/www/server/nginx/conf"
        mkdir -p "\$CERTDIR"

        ACME=""
        for p in /root/.acme.sh/acme.sh "\$HOME/.acme.sh/acme.sh"; do [ -x "\$p" ] && ACME="\$p" && break; done
        [ -z "\$ACME" ] && command -v acme.sh >/dev/null 2>&1 && ACME="acme.sh"

        # acme.sh not present → install it once (needs outbound internet).
        if [ -z "\$ACME" ]; then
            (curl -fsSL https://get.acme.sh | sh -s email="admin@\$DOMAIN") >/tmp/aa_acme_install.log 2>&1 || \\
            (wget -qO- https://get.acme.sh | sh -s email="admin@\$DOMAIN") >>/tmp/aa_acme_install.log 2>&1 || true
            for p in /root/.acme.sh/acme.sh "\$HOME/.acme.sh/acme.sh"; do [ -x "\$p" ] && ACME="\$p" && break; done
        fi
        if [ -z "\$ACME" ]; then echo "ACME_MISSING (auto-install failed)"; tail -n 20 /tmp/aa_acme_install.log 2>/dev/null; exit 1; fi

        "\$ACME" --register-account -m "admin@\$DOMAIN" --server letsencrypt >/dev/null 2>&1 || true
        "\$ACME" --issue -d "\$DOMAIN" -w "\$ROOT" --server letsencrypt --keylength 2048 >/tmp/aa_acme.log 2>&1
        rc=\$?
        if [ \$rc -ne 0 ] && [ \$rc -ne 2 ]; then
            echo "ACME_ISSUE_FAILED (rc=\$rc)"; tail -n 25 /tmp/aa_acme.log; exit 1
        fi

        "\$ACME" --install-cert -d "\$DOMAIN" \\
            --key-file "\$CERTDIR/privkey.pem" \\
            --fullchain-file "\$CERTDIR/fullchain.pem" \\
            --reloadcmd "nginx -s reload" >/dev/null 2>&1

        if [ ! -s "\$CERTDIR/fullchain.pem" ] || [ ! -s "\$CERTDIR/privkey.pem" ]; then echo "CERT_MISSING"; exit 1; fi

        if [ ! -f "\$NGX/enable-php-\$PHPVER.conf" ]; then
            alt=\$(ls "\$NGX"/enable-php-*.conf 2>/dev/null | head -1)
            [ -n "\$alt" ] && PHPVER=\$(basename "\$alt" | sed 's/enable-php-//; s/\\.conf//')
        fi

        echo '{$sslVhostB64}' | base64 -d > "\$VHOST"
        sed -i "s/__PHPVER__/\$PHPVER/g" "\$VHOST"

        ulimit -n 65535 2>/dev/null || ulimit -n "\$(ulimit -Hn)" 2>/dev/null || true
        if nginx -t 2>/tmp/aa_sslngt.log; then
            nginx -s reload 2>/dev/null || /etc/init.d/nginx reload 2>/dev/null || true
            echo "SSL_OK \$DOMAIN"
        else
            cat /tmp/aa_sslngt.log; echo "NGINX_FAIL"; exit 1
        fi
        BASH;

        $script = str_replace("\r", '', $script);
        $out    = (string) $ssh->execute("echo '" . base64_encode($script) . "' | base64 -d | bash");

        if (strpos($out, 'SSL_OK') === false) {
            throw new \Exception('SSL failed for ' . $domain . ': ' . trim($out));
        }
        return ['status' => true, 'via' => 'ssh', 'msg' => trim($out)];
    }

    /** aaPanel-style HTTP-only vhost (SSL block added later by applySsl). */
    private function vhost(string $domain): string
    {
        $root = "/www/wwwroot/{$domain}";
        return <<<CONF
server
{
    listen 80;
    listen [::]:80;
    server_name {$domain};
    index index.php index.html index.htm default.php default.htm default.html;
    root {$root};
    include /www/server/panel/vhost/nginx/extension/{$domain}/*.conf;

    #CERT-APPLY-CHECK--START
    include /www/server/panel/vhost/nginx/well-known/{$domain}.conf;
    #CERT-APPLY-CHECK--END

    #ERROR-PAGE-START
    error_page 404 /404.html;
    error_page 502 /502.html;
    #ERROR-PAGE-END

    #PHP-INFO-START
    include enable-php-__PHPVER__.conf;
    #PHP-INFO-END

    #REWRITE-START
    include /www/server/panel/vhost/rewrite/{$domain}.conf;
    #REWRITE-END

    location ~ ^/(\.user\.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|README\.md) { return 404; }
    location ~ \.well-known { allow all; }
    if (\$uri ~ "^/\.well-known/.*\.(php|jsp|py|js|css|lua|ts|go|zip|tar\.gz|rar|7z|sql|bak)\$") { return 403; }

    location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)\$ { expires 30d; access_log /dev/null; error_log /dev/null; }
    location ~ .*\.(js|css)?\$ { expires 12h; access_log /dev/null; error_log /dev/null; }

    access_log /www/wwwlogs/{$domain}.log;
    error_log  /www/wwwlogs/{$domain}.error.log;
}
CONF;
    }

    /** Full aaPanel-style vhost WITH the SSL (443) block. */
    private function vhostSsl(string $domain): string
    {
        $root = "/www/wwwroot/{$domain}";
        return <<<CONF
server
{
    listen 80;
    listen 443 ssl;
    listen [::]:80;
    listen [::]:443 ssl;
    http2 on;
    server_name {$domain};
    index index.php index.html index.htm default.php default.htm default.html;
    root {$root};
    include /www/server/panel/vhost/nginx/extension/{$domain}/*.conf;

    #CERT-APPLY-CHECK--START
    include /www/server/panel/vhost/nginx/well-known/{$domain}.conf;
    #CERT-APPLY-CHECK--END

    #SSL-START
    ssl_certificate    /www/server/panel/vhost/cert/{$domain}/fullchain.pem;
    ssl_certificate_key    /www/server/panel/vhost/cert/{$domain}/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers EECDH+CHACHA20:EECDH+AES128:RSA+AES128:EECDH+AES256:RSA+AES256:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    add_header Strict-Transport-Security "max-age=31536000";
    error_page 497  https://\$host\$request_uri;
    #SSL-END

    #ERROR-PAGE-START
    error_page 404 /404.html;
    error_page 502 /502.html;
    #ERROR-PAGE-END

    #PHP-INFO-START
    include enable-php-__PHPVER__.conf;
    #PHP-INFO-END

    #REWRITE-START
    include /www/server/panel/vhost/rewrite/{$domain}.conf;
    #REWRITE-END

    location ~ ^/(\.user\.ini|\.htaccess|\.git|\.env|\.svn|\.project|LICENSE|README\.md) { return 404; }
    location ~ \.well-known { allow all; }
    if (\$uri ~ "^/\.well-known/.*\.(php|jsp|py|js|css|lua|ts|go|zip|tar\.gz|rar|7z|sql|bak)\$") { return 403; }

    location ~ .*\.(gif|jpg|jpeg|png|bmp|swf)\$ { expires 30d; access_log /dev/null; error_log /dev/null; }
    location ~ .*\.(js|css)?\$ { expires 12h; access_log /dev/null; error_log /dev/null; }

    access_log /www/wwwlogs/{$domain}.log;
    error_log  /www/wwwlogs/{$domain}.error.log;
}
CONF;
    }
}
