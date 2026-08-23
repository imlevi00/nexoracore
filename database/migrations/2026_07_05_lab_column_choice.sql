-- Lab module — choice column type with custom options for result entry
-- Database: kasher_platform

ALTER TABLE `lab_test_columns`
  MODIFY `col_type` ENUM('result', 'reference', 'unit', 'text', 'status', 'choice') NOT NULL DEFAULT 'text',
  ADD COLUMN `options` JSON NULL DEFAULT NULL AFTER `width`;
