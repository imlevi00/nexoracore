-- زیادکردنی مانگ بۆ تەمەنی نەخۆش (بۆ منداڵان)
-- ئەنووع: دەستی لەسەر DB جێبەجێ بکە یان لە ڕێگەی import

ALTER TABLE medical_center_patients
  ADD COLUMN age_months TINYINT UNSIGNED NULL DEFAULT NULL
  AFTER age;
