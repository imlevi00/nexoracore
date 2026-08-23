<?php
/**
 * Patient visit history page.
 *
 * This page provides the exact same workflow as prescriptions/create.php:
 * the patient's visit history is shown alongside a fully working
 * "Write Prescription" form, so a doctor can review past visits and create a
 * new prescription from the same screen.
 *
 * create.php already renders the visit-history panel, so rather than duplicate
 * that logic we delegate to it entirely. The include is safe because create.php
 * resolves all of its own paths from its own __DIR__, and its <form> posts to
 * the current URL (this page), so the create flow runs end to end here too.
 *
 * Unlike create.php (which shows only the Treatment panel), this page keeps the
 * clinical fields (History / Examination / Diagnoses). We signal that with a
 * flag that create.php checks before rendering / validating those fields.
 */
define('SC_SHOW_CLINICAL_FIELDS', true);
require dirname(__DIR__) . '/prescriptions/create.php';
