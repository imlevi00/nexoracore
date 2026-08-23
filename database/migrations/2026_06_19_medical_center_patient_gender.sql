-- زیادکردنی ڕەگەزی نەخۆش بۆ medical_center_patients
-- ئەنووع: دەستی لەسەر DB جێبەجێ بکە یان لە ڕێگەی import

ALTER TABLE medical_center_patients
  ADD COLUMN gender ENUM('male', 'female') NULL DEFAULT NULL
  AFTER age;
