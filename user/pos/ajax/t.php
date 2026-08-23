<?php
/**
 * Test database connection and customer_cash_purchases table
 */

// Load configuration
require_once '../../../config/config.php';
require_once '../../../config/security.php';
require_once '../../../includes/functions.php';

// Test database connection
if (!$conn || $conn->connect_error) {
    echo "Database connection failed: " . ($conn->connect_error ?? 'No connection');
    exit;
}

echo "Database connection: OK\n";

// Check if customer_cash_purchases table exists
$result = $conn->query("SHOW TABLES LIKE 'customer_cash_purchases'");
if ($result && $result->num_rows > 0) {
    echo "Table customer_cash_purchases: EXISTS\n";
    
    // Show table structure
    $result = $conn->query("DESCRIBE customer_cash_purchases");
    if ($result) {
        echo "Table structure:\n";
        while ($row = $result->fetch_assoc()) {
            echo "- {$row['Field']} ({$row['Type']}) " . ($row['Null'] == 'NO' ? 'NOT NULL' : 'NULL') . "\n";
        }
    }
    
    // Check existing records
    $result = $conn->query("SELECT COUNT(*) as count FROM customer_cash_purchases");
    if ($result) {
        $count = $result->fetch_assoc()['count'];
        echo "Total records: $count\n";
    }
} else {
    echo "Table customer_cash_purchases: NOT FOUND\n";
}
?>

