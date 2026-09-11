<?php
echo "Error log path: " . ini_get('error_log') . "\n";
echo "File exists: " . (file_exists(ini_get('error_log')) ? "yes\n" : "no\n");
echo "Tail:\n";
if (file_exists(ini_get('error_log'))) {
    echo shell_exec('tail -n 20 ' . escapeshellarg(ini_get('error_log')));
}

