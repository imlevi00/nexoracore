<?php
/**
 * شاردنەوەی قاسە (soft delete) — is_active = 0
 */

if (!function_exists('walletCountActiveWallets')) {
    function walletCountActiveWallets(mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM wallets WHERE user_id = ? AND is_active = 1");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return (int)($row['cnt'] ?? 0);
    }
}

if (!function_exists('walletDeactivate')) {
    function walletDeactivate(mysqli $conn, int $userId, int $walletId): array
    {
        if ($walletId <= 0) {
            return ['success' => false, 'message' => 'قاسەکە نادروستە'];
        }

        if (!function_exists('walletGetById')) {
            return ['success' => false, 'message' => 'خزمەتگوزاری قاسە بە تەواوی بارنەکراوە'];
        }

        $wallet = walletGetById($conn, $userId, $walletId);
        if (!$wallet) {
            return ['success' => false, 'message' => 'قاسەکە نەدۆزرایەوە'];
        }
        if ((int)($wallet['is_active'] ?? 0) !== 1) {
            return ['success' => false, 'message' => 'ئەم قاسەیە پێشتر شاردراوەتەوە'];
        }
        if (walletCountActiveWallets($conn, $userId) <= 1) {
            return ['success' => false, 'message' => 'نابێت تەنها قاسەی چالاک بشاردرێتەوە'];
        }

        $conn->begin_transaction();
        try {
            if ((int)($wallet['is_default'] ?? 0) === 1) {
                $nextStmt = $conn->prepare(
                    "SELECT id FROM wallets WHERE user_id = ? AND is_active = 1 AND id <> ? ORDER BY id ASC LIMIT 1"
                );
                if (!$nextStmt) {
                    throw new Exception('نەتوانرا قاسەی بنەڕەتی نوێ دیاری بکرێت');
                }
                $nextStmt->bind_param('ii', $userId, $walletId);
                $nextStmt->execute();
                $nextRow = $nextStmt->get_result()->fetch_assoc();
                $nextStmt->close();

                if (!$nextRow) {
                    throw new Exception('نەتوانرا قاسەی بنەڕەتی نوێ دیاری بکرێت');
                }

                $clearDefault = $conn->prepare("UPDATE wallets SET is_default = 0 WHERE user_id = ?");
                if (!$clearDefault) {
                    throw new Exception('نەتوانرا قاسەی بنەڕەتی نوێ دیاری بکرێت');
                }
                $clearDefault->bind_param('i', $userId);
                $clearDefault->execute();
                $clearDefault->close();

                $nextWalletId = (int)$nextRow['id'];
                $setDefault = $conn->prepare(
                    "UPDATE wallets SET is_default = 1 WHERE id = ? AND user_id = ? AND is_active = 1"
                );
                if (!$setDefault) {
                    throw new Exception('نەتوانرا قاسەی بنەڕەتی نوێ دیاری بکرێت');
                }
                $setDefault->bind_param('ii', $nextWalletId, $userId);
                $setDefault->execute();
                if ($setDefault->affected_rows <= 0) {
                    $setDefault->close();
                    throw new Exception('نەتوانرا قاسەی بنەڕەتی نوێ دیاری بکرێت');
                }
                $setDefault->close();
            }

            $hideStmt = $conn->prepare(
                "UPDATE wallets SET is_active = 0, is_default = 0, updated_at = NOW() WHERE id = ? AND user_id = ? AND is_active = 1"
            );
            if (!$hideStmt) {
                throw new Exception('نەتوانرا قاسە بشاردرێتەوە');
            }
            $hideStmt->bind_param('ii', $walletId, $userId);
            $hideStmt->execute();
            $hidden = $hideStmt->affected_rows > 0;
            $hideStmt->close();

            if (!$hidden) {
                throw new Exception('نەتوانرا قاسە بشاردرێتەوە');
            }

            $conn->commit();
            return ['success' => true, 'message' => 'قاسەکە شاردرایەوە'];
        } catch (Throwable $e) {
            $conn->rollback();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
