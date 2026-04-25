# Setup guide

First-time setup for the fsaproviders.com repository and deployment pipeline. Follow in order. Skipping steps will cause downstream steps to fail.

## Prerequisites

- Kinsta hosting with MyKinsta dashboard access
- GitHub account (create at github.com if you don't have one)
- macOS or Windows with Terminal/PowerShell access
- The contents of this starter kit, unzipped

## Step 1: Create Kinsta staging environment

1. MyKinsta → **Sites → fsaproviders → Environments**
2. Click **Add environment → Staging**
3. Wait for Kinsta to copy production. Note the staging URL.
4. Leave this tab open — you'll need credentials in step 6.

From this point: **no code changes to production until deployment is set up.**

## Step 2: Install DevKinsta

1. Download from devkinsta.com
2. Install. On first launch, sign in with MyKinsta credentials.
3. DevKinsta → **Import from Kinsta** → select `fsaproviders` staging environment → Import
4. Wait. This pulls files + database. Site boots at a local URL.
5. Log in to local WP admin with your normal credentials.

## Step 3: Install Git and GitHub Desktop

**macOS**: In Terminal, run `git --version`. If "command not found," install Xcode Command Line Tools (the OS will prompt) or `brew install git`.

**Windows**: Download from git-scm.com. Accept defaults during install.

**Both**: Install GitHub Desktop from desktop.github.com. Sign in with your GitHub account.

## Step 4: Create the GitHub repository

1. github.com → **New repository** (green button top right, or github.com/new)
2. Name: `fsaproviders`
3. Visibility: **Private**
4. **Do not** check "Add a README," "Add .gitignore," or "Choose a license"
5. Click **Create repository**
6. Leave the page open — copy the SSH URL (`[email protected]:USERNAME/fsaproviders.git`) or HTTPS URL

## Step 5: Initialize the local repository

Find your DevKinsta site folder:
- DevKinsta → the site → **Open site shell** (or look for the site root path, usually something like `~/DevKinsta/public/fsaproviders`)

In Terminal:

```bash
cd ~/DevKinsta/public/fsaproviders   # adjust path to your actual location
```

Copy the starter kit contents into the root of this folder. You should see:
- `.gitignore`
- `README.md`
- `.github/workflows/` with three YAML files
- `docs/`
- `wp-content/themes/mylisting-child/` (empty child theme scaffold)

Copy your existing plugins from `wp-content/plugins/` — they're already there from the DevKinsta import. The `.gitignore` will only track the four FSA plugins.

Initialize Git:

```bash
git init
git branch -M main
git add .
git status
```

`git status` should show only your child theme, four FSA plugins, `.gitignore`, `README.md`, workflows, and docs. If it shows WordPress core files, `wp-config.php`, or third-party plugins, **stop** — the `.gitignore` isn't being applied correctly. Verify `.gitignore` is at the same level as `wp-content/`.

Once `git status` looks right:

```bash
git commit -m "Initial commit: child theme + custom plugins + CI/CD"
git remote add origin [email protected]:USERNAME/fsaproviders.git  # use YOUR URL
git push -u origin main
```

Refresh the GitHub page. Your files should be there.

Create the staging branch:

```bash
git checkout -b staging
git push -u origin staging
```

## Step 6: Configure GitHub Actions secrets

You need two sets of SSH credentials — one for staging, one for production.

### 6a. Get Kinsta SSH details

MyKinsta → **Sites → fsaproviders → (select staging environment) → Info**

Copy from the "SFTP/SSH" section:
- Host (IP address)
- Port
- Username
- Path (from "Environment details")

Repeat for the live environment. The host, port, username, and path will be different.

### 6b. Generate SSH keys for GitHub Actions

On your local machine (Terminal):

```bash
ssh-keygen -t ed25519 -C "github-actions-kinsta-staging" -f ~/.ssh/kinsta_staging_deploy -N ""
ssh-keygen -t ed25519 -C "github-actions-kinsta-prod" -f ~/.ssh/kinsta_prod_deploy -N ""
```

This creates two key pairs. The `-N ""` means no passphrase (required for automation).

### 6c. Add the PUBLIC keys to Kinsta

SSH into Kinsta staging first (use the SSH terminal command from MyKinsta — the one that looks like `ssh user@ip -p port`). Enter password when prompted.

Inside the SSH session:

```bash
mkdir -p ~/.ssh && chmod 700 ~/.ssh
echo "PASTE_STAGING_PUBLIC_KEY_HERE" >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
exit
```

To get the staging public key contents, on your local machine: `cat ~/.ssh/kinsta_staging_deploy.pub`. Paste that full line where it says `PASTE_STAGING_PUBLIC_KEY_HERE`.

Repeat the whole thing for production: SSH into production, paste the contents of `~/.ssh/kinsta_prod_deploy.pub`.

Test the keys work. On your local machine:

```bash
ssh -i ~/.ssh/kinsta_staging_deploy -p STAGING_PORT STAGING_USER@STAGING_HOST "echo connected"
ssh -i ~/.ssh/kinsta_prod_deploy -p PROD_PORT PROD_USER@PROD_HOST "echo connected"
```

Both should print "connected" without asking for a password. If either asks for a password, the public key isn't installed correctly on that environment.

### 6d. Add secrets to GitHub

GitHub repo → **Settings → Secrets and variables → Actions → New repository secret**

Add these six secrets for staging:

| Name | Value |
|---|---|
| `STAGING_KINSTA_SSH_HOST` | Staging IP |
| `STAGING_KINSTA_SSH_PORT` | Staging port |
| `STAGING_KINSTA_SSH_USER` | Staging username |
| `STAGING_KINSTA_PATH` | Staging path, e.g. `/www/sitename_123/public` |
| `STAGING_KINSTA_SSH_KEY` | Contents of `~/.ssh/kinsta_staging_deploy` (the PRIVATE key — no `.pub`). Include the `-----BEGIN` and `-----END` lines. |

### 6e. Configure production environment with approval gate

GitHub repo → **Settings → Environments → New environment → name `production`**

In the production environment:
- Check **Required reviewers** → add yourself
- Add the five production secrets (same pattern, `PROD_` prefix):
  - `PROD_KINSTA_SSH_HOST`
  - `PROD_KINSTA_SSH_PORT`
  - `PROD_KINSTA_SSH_USER`
  - `PROD_KINSTA_PATH`
  - `PROD_KINSTA_SSH_KEY`

Now every production deploy waits for you to click "Approve" before it runs. Prevents accidental pushes to live.

## Step 7: First deployment test

Make a trivial change in the child theme's `style.css` (add a comment), commit, push to staging:

```bash
git checkout staging
git merge main
echo "/* test */" >> wp-content/themes/mylisting-child/style.css
git add .
git commit -m "Test: trigger staging deploy"
git push
```

GitHub repo → **Actions tab** — you should see the workflow running. Click in to watch. If it fails, read the error log — 90% of first-time failures are typos in secret names or wrong paths.

After success, SSH into Kinsta staging and verify the comment appears:

```bash
ssh user@host -p port "tail -5 /www/sitename_123/public/wp-content/themes/mylisting-child/style.css"
```

If the test comment is there, deployment works.

## Step 8: Address the IP allowlist (if applicable)

MyKinsta → environment → **Tools → IP Allowlist**

If enabled, GitHub Actions will be blocked. Options:
- **Recommended**: disable the allowlist on staging (lower-risk environment)
- **Alternative**: remove the Actions workflow and deploy manually via SFTP for now

You noted earlier you weren't sure about the allowlist — check this after first deploy attempt. The workflow will fail with `Connection timed out` or `Connection refused` if the allowlist is blocking it.

## Step 9: You're ready for Phase 3

Everything above was infrastructure. Actual code migration starts with the snippet audit.

Open `docs/SNIPPET_AUDIT.md` and begin.

## Troubleshooting

**`git status` shows thousands of files.** The `.gitignore` isn't being read. Verify it's at the repo root (same level as `wp-content/`), not inside a subfolder. Run `git rm -r --cached .` then `git add .` to re-apply.

**`git push` fails with "permission denied (publickey)".** Your local machine isn't authenticated to GitHub. Either add an SSH key to GitHub (Settings → SSH and GPG keys) or use HTTPS with a personal access token.

**Actions workflow fails with "Host key verification failed".** The `ssh-keyscan` step didn't populate the known_hosts correctly. Check the SSH host/port secrets are exact.

**Actions workflow fails with "Permission denied" during rsync.** Public key isn't in the server's `authorized_keys`, or permissions are wrong. SSH in manually, run `ls -la ~/.ssh` — `authorized_keys` should be `600`, `.ssh` should be `700`.

**Deploy succeeds but changes don't appear on the site.** Kinsta's object cache. MyKinsta → Tools → Clear Cache. Also check you're looking at the right environment (staging URL vs live URL).
