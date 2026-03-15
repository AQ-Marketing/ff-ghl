# Releases and Who Can Push

## Does the AI (Cursor) have access to GitHub?

**No.** The assistant in Cursor cannot log in to GitHub, use your tokens, or push on its own. It can only:

- Edit files in your project
- Run commands **on your machine** (e.g. `git add`, `git commit`, or your PowerShell scripts)

So **you** (or your script/CI) must run `git push` and any release steps. The way to “have releases happen” is to use GitHub Actions on the repo.

---

## How releases work (no token needed in most cases)

This repo has a **GitHub Action** that creates releases:

- **Workflow file:** `.github/workflows/release.yml`
- **Triggers:** Push to `main`, or push of a tag `v*`, or **manual run** (Actions → “Create Release on Push” → “Run workflow”)

When it runs, GitHub provides a built-in **`GITHUB_TOKEN`** that can create releases and push tags in **this same repo**. For a **private** repo, that token still works for that repository, so you usually **do not** need to add a Personal Access Token.

**Typical flow:**

1. You (or the AI) bump the version in `aqm-ghl-connector.php`.
2. You commit and push to `main` (or run the workflow manually).
3. The Action builds the ZIP, creates the tag and the GitHub release, and uploads the ZIP.

So: **releases are triggered by pushes (or manual run), not by the AI having “access” to the repo.**

---

## If you need a Personal Access Token (PAT) in the Action

Some organizations restrict the default `GITHUB_TOKEN`. In that case you can use a **fine-grained token** (or classic PAT) and give it to the workflow via a **secret**:

1. **Create a fine-grained token (GitHub → Settings → Developer settings → Personal access tokens → Fine-grained):**
   - Repository access: only this repo (e.g. `JustCasey76/ff-ghl`).
   - Permissions:
     - **Contents:** Read and write (for releases and assets).
     - **Metadata:** Read (required).
   - Generate and copy the token.

2. **Add it as a secret in the repo:**
   - Repo → **Settings → Secrets and variables → Actions**
   - **New repository secret**
   - Name: `REPO_TOKEN` (or another name you use in the workflow).
   - Value: the token you copied.

3. **Use it in the workflow**  
   The workflow can be updated to use `secrets.REPO_TOKEN` instead of `secrets.GITHUB_TOKEN` for the steps that create the release and push the tag. If you add `REPO_TOKEN`, the workflow file can be changed to use it.

---

## Summary

| Who / What              | Can push or release? |
|-------------------------|----------------------|
| **Cursor (AI)**         | No. It only edits files and runs commands in your workspace. |
| **You**                 | Yes. You push with `git push` (using your Git credentials). |
| **GitHub Action**       | Yes. On push to `main` or manual run, it creates the release using `GITHUB_TOKEN` (or `REPO_TOKEN` if you add it). |

So: **to “have access to the private repo to push changes and trigger releases,” you:**

1. Push your changes to GitHub (e.g. to `main`).
2. Rely on the existing Action to create the release when that push happens (or when you run the workflow manually).

No need to “give the AI” a token; the automation runs on GitHub with the built-in token (or with a repo secret you add and use in the workflow).
