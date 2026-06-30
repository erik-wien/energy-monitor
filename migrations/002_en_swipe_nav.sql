-- 002_en_swipe_nav.sql
-- Adds the chart swipe-to-page preference to en_preferences.
--
-- Run-once is guaranteed by the deploy migration runner (ssh_deploy.php tracks
-- applied files in db_migrations and skips them), so this does NOT use
-- `ADD COLUMN IF NOT EXISTS` — world4you's MySQL (5.5-era) does not support that
-- MariaDB extension and the STRICT mysqli runner aborts on the syntax error.
-- No USE — runs in the connected app DB (jardyx locally, the world4you app DB).

ALTER TABLE `en_preferences`
  ADD COLUMN `swipe_nav` tinyint(1) NOT NULL DEFAULT 1
  COMMENT 'chart swipe-to-page gesture enabled (1) or off (0)';
