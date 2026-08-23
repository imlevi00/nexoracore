-- Prescription "consultation" status (finalized visit without any medication)
-- Database: kasher_platform
--
-- A doctor can finalize a visit that has clinical notes (History / Examination /
-- Diagnosis) but no medication. Such a record must NOT reach the pharmacy queue
-- (which only dispenses status = 'pending') yet must still appear in the clinic
-- visit history (which shows everything except 'draft'). 'draft' is reserved for
-- the in-progress auto-save, so a distinct 'consultation' status is added here.

ALTER TABLE `medical_prescriptions`
  MODIFY `status` ENUM('draft', 'pending', 'completed', 'consultation')
  NOT NULL DEFAULT 'pending';
