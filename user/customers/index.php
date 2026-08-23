<?php
/**
 * بەشی ئەکاوەنتی کڕیاران - user/customers/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/functions.php';
require_once '../../includes/permissions.php';
require_once '../../includes/theme_bootstrap.php';
require_once '../../includes/customer_change_logger.php';

// تاقیکردنی داخڵبوون
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'customers.view', [
    'route' => '/user/customers/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = (int)$currentUser['id'];

/**
 * دڵنیابوونەوە لە بوونی خشتە و ستوونەکانی پێویست بۆ کڕیاران
 */
function ensureCustomerSchemaTables($conn)
{
    if (!$conn || !($conn instanceof mysqli)) {
        return;
    }

    try {
        // خشتەی customer_regions
        $conn->query("
            CREATE TABLE IF NOT EXISTS `customer_regions` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `user_id` INT NOT NULL,
              `name` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_user_region_name` (`user_id`, `name`),
              KEY `idx_user_id` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        // ستوونی region_id لە خشتەی customers
        $colCheck = $conn->query("SHOW COLUMNS FROM `customers` LIKE 'region_id'");
        if ($colCheck && $colCheck->num_rows === 0) {
            $conn->query("ALTER TABLE `customers` ADD COLUMN `region_id` INT NULL DEFAULT NULL AFTER `address`, ADD KEY `idx_region_id` (`region_id`)");
        }
        if ($colCheck) {
            $colCheck->close();
        }

        // خشتەی customer_gmail_links
        $conn->query("
            CREATE TABLE IF NOT EXISTS `customer_gmail_links` (
              `id` INT NOT NULL AUTO_INCREMENT,
              `user_id` INT NOT NULL,
              `customer_id` INT NOT NULL,
              `gmail` VARCHAR(255) NOT NULL,
              `access_expires_at` DATETIME DEFAULT NULL,
              `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              KEY `idx_user_customer` (`user_id`, `customer_id`),
              KEY `idx_user_gmail` (`user_id`, `gmail`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) {
    }
}

ensureCustomerSchemaTables($conn);

function customerDaysSince($dateTime)
{
    if (empty($dateTime)) {
        return null;
    }
    $ts = strtotime($dateTime);
    if ($ts === false) {
        return null;
    }
    return (int) floor((time() - $ts) / 86400);
}

function resolveCustomerRegionId($conn, $userId, $rawRegionId)
{
    if ($rawRegionId === null || $rawRegionId === '' || $rawRegionId === '0') {
        return null;
    }
    $regionId = (int)$rawRegionId;
    if ($regionId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT id FROM customer_regions WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $userIdInt = (int)$userId;
    $stmt->bind_param("ii", $regionId, $userIdInt);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();

    return $exists ? $regionId : null;
}

// چەککردنی ئایا action=add هاتووە لە URL
$showAddCustomerForm = isset($_GET['action']) && $_GET['action'] === 'add';

$errors = [];
$success = '';

// زانیاری بۆ فۆرم لە کاتی هەڵەدا
$formData = [
    'name' => '',
    'phone' => '',
    'address' => '',
    'region_id' => '',
    'total_debt' => '0',
    'notes' => ''
];

// پرۆسەکردنی فۆرمەکان
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $errors[] = 'نادروستی ئامنیەتی (CSRF). تکایە لاپەڕەکە نوێ بکەرەوە و دووبارە هەوڵ بدەرەوە.';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add':
                enforceAuthorizationOrDeny($currentUser, 'customers.create', [
                    'route' => '/user/customers/index.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $name = trim($_POST['name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $rawRegionId = $_POST['region_id'] ?? null;
                $regionId = resolveCustomerRegionId($conn, $userId, $rawRegionId);
                $total_debt = max(0, (float)($_POST['total_debt'] ?? 0));
                $notes = trim($_POST['notes'] ?? '');
                
                $formData = [
                    'name' => $name,
                    'phone' => $phone,
                    'address' => $address,
                    'region_id' => $rawRegionId,
                    'total_debt' => (string)$total_debt,
                    'notes' => $notes
                ];

                if (empty($name)) {
                    $errors[] = 'ناوی کڕیار پێویستە';
                } else {
                    // چەکردنی دووبارەبوونەوەی ناو
                    $stmt = $conn->prepare("SELECT id FROM customers WHERE name = ? AND user_id = ? LIMIT 1");
                    if ($stmt) {
                        $stmt->bind_param("si", $name, $userId);
                        $stmt->execute();
                        
                        if ($stmt->get_result()->num_rows > 0) {
                            $errors[] = 'کڕیارێک بەم ناوە پێشتر تۆمارکراوە';
                        }
                        $stmt->close();
                    }
                    
                    if (empty($errors)) {
                        // زیادکردنی کڕیاری نوێ
                        if ($regionId !== null) {
                            $insertStmt = $conn->prepare("INSERT INTO customers (user_id, name, phone, address, region_id, total_debt, notes) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $insertStmt->bind_param("isssids", $userId, $name, $phone, $address, $regionId, $total_debt, $notes);
                        } else {
                            $insertStmt = $conn->prepare("INSERT INTO customers (user_id, name, phone, address, region_id, total_debt, notes) VALUES (?, ?, ?, ?, NULL, ?, ?)");
                            $insertStmt->bind_param("isssds", $userId, $name, $phone, $address, $total_debt, $notes);
                        }
                        
                        if ($insertStmt && $insertStmt->execute()) {
                            $customerId = $conn->insert_id;
                            $insertStmt->close();
                            
                            // ئەگەر قەرزی سەرەتایی هەبوو، تۆمارێک لە فرۆشتن و قەرزەکان دروست بکە
                            if ($total_debt > 0) {
                                $invoiceNumber = 'INIT-' . date('YmdHis') . '-' . $customerId;
                                
                                $saleStmt = $conn->prepare("INSERT INTO sales (user_id, customer_id, invoice_number, customer_name, total_amount, final_amount, currency, payment_method, payment_status, remaining_amount, sale_date) VALUES (?, ?, ?, ?, ?, ?, 'IQD', 'debt', 'pending', ?, NOW())");
                                if ($saleStmt) {
                                    $saleStmt->bind_param("iissddd", $userId, $customerId, $invoiceNumber, $name, $total_debt, $total_debt, $total_debt);
                                    if ($saleStmt->execute()) {
                                        $saleId = $conn->insert_id;
                                        
                                        $debtStmt = $conn->prepare("INSERT INTO debts (user_id, customer_id, sale_id, customer_name, customer_phone, total_debt, paid_amount, remaining_amount, debt_type) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'debt')");
                                        if ($debtStmt) {
                                            $initialPaidAmount = 0.0;
                                            $debtStmt->bind_param("iiissddd", $userId, $customerId, $saleId, $name, $phone, $total_debt, $initialPaidAmount, $total_debt);
                                            $debtStmt->execute();
                                            $debtStmt->close();
                                        }
                                    }
                                    $saleStmt->close();
                                }
                            }

                            $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                            logCustomerChangeEvent(
                                'customer.create',
                                'customer',
                                $customerId,
                                null,
                                $afterCustomerSnapshot,
                                [
                                    'user_id' => $userId,
                                    'current_user' => $currentUser,
                                    'customer_id' => $customerId,
                                    'currency' => 'IQD',
                                    'source_module' => 'user/customers/index.php',
                                    'source_reference' => (string)$customerId
                                ]
                            );
                            
                            $success = 'کڕیار بە سەرکەوتووی زیادکرا';
                            writeLog("Customer added: $name by user: {$currentUser['email']}");
                            
                            // Reset formData
                            $formData = [
                                'name' => '',
                                'phone' => '',
                                'address' => '',
                                'region_id' => '',
                                'total_debt' => '0',
                                'notes' => ''
                            ];
                        } else {
                            $errors[] = 'هەڵە لە زیادکردنی کڕیار: ' . ($insertStmt ? $insertStmt->error : $conn->error);
                            if ($insertStmt) {
                                $insertStmt->close();
                            }
                        }
                    }
                }
                break;
                
            case 'edit':
                enforceAuthorizationOrDeny($currentUser, 'customers.update', [
                    'route' => '/user/customers/index.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $customerId = (int)($_POST['customer_id'] ?? 0);
                $name = trim($_POST['name'] ?? '');
                $phone = trim($_POST['phone'] ?? '');
                $address = trim($_POST['address'] ?? '');
                $rawRegionId = $_POST['region_id'] ?? null;
                $regionId = resolveCustomerRegionId($conn, $userId, $rawRegionId);
                $total_debt = max(0, (float)($_POST['total_debt'] ?? 0));
                $notes = trim($_POST['notes'] ?? '');
                $status = trim($_POST['status'] ?? 'active');
                if (!in_array($status, ['active', 'inactive'], true)) {
                    $status = 'active';
                }
                
                if ($customerId <= 0) {
                    $errors[] = 'کڕیار دیاری نەکراوە';
                } elseif (empty($name)) {
                    $errors[] = 'ناوی کڕیار پێویستە';
                } else {
                    // چەکردنی خاوەنداریەتی
                    $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ? AND user_id = ? LIMIT 1");
                    if (!$stmt) {
                        $errors[] = 'هەڵەی داتابەیس';
                    } else {
                        $stmt->bind_param("ii", $customerId, $userId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows === 0) {
                            $errors[] = 'کڕیار نەدۆزرایەوە یان دەسەڵاتت نییە';
                        } else {
                            $oldCustomer = $result->fetch_assoc();
                            $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                            
                            // چەکردنی دووبارەبوونەوەی ناو (تەنها ئەگەر ناو گۆڕدرابێت)
                            if ($name !== $oldCustomer['name']) {
                                $checkStmt = $conn->prepare("SELECT id FROM customers WHERE name = ? AND user_id = ? AND id != ? LIMIT 1");
                                if ($checkStmt) {
                                    $checkStmt->bind_param("sii", $name, $userId, $customerId);
                                    $checkStmt->execute();
                                    
                                    if ($checkStmt->get_result()->num_rows > 0) {
                                        $errors[] = 'کڕیارێک بەم ناوە پێشتر تۆمارکراوە';
                                    }
                                    $checkStmt->close();
                                }
                            }
                            
                            if (empty($errors)) {
                                // نوێکردنەوەی کڕیار
                                if ($regionId !== null) {
                                    $updateStmt = $conn->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, region_id = ?, total_debt = ?, notes = ?, status = ? WHERE id = ? AND user_id = ?");
                                    $updateStmt->bind_param('sssidssii', $name, $phone, $address, $regionId, $total_debt, $notes, $status, $customerId, $userId);
                                } else {
                                    $updateStmt = $conn->prepare("UPDATE customers SET name = ?, phone = ?, address = ?, region_id = NULL, total_debt = ?, notes = ?, status = ? WHERE id = ? AND user_id = ?");
                                    $updateStmt->bind_param('sssdsii', $name, $phone, $address, $total_debt, $notes, $status, $customerId, $userId);
                                }
                                
                                if ($updateStmt && $updateStmt->execute()) {
                                    $success = 'کڕیار بە سەرکەوتووی نوێکرایەوە';
                                    writeLog("Customer updated: $name (ID: $customerId) by user: {$currentUser['email']}");
                                    $afterCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                                    logCustomerChangeEvent(
                                        'customer.update',
                                        'customer',
                                        $customerId,
                                        $beforeCustomerSnapshot ?? null,
                                        $afterCustomerSnapshot,
                                        [
                                            'user_id' => $userId,
                                            'current_user' => $currentUser,
                                            'customer_id' => $customerId,
                                            'currency' => 'IQD',
                                            'source_module' => 'user/customers/index.php',
                                            'source_reference' => (string)$customerId
                                        ]
                                    );
                                } else {
                                    $errors[] = 'هەڵە لە نوێکردنەوەی کڕیار: ' . ($updateStmt ? $updateStmt->error : $conn->error);
                                }
                                if ($updateStmt) {
                                    $updateStmt->close();
                                }
                            }
                        }
                        $stmt->close();
                    }
                }
                break;

            case 'link_gmail':
                enforceAuthorizationOrDeny($currentUser, 'customers.update', [
                    'route' => '/user/customers/index.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $customerId = (int)($_POST['customer_id'] ?? 0);
                $gmail = strtolower(trim($_POST['gmail'] ?? ''));

                if ($customerId <= 0) {
                    $errors[] = 'کڕیار دیاری نەکراوە';
                    break;
                }

                if (empty($gmail)) {
                    $errors[] = 'تکایە Gmail بنووسە';
                    break;
                }

                if (!filter_var($gmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'فۆرماتی Gmail دروست نییە';
                    break;
                }

                $ownerStmt = $conn->prepare("SELECT name FROM customers WHERE id = ? AND user_id = ? LIMIT 1");
                if (!$ownerStmt) {
                    $errors[] = 'هەڵەی داتابەیس';
                    break;
                }
                $ownerStmt->bind_param("ii", $customerId, $userId);
                $ownerStmt->execute();
                $ownerResult = $ownerStmt->get_result();

                if ($ownerResult->num_rows === 0) {
                    $errors[] = 'کڕیار نەدۆزرایەوە یان دەسەڵاتت نییە';
                    $ownerStmt->close();
                    break;
                }

                $customerInfo = $ownerResult->fetch_assoc();
                $ownerStmt->close();

                $existsStmt = $conn->prepare("SELECT id, gmail FROM customer_gmail_links WHERE user_id = ? AND customer_id = ? LIMIT 1");
                if ($existsStmt) {
                    $existsStmt->bind_param("ii", $userId, $customerId);
                    $existsStmt->execute();
                    $existingGmailRow = $existsStmt->get_result()->fetch_assoc();
                    $existsStmt->close();

                    if ($existingGmailRow) {
                        $oldGmail = $existingGmailRow['gmail'] ?? '';
                        $updateGmailStmt = $conn->prepare("UPDATE customer_gmail_links SET gmail = ? WHERE id = ? AND user_id = ?");
                        $linkId = (int)$existingGmailRow['id'];
                        $updateGmailStmt->bind_param("sii", $gmail, $linkId, $userId);

                        if ($updateGmailStmt->execute()) {
                            $success = 'Gmail بە سەرکەوتوویی نوێکرایەوە';
                            writeLog("Customer Gmail updated: customer_id=$customerId, old_gmail=$oldGmail, new_gmail=$gmail, customer_name={$customerInfo['name']}, by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵە لە نوێکردنەوەی Gmail';
                        }
                        $updateGmailStmt->close();
                    } else {
                        $insertGmailStmt = $conn->prepare("INSERT INTO customer_gmail_links (user_id, customer_id, gmail) VALUES (?, ?, ?)");
                        $insertGmailStmt->bind_param("iis", $userId, $customerId, $gmail);

                        if ($insertGmailStmt->execute()) {
                            $success = 'Gmail بە سەرکەوتوویی پەیوەست کرا';
                            writeLog("Customer Gmail linked: customer_id=$customerId, gmail=$gmail, customer_name={$customerInfo['name']}, by user: {$currentUser['email']}");
                        } else {
                            $errors[] = 'هەڵە لە خەزنکردنی Gmail';
                        }
                        $insertGmailStmt->close();
                    }
                }
                break;
                
            case 'delete':
                enforceAuthorizationOrDeny($currentUser, 'customers.delete', [
                    'route' => '/user/customers/index.php',
                    'request_method' => 'POST'
                ], 'redirect');

                $customerId = (int)($_POST['customer_id'] ?? 0);
                $confirmDeleteWithDebt = isset($_POST['confirm_delete_with_debt']) && $_POST['confirm_delete_with_debt'] === '1';
                
                if ($customerId <= 0) {
                    $errors[] = 'کڕیار دیاری نەکراوە';
                } else {
                    // چەکردنی خاوەنداریەتی
                    $stmt = $conn->prepare("SELECT name FROM customers WHERE id = ? AND user_id = ? LIMIT 1");
                    if (!$stmt) {
                        $errors[] = 'هەڵەی داتابەیس';
                    } else {
                        $stmt->bind_param("ii", $customerId, $userId);
                        $stmt->execute();
                        $result = $stmt->get_result();
                        
                        if ($result->num_rows === 0) {
                            $errors[] = 'کڕیار نەدۆزرایەوە یان دەسەڵاتت نییە';
                        } else {
                            $customer = $result->fetch_assoc();
                            $beforeCustomerSnapshot = getCustomerSnapshotForLogs($conn, $userId, $customerId);
                            
                            // چەکردنی ئەگەر کڕیارە قەرزی هەیە
                            $debtStmt = $conn->prepare("
                                SELECT 
                                    COUNT(*) as debt_count,
                                    COALESCE(SUM(d.remaining_amount), 0) as total_debt,
                                    COALESCE(MAX(CASE WHEN d.status = 'active' THEN COALESCE(s.currency, 'IQD') END), 'IQD') as debt_currency
                                FROM debts d
                                LEFT JOIN sales s ON d.sale_id = s.id
                                WHERE d.customer_id = ? AND d.user_id = ? AND d.status = 'active'
                            ");
                            $debtStmt->bind_param("ii", $customerId, $userId);
                            $debtStmt->execute();
                            $debtResult = $debtStmt->get_result()->fetch_assoc();
                            $debtStmt->close();

                            $debtCount = (int)($debtResult['debt_count'] ?? 0);
                            $totalDebt = (float)($debtResult['total_debt'] ?? 0);
                            $debtCurrency = $debtResult['debt_currency'] ?? 'IQD';
                            
                            // ئەگەر قەرز هەیە و بەکارهێنەر دڵنیایی نەداوەتەوە
                            if ($debtCount > 0 && !$confirmDeleteWithDebt) {
                                $errors[] = 'کڕیارەکە قەرزی چالاکی هەیە. تکایە دڵنیا بکەوە لە سڕینەوەکە.';
                            } else {
                                $conn->begin_transaction();
                                
                                try {
                                    // وەرگرتنی ID-ی هەموو قەرزەکان بۆ کڕیار
                                    $getDebtsStmt = $conn->prepare("SELECT id FROM debts WHERE customer_id = ? AND user_id = ?");
                                    $getDebtsStmt->bind_param("ii", $customerId, $userId);
                                    $getDebtsStmt->execute();
                                    $debtsResult = $getDebtsStmt->get_result();
                                    $debtIds = [];
                                    while ($row = $debtsResult->fetch_assoc()) {
                                        $debtIds[] = (int)$row['id'];
                                    }
                                    $getDebtsStmt->close();
                                    
                                    // سڕینەوەی پارەدانەکان
                                    if (!empty($debtIds)) {
                                        $placeholders = str_repeat('?,', count($debtIds) - 1) . '?';
                                        $deletePaymentsStmt = $conn->prepare("DELETE FROM debt_payments WHERE debt_id IN ($placeholders)");
                                        $deletePaymentsStmt->bind_param(str_repeat('i', count($debtIds)), ...$debtIds);
                                        $deletePaymentsStmt->execute();
                                        $deletePaymentsStmt->close();
                                    }
                                    
                                    // سڕینەوەی قەرزەکان
                                    $deleteDebtsStmt = $conn->prepare("DELETE FROM debts WHERE customer_id = ? AND user_id = ?");
                                    $deleteDebtsStmt->bind_param("ii", $customerId, $userId);
                                    $deleteDebtsStmt->execute();
                                    $deleteDebtsStmt->close();

                                    // سڕینەوەی بەستەری Gmail
                                    $deleteGmailStmt = $conn->prepare("DELETE FROM customer_gmail_links WHERE customer_id = ? AND user_id = ?");
                                    if ($deleteGmailStmt) {
                                        $deleteGmailStmt->bind_param("ii", $customerId, $userId);
                                        $deleteGmailStmt->execute();
                                        $deleteGmailStmt->close();
                                    }
                                    
                                    // سڕینەوەی کڕیار
                                    $deleteStmt = $conn->prepare("DELETE FROM customers WHERE id = ? AND user_id = ?");
                                    $deleteStmt->bind_param("ii", $customerId, $userId);
                                    
                                    if ($deleteStmt->execute()) {
                                        $conn->commit();
                                        
                                        $success = 'کڕیار بە سەرکەوتووی سڕایەوە';
                                        
                                        $logMessage = "Customer deleted: {$customer['name']} (ID: $customerId)";
                                        if ($debtCount > 0) {
                                            $logMessage .= " - Deleted $debtCount active debt(s) totaling " . formatMoney($totalDebt, $debtCurrency);
                                        }
                                        if (!empty($debtIds)) {
                                            $logMessage .= " - Total debts deleted: " . count($debtIds);
                                        }
                                        $logMessage .= " by user: {$currentUser['email']}";
                                        writeLog($logMessage);

                                        $deleteBeforeState = [
                                            'snapshot' => $beforeCustomerSnapshot,
                                            'delete_impact' => [
                                                'active_debt_count' => $debtCount,
                                                'active_debt_total' => $totalDebt,
                                                'active_debt_currency_hint' => $debtCurrency,
                                                'deleted_debts_total' => count($debtIds),
                                                'deleted_payment_rows' => count($debtIds)
                                            ]
                                        ];
                                        logCustomerChangeEvent(
                                            'customer.delete',
                                            'customer',
                                            $customerId,
                                            $deleteBeforeState,
                                            null,
                                            [
                                                'user_id' => $userId,
                                                'current_user' => $currentUser,
                                                'customer_id' => $customerId,
                                                'currency' => $debtCurrency,
                                                'source_module' => 'user/customers/index.php',
                                                'source_reference' => (string)$customerId
                                            ]
                                        );
                                    } else {
                                        throw new Exception('هەڵە لە سڕینەوەی کڕیار');
                                    }
                                    $deleteStmt->close();
                                    
                                } catch (Exception $e) {
                                    $conn->rollback();
                                    $errors[] = 'هەڵە لە سڕینەوەی کڕیار: ' . $e->getMessage();
                                    writeLog("Error deleting customer ID $customerId: " . $e->getMessage());
                                }
                            }
                        }
                        $stmt->close();
                    }
                }
                break;
        }
    }
}

// وەرگرتنی لیستی کڕیاران و فلتەرەکان
$search = trim($_GET['search'] ?? '');
$status = trim($_GET['status'] ?? 'all');
if (!in_array($status, ['all', 'active', 'inactive'], true)) {
    $status = 'all';
}
$debtStatus = trim($_GET['debt_status'] ?? 'all');
if (!in_array($debtStatus, ['all', 'with_debt', 'no_debt'], true)) {
    $debtStatus = 'all';
}
$regionFilter = trim($_GET['region'] ?? 'all');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 15;
$offset = ($page - 1) * $limit;

// وەرگرتنی ناوچەکان بۆ فلتەر و dropdown
$customerRegions = [];
$regionsStmt = $conn->prepare("
    SELECT cr.id, cr.name, COUNT(c.id) AS customer_count
    FROM customer_regions cr
    LEFT JOIN customers c ON c.region_id = cr.id AND c.user_id = cr.user_id
    WHERE cr.user_id = ?
    GROUP BY cr.id, cr.name
    ORDER BY cr.name ASC
");
if ($regionsStmt) {
    $regionsStmt->bind_param("i", $userId);
    $regionsStmt->execute();
    $customerRegions = $regionsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $regionsStmt->close();
}

// دروستکردنی مەرجەکانی WHERE
$whereConditions = ["c.user_id = ?"];
$baseParams = [$userId];
$baseTypes = 'i';

if (!empty($search)) {
    $whereConditions[] = "(c.name LIKE ? OR c.phone LIKE ? OR c.address LIKE ? OR cgl.gmail LIKE ? OR cr.name LIKE ?)";
    $searchTerm = "%$search%";
    $baseParams = array_merge($baseParams, [$searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm]);
    $baseTypes .= 'sssss';
}

if ($status !== 'all') {
    $whereConditions[] = "c.status = ?";
    $baseParams[] = $status;
    $baseTypes .= 's';
}

if ($debtStatus === 'with_debt') {
    $whereConditions[] = "EXISTS (SELECT 1 FROM debts d WHERE d.customer_id = c.id AND d.user_id = c.user_id AND d.status = 'active')";
} elseif ($debtStatus === 'no_debt') {
    $whereConditions[] = "NOT EXISTS (SELECT 1 FROM debts d WHERE d.customer_id = c.id AND d.user_id = c.user_id AND d.status = 'active')";
}

if ($regionFilter === '__no_region__') {
    $whereConditions[] = "(c.region_id IS NULL OR c.region_id = 0)";
} elseif ($regionFilter !== 'all' && ctype_digit((string)$regionFilter)) {
    $whereConditions[] = "c.region_id = ?";
    $baseParams[] = (int)$regionFilter;
    $baseTypes .= 'i';
}

$whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
$regionJoin = "LEFT JOIN customer_regions cr ON cr.id = c.region_id AND cr.user_id = c.user_id";

// ژماردنی گشتی کڕیاران
$totalCustomers = 0;
$countQuery = "
    SELECT COUNT(DISTINCT c.id) as total 
    FROM customers c 
    LEFT JOIN customer_gmail_links cgl ON cgl.customer_id = c.id AND cgl.user_id = c.user_id 
    $regionJoin 
    $whereClause
";
$countStmt = $conn->prepare($countQuery);
if ($countStmt) {
    $countStmt->bind_param($baseTypes, ...$baseParams);
    $countStmt->execute();
    $countRow = $countStmt->get_result()->fetch_assoc();
    $totalCustomers = (int)($countRow['total'] ?? 0);
    $countStmt->close();
}
$totalPages = max(1, (int)ceil($totalCustomers / $limit));

// وەرگرتنی کڕیاران
$customers = [];
$query = "
    SELECT 
        c.id, c.user_id, c.name, c.phone, c.address, c.region_id, c.total_debt, c.notes, c.status, c.created_at, c.updated_at,
        cr.name AS region_name,
        cgl.gmail AS customer_gmail,
        COALESCE(da.active_debts, 0) as active_debts,
        COALESCE(da.current_debt_iqd, 0) as current_debt_iqd,
        COALESCE(da.current_debt_usd, 0) as current_debt_usd,
        lp.last_payment_date as last_payment_date,
        lpu.last_purchase_date as last_purchase_date
    FROM customers c
    $regionJoin
    LEFT JOIN (
        SELECT customer_id, user_id, MAX(gmail) AS gmail
        FROM customer_gmail_links
        WHERE user_id = ?
        GROUP BY customer_id, user_id
    ) cgl ON cgl.customer_id = c.id AND cgl.user_id = c.user_id
    LEFT JOIN (
        SELECT
            d.customer_id,
            COUNT(*) as active_debts,
            COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_iqd,
            COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_usd
        FROM debts d
        LEFT JOIN sales s ON d.sale_id = s.id
        WHERE d.user_id = ? AND d.status = 'active'
        GROUP BY d.customer_id
    ) da ON da.customer_id = c.id
    LEFT JOIN (
        SELECT
            d.customer_id,
            MAX(dp.payment_date) as last_payment_date
        FROM debts d
        JOIN debt_payments dp ON dp.debt_id = d.id
        WHERE d.user_id = ?
        GROUP BY d.customer_id
    ) lp ON lp.customer_id = c.id
    LEFT JOIN (
        SELECT customer_id, MAX(sale_date) AS last_purchase_date
        FROM sales
        WHERE user_id = ? AND customer_id IS NOT NULL
        GROUP BY customer_id
    ) lpu ON lpu.customer_id = c.id
    $whereClause
    ORDER BY c.created_at DESC
    LIMIT ? OFFSET ?
";

$listParams = array_merge([$userId, $userId, $userId, $userId], $baseParams, [$limit, $offset]);
$listTypes = 'iiii' . $baseTypes . 'ii';

$stmt = $conn->prepare($query);
if ($stmt) {
    $stmt->bind_param($listTypes, ...$listParams);
    $stmt->execute();
    $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
}

// ئامارەکان
$activeCount = 0;
$actStmt = $conn->prepare("SELECT COUNT(*) as count FROM customers WHERE user_id = ? AND status = 'active'");
if ($actStmt) {
    $actStmt->bind_param("i", $userId);
    $actStmt->execute();
    $activeCount = (int)($actStmt->get_result()->fetch_assoc()['count'] ?? 0);
    $actStmt->close();
}

$debtCustomerCount = 0;
$debtCustStmt = $conn->prepare("SELECT COUNT(DISTINCT customer_id) as count FROM debts WHERE user_id = ? AND status = 'active'");
if ($debtCustStmt) {
    $debtCustStmt->bind_param("i", $userId);
    $debtCustStmt->execute();
    $debtCustomerCount = (int)($debtCustStmt->get_result()->fetch_assoc()['count'] ?? 0);
    $debtCustStmt->close();
}

$totalIQD = 0.0;
$totalUSD = 0.0;
$debtStatsStmt = $conn->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as total_iqd,
        COALESCE(SUM(CASE WHEN COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as total_usd
    FROM debts d
    LEFT JOIN sales s ON d.sale_id = s.id
    WHERE d.user_id = ? AND d.status = 'active'
");
if ($debtStatsStmt) {
    $debtStatsStmt->bind_param("i", $userId);
    $debtStatsStmt->execute();
    $debtStats = $debtStatsStmt->get_result()->fetch_assoc();
    $totalIQD = (float)($debtStats['total_iqd'] ?? 0);
    $totalUSD = (float)($debtStats['total_usd'] ?? 0);
    $debtStatsStmt->close();
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo kasher_get_theme_bootstrap_markup(); ?>
    <title>ئەکاوەنتی کڕیاران - <?php echo SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-pages.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-dark.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/customers/customers-responsive.css'); ?>" rel="stylesheet">
</head>
<body class="customers-module-page customers-list-page">

    <?php
    $customersNavId = 'customersListNav';
    $customersNavLinks = [
        ['href' => url('user/customers/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کڕیاران'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
    include __DIR__ . '/partials/customers_nav.php';
    ?>

    <div class="container-fluid py-4 customers-page-content cu-wrap">
        
        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></li>
                    <?php endforeach; ?>
                </ul>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="bi bi-check-circle-fill me-2"></i>
                <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <header class="cu-hero">
            <div>
                <div class="cu-kicker"><i class="bi bi-people"></i> بەشی کڕیاران</div>
                <h1><i class="bi bi-person-vcard"></i> ئەکاوەنتی کڕیاران</h1>
                <p class="cu-hero-sub">لیست، گەڕان، ناوچە و بەڕێوەبردنی قەرزی کڕیاران لە یەک شوێن</p>
                <div class="cu-hero-pills">
                    <span class="cu-pill"><i class="bi bi-collection"></i> <?php echo number_format($totalCustomers); ?> کڕیار</span>
                </div>
            </div>
            <div class="cu-actions">
                <a href="<?php echo url('user/customers/regions.php'); ?>" class="cu-btn cu-btn-ghost">
                    <i class="bi bi-geo-alt"></i> ناوچەکان
                </a>
                <a href="<?php echo url('user/customers/cash_purchases.php'); ?>" class="cu-btn cu-btn-ghost">
                    <i class="bi bi-cash-coin"></i> کڕینی کاش
                </a>
                <a href="<?php echo url('user/customers/credit_sales.php'); ?>" class="cu-btn cu-btn-warn">
                    <i class="bi bi-credit-card-2-back"></i> قەرزەکان
                </a>
                <button type="button" class="cu-btn cu-btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="bi bi-plus-lg"></i> کڕیاری نوێ
                </button>
            </div>
        </header>

        <div class="cu-panel">
            <div class="cu-panel-head"><i class="bi bi-funnel"></i> فلتەر و گەڕان</div>
            <div class="cu-panel-body">
                <form method="GET" action="<?php echo url('user/customers/index.php'); ?>" class="row g-3">
                    <div class="col-lg-4 col-md-6">
                        <label class="form-label">گەڕان</label>
                        <input type="text" class="form-control" name="search" 
                               value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" 
                               placeholder="گەڕان بە ناو، تەلەفۆن، ناونیشان، ناوچە یان Gmail..."
                               autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false">
                    </div>
                    <div class="col-lg-3 col-md-6">
                        <label class="form-label">ناوچە</label>
                        <select class="form-select" name="region">
                            <option value="all" <?php echo $regionFilter === 'all' ? 'selected' : ''; ?>>هەموو ناوچەکان</option>
                            <option value="__no_region__" <?php echo $regionFilter === '__no_region__' ? 'selected' : ''; ?>>بێ ناوچە</option>
                            <?php foreach ($customerRegions as $regionOption): ?>
                                <option value="<?php echo (int)$regionOption['id']; ?>" <?php echo (string)$regionFilter === (string)$regionOption['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($regionOption['name'], ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int)$regionOption['customer_count']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">دۆخ</label>
                        <select class="form-select" name="status">
                            <option value="all" <?php echo $status === 'all' ? 'selected' : ''; ?>>هەموو</option>
                            <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>چالاک</option>
                            <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>ناچالاک</option>
                        </select>
                    </div>
                    <div class="col-lg-2 col-md-4">
                        <label class="form-label">قەرز</label>
                        <select class="form-select" name="debt_status">
                            <option value="all" <?php echo $debtStatus === 'all' ? 'selected' : ''; ?>>هەموو</option>
                            <option value="with_debt" <?php echo $debtStatus === 'with_debt' ? 'selected' : ''; ?>>قەرزدار</option>
                            <option value="no_debt" <?php echo $debtStatus === 'no_debt' ? 'selected' : ''; ?>>بەبێ قەرز</option>
                        </select>
                    </div>
                    <div class="col-lg-1 col-md-4 d-flex align-items-end">
                        <button type="submit" class="cu-btn cu-btn-primary w-100" title="گەڕان">
                            <i class="bi bi-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <div class="cu-stats cu-stats-4">
            <div class="cu-stat" style="--stat-accent:#0ea5e9">
                <div class="cu-stat-icon"><i class="bi bi-people"></i></div>
                <div>
                    <div class="cu-stat-label">گشتی کڕیاران</div>
                    <div class="cu-stat-value"><?php echo number_format($totalCustomers); ?></div>
                </div>
            </div>
            <div class="cu-stat" style="--stat-accent:#10b981">
                <div class="cu-stat-icon"><i class="bi bi-person-check"></i></div>
                <div>
                    <div class="cu-stat-label">کڕیارانی چالاک</div>
                    <div class="cu-stat-value"><?php echo number_format($activeCount); ?></div>
                </div>
            </div>
            <div class="cu-stat" style="--stat-accent:#f59e0b">
                <div class="cu-stat-icon"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="cu-stat-label">کڕیارانی قەرزدار</div>
                    <div class="cu-stat-value"><?php echo number_format($debtCustomerCount); ?></div>
                </div>
            </div>
            <div class="cu-stat" style="--stat-accent:#ef4444">
                <div class="cu-stat-icon"><i class="bi bi-currency-dollar"></i></div>
                <div>
                    <div class="cu-stat-label">گشتی قەرزەکان</div>
                    <div class="cu-stat-value">
                        <?php 
                        if ($totalUSD > 0 && $totalIQD > 0) {
                            echo formatMoney($totalIQD, 'IQD') . ' + ' . formatMoney($totalUSD, 'USD');
                        } elseif ($totalUSD > 0) {
                            echo formatMoney($totalUSD, 'USD');
                        } else {
                            echo formatMoney($totalIQD, 'IQD');
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="cu-panel">
            <div class="cu-panel-head">
                <span><i class="bi bi-list"></i> لیستی کڕیاران</span>
                <?php
                    $printListParams = [];
                    if ($regionFilter !== 'all') {
                        $printListParams['region'] = $regionFilter;
                    }
                    $printListUrl = url('user/customers/print/index.php' . (!empty($printListParams) ? '?' . http_build_query($printListParams) : ''));
                ?>
                <a href="<?php echo $printListUrl; ?>" class="cu-btn cu-btn-ghost cu-btn-sm">
                    <i class="bi bi-printer"></i> چاپکردنی لیست
                </a>
            </div>
            <div class="p-0">
                <?php if (empty($customers)): ?>
                    <div class="cu-empty">
                        <div class="cu-empty-icon"><i class="bi bi-people"></i></div>
                        <h3>هیچ کڕیارێک نەدۆزرایەوە</h3>
                        <p>کڕیارێکی نوێ زیاد بکە یان فلتەرەکان بگۆڕە.</p>
                        <button type="button" class="cu-btn cu-btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                            <i class="bi bi-plus-lg"></i> یەکەمین کڕیار زیاد بکە
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0 customers-list-table">
                            <thead class="table-light">
                                <tr>
                                    <th>ناو</th>
                                    <th>تەلەفۆن</th>
                                    <th>ناونیشان</th>
                                    <th>ناوچە</th>
                                    <th>قەرزی دینار</th>
                                    <th>قەرزی دۆلار</th>
                                    <th>ژمارەی قەرزەکان</th>
                                    <th>دوایین پارەدانەوە (ڕۆژ)</th>
                                    <th>کۆتا کڕین (ڕۆژ)</th>
                                    <th>دۆخ</th>
                                    <th>بەروار</th>
                                    <th>کردارەکان</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($customers as $customer): ?>
                                    <tr>
                                        <td data-label="ناو">
                                            <strong><?php echo htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                            <?php if (!empty($customer['notes'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars(mb_substr($customer['notes'], 0, 50), ENT_QUOTES, 'UTF-8'); ?>...</small>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="تەلەفۆن"><?php echo htmlspecialchars($customer['phone'] ?: '-', ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="ناونیشان"><?php echo htmlspecialchars(mb_substr($customer['address'] ?: '-', 0, 30), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td data-label="ناوچە">
                                            <?php if (!empty($customer['region_name'])): ?>
                                                <span class="customer-region-badge">
                                                    <i class="bi bi-geo-alt"></i>
                                                    <?php echo htmlspecialchars($customer['region_name'], ENT_QUOTES, 'UTF-8'); ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="text-muted small">بێ ناوچە</span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="قەرزی دینار">
                                            <?php $debtIqd = (float)($customer['current_debt_iqd'] ?? 0); ?>
                                            <span class="badge bg-<?php echo $debtIqd > 0 ? 'danger' : 'success'; ?>">
                                                <?php echo formatMoney($debtIqd, 'IQD'); ?>
                                            </span>
                                        </td>
                                        <td data-label="قەرزی دۆلار">
                                            <?php $debtUsd = (float)($customer['current_debt_usd'] ?? 0); ?>
                                            <span class="badge bg-<?php echo $debtUsd > 0 ? 'danger' : 'success'; ?>">
                                                <?php echo formatMoney($debtUsd, 'USD'); ?>
                                            </span>
                                        </td>
                                        <td data-label="ژمارەی قەرزەکان">
                                            <span class="badge bg-info">
                                                <?php echo (int)($customer['active_debts'] ?? 0); ?>
                                            </span>
                                        </td>
                                        <td data-label="دوایین پارەدانەوە (ڕۆژ)">
                                            <?php
                                            $daysSincePayment = customerDaysSince($customer['last_payment_date'] ?? null);
                                            if ($daysSincePayment === null): ?>
                                                <span class="text-muted">-</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary"><?php echo $daysSincePayment; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="کۆتا کڕین (ڕۆژ)">
                                            <?php
                                            $daysSincePurchase = customerDaysSince($customer['last_purchase_date'] ?? null);
                                            if ($daysSincePurchase === null): ?>
                                                <span class="text-muted">-</span>
                                            <?php else: ?>
                                                <span class="badge bg-info"><?php echo $daysSincePurchase; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td data-label="دۆخ">
                                            <span class="badge bg-<?php echo $customer['status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo $customer['status'] === 'active' ? 'چالاک' : 'ناچالاک'; ?>
                                            </span>
                                        </td>
                                        <td data-label="بەروار"><?php echo date('Y/m/d', strtotime($customer['created_at'])); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <?php
                                                $debtIqd = (float)($customer['current_debt_iqd'] ?? 0);
                                                $debtUsd = (float)($customer['current_debt_usd'] ?? 0);
                                                $hasDebt = ($debtIqd > 0 || $debtUsd > 0);
                                                $phone = trim($customer['phone'] ?? '');
                                                $lastPaymentDate = $customer['last_payment_date'] ?? null;

                                                $isLate = false;
                                                $daysLate = null;
                                                if ($hasDebt) {
                                                    $thresholdTs = strtotime('-1 month');
                                                    if (empty($lastPaymentDate)) {
                                                        $customerCreatedTs = strtotime($customer['created_at']);
                                                        if ($customerCreatedTs !== false) {
                                                            $daysLate = floor((time() - $customerCreatedTs) / 86400);
                                                            if ($daysLate > 30) {
                                                                $isLate = true;
                                                            }
                                                        } else {
                                                            $isLate = true;
                                                        }
                                                    } else {
                                                        $lastPaymentTs = strtotime($lastPaymentDate);
                                                        if ($lastPaymentTs !== false && $lastPaymentTs < $thresholdTs) {
                                                            $isLate = true;
                                                            $daysLate = floor((time() - $lastPaymentTs) / 86400);
                                                        }
                                                    }
                                                }

                                                if ($hasDebt && $isLate && $phone !== '') {
                                                    $normalizedPhone = preg_replace('/\D+/', '', $phone);
                                                    if (strpos($normalizedPhone, '964') === 0) {
                                                        $whatsAppPhone = '+' . $normalizedPhone;
                                                    } elseif (strpos($normalizedPhone, '0') === 0) {
                                                        $whatsAppPhone = '+964' . substr($normalizedPhone, 1);
                                                    } else {
                                                        $whatsAppPhone = '+964' . $normalizedPhone;
                                                    }

                                                    $amountLines = [];
                                                    if ($debtIqd > 0) {
                                                        $amountLines[] = '*قەرزی دینار:* ' . formatMoney($debtIqd, 'IQD');
                                                    }
                                                    if ($debtUsd > 0) {
                                                        $amountLines[] = '*قەرزی دۆلار:* ' . formatMoney($debtUsd, 'USD');
                                                    }
                                                    $amountText = implode("\n", $amountLines);

                                                    $daysLine = '';
                                                    if ($daysLate !== null && $daysLate > 0) {
                                                        $daysLine = $daysLate . " ڕۆژ زیاتر پارەی قەرزت واسڵ نەکردووە.\n";
                                                    }

                                                    $whatsAppText = "سڵاو و ڕێز.\n\n"
                                                        . "تۆ زیاتر لە مانگێکە هیچ پارەیەکت واسڵ نەکردووە .\n"
                                                        . $daysLine . "\n"
                                                        . "ئێستا بڕی قەرزت بریتیە لە:\n\n"
                                                        . $amountText . "\n\n"
                                                        . "زۆر سوپاس دەکەین ئەگەر بۆت گونجا ، لە نزیکترین کاتدا قەرزەکە بدەیتەوە.";
                                                    $whatsAppUrl = 'https://wa.me/' . rawurlencode($whatsAppPhone) . '?text=' . rawurlencode($whatsAppText);
                                                ?>
                                                    <a href="<?php echo htmlspecialchars($whatsAppUrl, ENT_QUOTES, 'UTF-8'); ?>"
                                                       class="btn btn-success"
                                                       target="_blank"
                                                       title="ناردنی نامە بۆ واتسئاپ">
                                                        <i class="bi bi-whatsapp"></i>
                                                    </a>
                                                <?php } ?>

                                                <button class="btn btn-outline-primary" 
                                                        type="button"
                                                        title="دەستکاریکردنی کڕیار"
                                                        data-customer="<?php echo htmlspecialchars(json_encode($customer, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>"
                                                        onclick="editCustomerFromBtn(this)">
                                                    <i class="bi bi-pencil"></i>
                                                </button>

                                                <button class="btn btn-outline-info"
                                                        type="button"
                                                        title="<?php echo !empty($customer['customer_gmail']) ? 'گۆڕینی Gmail: ' . htmlspecialchars($customer['customer_gmail'], ENT_QUOTES, 'UTF-8') : 'پەیوەستکردنی Gmail'; ?>"
                                                        onclick="openLinkGmailModal(<?php echo (int)$customer['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$customer['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars(json_encode((string)($customer['customer_gmail'] ?? ''), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class="bi bi-envelope-at"></i>
                                                </button>

                                                <a href="<?php echo url('user/pos/index.php?customer_id=' . (int)$customer['id']); ?>" 
                                                   class="btn btn-outline-success" title="فرۆشتن لە POS">
                                                    <i class="bi bi-cart-plus"></i>
                                                </a>

                                                <button class="btn btn-outline-danger" 
                                                        type="button"
                                                        title="سڕینەوەی کڕیار"
                                                        onclick="deleteCustomer(<?php echo (int)$customer['id']; ?>, <?php echo htmlspecialchars(json_encode((string)$customer['name'], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                        <nav class="py-3" aria-label="پەڕەکان">
                            <ul class="pagination justify-content-center mb-0">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo urlencode($regionFilter); ?>&status=<?php echo urlencode($status); ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                                        <i class="bi bi-chevron-right"></i>
                                    </a>
                                </li>
                                
                                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                        <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo urlencode($regionFilter); ?>&status=<?php echo urlencode($status); ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                                            <?php echo $i; ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>&region=<?php echo urlencode($regionFilter); ?>&status=<?php echo urlencode($status); ?>&debt_status=<?php echo urlencode($debtStatus); ?>">
                                        <i class="bi bi-chevron-left"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Customer Modal -->
    <div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('user/customers/index.php'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="addCustomerModalLabel">
                            <i class="bi bi-person-plus me-1"></i> زیادکردنی کڕیاری نوێ
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">ناوی کڕیار <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required autofocus>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ژمارەی موبایل</label>
                            <input type="tel" class="form-control" name="phone" placeholder="07xxxxxxxxx">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ناونیشان</label>
                            <textarea class="form-control" name="address" rows="2" placeholder="شار، گەڕەک یان شوێنی نیشتەجێبوون..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ناوچە</label>
                            <select class="form-select" name="region_id">
                                <option value="">بێ ناوچە</option>
                                <?php foreach ($customerRegions as $regionOption): ?>
                                    <option value="<?php echo (int)$regionOption['id']; ?>">
                                        <?php echo htmlspecialchars($regionOption['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if (empty($customerRegions)): ?>
                                <small class="text-muted">
                                    هیچ ناوچەیەک نییە.
                                    <a href="<?php echo url('user/customers/regions.php?action=add'); ?>">ناوچەیەک دروست بکە</a>
                                </small>
                            <?php else: ?>
                                <small class="text-muted">
                                    <a href="<?php echo url('user/customers/regions.php'); ?>">بەڕێوەبردنی ناوچەکان</a>
                                </small>
                            <?php endif; ?>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">بڕی قەرزی سەرەتایی (دینار)</label>
                            <input type="number" class="form-control" name="total_debt" step="any" min="0" value="0">
                            <small class="text-muted">ئەگەر کڕیار پێشتر قەرزی هەبووە، بڕەکەی لێرە بنووسە.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">تێبینی</label>
                            <textarea class="form-control" name="notes" rows="2" placeholder="تێبینی زیادە لەسەر کڕیار..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-lg me-1"></i> زیادکردن
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Customer Modal -->
    <div class="modal fade" id="editCustomerModal" tabindex="-1" aria-labelledby="editCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('user/customers/index.php'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="editCustomerModalLabel">
                            <i class="bi bi-pencil-square me-1"></i> دەستکاریکردنی کڕیار
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="edit">
                        <input type="hidden" name="customer_id" id="edit_customer_id">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">ناوی کڕیار <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ژمارەی موبایل</label>
                            <input type="tel" class="form-control" name="phone" id="edit_phone">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">ناونیشان</label>
                            <textarea class="form-control" name="address" id="edit_address" rows="2"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">ناوچە</label>
                            <select class="form-select" name="region_id" id="edit_region_id">
                                <option value="">بێ ناوچە</option>
                                <?php foreach ($customerRegions as $regionOption): ?>
                                    <option value="<?php echo (int)$regionOption['id']; ?>">
                                        <?php echo htmlspecialchars($regionOption['name'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">
                                <a href="<?php echo url('user/customers/regions.php'); ?>">بەڕێوەبردنی ناوچەکان</a>
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">قەرزی ئێستا</label>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label small text-muted">قەرزی دینار</label>
                                    <input type="text" class="form-control" id="edit_debt_iqd" readonly>
                                </div>
                                <div class="col-6">
                                    <label class="form-label small text-muted">قەرزی دۆلار</label>
                                    <input type="text" class="form-control" id="edit_debt_usd" readonly>
                                </div>
                            </div>
                            <input type="hidden" name="total_debt" id="edit_total_debt" value="0">
                            <small class="text-muted">قەرز بە ئۆتۆماتیک لە سیستەمی قەرزەکانەوە حیساب دەکرێت.</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">تێبینی</label>
                            <textarea class="form-control" name="notes" id="edit_notes" rows="2"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">دۆخ</label>
                            <select class="form-select" name="status" id="edit_status" required>
                                <option value="active">چالاک</option>
                                <option value="inactive">ناچالاک</option>
                            </select>
                            <small class="text-muted">کڕیارانی ناچالاک لە سیستەمی POS و فرۆشتندا دەرناکەون.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save me-1"></i> نوێکردنەوە
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Link Gmail Modal -->
    <div class="modal fade" id="linkGmailModal" tabindex="-1" aria-labelledby="linkGmailModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" action="<?php echo url('user/customers/index.php'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="linkGmailModalLabel">
                            <i class="bi bi-envelope-at me-1"></i> پەیوەستکردنی Gmail
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="link_gmail">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="customer_id" id="gmail_customer_id">

                        <div class="alert alert-light border mb-3">
                            <strong>کڕیار:</strong>
                            <span id="gmail_customer_name">-</span>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Gmail <span class="text-danger">*</span></label>
                            <input type="email" class="form-control" name="gmail" id="gmail_input" placeholder="example@gmail.com" required>
                            <small class="text-muted">بۆ چوونەژوورەوەی کڕیار لە وێبسایتی فرۆشگا بەکاردێت.</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">داخستن</button>
                        <button type="submit" class="btn btn-primary" id="gmail_submit_btn">
                            <i class="bi bi-check2-circle me-1"></i> خەزنکردن
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Customer Modal -->
    <div class="modal fade" id="deleteCustomerModal" tabindex="-1" aria-labelledby="deleteCustomerModalLabel" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="deleteCustomerForm" action="<?php echo url('user/customers/index.php'); ?>">
                    <div class="modal-header">
                        <h5 class="modal-title" id="deleteCustomerModalLabel">
                            <i class="bi bi-trash text-danger me-1"></i> سڕینەوەی کڕیار
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="customer_id" id="delete_customer_id">
                        <input type="hidden" name="confirm_delete_with_debt" id="confirm_delete_with_debt" value="0">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div id="delete_loading" class="text-center py-3" style="display: none;">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">چاوەڕوان بکە...</span>
                            </div>
                            <p class="mt-2 text-muted">چاوەڕوان بکە...</p>
                        </div>
                        
                        <div id="delete_no_debt_warning" style="display: none;">
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                <strong>ئایا دڵنیایت لە سڕینەوەی کڕیاری <span id="delete_customer_name"></span>؟</strong>
                                <br><br>
                                <small>ئەم کردارە ناگەڕێتەوە و هەموو زانیاریەکانی کڕیار دەسڕێتەوە.</small>
                            </div>
                        </div>
                        
                        <div id="delete_debt_warning_step1" style="display: none;">
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <strong>ئاگاداری!</strong>
                                <br><br>
                                کڕیاری <strong id="delete_customer_name_debt"></strong> قەرزی چالاکی هەیە:
                                <ul class="mt-2 mb-2">
                                    <li id="delete_debt_count_info"></li>
                                    <li id="delete_debt_amount_info"></li>
                                </ul>
                                <strong>ئەگەر کڕیارەکە بسڕیتەوە، هەموو زانیاریەکانی قەرزەکانیش دەسڕێتەوە.</strong>
                                <br><br>
                                <small class="text-muted">ئایا دەتەوێت بەردەوام بیت لە سڕینەوەکەدا؟</small>
                            </div>
                        </div>
                        
                        <div id="delete_debt_warning_step2" style="display: none;">
                            <div class="alert alert-danger border-danger">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                <strong>دڵنیایی کۆتایی</strong>
                                <br><br>
                                تۆ دەتەوێت کڕیاری <strong id="delete_customer_name_final"></strong> بسڕیتەوە لەگەڵ قەرزی چالاکی هەیە.
                                <br><br>
                                <strong class="text-danger">ئەم کردارە ناگەڕێتەوە و هەموو زانیاریەکان دەسڕێتەوە!</strong>
                                <br><br>
                                <small>تکایە دڵنیا بکەوە لە بڕیارەکەت.</small>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">پاشگەزبوونەوە</button>
                        <button type="button" id="delete_continue_btn" class="btn btn-warning" style="display: none;" onclick="confirmDeleteWithDebt()">بەردەوامبوون لەگەڵ سڕینەوە</button>
                        <button type="submit" id="delete_confirm_btn" class="btn btn-danger" style="display: none;">بەڵێ، دڵنیام - بسڕەوە</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function openLinkGmailModal(customerId, customerName, existingGmail) {
            document.getElementById('gmail_customer_id').value = customerId;
            document.getElementById('gmail_customer_name').textContent = customerName || '-';

            const gmailInput = document.getElementById('gmail_input');
            const submitBtn = document.getElementById('gmail_submit_btn');
            const normalizedExisting = (existingGmail || '').trim();

            gmailInput.value = normalizedExisting;
            gmailInput.readOnly = false;
            submitBtn.disabled = false;
            gmailInput.title = normalizedExisting !== '' ? 'Gmailی ئێستا دەتوانیت بگۆڕیت' : '';

            const modal = new bootstrap.Modal(document.getElementById('linkGmailModal'));
            modal.show();
        }

        function editCustomerFromBtn(btn) {
            try {
                const customer = JSON.parse(btn.getAttribute('data-customer'));
                editCustomer(customer);
            } catch (e) {
                console.error('Error parsing customer data:', e);
            }
        }

        function editCustomer(customer) {
            document.getElementById('edit_customer_id').value = customer.id || '';
            document.getElementById('edit_name').value = customer.name || '';
            document.getElementById('edit_phone').value = customer.phone || '';
            document.getElementById('edit_address').value = customer.address || '';
            document.getElementById('edit_region_id').value = customer.region_id || '';
            
            const debtIqd = parseFloat(customer.current_debt_iqd) || 0;
            const debtUsd = parseFloat(customer.current_debt_usd) || 0;
            
            document.getElementById('edit_debt_iqd').value = debtIqd.toLocaleString('en-US', {minimumFractionDigits: 0, maximumFractionDigits: 0}) + ' د.ع';
            document.getElementById('edit_debt_usd').value = debtUsd.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' $';
            document.getElementById('edit_total_debt').value = customer.total_debt || 0;
            document.getElementById('edit_notes').value = customer.notes || '';
            document.getElementById('edit_status').value = customer.status || 'active';

            const modal = new bootstrap.Modal(document.getElementById('editCustomerModal'));
            modal.show();
        }

        function deleteCustomer(id, name) {
            resetDeleteModal();
            
            document.getElementById('delete_customer_id').value = id;
            document.getElementById('delete_customer_name').textContent = name;
            document.getElementById('delete_customer_name_debt').textContent = name;
            document.getElementById('delete_customer_name_final').textContent = name;
            document.getElementById('confirm_delete_with_debt').value = '0';
            
            document.getElementById('delete_loading').style.display = 'block';
            document.getElementById('delete_no_debt_warning').style.display = 'none';
            document.getElementById('delete_debt_warning_step1').style.display = 'none';
            document.getElementById('delete_debt_warning_step2').style.display = 'none';
            document.getElementById('delete_continue_btn').style.display = 'none';
            document.getElementById('delete_confirm_btn').style.display = 'none';
            
            const modal = new bootstrap.Modal(document.getElementById('deleteCustomerModal'));
            modal.show();

            fetchCustomerDebts(id);
        }
        
        function fetchCustomerDebts(customerId) {
            fetch(`<?php echo url('user/customers/get_customer_debts.php'); ?>?customer_id=${customerId}`)
                .then(response => response.json())
                .then(data => {
                    document.getElementById('delete_loading').style.display = 'none';
                    
                    if (data.success && data.debts && data.debts.length > 0) {
                        showDebtWarning(data);
                    } else {
                        showNoDebtConfirmation();
                    }
                })
                .catch(error => {
                    console.error('Error fetching debts:', error);
                    document.getElementById('delete_loading').style.display = 'none';
                    showNoDebtConfirmation();
                });
        }
        
        function showDebtWarning(debtData) {
            const debts = debtData.debts || [];
            const debtCount = debts.length;
            
            let totalIQD = 0;
            let totalUSD = 0;
            
            debts.forEach(debt => {
                const currency = (debt.currency || 'IQD').toUpperCase();
                const amount = parseFloat(debt.remaining_amount) || 0;
                if (currency === 'USD') {
                    totalUSD += amount;
                } else {
                    totalIQD += amount;
                }
            });
            
            document.getElementById('delete_debt_count_info').textContent = 
                `ژمارەی قەرزە چالاکەکان: ${debtCount}`;
            
            let amountText = 'کۆی قەرزی ماوە: ';
            const amounts = [];
            if (totalIQD > 0) {
                amounts.push(totalIQD.toLocaleString('en-US') + ' IQD');
            }
            if (totalUSD > 0) {
                amounts.push(totalUSD.toLocaleString('en-US', {minimumFractionDigits: 2}) + ' USD');
            }
            if (amounts.length === 0) {
                amounts.push('0 IQD');
            }
            document.getElementById('delete_debt_amount_info').textContent = 
                amountText + amounts.join(' + ');
            
            // Show first step warning
            document.getElementById('delete_debt_warning_step1').style.display = 'block';
            document.getElementById('delete_continue_btn').style.display = 'inline-block';
        }
        
        function showNoDebtConfirmation() {
            document.getElementById('delete_no_debt_warning').style.display = 'block';
            document.getElementById('delete_confirm_btn').style.display = 'inline-block';
        }
        
        function confirmDeleteWithDebt() {
            // Hide first step, show second step
            document.getElementById('delete_debt_warning_step1').style.display = 'none';
            document.getElementById('delete_continue_btn').style.display = 'none';
            document.getElementById('delete_debt_warning_step2').style.display = 'block';
            document.getElementById('delete_confirm_btn').style.display = 'inline-block';
            
            // Set confirmation flag
            document.getElementById('confirm_delete_with_debt').value = '1';
        }
        
        function resetDeleteModal() {
            // Reset all states when modal is closed
            document.getElementById('delete_loading').style.display = 'none';
            document.getElementById('delete_no_debt_warning').style.display = 'none';
            document.getElementById('delete_debt_warning_step1').style.display = 'none';
            document.getElementById('delete_debt_warning_step2').style.display = 'none';
            document.getElementById('delete_continue_btn').style.display = 'none';
            document.getElementById('delete_confirm_btn').style.display = 'none';
            document.getElementById('confirm_delete_with_debt').value = '0';
            currentDeleteCustomerId = null;
            currentDeleteCustomerName = null;
            currentDebtInfo = null;
        }
        
        function formatMoneyDisplay(amount, currency) {
            const formatted = new Intl.NumberFormat('ar-IQ', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 3
            }).format(amount);
            return formatted + ' ' + currency;
        }
        
        // Reset modal when closed
        document.addEventListener('DOMContentLoaded', function() {
            const deleteModal = document.getElementById('deleteCustomerModal');
            if (deleteModal) {
                deleteModal.addEventListener('hidden.bs.modal', function() {
                    resetDeleteModal();
                });
            }
        });
        
        // ئەگەر action=add بێت و سەرکەوتوو نەبووبێت، راستەوخۆ modal ی add customer نیشان بدە
<?php if ($showAddCustomerForm && empty($success)): ?>
document.addEventListener('DOMContentLoaded', function() {
    const addModalEl = document.getElementById('addCustomerModal');
    if (addModalEl && typeof bootstrap !== 'undefined') {
        const addModal = new bootstrap.Modal(addModalEl);
        addModal.show();
    }
});
<?php endif; ?>

    </script>

</body>
</html>