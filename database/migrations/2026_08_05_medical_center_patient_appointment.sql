-- زیادکردنی بەروار و کاتی سەردانی (appointment) بۆ medical_center_patients
-- appointment_date  : ڕۆژی سەردان (بنەڕەت: ئەمڕۆ لە فۆرمەکەدا)
-- appointment_time  : کاتی دەستپێکردنی سەردان
-- appointment_end_time : کاتی کۆتایی سەردان (لە دەستپێک + ماوە دەردەکەوێت)
-- ئەنجامدان: دەستی لەسەر DB جێبەجێ بکە یان: php database/migrations/run_2026_08_05_medical_center_patient_appointment.php

ALTER TABLE medical_center_patients
  ADD COLUMN appointment_date DATE NULL DEFAULT NULL AFTER visit_status_updated_at,
  ADD COLUMN appointment_time TIME NULL DEFAULT NULL AFTER appointment_date,
  ADD COLUMN appointment_end_time TIME NULL DEFAULT NULL AFTER appointment_time,
  ADD KEY idx_patients_doctor_appointment (user_id, doctor_id, appointment_date, appointment_time);
