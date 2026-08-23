<?php
require dirname(__DIR__, 2) . '/config/database.php';

$db = new Database();
$conn = $db->getConnection();
$sql = file_get_contents(__DIR__ . '/2026_07_07_smart_scale.sql');

if ($conn->multi_query($sql)) {
    do {
        if ($result = $conn->store_result()) {
            $result->free();
        }
    } while ($conn->more_results() && $conn->next_result());
    echo "migration_ok\n";
} else {
    fwrite(STDERR, 'migration_fail: ' . $conn->error . "\n");
    exit(1);
}
