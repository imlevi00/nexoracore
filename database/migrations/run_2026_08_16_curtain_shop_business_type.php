<?php
/**
 * Runner for 2026_08_16_curtain_shop_business_type.sql (database: test_test / app DB).
 * Idempotent: skips if curtain_shop already exists; uses next available id.
 * Usage:  php database/migrations/run_2026_08_16_curtain_shop_business_type.php
 */
require dirname(__DIR__, 2) . '/config/database.php';

$db = new Database();
$conn = $db->connect();
if (!($conn instanceof mysqli)) {
    fwrite(STDERR, "migration_fail: app DB connection unavailable\n");
    exit(1);
}

$dbName = $conn->query("SELECT DATABASE()")->fetch_row()[0];

$check = $conn->prepare("SELECT id, code, name_ku, sort_order FROM business_types WHERE code = ? LIMIT 1");
$code = 'curtain_shop';
$check->bind_param('s', $code);
$check->execute();
$existing = $check->get_result()->fetch_assoc();
$check->close();

if ($existing) {
    if ((string)$existing['name_ku'] !== 'دوکانی پەردە') {
        $update = $conn->prepare("UPDATE business_types SET name_ku = ?, sort_order = ? WHERE id = ?");
        $nameKu = 'دوکانی پەردە';
        $sortOrder = (int)$existing['sort_order'];
        $id = (int)$existing['id'];
        $update->bind_param('sii', $nameKu, $sortOrder, $id);
        if (!$update->execute()) {
            fwrite(STDERR, "migration_fail: " . $conn->error . "\n");
            exit(1);
        }
        $update->close();
        echo "updated: business_types id={$id} name_ku=دوکانی پەردە\n";
    } else {
        echo "skip: business_types already has id={$existing['id']} code=curtain_shop ({$dbName})\n";
    }
    echo "migration_ok ({$dbName})\n";
    exit(0);
}

$maxRow = $conn->query("SELECT COALESCE(MAX(id), 0) AS max_id, COALESCE(MAX(sort_order), 0) AS max_sort FROM business_types")->fetch_assoc();
$nextId = (int)$maxRow['max_id'] + 1;
$nextSort = (int)$maxRow['max_sort'] + 1;
$nameKu = 'دوکانی پەردە';

$insert = $conn->prepare("INSERT INTO business_types (id, code, name_ku, sort_order) VALUES (?, ?, ?, ?)");
$insert->bind_param('issi', $nextId, $code, $nameKu, $nextSort);
if (!$insert->execute()) {
    fwrite(STDERR, "migration_fail: " . $conn->error . "\n");
    exit(1);
}
$insert->close();

echo "added: business_types id={$nextId} code=curtain_shop name_ku=دوکانی پەردە sort_order={$nextSort}\n";
echo "migration_ok ({$dbName})\n";
