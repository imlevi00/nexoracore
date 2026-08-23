-- زیادکردنی ناونیشان بۆ medical_center_patients
-- address : ناونیشانی نەخۆش (ئیختیاری)
-- ئەنجامدان: دەستی لەسەر DB جێبەجێ بکە یان: php database/migrations/run_2026_08_05_medical_center_patient_address.php

ALTER TABLE medical_center_patients
  ADD COLUMN address VARCHAR(255) NULL DEFAULT NULL AFTER blood_type;
