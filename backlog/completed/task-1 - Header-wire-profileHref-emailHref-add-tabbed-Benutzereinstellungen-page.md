---
id: TASK-1
title: 'Header: wire profileHref + emailHref; add tabbed Benutzereinstellungen page'
status: Done
assignee: []
created_date: '2026-04-21 19:23'
updated_date: '2026-04-22 04:50'
labels: []
dependencies: []
priority: medium
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Per §18 and §12: Energie has preferences.php and security.php but no Profilbild or E-Mail tabs. Header::render() is missing profileHref and emailHref, so the dropdown falls back to legacy flat mode. Add Profilbild and E-Mail tabs to preferences.php (or a combined account page) and wire all three hrefs. No appPrefsHref needed — Energie has no user-configurable app preferences.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Profilbild tab exists with anchor #profilbild
- [x] #2 E-Mail tab exists with anchor #email
- [x] #3 profileHref, emailHref, securityHref all wired in Header::render()
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
### 1. Audit current preferences.php

Read `web/preferences.php`. Known: handles `upload_avatar` and `change_email` actions. Grep found Sicherheit-related tabs (`data-tab="sicherheit"`, `data-tab="avatar"`). Need to:
- Keep Profilbild (rename `data-tab="avatar"` → `data-tab="profilbild"`, update id and aria-controls)
- Keep E-Mail tab
- Remove Sicherheit tab (security.php already has the content; securityHref already wired to security.php)

### 2. Rename avatar tab → profilbild

In HTML: change `data-tab="avatar"` → `data-tab="profilbild"`, update panel `id="profilbild"`, aria references. The PHP redirect after upload_avatar should already point to `preferences.php#avatar` — update to `preferences.php#profilbild`.

### 3. Remove Sicherheit tab

Delete the Sicherheit `tab-btn` and its `tab-panel` block. All Sicherheit form handlers belong in security.php (already there). Remove any match-arm or condition that routed `sicherheit` actions here.

### 4. Wire hrefs in inc/layout.php

Add to `Header::render()` call:
```php
'profileHref' => $base . '/preferences.php#profilbild',
'emailHref'   => $base . '/preferences.php#email',
```
`securityHref => $base . '/security.php'` was already committed — verify it remains.

### 5. Hash-based tab activation

Ensure the tab JS activates the hash-named tab on load (wlmonitor pattern). The existing preferences.php tab JS should already handle `location.hash` — verify it covers the renamed tab ID.

### 6. Smoke-test

User dropdown shows grouped mode (Benutzereinstellungen section visible). Profilbild → #profilbild tab, E-Mail → #email tab, Sicherheit → security.php. Avatar upload still works.
<!-- SECTION:PLAN:END -->
