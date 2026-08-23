<?php
/**
 * Wallet service helpers
 */

function walletGetUserWallets(mysqli $conn, int $userId, bool $activeOnly = true): array
{
    $sql = "SELECT id, user_id, name, notes, is_active, is_default, balance_iqd, balance_usd, created_at, updated_at
            FROM wallets
            WHERE user_id = ?";
    if ($activeOnly) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY is_default DESC, id ASC";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows ?: [];
}

function walletGetDefaultWalletId(mysqli $conn, int $userId): ?int
{
    $stmt = $conn->prepare("SELECT id FROM wallets WHERE user_id = ? AND is_default = 1 AND is_active = 1 LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? (int)$row['id'] : null;
}

function walletEnsureDefaultForUser(mysqli $conn, int $userId, string $defaultName = 'قاسەی سەرەکی'): array
{
    if ($userId <= 0) {
        return ['success' => false, 'message' => 'ناسنامەی بەکارهێنەر نادروستە'];
    }

    $wallets = walletGetUserWallets($conn, $userId, false);
    if (empty($wallets)) {
        $createResult = walletCreate($conn, $userId, $defaultName, '', true);
        if (!$createResult['success']) {
            return ['success' => false, 'message' => $createResult['message'] ?? 'نەتوانرا قاسەی سەرەکی دروست بکرێت'];
        }
        return [
            'success' => true,
            'wallet_id' => (int)$createResult['wallet_id'],
            'created' => true,
            'default_assigned' => true
        ];
    }

    $defaultWalletId = walletGetDefaultWalletId($conn, $userId);
    if ($defaultWalletId !== null) {
        return [
            'success' => true,
            'wallet_id' => $defaultWalletId,
            'created' => false,
            'default_assigned' => false
        ];
    }

    $firstActiveWalletId = null;
    foreach ($wallets as $wallet) {
        if ((int)($wallet['is_active'] ?? 0) === 1) {
            $firstActiveWalletId = (int)$wallet['id'];
            break;
        }
    }

    if ($firstActiveWalletId === null) {
        // ئەگەر تەنها قاسەی ناچالاک هەبێت، قاسەیەکی نوێی چالاکی بنەڕەتی دروست بکە.
        $createResult = walletCreate($conn, $userId, $defaultName, '', true);
        if (!$createResult['success']) {
            return ['success' => false, 'message' => $createResult['message'] ?? 'نەتوانرا قاسەی چالاکی بنەڕەتی دروست بکرێت'];
        }
        return [
            'success' => true,
            'wallet_id' => (int)$createResult['wallet_id'],
            'created' => true,
            'default_assigned' => true
        ];
    }

    $targetWalletId = $firstActiveWalletId;
    $setDefaultResult = walletSetDefault($conn, $userId, $targetWalletId);
    if (!$setDefaultResult) {
        return ['success' => false, 'message' => 'نەتوانرا قاسەی بنەڕەتی دابنرێت'];
    }

    return [
        'success' => true,
        'wallet_id' => $targetWalletId,
        'created' => false,
        'default_assigned' => true
    ];
}

function walletCreate(mysqli $conn, int $userId, string $name, string $notes = '', bool $setDefault = false): array
{
    $name = trim($name);
    if ($name === '') {
        return ['success' => false, 'message' => 'ناوی قاسە پێویستە'];
    }

    $conn->begin_transaction();
    try {
        if ($setDefault) {
            $clear = $conn->prepare("UPDATE wallets SET is_default = 0 WHERE user_id = ?");
            $clear->bind_param('i', $userId);
            $clear->execute();
            $clear->close();
        }

        $stmt = $conn->prepare("INSERT INTO wallets (user_id, name, notes, is_active, is_default, balance_iqd, balance_usd) VALUES (?, ?, ?, 1, ?, 0, 0)");
        $defaultInt = $setDefault ? 1 : 0;
        $stmt->bind_param('issi', $userId, $name, $notes, $defaultInt);
        if (!$stmt->execute()) {
            throw new Exception('نەتوانرا قاسە زیاد بکرێت');
        }
        $walletId = (int)$conn->insert_id;
        $stmt->close();

        if (!$setDefault) {
            $hasDefault = walletGetDefaultWalletId($conn, $userId);
            if (!$hasDefault) {
                $makeDefault = $conn->prepare("UPDATE wallets SET is_default = 1 WHERE id = ? AND user_id = ?");
                $makeDefault->bind_param('ii', $walletId, $userId);
                $makeDefault->execute();
                $makeDefault->close();
            }
        }

        $conn->commit();
        return ['success' => true, 'wallet_id' => $walletId];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function walletSetDefault(mysqli $conn, int $userId, int $walletId): bool
{
    $conn->begin_transaction();
    try {
        $clear = $conn->prepare("UPDATE wallets SET is_default = 0 WHERE user_id = ?");
        $clear->bind_param('i', $userId);
        $clear->execute();
        $clear->close();

        $set = $conn->prepare("UPDATE wallets SET is_default = 1 WHERE id = ? AND user_id = ? AND is_active = 1");
        $set->bind_param('ii', $walletId, $userId);
        $set->execute();
        $ok = $set->affected_rows > 0;
        $set->close();

        if (!$ok) {
            throw new Exception('قاسەکە نەدۆزرایەوە');
        }
        $conn->commit();
        return true;
    } catch (Throwable $e) {
        $conn->rollback();
        return false;
    }
}

function walletGetById(mysqli $conn, int $userId, int $walletId): ?array
{
    $stmt = $conn->prepare("SELECT * FROM wallets WHERE id = ? AND user_id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $walletId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function walletAdjustBalance(mysqli $conn, int $userId, int $walletId, string $currency, float $amountDelta): bool
{
    $currency = strtoupper($currency) === 'USD' ? 'USD' : 'IQD';
    $column = $currency === 'USD' ? 'balance_usd' : 'balance_iqd';
    $stmt = $conn->prepare("UPDATE wallets SET {$column} = {$column} + ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_active = 1");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param('dii', $amountDelta, $walletId, $userId);
    $ok = $stmt->execute() && $stmt->affected_rows > 0;
    $stmt->close();
    return $ok;
}

function walletRecordTransaction(
    mysqli $conn,
    int $userId,
    int $walletId,
    string $txType,
    string $direction,
    string $currency,
    float $amount,
    ?string $referenceType = null,
    ?int $referenceId = null,
    ?int $relatedWalletId = null,
    string $notes = '',
    ?int $createdBy = null
): bool {
    $currency = strtoupper($currency) === 'USD' ? 'USD' : 'IQD';
    $direction = $direction === 'out' ? 'out' : 'in';
    if ($amount <= 0) {
        return false;
    }

    $stmt = $conn->prepare("INSERT INTO wallet_transactions
        (user_id, wallet_id, tx_type, direction, currency, amount, reference_type, reference_id, related_wallet_id, notes, created_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    if (!$stmt) {
        return false;
    }
    $stmt->bind_param(
        'iisssdsiisi',
        $userId,
        $walletId,
        $txType,
        $direction,
        $currency,
        $amount,
        $referenceType,
        $referenceId,
        $relatedWalletId,
        $notes,
        $createdBy
    );
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

function walletPostEntry(
    mysqli $conn,
    int $userId,
    int $walletId,
    string $txType,
    string $direction,
    string $currency,
    float $amount,
    ?string $referenceType = null,
    ?int $referenceId = null,
    string $notes = '',
    ?int $createdBy = null
): int|false {
    if (!walletRecordTransaction($conn, $userId, $walletId, $txType, $direction, $currency, $amount, $referenceType, $referenceId, null, $notes, $createdBy)) {
        return false;
    }
    $transactionId = (int)$conn->insert_id;
    $delta = $direction === 'out' ? -1 * $amount : $amount;
    if (!walletAdjustBalance($conn, $userId, $walletId, $currency, $delta)) {
        return false;
    }
    return $transactionId;
}

function walletGetTransactionById(mysqli $conn, int $userId, int $transactionId): ?array
{
    if ($transactionId <= 0) {
        return null;
    }

    $stmt = $conn->prepare("SELECT
            wt.id,
            wt.wallet_id,
            wt.tx_type,
            wt.direction,
            wt.currency,
            wt.amount,
            wt.reference_type,
            wt.reference_id,
            wt.related_wallet_id,
            wt.notes,
            wt.created_at,
            w.name AS wallet_name,
            rw.name AS related_wallet_name,
            (
                SELECT COALESCE(SUM(
                    CASE WHEN wt_hist.direction = 'in' THEN wt_hist.amount ELSE -wt_hist.amount END
                ), 0)
                FROM wallet_transactions wt_hist
                WHERE wt_hist.user_id = wt.user_id
                  AND wt_hist.wallet_id = wt.wallet_id
                  AND wt_hist.currency = wt.currency
                  AND (
                      wt_hist.created_at < wt.created_at
                      OR (wt_hist.created_at = wt.created_at AND wt_hist.id <= wt.id)
                  )
            ) AS balance_after_tx,
            u.business_name,
            u.phone AS business_phone,
            u.address AS business_address
        FROM wallet_transactions wt
        INNER JOIN wallets w ON w.id = wt.wallet_id AND w.user_id = wt.user_id
        LEFT JOIN wallets rw ON rw.id = wt.related_wallet_id AND rw.user_id = wt.user_id
        INNER JOIN users u ON u.id = wt.user_id
        WHERE wt.id = ? AND wt.user_id = ?
        LIMIT 1");
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('ii', $transactionId, $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function walletReverseExpenseCash(
    mysqli $conn,
    int $userId,
    int $expenseId,
    int $walletId,
    float $amount,
    string $currency = 'IQD',
    ?int $createdBy = null
): bool {
    if ($walletId <= 0 || $amount <= 0) {
        return true;
    }

    $currency = strtoupper($currency) === 'USD' ? 'USD' : 'IQD';

    return walletPostEntry(
        $conn,
        $userId,
        $walletId,
        'expense_reversal_in',
        'in',
        $currency,
        $amount,
        'expense',
        $expenseId,
        'Expense reversal',
        $createdBy
    ) !== false;
}

function walletReverseSaleCash(
    mysqli $conn,
    int $userId,
    int $saleId,
    int $walletId,
    float $amount,
    string $currency = 'IQD',
    ?int $createdBy = null
): int|false {
    if ($walletId <= 0 || $amount <= 0) {
        return 0;
    }

    $currency = strtoupper($currency) === 'USD' ? 'USD' : 'IQD';

    return walletPostEntry(
        $conn,
        $userId,
        $walletId,
        'sale_reversal_out',
        'out',
        $currency,
        $amount,
        'sale',
        $saleId,
        'Sale deletion reversal',
        $createdBy
    );
}

function walletSyncExpenseOnEdit(
    mysqli $conn,
    int $userId,
    int $expenseId,
    string $oldPaymentMethod,
    int $oldWalletId,
    float $oldAmount,
    string $newPaymentMethod,
    int $newWalletId,
    float $newAmount,
    string $oldCurrency = 'IQD',
    string $newCurrency = 'IQD',
    ?int $createdBy = null
): bool {
    $oldCurrency = strtoupper($oldCurrency) === 'USD' ? 'USD' : 'IQD';
    $newCurrency = strtoupper($newCurrency) === 'USD' ? 'USD' : 'IQD';

    if ($oldPaymentMethod === 'cash' && $oldWalletId > 0 && $oldAmount > 0) {
        if (!walletReverseExpenseCash($conn, $userId, $expenseId, $oldWalletId, $oldAmount, $oldCurrency, $createdBy)) {
            return false;
        }
    }

    if ($newPaymentMethod === 'cash' && $newWalletId > 0 && $newAmount > 0) {
        if (!walletPostEntry(
            $conn,
            $userId,
            $newWalletId,
            'expense_out',
            'out',
            $newCurrency,
            $newAmount,
            'expense',
            $expenseId,
            'Expense payment',
            $createdBy
        )) {
            return false;
        }
    }

    return true;
}

function walletTransfer(
    mysqli $conn,
    int $userId,
    int $fromWalletId,
    int $toWalletId,
    string $currency,
    float $amount,
    string $notes = '',
    ?int $createdBy = null
): array {
    if ($fromWalletId === $toWalletId) {
        return ['success' => false, 'message' => 'قاسەی سەرچاوە و وەرگر نابێت یەک بن'];
    }
    if ($amount <= 0) {
        return ['success' => false, 'message' => 'بڕ دەبێت زیاتر بێت لە 0'];
    }

    $currency = strtoupper($currency) === 'USD' ? 'USD' : 'IQD';
    $conn->begin_transaction();
    try {
        $fromWallet = walletGetById($conn, $userId, $fromWalletId);
        $toWallet = walletGetById($conn, $userId, $toWalletId);
        if (!$fromWallet || !$toWallet) {
            throw new Exception('قاسەکە نەدۆزرایەوە');
        }

        $currentBalance = $currency === 'USD' ? (float)$fromWallet['balance_usd'] : (float)$fromWallet['balance_iqd'];
        if ($currentBalance < $amount) {
            throw new Exception('باڵانسی قاسەی سەرچاوە نەکافییە');
        }

        if (!walletRecordTransaction($conn, $userId, $fromWalletId, 'transfer_out', 'out', $currency, $amount, 'wallet_transfer', null, $toWalletId, $notes, $createdBy)) {
            throw new Exception('هەڵە لە تۆماری دەرچوون');
        }
        if (!walletAdjustBalance($conn, $userId, $fromWalletId, $currency, -1 * $amount)) {
            throw new Exception('هەڵە لە نوێکردنەوەی باڵانسی دەرچوون');
        }

        if (!walletRecordTransaction($conn, $userId, $toWalletId, 'transfer_in', 'in', $currency, $amount, 'wallet_transfer', null, $fromWalletId, $notes, $createdBy)) {
            throw new Exception('هەڵە لە تۆماری هاتن');
        }
        if (!walletAdjustBalance($conn, $userId, $toWalletId, $currency, $amount)) {
            throw new Exception('هەڵە لە نوێکردنەوەی باڵانسی هاتن');
        }

        $conn->commit();
        return ['success' => true];
    } catch (Throwable $e) {
        $conn->rollback();
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

function walletGetTransactionHistory(
    mysqli $conn,
    int $userId,
    ?int $walletId = null,
    int $limit = 100,
    ?string $dateFrom = null,
    ?string $dateTo = null
): array {
    $limit = max(1, min($limit, 500));
    $sql = "SELECT
                wt.id,
                wt.wallet_id,
                w.name AS wallet_name,
                wt.tx_type,
                wt.direction,
                wt.currency,
                wt.amount,
                wt.reference_type,
                wt.reference_id,
                wt.related_wallet_id,
                rw.name AS related_wallet_name,
                wt.notes,
                wt.created_at
            FROM wallet_transactions wt
            INNER JOIN wallets w ON w.id = wt.wallet_id AND w.user_id = wt.user_id
            LEFT JOIN wallets rw ON rw.id = wt.related_wallet_id AND rw.user_id = wt.user_id
            WHERE wt.user_id = ?";

    $types = 'i';
    $params = [$userId];

    if ($walletId !== null && $walletId > 0) {
        $sql .= " AND wt.wallet_id = ?";
        $types .= 'i';
        $params[] = $walletId;
    }

    if ($dateFrom !== null && $dateFrom !== '') {
        $sql .= " AND DATE(wt.created_at) >= ?";
        $types .= 's';
        $params[] = $dateFrom;
    }

    if ($dateTo !== null && $dateTo !== '') {
        $sql .= " AND DATE(wt.created_at) <= ?";
        $types .= 's';
        $params[] = $dateTo;
    }

    $sql .= " ORDER BY wt.created_at DESC, wt.id DESC LIMIT ?";
    $types .= 'i';
    $params[] = $limit;

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $rows ?: [];
}

require_once __DIR__ . '/wallet_deactivate.php';
