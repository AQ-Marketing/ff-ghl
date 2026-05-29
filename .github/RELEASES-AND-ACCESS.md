# Releases — How They Work

## Two-repo split

- **Source repo (private):** `AQ-Marketing/ff-ghl` — code, history, branches, issues live here.
- **Releases repo (public):** `AQ-Marketing/aqm-ghl-connector-releases` — empty repo whose only purpose is hosting the built plugin ZIP as a GitHub Release. WordPress sites pull updates from here without any authentication.

## Release flow

1. Bump version in `aqm-ghl-connector.php` (both the header `Version:` and `AQM_GHL_CONNECTOR_VERSION`).
2. Commit and push to `main` on the private repo.
3. Tag the commit: `git tag v1.8.0 && git push aqm v1.8.0` (the `aqm` remote is the AQ-Marketing source repo, `https://github.com/AQ-Marketing/ff-ghl.git`; the legacy `origin` remote still points at the old JustCasey76 repo — don't push tags there).
4. The `release.yml` GitHub Action fires on the tag push:
   - Builds `aqm-ghl-connector.zip` (just the plugin folder, structured for WP install).
   - Creates a release on the **private** repo (internal record, uses built-in `GITHUB_TOKEN`).
   - Mirrors the release to the **public** repo using a PAT stored as `RELEASES_REPO_TOKEN`.

WordPress sites with the plugin installed poll the public repo's releases API. No token, no per-site config.

## Required secret on the private repo

The private repo's Actions need permission to create releases on the public repo. This requires a fine-grained PAT:

1. Create a fine-grained token at https://github.com/settings/personal-access-tokens/new
   - **Resource owner:** `AQ-Marketing`
   - **Repository access:** Only `AQ-Marketing/aqm-ghl-connector-releases`
   - **Permissions:** Contents = Read and write, Metadata = Read
   - **Expiration:** your call (1 year is reasonable; revisit when it nears expiry)
2. Add it as a repo secret on the **private repo** (`AQ-Marketing/ff-ghl`):
   - Repo Settings → Secrets and variables → Actions → New repository secret
   - **Name:** `RELEASES_REPO_TOKEN`
   - **Value:** the token

This is the only token in the system. WordPress sites no longer need any token at all.

## Why this exists

Going public-source for the *source repo* would expose commit history, branches, and unmerged work. A releases-only public repo gives WordPress's update mechanism a public, unauthenticated endpoint while keeping the dev workflow (issues, PRs, branches, history) private.
