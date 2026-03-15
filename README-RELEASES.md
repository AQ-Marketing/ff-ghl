# Release and Zip Process (ff-ghl / aqm-ghl-connector)

## Versioning
- Update version in `aqm-ghl-connector.php` header and the `AQM_GHL_CONNECTOR_VERSION` constant.
- Tag format: `vX.Y.Z`.

## Build ZIP (forward slashes, single root folder)
```powershell
cd scripts
.\create-plugin-zip.ps1 -Version "1.1.0"
```
Outputs `aqm-ghl-connector-1.1.0.zip` with correct `aqm-ghl-connector/` root and forward-slash paths.

## Create GitHub Release (manual script)
Requires `GITHUB_TOKEN` (repo scope) in env or pass `-Token`.
```powershell
cd scripts
.\create-release.ps1 -Version "1.1.0"
```
Creates tag `v1.1.0`, release, and uploads the ZIP to `JustCasey76/ff-ghl`.

## Why "Update available" may not show on the WordPress Plugins page
1. **No release on GitHub yet** – The updater reads from GitHub Releases. If you only bumped the version locally and did not run `create-release.ps1`, there is no new release for WordPress to see. Create a release (see above) and upload the ZIP.
2. **Private repo** – If the repo is private, the GitHub API needs a token. Set **GitHub token** in GHL + Formidable settings, or define `AQM_GHL_GITHUB_TOKEN` in `wp-config.php`.
3. **Cached "no update"** – WordPress caches update checks. In **GHL + Formidable** use **Update Management → Clear Update Cache**, then reload the Plugins page.
4. **Already on latest** – If the installed plugin version is the same or higher than the latest release tag (e.g. you deployed 1.5.16 via FTP), no update will be offered.

## Notes
- Script excludes `.git`, `scripts`, `.vscode` from the ZIP.
- If you change the plugin slug, update the slug in both scripts.
- For CI automation, mirror the pattern from `aqm-chatbot` (GitHub Actions) if desired.

## FTP Deploy Package (changed files only)
Creates `FTP-Deploy/` with only changed files since the last run.
```powershell
cd scripts
.\create-ftp-deploy.ps1
```
Re-run to refresh with only new changes. State is tracked in `.last-ftp-deploy.json` (ignored by git).

