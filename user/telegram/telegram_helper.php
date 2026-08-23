<?php
/**
 * یارمەتیدەری تیلیگرام بۆت
 * user/telegram/telegram_helper.php
 */

class TelegramHelper {
    private $botToken;
    private $apiUrl;
    
    public function __construct($botToken = null) {
        if ($botToken) {
            $this->botToken = $botToken;
        } else {
            $this->botToken = $this->getBotToken();
        }
        $this->apiUrl = "https://api.telegram.org/bot{$this->botToken}/";
    }
    
    /**
     * وەرگرتنی تۆکنی بۆت لە دات اتابەیس
     */
    private function getBotToken() {
        global $conn;
        $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_bot_token'");
        if ($result && $row = $result->fetch_assoc()) {
            return $row['setting_value'];
        }
        return '';
    }
    
    /**
     * پشکنینی چالاکی سیستەمی تیلیگرام
     */
    public static function isEnabled() {
        global $conn;
        $result = $conn->query("SELECT setting_value FROM system_settings WHERE setting_key = 'telegram_enabled'");
        if ($result && $row = $result->fetch_assoc()) {
            return $row['setting_value'] == '1';
        }
        return false;
    }
    
    /**
     * ناردنی پەیامی تەکست
     */
    public function sendMessage($chatId, $text, $parseMode = 'HTML') {
        if (empty($this->botToken) || empty($chatId)) {
            return [
                'success' => false,
                'error' => 'تۆکنی بۆت یان ئایدی تیلیگرام بەتاڵە'
            ];
        }
        
        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => $parseMode
        ];
        
