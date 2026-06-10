# Auto-refreshing FSIS data with GitHub (no terminal, no VPS install)

Your cPanel has **Git Version Control** but no Terminal/Python tools, so we let
GitHub run the Akamai-bypassing fetch and your site pull the result.

## One-time setup (~10 minutes)

### 1. Put the site in a GitHub repo
- Create a new repo at github.com (private is fine).
- Upload the entire site folder to it (drag-and-drop in GitHub's web UI works:
  "Add file" -> "Upload files" -> drop everything -> Commit).
- Make sure these are included: `fetch_fsis.py`, the `.github/workflows/`
  folder, and `.gitignore`.

### 2. The Action is already configured
`.github/workflows/fetch-fsis.yml` runs daily, fetches FSIS with browser-
impersonating TLS, and commits `data/fsis_manual.json`. To test it now:
- Repo -> **Actions** tab -> "Refresh FSIS recall data" -> **Run workflow**.
- After ~1 min it should commit the data file. Check `data/fsis_manual.json`
  appears in the repo.

### 3. Connect cPanel to the repo (pull-deploy)
- cPanel -> **Git Version Control** -> **Create**.
- Clone URL: your repo's HTTPS URL. (Private repo? Use a GitHub
  Personal Access Token in the URL: `https://TOKEN@github.com/you/repo.git`.)
- Repository Path: your site's docroot (e.g. `public_html` or the domain's
  folder you added in cPanel).
- After it clones, cPanel shows a **Pull or Deploy** button.

### 4. Auto-deploy on each push (optional but recommended)
Add a `.cpanel.yml` (already included) so cPanel copies files into place on
every deploy. Then in Git Version Control, use **Update from Remote** to pull.
To make pulls automatic, add a cron job in cPanel -> **Cron Jobs**:
```
*/30 * * * * cd /home/USER/repo && git pull >/dev/null 2>&1
```
(replace USER/repo with your path). This pulls the latest FSIS commit every
30 min. Meat recalls are low-volume, so even a daily pull is plenty.

## What runs where
- **GitHub** fetches FSIS daily (its servers aren't Akamai-blocked) and
  commits the JSON.
- **cPanel** pulls the repo, so `data/fsis_manual.json` lands on the server.
- **Your site's ingester** reads that file on its normal daily refresh and
  serves FSIS as the 8th source. No Python or curl_cffi needed on your VPS.

If you ever skip all this, the site still runs fine on 7 sources and never
falsely advertises FSIS.
