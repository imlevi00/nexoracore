<?php
require_once dirname(__DIR__) . '/includes/bootstrap.php';
cosmeticCenterLogout();
redirect(url('professions/cosmetic-center/center/auth/login.php'));
