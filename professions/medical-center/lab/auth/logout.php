<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
medicalLabLogout();
setMessage('بە سەرکەوتوویی چوویتە دەرەوە', 'success');
redirect(url('professions/medical-center/lab/auth/login.php'));
