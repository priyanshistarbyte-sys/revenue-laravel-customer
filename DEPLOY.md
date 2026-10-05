# Auto-deploy on push to `master`

When you push to `master`, GitHub Actions connects to the server over SSH,
pulls the latest code, and runs [`deploy.sh`](deploy.sh) (composer install,
migrations, cache rebuild).

Files: [`.github/workflows/deploy.yml`](.github/workflows/deploy.yml) · [`deploy.sh`](deploy.sh)

---

## One-time setup

### 1. Prepare the server (once)

SSH into the server and clone the repo where the site is served from:

```bash
cd /var/www            # wherever your webroot lives
git clone git@github.com:MangukiyaParth/revenue_laravel.git
cd revenue_laravel
cp .env.example .env    # then fill in real values (DB, GAM, etc.)
php artisan key:generate
composer install --no-dev --optimize-autoloader
php artisan migrate --force
```

Point your web server (Nginx/Apache) document root at `.../revenue_laravel/public`.

### 2. Let the server pull from GitHub without a password

The repo is private, so the server needs read access. Easiest is a **deploy key**:

```bash
ssh-keygen -t ed25519 -C "deploy@server" -f ~/.ssh/revenue_deploy -N ""
cat ~/.ssh/revenue_deploy.pub
```

Add that public key in GitHub → repo **Settings → Deploy keys → Add** (read-only is fine),
then tell git to use it and switch the remote to SSH:

```bash
git remote set-url origin git@github.com:MangukiyaParth/revenue_laravel.git
# ~/.ssh/config on the server:
#   Host github.com
#     IdentityFile ~/.ssh/revenue_deploy
git fetch   # confirm it pulls without asking for a password
```

### 3. Add the GitHub Actions secrets

In GitHub → repo **Settings → Secrets and variables → Actions → New repository secret**,
add these (I can't add them for you — they're credentials):

| Secret         | Value                                                       |
|----------------|-------------------------------------------------------------|
| `SSH_HOST`     | server IP or hostname (e.g. `b2046.vtbitsolution.com`)      |
| `SSH_USER`     | SSH login user (e.g. `root` or a deploy user)              |
| `SSH_PASSWORD` | that user's SSH login password                             |
| `DEPLOY_PATH`  | absolute path to the project on the server (e.g. `/var/www/revenue_laravel`) |
| `SSH_PORT`     | optional, defaults to `22`                                 |

> `SSH_PASSWORD` logs GitHub Actions into the server. The **deploy key** in
> step 2 is a separate thing — it lets the *server* pull from GitHub (git pull).
>
> **Prefer an SSH key over a password** if you can: keys are safer and many
> servers disable password SSH. To use a key instead, replace the
> `password:` line in `.github/workflows/deploy.yml` with
> `key: ${{ secrets.SSH_KEY }}` and store the private key as `SSH_KEY`.

### 4. Test it

- Push any commit to `master`, or run the workflow manually from the
  **Actions** tab → *Deploy to server* → **Run workflow**.
- Watch the run's logs. On success you'll see `✓ Deploy complete`.

---

## Notes & safety

- **`git reset --hard origin/master`** makes the server exactly match master, so any
  files edited *directly on the server* are discarded. `.env`, `vendor/`, and
  `storage/` are git-ignored, so they are **not** touched.
- Migrations run with `--force` (required in production/non-interactive).
- If you use a queue worker, uncomment `php artisan queue:restart` in `deploy.sh`.
- To pause auto-deploy, disable the workflow in the Actions tab (or delete the file).
