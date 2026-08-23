<?php
declare(strict_types=1);

/**
 * پاڵاوتەی تۆماری کەیس بەپێی ئەو ئەکاونتەی خۆی دروستی کردووە (سەنتەر یان دکتۆر).
 *
 * لەگەڵ هەر خوێندنەوە/گۆڕانکارییەکدا WHERE ـی یادکردنەوە:
 *   c.user_id = ? AND c.created_by_role = ? AND c.created_by_account_id = ?
 */

/**
 * @return array{user_id: int, role: string, account_id: int}
 */
function cosmetic_case_creator_from_center_session(array $session): array
{
    return [
        'user_id' => (int)($session['user_id'] ?? 0),
        'role' => 'center',
        'account_id' => (int)($session['center_account_id'] ?? 0),
    ];
}

/**
 * @return array{user_id: int, role: string, account_id: int}
 */
function cosmetic_case_creator_from_doctor_session(array $session): array
{
    return [
        'user_id' => (int)($session['user_id'] ?? 0),
        'role' => 'doctor',
        'account_id' => (int)($session['doctor_account_id'] ?? 0),
    ];
}
