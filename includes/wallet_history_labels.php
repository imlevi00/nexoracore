<?php

function walletHistoryTypeLabel(string $txType): string
{
    $map = [
        'transfer_out' => 'گواستنەوە (دەرچوون)',
        'transfer_in' => 'گواستنەوە (هاتن)',
        'deposit' => 'زیادکردن',
        'withdraw' => 'کەمکردن',
        'sale_income' => 'داهاتی فرۆشتن',
        'sale_reversal_out' => 'گەڕاندنەوەی فرۆشتن (سڕینەوە)',
        'expense_out' => 'خەرجی (دەرچوون)',
        'expense_reversal_in' => 'گەڕاندنەوەی خەرجی (دەستکاری)',
        'purchase_out' => 'کڕین (دەرچوون)',
        'purchase_installment_out' => 'قستی کڕین (دەرچوون)',
        'return_out' => 'گەڕاندنەوەی پارە (دەرچوون)',
        'expense_credit_payment_out' => 'پارەدانی قەرزی خەرجی (دەرچوون)',
    ];

    return $map[$txType] ?? 'جۆری نەناسراو';
}

function walletHistoryReferenceLabel(string $referenceType): string
{
    $map = [
        'wallet_transfer' => 'گواستنەوەی نێوان قاسەکان',
        'wallet_adjustment' => 'دەستکاریی دەستی قاسە',
        'sale' => 'مامەڵەی فرۆشتن',
        'expense' => 'مامەڵەی خەرجی',
        'purchase_receipt' => 'وەسڵی کڕین',
        'purchase_installment' => 'قستی کڕین',
        'return' => 'گەڕاندنەوە',
        'expense_credit_payment' => 'پارەدانی قەرزی خەرجی',
    ];

    return $map[$referenceType] ?? 'مامەڵەی دارایی';
}

function walletHistorySystemNoteMap(): array
{
    return [
        'POS sale income' => 'داهاتی فرۆشتنی POS',
        'Expense payment' => 'پارەدانی خەرجی',
        'Purchase installment payment' => 'پارەدانی قستی کڕین',
        'Purchase receipt outflow' => 'دەرچوونی پارەی وەسڵی کڕین',
        'Return refund outflow' => 'دەرچوونی پارەی گەڕاندنەوە',
        'Expense credit payment' => 'پارەدانی قەرزی خەرجی',
        'Expense reversal' => 'گەڕاندنەوەی خەرجی',
        'Sale deletion reversal' => 'گەڕاندنەوەی پارەی فرۆشتن (سڕینەوە)',
    ];
}

function walletHistoryUserNote(array $row): string
{
    $rawNote = trim((string)($row['notes'] ?? ''));
    if ($rawNote === '') {
        return '';
    }

    $referenceType = (string)($row['reference_type'] ?? '');
    $knownNotes = walletHistorySystemNoteMap();

    if (isset($knownNotes[$rawNote])) {
        return '';
    }

    if (in_array($referenceType, ['wallet_adjustment', 'wallet_transfer'], true)) {
        return $rawNote;
    }

    if (preg_match('/[A-Za-z]/u', $rawNote) === 1) {
        return '';
    }

    return $rawNote;
}

function walletHistoryNoteLabel(array $row): string
{
    $userNote = walletHistoryUserNote($row);
    if ($userNote !== '') {
        return $userNote;
    }

    $rawNote = trim((string)($row['notes'] ?? ''));
    $referenceType = (string)($row['reference_type'] ?? '');

    if ($rawNote !== '') {
        $knownNotes = walletHistorySystemNoteMap();
        if (isset($knownNotes[$rawNote])) {
            return $knownNotes[$rawNote];
        }

        if (preg_match('/[A-Za-z]/u', $rawNote) === 1) {
            return walletHistoryReferenceLabel($referenceType);
        }
    }

    if ($referenceType === 'wallet_transfer') {
        return (string)($row['direction'] ?? '') === 'in' ? 'گواستنەوە بۆ ناو ئەم قاسەیە' : 'گواستنەوە لە ناو ئەم قاسەیە';
    }

    if ($referenceType === 'wallet_adjustment') {
        return (string)($row['direction'] ?? '') === 'in' ? 'زیادکردنی دەستی باڵانس' : 'کەمکردنی دەستی باڵانس';
    }

    return walletHistoryReferenceLabel($referenceType);
}

function walletReceiptNumberPrefix(string $referenceType, string $txType): string
{
    $map = [
        'wallet_adjustment' => 'WA',
        'wallet_transfer' => 'WT',
        'sale' => 'SL',
        'expense' => 'EX',
        'purchase_receipt' => 'PR',
        'purchase_installment' => 'PI',
        'return' => 'RT',
        'expense_credit_payment' => 'EC',
    ];

    if (isset($map[$referenceType])) {
        return $map[$referenceType];
    }

    if (str_starts_with($txType, 'transfer_')) {
        return 'WT';
    }

    return 'WX';
}

function walletReceiptTitle(string $referenceType, string $txType): string
{
    if ($referenceType === 'wallet_adjustment') {
        return 'وەسڵی کاش';
    }
    if ($referenceType === 'wallet_transfer') {
        return 'وەسڵی گواستنەوە';
    }

    $map = [
        'sale_income' => 'وەسڵی داهاتی فرۆشتن',
        'sale_reversal_out' => 'وەسڵی سڕینەوەی فرۆشتن',
        'expense_out' => 'وەسڵی خەرجی',
        'expense_reversal_in' => 'وەسڵی گەڕاندنەوەی خەرجی',
        'purchase_out' => 'وەسڵی کڕین',
        'purchase_installment_out' => 'وەسڵی قستی کڕین',
        'return_out' => 'وەسڵی گەڕاندنەوە',
        'expense_credit_payment_out' => 'وەسڵی پارەدانی قەرز',
        'deposit' => 'وەسڵی زیادکردن',
        'withdraw' => 'وەسڵی کەمکردن',
    ];

    return $map[$txType] ?? 'وەسڵی جوڵەی قاسە';
}
