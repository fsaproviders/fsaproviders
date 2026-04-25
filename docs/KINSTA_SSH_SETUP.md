# Kinsta SSH key setup for GitHub Actions

This is the most error-prone step in the whole setup. Extracted as its own doc because it's where people get stuck.

## What you're building

Two SSH key pairs, used only by GitHub Actions to deploy code to Kinsta:

- **Staging key pair** — public key on Kinsta staging, private key stored as GitHub secret
- **Production key pair** — public key on Kinsta production, private key stored as GitHub secret

These keys are separate from your personal SSH keys and separate from the keys you use to SSH into Kinsta manually. Separation of concerns: a leaked deploy key only affects automation, not your personal access.

## Step 1: Check the IP allowlist first

MyKinsta → Sites → fsaproviders → (environment) → **Tools → IP Allowlist**

- **Enabled?** GitHub Actions cannot connect. Their IPs are dynamic and GitHub doesn't publish a stable range you can whitelist. Options:
  - Disable the allowlist for staging (lowest friction; staging isn't your live site)
  - Keep allowlist on production, deploy manually for now
  - Use a self-hosted GitHub Actions runner on a machine with a static IP (advanced)
- **Disabled?** You're fine, proceed.

## Step 2: Generate two key pairs locally

Open Terminal on your Mac (or PowerShell with OpenSSH on Windows).

```bash
ssh-keygen -t ed25519 -C "github-actions-kinsta-staging" -f ~/.ssh/kinsta_staging_deploy -N ""
ssh-keygen -t ed25519 -C "github-actions-kinsta-prod" -f ~/.ssh/kinsta_prod_deploy -N ""
```

What each flag does:

- `-t ed25519` — modern, secure key type (use this, not RSA)
- `-C "..."` — comment; helps you identify the key later
- `-f ~/.ssh/...` — filename for the key. Two separate names for two separate keys.
- `-N ""` — no passphrase. Required for automation — a passphrase would break non-interactive use.

You now have four files:

```
~/.ssh/kinsta_staging_deploy        # staging PRIVATE key (secret!)
~/.ssh/kinsta_staging_deploy.pub    # staging PUBLIC key (safe to share)
~/.ssh/kinsta_prod_deploy           # production PRIVATE key (secret!)
~/.ssh/kinsta_prod_deploy.pub       # production PUBLIC key (safe to share)
```

The `.pub` files go on Kinsta servers. The files without extension stay on your machine and go into GitHub Secrets. **Never put the private key on the Kinsta server. Never put the public key in GitHub Secrets.**

## Step 3: Get Kinsta SSH connection info

MyKinsta → Sites → fsaproviders → (select staging environment) → **Info**

In the SFTP/SSH section, note:

- SSH command (looks like `ssh [email protected] -p 22222`)
- Path (in "Environment details," looks like `/www/sitename_123/public`)

Extract:

| Field | Example | Where it comes from |
|---|---|---|
| Host | `12.34.56.78` | The IP in the SSH command |
| Port | `22222` | After the `-p` in the SSH command |
| User | `sitename_xyz` | Before the `@` in the SSH command |
| Path | `/www/sitename_123/public` | Environment details |

Repeat for production — the values will be different.

## Step 4: Install the PUBLIC key on Kinsta staging

First, SSH in **manually** using your regular password:

```bash
ssh [email protected] -p 22222
```

Enter your password when prompted. You're now on the Kinsta server.

Inside the SSH session, create the `.ssh` directory if needed and add the staging public key:

```bash
mkdir -p ~/.ssh
chmod 700 ~/.ssh
touch ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Now you need to append the public key. Exit the SSH session temporarily:

```bash
exit
```

Back on your local machine, display the staging public key:

```bash
cat ~/.ssh/kinsta_staging_deploy.pub
```

Output looks like: `ssh-ed25519 AAAAC3Nza...long-string... github-actions-kinsta-staging`

Copy that entire line. Then SSH back into Kinsta staging and append it:

```bash
ssh [email protected] -p 22222
echo 'PASTE-THE-PUBLIC-KEY-LINE-HERE' >> ~/.ssh/authorized_keys
exit
```

## Step 5: Install the PUBLIC key on Kinsta production

Same process, but with the production SSH credentials and the `kinsta_prod_deploy.pub` key.

## Step 6: Test the keys work

On your local machine:

```bash
ssh -i ~/.ssh/kinsta_staging_deploy -p STAGING_PORT STAGING_USER@STAGING_HOST "echo connected"
ssh -i ~/.ssh/kinsta_prod_deploy -p PROD_PORT PROD_USER@PROD_HOST "echo connected"
```

Both should print `connected` without asking for a password. If either asks for a password:

- Check that the public key was pasted correctly (no line breaks, no extra spaces)
- Check permissions on Kinsta: `ls -la ~/.ssh/` — `authorized_keys` should be `600`, `.ssh` should be `700`
- Check you used the right key (`-i` flag pointing to the private key, not the `.pub`)

Fix and re-test. Do not proceed until both tests pass.

## Step 7: Add secrets to GitHub

GitHub repo → **Settings → Secrets and variables → Actions**

### Staging (repository secrets)

Click **New repository secret** for each:

| Name | Value |
|---|---|
| `STAGING_KINSTA_SSH_HOST` | Staging IP (e.g. `12.34.56.78`) |
| `STAGING_KINSTA_SSH_PORT` | Staging port (e.g. `22222`) |
| `STAGING_KINSTA_SSH_USER` | Staging username |
| `STAGING_KINSTA_PATH` | Staging path (e.g. `/www/sitename_123/public`) |
| `STAGING_KINSTA_SSH_KEY` | Entire contents of `~/.ssh/kinsta_staging_deploy` (private key, no `.pub`) |

For the key: `cat ~/.ssh/kinsta_staging_deploy` and copy everything including the `-----BEGIN OPENSSH PRIVATE KEY-----` header and `-----END OPENSSH PRIVATE KEY-----` footer. Paste the whole block into the secret value field.

### Production (environment secrets)

First create the environment: **Settings → Environments → New environment → name: `production`**

In the environment:
- Check **Required reviewers** → add yourself → Save
- Scroll to **Environment secrets** → **Add secret** for each of:

| Name | Value |
|---|---|
| `PROD_KINSTA_SSH_HOST` | |
| `PROD_KINSTA_SSH_PORT` | |
| `PROD_KINSTA_SSH_USER` | |
| `PROD_KINSTA_PATH` | |
| `PROD_KINSTA_SSH_KEY` | |

Environment secrets (vs. repository secrets) give you two benefits:
1. The required-reviewers gate fires before secrets are decrypted
2. Production secrets are isolated from the staging workflow

## Step 8: First deploy test

Make a trivial change in your local repo and push to staging:

```bash
git checkout staging
git pull
echo "/* deploy test $(date) */" >> wp-content/themes/mylisting-child/style.css
git add .
git commit -m "Test: first deploy to Kinsta staging"
git push
```

GitHub repo → **Actions** tab → watch the workflow run. It should:

1. Check out code
2. Configure SSH (adds your secret key, adds Kinsta host to known_hosts)
3. Rsync child theme + four plugins
4. Cleanup SSH

If it succeeds: SSH into Kinsta staging and verify the file changed:

```bash
ssh user@host -p port "tail -5 /www/sitename_123/public/wp-content/themes/mylisting-child/style.css"
```

The test comment should appear. Done.

If it fails: read the Actions log. Common failures:

| Error | Cause | Fix |
|---|---|---|
| `Host key verification failed` | ssh-keyscan couldn't reach Kinsta | Check `STAGING_KINSTA_SSH_HOST` and `STAGING_KINSTA_SSH_PORT` exactly match MyKinsta |
| `Permission denied (publickey)` | Public key not installed, or installed wrong | SSH in manually, check `~/.ssh/authorized_keys` has the right key on one line |
| `Connection timed out` | IP allowlist blocking | Disable the allowlist (see Step 1) |
| `rsync: connection unexpectedly closed` | Usually an intermittent Kinsta issue or wrong path | Check `STAGING_KINSTA_PATH` exactly matches MyKinsta Environment details |
| `invalid format` when loading key | Key didn't copy correctly into secret | Re-copy the private key including BEGIN/END lines |

## Production deploy test

Only do this after staging works. Push a trivial change from `staging` into `main`:

```bash
git checkout main
git pull
git merge staging
git push
```

Actions tab → production workflow starts → **Review deployments** button appears → click it → approve. Workflow proceeds, deploys. Same verification — SSH in and check.

The approval gate is what protects you from accidental production pushes. Don't disable it.

## Rotating keys

If a private key is ever compromised, or annually for hygiene:

1. Generate a new key pair (Step 2 with a new filename)
2. Install the new public key on Kinsta (Step 4/5) — append, don't overwrite yet
3. Update the GitHub secret with the new private key
4. Test a deploy works
5. Once confirmed, remove the old public key from Kinsta's `~/.ssh/authorized_keys`

## Why two separate key pairs instead of one

- Compromise isolation: staging key leak doesn't give access to production
- Audit trail: Kinsta logs show which key authenticated, so you can see which environment's automation ran
- Least privilege: if you later use self-hosted runners only for production, the staging key never touches that runner
