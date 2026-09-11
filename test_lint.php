<?php
echo "<pre>";
echo "Linting item_section_profit_report.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/user/reports/item_section_profit_report.php'));
echo "\nLinting item_profit_report_stats.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/includes/item_profit_report_stats.php'));
echo "\nLinting custom_fields_helpers.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/user/products/includes/custom_fields_helpers.php'));
echo "\nLinting profit_schema.php:\n";
system("php -l " . escapeshellarg(__DIR__ . '/includes/profit_schema.php'));
echo "</pre>";

