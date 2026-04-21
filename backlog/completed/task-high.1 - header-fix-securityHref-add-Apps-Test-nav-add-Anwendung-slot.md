---
id: TASK-HIGH.1
title: 'header: fix securityHref; add Apps + Test nav; add Anwendung slot'
status: Done
assignee: []
created_date: '2026-04-21 16:26'
updated_date: '2026-04-21 16:31'
labels: []
dependencies: []
parent_task_id: TASK-HIGH
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Audit 2026-04-21 — issues in inc/layout.php Header::render() call:

1. CRITICAL: securityHref not passed → Chrome uses default 'password.php' which does not exist in Energie; app has 'security.php'. Fix: add securityHref => $base . '/security.php'
2. MISSING Apps nav: no cross-app links in header — add appsMenu with wlmonitor, suche, simplechat, zeiterfassung, last.fm (excluding Energie)
3. MISSING Test submenu: add conditional Test submenu for *.test URLs
4. ANWENDUNG: Energie has no app-specific user settings (Aktuell/Woche/Monat/Jahr are views, not prefs) — skip Anwendung slot unless a settings page is added later

File: inc/layout.php lines ~99-110
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 securityHref => 'security.php' passed to Header::render()
- [x] #2 appsMenu lists all apps except Energie itself
- [x] #3 Test submenu present locally, hidden in prod
<!-- AC:END -->
