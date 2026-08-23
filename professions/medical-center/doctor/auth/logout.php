<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
medicalDoctorLogout();
setMessage('بە سەرکەوتوویی چوویتە دەرەوە', 'success');
redirect(url('professions/medical-center/doctor/auth/login.php'));
