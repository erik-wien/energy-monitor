---
id: TASK-LOW.1
title: Align web/styles/ directory with other apps' web/css/
status: Done
assignee: []
created_date: '2026-04-21 05:44'
updated_date: '2026-04-21 11:54'
labels: []
dependencies: []
parent_task_id: TASK-LOW
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-20: Energie is the only app using web/styles/ instead of web/css/. Every other app uses web/css/shared → css_library. Rename web/styles/ to web/css/ and update all references (HTML <link>, nginx config, deploy rsync paths). Low priority — cosmetic drift only — but worth fixing during next larger Energie change.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 web/styles/ renamed to web/css/
- [x] #2 shared symlink absolute, pointing at /Users/erikr/Git/css_library
- [x] #3 All <link rel="stylesheet"> paths updated
- [x] #4 nginx + .htaccess + deploy paths updated
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Directory rename `web/styles/` → `web/css/` to match ecosystem convention.

**Do this during a low-traffic window** — the rename + deploy must be atomic or there's a brief 404 window on styles.

1. **Local rename** (Git repo, dev env):
   ```bash
   cd ~/Git/Energie/web && git mv styles css
   ```
   Verify the `shared/` symlink inside — `ls -la web/css/shared` should resolve to `/Users/erikr/Git/css_library`. If it was relative, make it absolute.

2. **Update references** — grep-replace across Energie:
   - `grep -rn "styles/" web/ inc/` — every hit that refers to the renamed dir.
   - HTML `<link rel="stylesheet" href="styles/…">` → `href="css/…"`. Check `inc/_header.php` and any inline heads.
   - Inline `@import` in any CSS file: `@import url("styles/…")` → `css/…`.
   - PHP: any `$base . '/styles/...'` → `/css/...`.

3. **Nginx / Apache config** (prod + dev):
   - Dev (local Apache alias): check `/etc/apache2/extra/httpd-vhosts.conf` or the local config for any `/styles/` references.
   - Prod akadbrain: nginx vhost may reference `/styles/` for caching rules — update or remove.

4. **Deploy path** — `mcp/deploy.py` or the per-app deploy — any rsync include/exclude list that names `styles/` needs update.

5. **Deploy** to both dev and prod after local verification. rsync `--delete` will remove the old `styles/` dir on the target.

6. **Smoke test:**
   - Dev: hard-reload `energie.test` — Network tab shows CSS from `/css/…`, no 404 on `/styles/…`.
   - Prod: same check on live URL.

Low priority, so do during the next larger Energie change to amortize deploy cost.
<!-- SECTION:PLAN:END -->
