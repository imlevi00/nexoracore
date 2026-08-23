<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
cosmeticDoctorLogout();
redirect(url('professions/cosmetic-center/doctor/auth/login.php'));
