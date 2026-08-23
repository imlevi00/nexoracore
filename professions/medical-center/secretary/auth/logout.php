<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
medicalSecretaryLogout();
setMessage('بە سەرکەوتوویی چوویتە دەرەوە', 'success');
redirect(url('professions/medical-center/secretary/auth/login.php'));
