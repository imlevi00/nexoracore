-- Lab module — per-row choice options on designer cells
-- Database: kasher_platform

ALTER TABLE `lab_test_cells`
  ADD COLUMN `options` JSON NULL DEFAULT NULL AFTER `content`;
