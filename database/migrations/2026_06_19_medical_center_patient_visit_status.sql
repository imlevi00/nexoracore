-- زیادکردنی دۆخی سەردانی نەخۆش بۆ medical_center_patients
-- ئەنووع: دەستی لەسەر DB جێبەجێ بکە یان لە ڕێگەی import

ALTER TABLE medical_center_patients
  ADD COLUMN visit_status ENUM('waiting', 'with_doctor', 'completed') NOT NULL DEFAULT 'waiting' AFTER gender,
  ADD COLUMN visit_status_updated_at DATETIME NULL DEFAULT NULL AFTER visit_status;
