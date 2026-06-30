-- 002_en_swipe_nav.sql
-- Adds the chart swipe-to-page preference to en_preferences.
-- Safe to run repeatedly: ADD COLUMN IF NOT EXISTS. No USE — runs in the
-- connected app DB (jardyx locally, the world4you app DB in prod).

ALTER TABLE `en_preferences`
  ADD COLUMN IF NOT EXISTS `swipe_nav` tinyint(1) NOT NULL DEFAULT 1
  COMMENT 'chart swipe-to-page gesture enabled (1) or off (0)';
