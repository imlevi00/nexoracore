<?php
$output = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/user/products/includes/custom_fields_helpers.php') . ' 2>&1');
file_put_contents(__DIR__ . '/lint_result.txt', "custom_fields_helpers.php:\n" . $output . "\n");

$output = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/user/reports/item_section_profit_report.php') . ' 2>&1');
file_put_contents(__DIR__ . '/lint_result.txt', "item_section_profit_report.php:\n" . $output . "\n", FILE_APPEND);

$output = shell_exec('php -l ' . escapeshellarg(__DIR__ . '/includes/item_profit_report_stats.php') . ' 2>&1');
file_put_contents(__DIR__ . '/lint_result.txt', "item_profit_report_stats.php:\n" . $output . "\n", FILE_APPEND);