        return $this->makeRequest('sendMessage', $data);
    }
    
    /**
     * ناردنی فایلی HTML
     */
    public function sendDocument($chatId, $filePath, $caption = '') {
        if (empty($this->botToken) || empty($chatId)) {
            return [
                'success' => false,
                'error' => 'تۆکنی بۆت یان ئایدی تیلیگرام بەتاڵە'
            ];
        }
        
        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error' => 'فایل نەدۆزرایەوە'
            ];
        }
        
        $url = $this->apiUrl . 'sendDocument';
        
        $postFields = [
            'chat_id' => $chatId,
            'document' => new CURLFile(realpath($filePath)),
            'caption' => $caption
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $result = json_decode($response, true);
            return [
                'success' => isset($result['ok']) && $result['ok'],
                'response' => $result
            ];
        }
        
        return [
            'success' => false,
            'error' => 'هەڵەی HTTP: ' . $httpCode,
            'response' => $response
        ];
    }
    
    /**
     * داواکاری گشتی بۆ API ی تیلیگرام
     */
    private function makeRequest($method, $data) {
        $url = $this->apiUrl . $method;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode == 200) {
            $result = json_decode($response, true);
            return [
                'success' => isset($result['ok']) && $result['ok'],
                'response' => $result
            ];
        }
        
        return [
            'success' => false,
            'error' => 'هەڵەی HTTP: ' . $httpCode,
            'response' => $response
        ];
    }

    /**
     * فۆڵدەری کاتی بۆ فایلەکانی ڕاپۆرتی تیلیگرام (temp dir ـی سێرڤەر)
     */
    private static function getReportsStorageDir() {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kasher_telegram_reports';
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            return false;
        }
        return $dir;
    }

    /**
     * هەڵگرتنی فایلی HTML لە temp dir بۆ ناردن بە Telegram
     */
    private static function saveReportHtml($fileName, $html) {
        $dir = self::getReportsStorageDir();
        if ($dir === false) {
            return false;
        }

        $filePath = $dir . DIRECTORY_SEPARATOR . $fileName;
        if (@file_put_contents($filePath, $html) === false) {
            return false;
        }

        return [
            'file_path' => $filePath,
            'file_name' => $fileName
        ];
    }
    
    /**
     * دروستکردنی فایلی HTML ی ڕاپۆرتی قەرز
     */
    public static function generateDebtReportHTML($userId) {
        global $conn;
        
        // وەرگرتنی زانیاری بەکارهێنەر
        $userStmt = $conn->prepare("SELECT business_name, email, phone FROM users WHERE id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userData = $userResult->fetch_assoc();
        $userStmt->close();
        
        if (!$userData) {
            return false;
        }
        
        // وەرگرتنی زانیاریەکانی قەرز
        $debtStmt = $conn->prepare("
            SELECT 
                d.id,
                d.customer_name,
                d.customer_phone,
                d.total_debt,
                d.paid_amount,
                d.remaining_amount,
                d.status,
                d.created_at,
                COALESCE(c.address, '') as customer_address
            FROM debts d
            LEFT JOIN customers c ON d.customer_id = c.id
            WHERE d.user_id = ? AND d.status = 'active'
            ORDER BY d.remaining_amount DESC
        ");
        $debtStmt->bind_param("i", $userId);
        $debtStmt->execute();
        $debtResult = $debtStmt->get_result();
        $debts = $debtResult->fetch_all(MYSQLI_ASSOC);
        $debtStmt->close();
        
        // حسابکردنی کۆی گشتی
        $totalDebt = 0;
        $totalPaid = 0;
        $totalRemaining = 0;
        foreach ($debts as $debt) {
            $totalDebt += $debt['total_debt'];
            $totalPaid += $debt['paid_amount'];
            $totalRemaining += $debt['remaining_amount'];
        }
        
        // دروستکردنی HTML
        $html = self::getHTMLTemplate($userData, $debts, [
            'total_debt' => $totalDebt,
            'total_paid' => $totalPaid,
            'total_remaining' => $totalRemaining,
            'debt_count' => count($debts)
        ]);
        
        $fileName = "debt_report_" . $userId . "_" . date('Y-m-d') . ".html";
        return self::saveReportHtml($fileName, $html);
    }

    /**
     * دروستکردنی فایلی HTML ی لیستی کڕیاران (وەک پەڕەی چاپ)
     */
    public static function generateCustomersPrintHTML($userId) {
        global $conn;

        // وەرگرتنی زانیاری بەکارهێنەر
        $userStmt = $conn->prepare("SELECT business_name FROM users WHERE id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userData = $userResult->fetch_assoc();
        $userStmt->close();

        if (!$userData) {
            return false;
        }

        // وەرگرتنی لیستی تەواوی کڕیاران وەک پەڕەی چاپ
        $query = "
            SELECT c.id,
                   c.name,
                   c.phone,
                   COUNT(d.id) as active_debts,
                   COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'IQD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_iqd,
                   COALESCE(SUM(CASE WHEN d.status = 'active' AND COALESCE(s.currency, 'IQD') = 'USD' THEN d.remaining_amount ELSE 0 END), 0) as current_debt_usd
            FROM customers c
            LEFT JOIN debts d ON c.id = d.customer_id AND d.status = 'active'
            LEFT JOIN sales s ON d.sale_id = s.id
            WHERE c.user_id = ?
            GROUP BY c.id
            ORDER BY c.created_at DESC
        ";

        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $customers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $currentDate = date('Y/m/d');
        $currentTime = date('H:i');

        // دروستکردنی سەرەوەی HTML و دیزاین وەک پەڕەی چاپ
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپکردنی لیستی کڕیاران - {$userData['business_name']}</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .print-header {
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
        }

        .print-controls {
            margin-bottom: 1rem;
        }

        .table thead th {
            background-color: #f1f3f5;
        }

        @media print {
            body {
                background-color: #ffffff;
            }

            html[data-bs-theme='dark'] body,
            html[data-bs-theme='dark'] .card,
            html[data-bs-theme='dark'] .table,
            html[data-bs-theme='dark'] .table th,
            html[data-bs-theme='dark'] .table td,
            html[data-bs-theme='dark'] .text-muted {
                background: #ffffff !important;
                color: #000000 !important;
                border-color: #d1d5db !important;
            }

            .print-header {
                margin-bottom: 0.5rem;
                border-bottom-width: 1px;
            }

            .card {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <div class="print-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">
                    {$userData['business_name']}
                </h5>
                <small class="text-muted">لیستی کڕیاران بۆ چاپ</small>
            </div>
            <div class="text-muted small">
                <div>بەروار: {$currentDate}</div>
                <div>کات: {$currentTime}</div>
            </div>
        </div>

        <div class="print-controls d-flex justify-content-start align-items-center">
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                چاپکردن
            </button>
        </div>
HTML;

        // ناوەڕۆکی خشتە وەک پەڕەی چاپ
        $html .= <<<HTML
        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
HTML;

        if (empty($customers)) {
            $html .= <<<HTML
                <div class="text-center py-5">
                    <p class="text-muted mb-0">هیچ کڕیارێک بۆ چاپ نییە</p>
                </div>
HTML;
        } else {
            $html .= <<<HTML
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>ناوی کڕیار</th>
                                <th>ژمارەی تەلەفۆن</th>
                                <th>قەرزی دینار</th>
                                <th>قەرزی دۆلار</th>
                                <th>ژمارەی قەرزە چالاکەکان</th>
                            </tr>
                        </thead>
                        <tbody>
HTML;

            $index = 1;
            foreach ($customers as $customer) {
                $debtIqd = (float)($customer['current_debt_iqd'] ?? 0);
                $debtUsd = (float)($customer['current_debt_usd'] ?? 0);
                $phone = $customer['phone'] ?: '-';

                // هاوشێوەکردنی فۆرماتی پارە وەک formatMoney ئەگەر بوونی هەبێت
                if (function_exists('formatMoney')) {
                    $debtIqdFormatted = formatMoney($debtIqd, 'IQD');
                    $debtUsdFormatted = formatMoney($debtUsd, 'USD');
                } else {
                    $debtIqdFormatted = number_format($debtIqd) . ' IQD';
                    $debtUsdFormatted = number_format($debtUsd) . ' USD';
                }

                $customerName = htmlspecialchars($customer['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $phoneSafe = htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $activeDebts = (int)$customer['active_debts'];

                $html .= <<<HTML
                            <tr>
                                <td>{$index}</td>
                                <td>{$customerName}</td>
                                <td>{$phoneSafe}</td>
                                <td>{$debtIqdFormatted}</td>
                                <td>{$debtUsdFormatted}</td>
                                <td>{$activeDebts}</td>
                            </tr>
HTML;

                $index++;
            }

            $html .= <<<HTML
                        </tbody>
                    </table>
                </div>
HTML;
        }

        $html .= <<<HTML
            </div>
        </div>

        <div class="d-flex justify-content-center mt-2">
            <div class="text-center px-4 py-2 border border-2 border-primary rounded-pill" style="border-style: dashed;">
                <div class="fw-semibold">سیستەمی NexoraCore</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        $fileName = "customers_list_" . $userId . "_" . date('Y-m-d') . ".html";
        return self::saveReportHtml($fileName, $html);
    }

    /**
     * دروستکردنی فایلی HTML ی لیستی کۆمپانیاکان (هەمان خشتە و منطق وەک user/companies/print/index.php)
     */
    public static function generateCompaniesPrintHTML($userId) {
        global $conn;

        require_once __DIR__ . '/../../includes/company_computed_debt.php';

        $userStmt = $conn->prepare("SELECT business_name FROM users WHERE id = ?");
        $userStmt->bind_param("i", $userId);
        $userStmt->execute();
        $userResult = $userStmt->get_result();
        $userData = $userResult->fetch_assoc();
        $userStmt->close();

        if (!$userData) {
            return false;
        }

        $computedDebtExpr = company_computed_remaining_debt_expr_sql('c');
        $query = "
            SELECT 
                c.id,
                c.name,
                c.phone,
                $computedDebtExpr AS computed_remaining_debt,
                (
                    SELECT COUNT(*) 
                    FROM purchase_receipts pr 
                    WHERE pr.company_id = c.id 
                      AND pr.user_id = c.user_id
                      AND pr.payment_type = 'debt'
                      AND pr.status = 'active'
                ) AS debt_receipts_count
            FROM companies c
            WHERE c.user_id = ?
            ORDER BY c.name ASC
        ";

        $stmt = $conn->prepare($query);
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $companies = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $businessName = htmlspecialchars($userData['business_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $currentDate = date('Y/m/d');
        $currentTime = date('H:i');

        $html = <<<HTML
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>چاپکردنی لیستی قەرزی کۆمپانیاکان - {$businessName}</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        body {
            background-color: #f8f9fa;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
        }

        .print-header {
            border-bottom: 2px solid #0d6efd;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
        }

        .print-controls {
            margin-bottom: 1rem;
        }

        .table thead th {
            background-color: #f1f3f5;
        }

        @media print {
            body {
                background-color: #ffffff;
            }

            .print-header {
                margin-bottom: 0.5rem;
                border-bottom-width: 1px;
            }

            .card {
                border: none;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid py-3">
        <div class="print-header d-flex justify-content-between align-items-center">
            <div>
                <h5 class="mb-1">
                    {$businessName}
                </h5>
                <small class="text-muted">لیستی قەرزی کۆمپانیاکان بۆ چاپ</small>
            </div>
            <div class="text-muted small">
                <div>بەروار: {$currentDate}</div>
                <div>کات: {$currentTime}</div>
            </div>
        </div>

        <div class="print-controls d-flex justify-content-start align-items-center">
            <button class="btn btn-primary btn-sm" onclick="window.print()">
                چاپکردن
            </button>
        </div>
HTML;

        $html .= <<<HTML
        <div class="card shadow-sm mb-3">
            <div class="card-body p-0">
HTML;

        if (empty($companies)) {
            $html .= <<<HTML
                <div class="text-center py-5">
                    <p class="text-muted mb-0">هیچ کۆمپانیایەک بۆ چاپ نییە</p>
                </div>
HTML;
        } else {
            $html .= <<<HTML
                <div class="table-responsive">
                    <table class="table table-bordered table-striped mb-0">
                        <thead>
                            <tr>
                                <th style="width: 60px;">#</th>
                                <th>ناوی کۆمپانیا</th>
                                <th>ژمارەی موبایل</th>
                                <th>بڕی پارەی قەرزی ماوە (دینار)</th>
                                <th>ژمارەی وەسڵە قەرزە چالاکەکان</th>
                            </tr>
                        </thead>
                        <tbody>
HTML;

            $index = 1;
            foreach ($companies as $company) {
                $name = htmlspecialchars($company['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $phone = htmlspecialchars(($company['phone'] ?: '-') ?: '-', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $debt = number_format((float)($company['computed_remaining_debt'] ?? 0));
                $cnt = (int)($company['debt_receipts_count'] ?? 0);

                $html .= <<<HTML
                            <tr>
                                <td>{$index}</td>
                                <td>{$name}</td>
                                <td>{$phone}</td>
                                <td>{$debt}</td>
                                <td>{$cnt}</td>
                            </tr>
HTML;

                $index++;
            }

            $html .= <<<HTML
                        </tbody>
                    </table>
                </div>
HTML;
        }

        $html .= <<<HTML
            </div>
        </div>

        <div class="d-flex justify-content-center mt-2">
            <div class="text-center px-4 py-2 border border-2 border-primary rounded-pill" style="border-style: dashed;">
                <div class="fw-semibold">سیستەمی NexoraCore</div>
                <div class="small text-muted">nexoracore.com</div>
            </div>
        </div>
    </div>
</body>
</html>
HTML;

        $fileName = "companies_list_" . $userId . "_" . date('Y-m-d') . ".html";
        return self::saveReportHtml($fileName, $html);
    }
    
    /**
     * تێمپلەیتی HTML
     */
    private static function getHTMLTemplate($userData, $debts, $summary) {
        $currentDate = date('Y/m/d - H:i');
        
        $html = <<<HTML
<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ڕاپۆرتی قەرزەکان - {$userData['business_name']}</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            direction: rtl;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            opacity: 0.9;
            font-size: 14px;
        }
        
        .summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            padding: 30px;
            background: #f8f9fa;
        }
        
        .summary-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .summary-card .label {
            color: #6c757d;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .summary-card .value {
            font-size: 24px;
            font-weight: bold;
            color: #495057;
        }
        
        .summary-card.danger .value {
            color: #dc3545;
        }
        
        .summary-card.success .value {
            color: #28a745;
        }
        
        .summary-card.info .value {
            color: #17a2b8;
        }
        
        .content {
            padding: 30px;
        }
        
        .info-box {
            background: #e7f3ff;
            border-right: 4px solid #2196F3;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 30px;
        }
        
        .info-box h3 {
            color: #2196F3;
            margin-bottom: 10px;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,0,0,0.1);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: #6c757d;
            font-weight: 600;
        }
        
        .info-value {
            color: #495057;
        }
        
        .debt-list {
            margin-top: 20px;
        }
        
        .debt-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .debt-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .debt-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f8f9fa;
        }
        
        .customer-name {
            font-size: 18px;
            font-weight: bold;
            color: #495057;
        }
        
        .customer-phone {
            color: #6c757d;
            font-size: 14px;
        }
        
        .debt-badge {
            background: #dc3545;
            color: white;
            padding: 8px 15px;
            border-radius: 20px;
            font-weight: bold;
        }
        
        .debt-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
        }
        
        .detail-item {
            text-align: center;
        }
        
        .detail-label {
            color: #6c757d;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .detail-value {
            font-size: 16px;
            font-weight: bold;
            color: #495057;
        }
        
        .detail-value.danger {
            color: #dc3545;
        }
        
        .detail-value.success {
            color: #28a745;
        }
        
        .footer {
            background: #f8f9fa;
            padding: 20px;
            text-align: center;
            color: #6c757d;
            font-size: 14px;
        }
        
        .no-debts {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }
        
        .no-debts i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .container {
                box-shadow: none;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📊 ڕاپۆرتی قەرزەکان</h1>
            <p>{$userData['business_name']}</p>
            <p style="font-size: 12px; opacity: 0.8;">$currentDate</p>
        </div>
        
        <!-- Summary Cards -->
        <div class="summary">
            <div class="summary-card danger">
                <div class="label">کۆی قەرزی ماوە</div>
                <div class="value">{$summary['total_remaining']} IQD</div>
            </div>
            <div class="summary-card success">
                <div class="label">پارەی وەرگیراو</div>
                <div class="value">{$summary['total_paid']} IQD</div>
            </div>
            <div class="summary-card info">
                <div class="label">ژمارەی قەرزداران</div>
                <div class="value">{$summary['debt_count']}</div>
            </div>
        </div>
        
        <!-- Content -->
        <div class="content">
            <!-- Business Info -->
            <div class="info-box">
                <h3>زانیاری فرۆشگا</h3>
                <div class="info-item">
                    <span class="info-label">ناو:</span>
                    <span class="info-value">{$userData['business_name']}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">ئیمەیڵ:</span>
                    <span class="info-value">{$userData['email']}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">تەلەفۆن:</span>
                    <span class="info-value">{$userData['phone']}</span>
                </div>
            </div>
            
            <!-- Debt List -->
            <h2 style="margin-bottom: 20px; color: #495057;">لیستی قەرزداران</h2>
HTML;

        if (empty($debts)) {
            $html .= <<<HTML
            <div class="no-debts">
                <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
                <h3>پیرۆزە! هیچ قەرزێکت نییە</h3>
                <p>هەموو قەرزەکانت تەواو کراوە یان هیچ قەرزێکت نییە.</p>
            </div>
HTML;
        } else {
            foreach ($debts as $debt) {
                $createdDate = date('Y/m/d', strtotime($debt['created_at']));
                $html .= <<<HTML
            <div class="debt-item">
                <div class="debt-header">
                    <div>
                        <div class="customer-name">{$debt['customer_name']}</div>
                        <div class="customer-phone">📞 {$debt['customer_phone']}</div>
                    </div>
                    <div class="debt-badge">{$debt['remaining_amount']} IQD</div>
                </div>
                <div class="debt-details">
                    <div class="detail-item">
                        <div class="detail-label">کۆی قەرز</div>
                        <div class="detail-value">{$debt['total_debt']}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">پارەی دراو</div>
                        <div class="detail-value success">{$debt['paid_amount']}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">ماوە</div>
                        <div class="detail-value danger">{$debt['remaining_amount']}</div>
                    </div>
                    <div class="detail-item">
                        <div class="detail-label">بەروار</div>
                        <div class="detail-value">$createdDate</div>
                    </div>
                </div>
            </div>
HTML;
            }
        }
        
        $html .= <<<HTML
        </div>
        
        <!-- Footer -->
        <div class="footer">
            <p>🤖 ئەم ڕاپۆرتە بە شێوەی ئۆتۆماتیک دروستکراوە</p>
            <p>NexoraCore</p>
        </div>
    </div>
</body>
</html>
HTML;
        
        return $html;
    }
    
    /**
     * Escape بۆ HTML پەیامەکانی تیلیگرام
     */
    public static function escapeHtml($text) {
        return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
    }

    /**
     * فۆرماتی نرخ بەپێی دراو
     */
    public static function formatPrice($price, $currency = 'IQD') {
        $curr = ($currency === 'USD') ? 'USD' : 'IQD';
        $decimals = ($curr === 'USD') ? 2 : 0;
        $formatted = number_format((float)$price, $decimals, '.', ',');
        return $formatted . ($curr === 'USD' ? ' دۆلار' : ' دینار');
    }

    /**
     * وەرگرتنی ئایدی تیلیگرامی بەکارهێنەر
     */
    public static function getUserTelegramId($userId) {
        global $conn;
        $userId = (int)$userId;
        if ($userId <= 0) {
            return '';
        }

        $stmt = $conn->prepare("SELECT telegram_id FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return trim((string)($row['telegram_id'] ?? ''));
    }

    /**
     * ناردنی ئاگادارکەرەوە بۆ بەکارهێنەر (بێ شکاندنی flow ـی سەرەکی)
     */
    public static function notifyUser($userId, $messageType, $message, $parseMode = 'HTML') {
        if (!self::isEnabled()) {
            return ['success' => false, 'skipped' => true];
        }

        if (!function_exists('hasFeaturePermissionForUser')) {
            require_once __DIR__ . '/../../includes/permissions.php';
        }

        $notificationFeatureMap = getNotificationFeatureKeyMap();
        if (isset($notificationFeatureMap[$messageType])) {
            if (!hasFeaturePermissionForUser((int)$userId, $notificationFeatureMap[$messageType])) {
                return ['success' => false, 'skipped' => true, 'reason' => 'package_feature_disabled'];
            }
        }

        $telegramId = self::getUserTelegramId($userId);
        if ($telegramId === '') {
            return ['success' => false, 'skipped' => true];
        }

        try {
            $telegram = new self();
            $result = $telegram->sendMessage($telegramId, $message, $parseMode);

            if (!empty($result['success'])) {
                self::logTelegramSend($userId, $messageType, $telegramId, 'success');
            } else {
                self::logTelegramSend(
                    $userId,
                    $messageType,
                    $telegramId,
                    'failed',
                    $result['error'] ?? 'Unknown error'
                );
            }

            return $result;
        } catch (Exception $e) {
            self::logTelegramSend($userId, $messageType, $telegramId, 'failed', $e->getMessage());
            error_log('Telegram notifyUser failed: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * کاتالۆگی ئاگادارکەرەوەکان بۆ UI
     */
    public static function getNotificationCatalog() {
        return [
            [
                'key' => 'web_order',
                'title' => 'داواکاری نوێ لە فرۆشگا ئۆنڵاین',
                'description' => 'کاتێک کڕیار داواکاری نوێ دەکات لە وێب سایتەکەت',
                'icon' => 'bi-cart-check',
                'frequency' => 'instant',
            ],
            [
                'key' => 'sale_delete',
                'feature_key' => 'notifications_sale_receipt_delete',
                'title' => 'سڕینەوەی وەسڵی فرۆشتن',
                'description' => 'کاتێک وەسڵێکی فرۆشتن لە POS دەسڕدرێتەوە',
                'icon' => 'bi-receipt-cutoff',
                'frequency' => 'instant',
            ],
            [
                'key' => 'purchase_receipt_delete',
                'feature_key' => 'notifications_purchase_receipt_delete',
                'title' => 'سڕینەوەی وەسڵی کڕین',
                'description' => 'کاتێک وەسڵێکی کڕین لە بەشی کڕینەکان دەسڕدرێتەوە',
                'icon' => 'bi-bag-x',
                'frequency' => 'instant',
            ],
            [
                'key' => 'debt_payment_delete',
                'feature_key' => 'notifications_debt_payment_delete',
                'title' => 'سڕینەوەی وەسڵی پارەدانەوەی قەرز',
                'description' => 'کاتێک پارەدانەوەی قەرزی کڕیار دەسڕدرێتەوە',
                'icon' => 'bi-cash-coin',
                'frequency' => 'instant',
            ],
        ];
    }

    /**
     * ناونیشانی جۆری پەیام بۆ UI
     */
    public static function getMessageTypeLabel($messageType) {
        $labels = [
            'test_message' => 'پەیامی تاقیکردنەوە',
            'customers_report' => 'لیستی کڕیاران',
            'companies_report' => 'لیستی کۆمپانیاکان',
            'debt_report' => 'ڕاپۆرتی قەرز',
            'web_order' => 'داواکاری ئۆنڵاین',
            'sale_delete' => 'سڕینەوەی وەسڵی فرۆشتن',
            'purchase_receipt_delete' => 'سڕینەوەی وەسڵی کڕین',
            'debt_payment_delete' => 'سڕینەوەی پارەدانەوە',
        ];

        return $labels[$messageType] ?? $messageType;
    }

    /**
     * دوایین لۆگەکانی تیلیگرام بۆ بەکارهێنەر
     */
    public static function getRecentUserLogs($userId, $limit = 10) {
        global $conn;
        $userId = (int)$userId;
        $limit = max(1, min(20, (int)$limit));

        $stmt = $conn->prepare("
            SELECT message_type, status, sent_at
            FROM telegram_logs
            WHERE user_id = ?
            ORDER BY sent_at DESC
            LIMIT ?
        ");
        $stmt->bind_param('ii', $userId, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        return $rows;
    }

    private static function formatSalePaymentMethod($method) {
        $map = [
            'cash' => 'نەقد',
            'credit' => 'قەرز',
            'debt' => 'قەرز',
            'installment' => 'قسط',
        ];
        return $map[$method] ?? (string)$method;
    }

    private static function formatPurchasePaymentType($type) {
        $map = [
            'cash' => 'نەقد',
            'debt' => 'قەرز',
        ];
        return $map[$type] ?? (string)$type;
    }

    /**
     * پەیامی سڕینەوەی وەسڵی فرۆشتن
     */
    public static function buildSaleDeletedMessage($snapshot, $actorEmail, $businessName) {
        if (empty($snapshot['sale'])) {
            return '';
        }

        $sale = $snapshot['sale'];
        $items = $snapshot['items'] ?? [];
        $currency = $sale['currency'] ?? 'IQD';

        $message = "🗑️ <b>سڕینەوەی وەسڵی فرۆشتن</b>\n\n";
        $message .= "🏪 فرۆشگا: " . self::escapeHtml($businessName) . "\n";
        $message .= "📋 ژمارەی وەسڵ: <code>" . self::escapeHtml($sale['invoice_number'] ?? '-') . "</code>\n";

        if (!empty($sale['sale_date'])) {
            $message .= "📅 بەروار: " . self::escapeHtml($sale['sale_date']) . "\n";
        }

        $customerName = trim((string)($sale['customer_name'] ?? ''));
        if ($customerName !== '') {
            $message .= "👤 کڕیار: " . self::escapeHtml($customerName) . "\n";
        }

        $message .= "💳 شێوازی پارەدان: " . self::escapeHtml(self::formatSalePaymentMethod($sale['payment_method'] ?? '')) . "\n";
        $message .= "💰 کۆی گشتی: " . self::formatPrice($sale['final_amount'] ?? 0, $currency) . "\n";

        if (!empty($sale['discount']) && (float)$sale['discount'] > 0) {
            $message .= "🏷️ داشکاندن: " . self::formatPrice($sale['discount'], $currency) . "\n";
        }

        if (!empty($sale['paid_amount'])) {
            $message .= "✅ پارەی دراو: " . self::formatPrice($sale['paid_amount'], $currency) . "\n";
        }

        if (!empty($sale['remaining_amount']) && (float)$sale['remaining_amount'] > 0) {
            $message .= "⚠️ قەرزی ماوە: " . self::formatPrice($sale['remaining_amount'], $currency) . "\n";
        }

        if (!empty($items)) {
            $message .= "\n<b>کاڵاکان:</b>\n";
            foreach ($items as $item) {
                $itemCurrency = $item['currency'] ?? $currency;
                $unit = $item['unit_symbol'] ?? $item['unit_name'] ?? 'دانە';
                $message .= "• " . self::escapeHtml($item['product_name'] ?? 'کاڵا') . " — "
                    . self::escapeHtml($item['quantity'] ?? 0) . " " . self::escapeHtml($unit)
                    . " = " . self::formatPrice($item['total_price'] ?? 0, $itemCurrency) . "\n";
            }
        }

        $message .= "\n👤 سڕدرایەوە لەلایەن: " . self::escapeHtml($actorEmail) . "\n";
        $message .= "⏰ کات: " . date('Y/m/d H:i');

        return $message;
    }

    /**
     * پەیامی سڕینەوەی وەسڵی کڕین
     */
    public static function buildPurchaseReceiptDeletedMessage($receipt, $items, $companyName, $actorEmail, $businessName) {
        $message = "🗑️ <b>سڕینەوەی وەسڵی کڕین</b>\n\n";
        $message .= "🏪 فرۆشگا: " . self::escapeHtml($businessName) . "\n";
        $message .= "📋 ژمارەی وەسڵ: <code>" . self::escapeHtml($receipt['receipt_number'] ?? $receipt['id'] ?? '-') . "</code>\n";

        if (!empty($receipt['receipt_date'])) {
            $message .= "📅 بەروار: " . self::escapeHtml($receipt['receipt_date']) . "\n";
        }

        if (!empty($companyName)) {
            $message .= "🏢 کۆمپانیا: " . self::escapeHtml($companyName) . "\n";
        }

        $message .= "💳 شێوازی پارەدان: " . self::escapeHtml(self::formatPurchasePaymentType($receipt['payment_type'] ?? '')) . "\n";
        $message .= "💰 کۆی گشتی: " . self::formatPrice($receipt['final_amount'] ?? 0, 'IQD') . "\n";

        if (!empty($receipt['discount_amount']) && (float)$receipt['discount_amount'] > 0) {
            $message .= "🏷️ داشکاندن: " . self::formatPrice($receipt['discount_amount'], 'IQD') . "\n";
        }

        if (!empty($items)) {
            $message .= "\n<b>کاڵاکان:</b>\n";
            foreach ($items as $item) {
                $name = $item['product_name'] ?? ('کاڵا #' . ($item['product_id'] ?? ''));
                $message .= "• " . self::escapeHtml($name) . " — "
                    . self::escapeHtml($item['quantity'] ?? 0) . " = "
                    . self::formatPrice(($item['buy_price'] ?? 0) * ($item['quantity'] ?? 0), 'IQD') . "\n";
            }
        }

        $message .= "\n👤 سڕدرایەوە لەلایەن: " . self::escapeHtml($actorEmail) . "\n";
        $message .= "⏰ کات: " . date('Y/m/d H:i');

        return $message;
    }

    /**
     * پەیامی سڕینەوەی وەسڵی پارەدانەوەی قەرز
     */
    public static function buildDebtPaymentDeletedMessage($payment, $customerSnapshot, $actorEmail, $businessName) {
        $customerName = 'کڕیار نەناسراو';
        $customerPhone = '';
        if (is_array($customerSnapshot) && !empty($customerSnapshot['customer'])) {
            $customerName = $customerSnapshot['customer']['name'] ?? $customerName;
            $customerPhone = $customerSnapshot['customer']['phone'] ?? '';
        }

        $message = "🗑️ <b>سڕینەوەی وەسڵی پارەدانەوە</b>\n\n";
        $message .= "🏪 فرۆشگا: " . self::escapeHtml($businessName) . "\n";
        $message .= "👤 کڕیار: " . self::escapeHtml($customerName) . "\n";

        if ($customerPhone !== '') {
            $message .= "📱 تەلەفۆن: " . self::escapeHtml($customerPhone) . "\n";
        }

        if (!empty($payment['payment_date'])) {
            $message .= "📅 بەرواری پارەدان: " . self::escapeHtml($payment['payment_date']) . "\n";
        }

        $message .= "💰 بڕی پارەدان: " . self::formatPrice($payment['payment_amount'] ?? 0, 'IQD') . "\n";

        if (!empty($payment['receipt_id'])) {
            $message .= "📋 ژمارەی وەسڵ: <code>" . self::escapeHtml($payment['receipt_id']) . "</code>\n";
        }

        $message .= "\n👤 سڕدرایەوە لەلایەن: " . self::escapeHtml($actorEmail) . "\n";
        $message .= "⏰ کات: " . date('Y/m/d H:i');

        return $message;
    }

    /**
     * پەیامی داواکاری نوێ لە فرۆشگا ئۆنڵاین
     */
    public static function buildWebOrderMessage($orderNumber, $customerName, $customerPhone, $customerAddress, $items) {
        $itemsList = '';
        $totalIQD = 0;
        $totalUSD = 0;

        foreach ($items as $item) {
            $subtotal = ($item['price'] ?? 0) * ($item['quantity'] ?? 0);
            $curr = $item['currency'] ?? 'IQD';
            if ($curr === 'USD') {
                $totalUSD += $subtotal;
            } else {
                $totalIQD += $subtotal;
            }
            $itemsList .= "• " . self::escapeHtml($item['name'] ?? 'کاڵا') . " — "
                . self::escapeHtml($item['quantity'] ?? 0) . " "
                . self::escapeHtml($item['unit'] ?? 'دانە') . " × "
                . self::formatPrice($item['price'] ?? 0, $curr) . " = "
                . self::formatPrice($subtotal, $curr) . "\n";
        }

        $message = "🛒 <b>داواکارییەکی نوێ!</b>\n\n";
        $message .= "📋 ژمارەی وەسڵ: <code>" . self::escapeHtml($orderNumber) . "</code>\n";
        $message .= "👤 ناوی کڕیار: " . self::escapeHtml($customerName) . "\n";
        $message .= "📱 تەلەفۆن: " . self::escapeHtml($customerPhone) . "\n";

        if (!empty($customerAddress)) {
            $message .= "📍 ناونیشان: " . self::escapeHtml($customerAddress) . "\n";
        }

        $message .= "\n<b>کاڵاکان:</b>\n{$itemsList}";

        if ($totalIQD > 0 && $totalUSD > 0) {
            $message .= "\n💰 <b>کۆی دینار:</b> " . self::formatPrice($totalIQD, 'IQD') . "\n";
            $message .= "💰 <b>کۆی دۆلار:</b> " . self::formatPrice($totalUSD, 'USD') . "\n";
        } elseif ($totalIQD > 0) {
            $message .= "\n💰 <b>کۆی گشتی:</b> " . self::formatPrice($totalIQD, 'IQD') . "\n";
        } else {
            $message .= "\n💰 <b>کۆی گشتی:</b> " . self::formatPrice($totalUSD, 'USD') . "\n";
        }

        $message .= "\n⏰ کات: " . date('Y/m/d H:i');

        return $message;
    }

    /**
     * تۆمارکردنی لۆگی ناردن
     */
    public static function logTelegramSend($userId, $messageType, $telegramId, $status, $errorMessage = null) {
        global $conn;
        
        $stmt = $conn->prepare("
            INSERT INTO telegram_logs (user_id, message_type, telegram_id, status, error_message) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("issss", $userId, $messageType, $telegramId, $status, $errorMessage);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * نوێکردنەوەی کاتی دوایین ناردن
     */
    public static function updateLastSent($userId) {
        global $conn;
        
        $stmt = $conn->prepare("UPDATE users SET telegram_last_sent = NOW() WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
    
    /**
     * پشکنینی پێویستی بە ناردن (ڕۆژانە یەک جار)
     */
    public static function shouldSendToday($userId) {
        global $conn;
        
        $stmt = $conn->prepare("
            SELECT telegram_last_sent 
            FROM users 
            WHERE id = ? AND (
                telegram_last_sent IS NULL 
                OR DATE(telegram_last_sent) < CURDATE()
            )
        ");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $shouldSend = $result->num_rows > 0;
        $stmt->close();
        
        return $shouldSend;
    }
}

