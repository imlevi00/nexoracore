-- زیادکردنی پیشە و جۆری خوێن بۆ medical_center_patients
-- profession : پیشەی نەخۆش (ئیختیاری)
-- blood_type : جۆری خوێنی نەخۆش (ئیختیاری، وەک A+, O-)
-- ئەنجامدان: دەستی لەسەر DB جێبەجێ بکە یان: php database/migrations/run_2026_08_05_medical_center_patient_profile.php

ALTER TABLE medical_center_patients
  ADD COLUMN profession VARCHAR(100) NULL DEFAULT NULL AFTER gender,
  ADD COLUMN blood_type VARCHAR(10) NULL DEFAULT NULL AFTER profession;
