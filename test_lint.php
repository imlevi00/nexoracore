<?php
echo "<pre>";
echo "Linting user/telegram/index.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/user/telegram/index.php'));
echo "\nLinting user/telegram/telegram_helper.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/user/telegram/telegram_helper.php'));
echo "\nLinting user/telegram/auto_send.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/user/telegram/auto_send.php'));
echo "</pre>";

