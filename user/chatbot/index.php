<?php
/**
 * گفتوگۆکردن لەگەڵ NexoraCore
 * user/chatbot/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');

$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'dashboard.view', [
    'route' => '/user/chatbot/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
$userId = $currentUser['id'];

// وەرگرتنی باڵانس
$stmt = $conn->prepare("SELECT ai_balance FROM users WHERE id = ?");
$stmt->bind_param("i", $userId);
$stmt->execute();
$result = $stmt->get_result();
$userData = $result->fetch_assoc();
$balance = $userData['ai_balance'] ?? 0;

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>NexoraCore - <?php echo SITE_NAME; ?></title>
    <meta name="theme-color" content="#343541">
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/modules-responsive.css'); ?>" rel="stylesheet">

    
    <!-- Markdown & Syntax Highlighting -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/atom-one-dark.min.css">
    <script src="https://cdn.jsdelivr.net/npm/marked@11.1.1/marked.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    
    <style>
        :root {
            --chat-bg: #343541;
            --sidebar-bg: #202123;
            --user-msg-bg: #343541;
            --assistant-msg-bg: #444654;
            --border-color: #4d4d4f;
            --text-primary: #ececf1;
            --text-secondary: #8e8ea0;
            --accent-color: #10a37f;
            --hover-bg: #2a2b32;
        }

        body {
            background: var(--chat-bg);
            color: var(--text-primary);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            height: 100vh;
            overflow: hidden;
        }

        .chat-container {
            display: flex;
            height: 100vh;
            max-height: 100vh;
        }

        /* Sidebar */
        .sidebar {
            width: 280px;
            background: var(--sidebar-bg);
            border-left: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
        }

        .new-chat-btn {
            width: 100%;
            padding: 12px;
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .new-chat-btn:hover {
            background: var(--hover-bg);
        }

        .chat-list {
            flex: 1;
            overflow-y: auto;
            padding: 10px;
        }

        .chat-item {
            padding: 12px;
            margin-bottom: 5px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            color: var(--text-primary);
            border: 1px solid transparent;
        }

        .chat-item:hover {
            background: var(--hover-bg);
        }

        .chat-item.active {
            background: var(--hover-bg);
            border-color: var(--accent-color);
        }

        .chat-item-title {
            font-size: 14px;
            margin-bottom: 4px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .chat-item-meta {
            font-size: 12px;
            color: var(--text-secondary);
        }

        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid var(--border-color);
        }

        .balance-info {
            background: var(--hover-bg);
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 10px;
            text-align: center;
        }

        .balance-amount {
            font-size: 20px;
            font-weight: bold;
            color: var(--accent-color);
        }

        /* Main Chat Area */
        .chat-main {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: var(--chat-bg);
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            scroll-behavior: smooth;
        }

        .message {
            max-width: 800px;
            margin: 0 auto 20px;
            display: flex;
            gap: 20px;
            padding: 20px;
            border-radius: 8px;
        }

        .message.user {
            background: var(--user-msg-bg);
        }

        .message.assistant {
            background: var(--assistant-msg-bg);
        }

        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-color);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .message-content {
            flex: 1;
            line-height: 1.6;
        }

        .message-usage {
            max-width: 800px;
            margin: -10px auto 20px;
            padding: 8px 15px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 6px;
            font-size: 11px;
            color: var(--text-secondary);
            display: flex;
            gap: 15px;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.08);
        }

        .usage-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .usage-item i {
            font-size: 12px;
            opacity: 0.7;
        }

        .usage-cost {
            margin-right: auto;
            color: var(--accent-color);
            font-weight: 600;
        }

        /* Section Selection */
        .section-selection {
            text-align: center;
            padding: 40px 20px;
            max-width: 600px;
            margin: 0 auto;
        }

        .section-buttons {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }

        .section-btn {
            padding: 20px;
            background: var(--hover-bg);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }

        .section-btn:hover {
            border-color: var(--accent-color);
            transform: translateY(-2px);
        }

        .section-btn i {
            font-size: 32px;
            display: block;
            margin-bottom: 10px;
            color: var(--accent-color);
        }

        /* Input Area */
        .input-area {
            padding: 20px;
            border-top: 1px solid var(--border-color);
            background: var(--chat-bg);
        }

        .input-wrapper {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
        }

        .message-input {
            width: 100%;
            padding: 15px 50px 15px 15px;
            background: var(--hover-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            resize: none;
            min-height: 50px;
            max-height: 200px;
        }

        .message-input:focus {
            outline: none;
            border-color: var(--accent-color);
        }

        .send-btn {
            position: absolute;
            left: 10px;
            bottom: 10px;
            width: 35px;
            height: 35px;
            background: var(--accent-color);
            border: none;
            border-radius: 8px;
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
        }

        .send-btn:hover {
            background: #0d8c6f;
        }

        .send-btn:disabled {
            background: var(--border-color);
            cursor: not-allowed;
        }

        .loading {
            display: inline-block;
            width: 2px;
            height: 20px;
            background: var(--accent-color);
            margin-left: 2px;
            animation: blink 1s ease-in-out infinite;
        }

        @keyframes blink {
            0%, 49% { opacity: 1; }
            50%, 100% { opacity: 0; }
        }

        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Streaming cursor effect */
        .message-content.streaming::after {
            content: '';
            display: inline-block;
            width: 2px;
            height: 1em;
            background: var(--accent-color);
            margin-left: 2px;
            animation: blink 1s ease-in-out infinite;
            vertical-align: middle;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-secondary);
        }

        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        /* Mobile responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                right: -280px;
                height: 100vh;
                z-index: 1000;
            }

            .sidebar.active {
                right: 0;
            }

            .section-buttons {
                grid-template-columns: 1fr;
            }
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
        }

        ::-webkit-scrollbar-track {
            background: var(--sidebar-bg);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 4px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--text-secondary);
        }

        /* Markdown Styling */
        .message-content h1,
        .message-content h2,
        .message-content h3,
        .message-content h4,
        .message-content h5,
        .message-content h6 {
            color: var(--text-primary);
            margin: 16px 0 8px 0;
            font-weight: 600;
        }

        .message-content h1 { font-size: 1.8em; border-bottom: 1px solid var(--border-color); padding-bottom: 8px; }
        .message-content h2 { font-size: 1.5em; }
        .message-content h3 { font-size: 1.3em; }

        .message-content p {
            margin: 8px 0;
            line-height: 1.6;
        }

        .message-content ul,
        .message-content ol {
            margin: 12px 0;
            padding-right: 24px;
        }

        .message-content li {
            margin: 6px 0;
        }

        .message-content code {
            background: rgba(0, 0, 0, 0.3);
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            color: #e06c75;
        }

        .message-content pre {
            background: #282c34;
            border-radius: 8px;
            padding: 16px;
            margin: 12px 0;
            overflow-x: auto;
            position: relative;
        }

        .message-content pre code {
            background: none;
            padding: 0;
            color: #abb2bf;
            font-size: 0.9em;
            line-height: 1.5;
        }

        .message-content blockquote {
            border-right: 4px solid var(--accent-color);
            margin: 12px 0;
            padding: 8px 16px;
            background: rgba(16, 163, 127, 0.1);
            border-radius: 4px;
        }

        .message-content table {
            border-collapse: collapse;
            width: 100%;
            margin: 12px 0;
        }

        .message-content table th,
        .message-content table td {
            border: 1px solid var(--border-color);
            padding: 8px 12px;
            text-align: right;
        }

        .message-content table th {
            background: var(--hover-bg);
            font-weight: 600;
        }

        .message-content a {
            color: var(--accent-color);
            text-decoration: none;
        }

        .message-content a:hover {
            text-decoration: underline;
        }

        .message-content strong {
            font-weight: 600;
            color: var(--text-primary);
        }

        .message-content em {
            font-style: italic;
            color: var(--text-secondary);
        }

        /* HTML Preview Box */
        .html-preview-container {
            margin: 16px 0;
            border: 2px solid var(--accent-color);
            border-radius: 10px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(16, 163, 127, 0.1), rgba(16, 163, 127, 0.05));
            transition: all 0.3s ease;
        }

        .html-preview-container:hover {
            border-color: var(--accent-color);
            box-shadow: 0 0 20px rgba(16, 163, 127, 0.3);
            transform: translateY(-2px);
        }

        .html-preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            background: rgba(16, 163, 127, 0.15);
            cursor: pointer;
            transition: all 0.3s;
        }

        .html-preview-header:hover {
            background: rgba(16, 163, 127, 0.25);
        }

        .html-preview-title {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            color: var(--text-primary);
            font-weight: 600;
        }

        .html-preview-title i {
            color: var(--accent-color);
            font-size: 20px;
        }

        .html-preview-toggle {
            font-size: 13px;
            color: var(--accent-color);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }

        .html-preview-toggle i {
            font-size: 16px;
        }

        /* Copy button for code blocks */
        .code-block-wrapper {
            position: relative;
        }

        .copy-code-btn {
            position: absolute;
            top: 8px;
            left: 8px;
            background: var(--border-color);
            color: var(--text-primary);
            border: none;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 11px;
            cursor: pointer;
            opacity: 0.7;
            transition: all 0.2s;
        }

        .copy-code-btn:hover {
            opacity: 1;
            background: var(--accent-color);
        }

        .copy-code-btn.copied {
            background: #28a745;
        }

        /* HTML Preview Modal (Fullscreen) */
        .html-preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            width: 100%;
            height: 100%;
            background: white;
            z-index: 999999;
            animation: fadeIn 0.2s ease;
        }

        .html-preview-modal.show {
            display: flex;
            flex-direction: column;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        /* Floating controls - دوگمەکان بە شێوەی floating */
        .html-modal-controls {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000000;
            display: flex;
            gap: 10px;
            opacity: 0.9;
            transition: opacity 0.3s, transform 0.3s;
        }

        .html-modal-controls:hover {
            opacity: 1;
            transform: translateY(-2px);
        }

        .html-modal-btn {
            padding: 12px 20px;
            border: none;
            background: rgba(0, 0, 0, 0.85);
            color: white;
            border-radius: 10px;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            backdrop-filter: blur(10px);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
        }

        .html-modal-btn:hover {
            background: rgba(0, 0, 0, 0.95);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(0, 0, 0, 0.4);
        }

        .html-modal-btn:active {
            transform: translateY(0);
        }

        .html-modal-btn i {
            font-size: 16px;
        }

        .html-modal-btn.copy {
            background: linear-gradient(135deg, #6366f1, #4f46e5);
        }

        .html-modal-btn.copy:hover {
            background: linear-gradient(135deg, #4f46e5, #4338ca);
        }

        .html-modal-btn.download {
            background: linear-gradient(135deg, var(--accent-color), #0d8c6f);
        }

        .html-modal-btn.download:hover {
            background: linear-gradient(135deg, #0d8c6f, #0a7357);
        }

        .html-modal-btn.close {
            background: linear-gradient(135deg, #dc3545, #c82333);
        }

        .html-modal-btn.close:hover {
            background: linear-gradient(135deg, #c82333, #bd2130);
        }

        .html-modal-iframe {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            border: none;
            background: white;
            display: block;
            z-index: 1;
        }

        /* Loading overlay */
        .html-modal-loading {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255, 255, 255, 0.95);
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 15px;
            z-index: 2;
            opacity: 1;
            transition: opacity 0.3s;
        }

        .html-modal-loading.hidden {
            opacity: 0;
            pointer-events: none;
        }

        .spinner {
            width: 50px;
            height: 50px;
            border: 4px solid #f3f3f3;
            border-top: 4px solid var(--accent-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading-text {
            color: var(--accent-color);
            font-weight: 500;
        }

        /* Error state */
        .html-modal-error {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.2);
            text-align: center;
            z-index: 3;
            display: none;
        }

        .html-modal-error.show {
            display: block;
        }

        .html-modal-error i {
            font-size: 48px;
            color: #dc3545;
            margin-bottom: 15px;
        }

        .html-modal-error h4 {
            color: #333;
            margin-bottom: 10px;
        }

        .html-modal-error p {
            color: #666;
            margin-bottom: 20px;
        }

        /* Responsive بۆ مۆبایل */
        @media (max-width: 768px) {
            .html-modal-controls {
                top: 10px;
                right: 10px;
                flex-direction: row;
                gap: 6px;
            }

            .html-modal-btn {
                padding: 10px 14px;
                font-size: 13px;
                min-width: 44px;
                min-height: 44px;
                justify-content: center;
            }

            .html-modal-btn span {
                display: none;
            }

            .html-modal-btn i {
                font-size: 20px;
            }
        }

        /* Guide Modal Styles */
        .guide-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.8);
            z-index: 10000;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .guide-modal.show {
            display: flex;
        }

        .guide-content {
            background: var(--sidebar-bg);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 30px;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
            position: relative;
        }

        .guide-close {
            position: absolute;
            top: 15px;
            left: 15px;
            background: transparent;
            border: none;
            color: var(--text-primary);
            font-size: 24px;
            cursor: pointer;
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 8px;
            transition: all 0.2s;
        }

        .guide-close:hover {
            background: var(--hover-bg);
        }

        .guide-content h3 {
            color: var(--accent-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--border-color);
        }

        .guide-content p {
            color: var(--text-primary);
            line-height: 1.8;
            margin-bottom: 15px;
        }

        .guide-content strong {
            color: var(--accent-color);
            font-weight: 600;
        }

        .guide-example {
            background: var(--hover-bg);
            border-radius: 8px;
            padding: 15px;
            margin: 15px 0;
            border-right: 3px solid var(--accent-color);
        }

        .guide-example h4 {
            color: var(--accent-color);
            font-size: 14px;
            margin-bottom: 8px;
        }

        .guide-example p {
            margin: 5px 0;
            font-size: 13px;
            color: var(--text-secondary);
        }
    </style>
</head>
<body class="chatbot-module-page">
<div class="chat-container">
    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <button class="new-chat-btn" onclick="createNewChat()">
                <i class="bi bi-plus-lg"></i>
                چاتی نوێ
            </button>
        </div>

        <div class="chat-list" id="chatList">
            <!-- Chat history will be loaded here -->
        </div>

        <div class="sidebar-footer">
            <div class="balance-info">
                <div class="text-secondary" style="font-size: 12px;">باڵانسی ماوە</div>
                <div class="balance-amount">$<span id="balanceAmount"><?php echo number_format($balance, 2); ?></span></div>
            </div>
            <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn btn-sm btn-outline-light w-100">
                <i class="bi bi-arrow-right"></i> گەڕانەوە بۆ داشبۆرد
            </a>
        </div>
    </div>

    <!-- Main Chat Area -->
    <div class="chat-main">
        <div class="chat-header">
            <h5 class="mb-0">
                <i class="bi bi-robot"></i>
                NexoraCore
            </h5>
            <button class="btn btn-sm btn-outline-light d-md-none" onclick="toggleSidebar()">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="messages-container" id="messagesContainer">
            <div class="empty-state">
                <i class="bi bi-chat-dots"></i>
                <h4>بەخێربێیت بۆ NexoraCore!</h4>
                <p>چاتێکی نوێ دەست پێ بکە یان چاتێکی کۆن هەڵبژێرە</p>
                <button class="btn btn-link" onclick="showGuideModal()" style="color: var(--text-secondary); text-decoration: none; margin-top: 10px;">
                    <i class="bi bi-info-circle" style="font-size: 14px;"></i>
                    خوێندنەوەی ڕێنمایەکان
                </button>
            </div>
        </div>

        <div class="input-area" id="inputArea" style="display: none;">
            <div class="input-wrapper">
                <textarea 
                    class="message-input" 
                    id="messageInput" 
                    placeholder="پرسیارەکەت لێرە بنووسە..."
                    rows="1"
                ></textarea>
                <button class="send-btn" id="sendBtn" onclick="sendMessage()">
                    <i class="bi bi-send-fill"></i>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Guide Modal -->
<div class="guide-modal" id="guideModal">
    <div class="guide-content">
        <button class="guide-close" onclick="closeGuideModal()">
            <i class="bi bi-x-lg"></i>
        </button>

        <h3><i class="bi bi-info-circle"></i> ڕێنمایی بەکارهێنانی NexoraCore</h3>

        <p>لەگەڵ پرسیارەکاندا، داتای سیستەمی کاشێری پەیوەست بەبابەتەکەتان دەنێردرێت بۆ AI کە تاوەکو وەڵامی پرسیارەکەتان بەباشترین شێوە بداتەوە.</p>

        <p>پێداچوونەوە دەکرێت بەچاتەکاندا تاوەکو بەردەوام باشترین ئەنجام پێشکەش بکات و لەڤێرشنەکانی داهاتوودا کەم و کورتیەکان نەهێڵدرێت.</p>

        <p>هەر گفتوگۆیەک لەگەڵ سیستەمی NexoraCore تێچوویەکی هەیە و تێچوەکەی بەپێی زۆری و کەمی Token دیاری دەکرێت.</p>

        <p>تاوەکو باشتر پرسیارەکەت بکەیت و وردەکاری زیاتر بنوسیت بێگومان NexoraCore باشتر وەڵامت دەداتەوە</p>
        
        <div class="guide-example">
            <h4><i class="bi bi-question-circle"></i> Token چیە؟</h4>
            <p>Token بریکەیەکی بچووکی تێکستە کە AI بەکاری دەهێنێت بۆ لێکدانەوە و دروستکردنی تێکست. بە کورتی، بەشێکی وشەیە.</p>

            <p style="margin-top: 10px;"><strong>نمونەی سادە:</strong></p>
            <p>• وشەی "NexoraCore" = نزیکەی <strong>1-2 token</strong></p>
            <p>• ڕستەی "سڵاو، چۆنی؟" = نزیکەی <strong>3-4 token</strong></p>
        </div>

    </div>
</div>

<!-- HTML Preview Modal -->
<div class="html-preview-modal" id="htmlPreviewModal">
    <!-- Floating Controls -->
    <div class="html-modal-controls">
        <button class="html-modal-btn download" onclick="downloadHtmlFile()" title="دابەزاندنی فایلی HTML" aria-label="دابەزاندنی فایل">
            <i class="bi bi-download"></i>
            <span>دابەزاندن</span>
        </button>
        <button class="html-modal-btn close" onclick="closeHtmlModal()" title="داخستن (ESC)" aria-label="داخستنی پێشبینی">
            <i class="bi bi-x-lg"></i>
            <span>داخستن</span>
        </button>
    </div>

    <!-- Loading State -->
    <div class="html-modal-loading" id="htmlModalLoading">
        <div class="spinner"></div>
        <div class="loading-text">بارکردنی پێشبینی...</div>
    </div>

    <!-- Error State -->
    <div class="html-modal-error" id="htmlModalError">
        <i class="bi bi-exclamation-triangle"></i>
        <h4>هەڵەیەک ڕوویدا</h4>
        <p>ناتوانرێت HTML بارکرێت</p>
        <button class="btn btn-danger" onclick="closeHtmlModal()">داخستن</button>
    </div>

    <!-- Full Screen iframe -->
    <iframe class="html-modal-iframe" id="htmlModalIframe" aria-label="پێشبینی HTML"></iframe>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentSessionId = null;
let currentSection = null;
const csrfToken = '<?php echo $csrf_token; ?>';

// Configure marked.js
marked.setOptions({
    breaks: true,
    gfm: true,
    highlight: function(code, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return hljs.highlight(code, { language: lang }).value;
            } catch (err) {}
        }
        return hljs.highlightAuto(code).value;
    }
});

// گلۆباڵ بۆ هەڵگرتنی HTML content
let currentHtmlContent = '';
let currentHtmlFileName = 'preview.html';

// Load chat history on page load
document.addEventListener('DOMContentLoaded', function() {
    loadChatHistory();
    
    // Auto-resize textarea
    const textarea = document.getElementById('messageInput');
    textarea.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(this.scrollHeight, 200) + 'px';
    });

    // Send on Enter, new line on Shift+Enter
    textarea.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
});

// Create new chat
function createNewChat() {
    currentSessionId = null;
    currentSection = null;
    
    const messagesContainer = document.getElementById('messagesContainer');
    messagesContainer.innerHTML = `
        <div class="section-selection">
            <h4 class="mb-4">پرسیارەکەت دەربارەی چ بەشێکە؟</h4>
            <div class="section-buttons">
                <div class="section-btn" onclick="selectSection('products')">
                    <i class="bi bi-box-seam"></i>
                    <div>کاڵاکان</div>
                </div>
                <div class="section-btn" onclick="selectSection('sales')">
                    <i class="bi bi-cash-coin"></i>
                    <div>فرۆشتن</div>
                </div>
                <div class="section-btn" onclick="selectSection('debts')">
                    <i class="bi bi-credit-card"></i>
                    <div>قەرز</div>
                </div>
            </div>
        </div>
    `;
    
    document.getElementById('inputArea').style.display = 'none';
    
    // Remove active class from all chat items
    document.querySelectorAll('.chat-item').forEach(item => {
        item.classList.remove('active');
    });
}

// Select section
function selectSection(section) {
    currentSection = section;
    
    const messagesContainer = document.getElementById('messagesContainer');
    messagesContainer.innerHTML = `
        <div class="empty-state">
            <i class="bi bi-chat-dots"></i>
            <h4>ئێستا پرسیارەکەت بنێرە</h4>
            <p>دەتوانیت پرسیار دەربارەی ${getSectionName(section)} بکەیت</p>
        </div>
    `;
    
    document.getElementById('inputArea').style.display = 'block';
    document.getElementById('messageInput').focus();
}

// Get section name in Kurdish
function getSectionName(section) {
    const names = {
        'products': 'کاڵاکان',
        'sales': 'فرۆشتن',
        'debts': 'قەرز'
    };
    return names[section] || section;
}

// Send message with streaming
async function sendMessage() {
    const input = document.getElementById('messageInput');
    const message = input.value.trim();
    
    if (!message || !currentSection) return;
    
    // Check balance
    const balance = parseFloat(document.getElementById('balanceAmount').textContent);
    if (balance <= 0) {
        alert('باڵانست تەواوبووە! تکایە پەیوەندی بە بەڕێوەبەرەوە بکە بۆ زیادکردنی باڵانس.');
        return;
    }
    
    // Disable send button
    const sendBtn = document.getElementById('sendBtn');
    sendBtn.disabled = true;
    input.disabled = true;
    
    // Add user message to UI
    addMessageToUI('user', message);
    input.value = '';
    input.style.height = 'auto';
    
    // دروستکردنی پەیامی بەتاڵ بۆ وەڵامی AI
    const assistantMessageDiv = createStreamingMessage();
    let fullResponse = '';
    let usageData = {};
    
    try {
        const response = await fetch('api/send_message.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                session_id: currentSessionId,
                section: currentSection,
                message: message,
                csrf_token: csrfToken
            })
        });
        
        if (!response.ok) {
            throw new Error(`هەڵەیەک ڕوویدا: ${response.status}`);
        }
        
        // وەرگرتنی streaming response
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        
        while (true) {
            const {done, value} = await reader.read();
            
            if (done) break;
            
            buffer += decoder.decode(value, {stream: true});
            const lines = buffer.split('\n\n');
            buffer = lines.pop() || '';
            
            for (const line of lines) {
                if (!line.trim()) continue;
                
                // پارسکردنی SSE
                const eventMatch = line.match(/^event: (.+)$/m);
                const dataMatch = line.match(/^data: (.+)$/m);
                
                if (eventMatch && dataMatch) {
                    const event = eventMatch[1];
                    const data = JSON.parse(dataMatch[1]);
                    
                    if (event === 'session') {
                        // نوێکردنەوەی session ID
                        if (!currentSessionId) {
                            currentSessionId = data.session_id;
                            loadChatHistory();
                        }
                        
                    } else if (event === 'content') {
                        // زیادکردنی تێکستی نوێ
                        fullResponse += data.text;
                        updateStreamingMessage(assistantMessageDiv, fullResponse);
                        
                    } else if (event === 'done') {
                        // تەواوبوون
                        usageData = {
                            input_tokens: data.input_tokens || 0,
                            output_tokens: data.output_tokens || 0,
                            cost: data.cost || 0
                        };
                        
                        // لابردنی کێرسەری streaming
                        finishStreamingMessage(assistantMessageDiv);
                        
                        // نوێکردنەوەی باڵانس
                        if (data.new_balance !== undefined) {
                            document.getElementById('balanceAmount').textContent = parseFloat(data.new_balance).toFixed(2);
                        }
                        
                        // زیادکردنی usage info
                        addUsageInfo(usageData);
                        
                    } else if (event === 'error') {
                        throw new Error(data.message || 'هەڵەیەک ڕوویدا');
                    }
                }
            }
        }
        
    } catch (error) {
        alert('هەڵەیەک ڕوویدا لە ناردنی پەیام: ' + error.message);
        
        // سڕینەوەی پەیامی بەتاڵ
        if (assistantMessageDiv && assistantMessageDiv.parentNode) {
            assistantMessageDiv.parentNode.removeChild(assistantMessageDiv);
        }
    } finally {
        sendBtn.disabled = false;
        input.disabled = false;
        input.focus();
    }
}

// دروستکردنی پەیامێکی بەتاڵ بۆ streaming
function createStreamingMessage() {
    const messagesContainer = document.getElementById('messagesContainer');
    
    // سڕینەوەی empty state ئەگەر هەبێت
    const emptyState = messagesContainer.querySelector('.empty-state, .section-selection');
    if (emptyState) {
        messagesContainer.innerHTML = '';
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = 'message assistant';
    messageDiv.innerHTML = `
        <div class="message-avatar">
            <i class="bi bi-robot"></i>
        </div>
        <div class="message-content streaming"></div>
    `;
    
    messagesContainer.appendChild(messageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
    
    return messageDiv;
}

// نوێکردنەوەی پەیامی streaming
function updateStreamingMessage(messageDiv, content) {
    const contentDiv = messageDiv.querySelector('.message-content');
    contentDiv.innerHTML = renderMessageContent(content);
    
    // پاراستنی کلاسی streaming بۆ نیشاندانی کێرسەر
    if (!contentDiv.classList.contains('streaming')) {
        contentDiv.classList.add('streaming');
    }
    
    const messagesContainer = document.getElementById('messagesContainer');
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// لابردنی کێرسەری streaming
function finishStreamingMessage(messageDiv) {
    const contentDiv = messageDiv.querySelector('.message-content');
    if (contentDiv) {
        contentDiv.classList.remove('streaming');
    }
}

// زیادکردنی زانیاری usage
function addUsageInfo(usageData) {
    const messagesContainer = document.getElementById('messagesContainer');
    
    const usageDiv = document.createElement('div');
    usageDiv.className = 'message-usage';
    usageDiv.innerHTML = `
        <div class="usage-item">
            <i class="bi bi-arrow-down-circle"></i>
            <span> Input: ${usageData.input_tokens.toLocaleString()}</span>
        </div>
        <div class="usage-item">
            <i class="bi bi-arrow-up-circle"></i>
            <span> Output: ${usageData.output_tokens.toLocaleString()}</span>
        </div>
        <div class="usage-cost">
            <i class="bi bi-cash"></i>
            تێچوو: $${usageData.cost.toFixed(4)}
        </div>
    `;
    messagesContainer.appendChild(usageDiv);
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Add message to UI
function addMessageToUI(role, content, usageData = null) {
    const messagesContainer = document.getElementById('messagesContainer');
    
    // Remove empty state if exists
    const emptyState = messagesContainer.querySelector('.empty-state, .section-selection');
    if (emptyState) {
        messagesContainer.innerHTML = '';
    }
    
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${role}`;
    messageDiv.innerHTML = `
        <div class="message-avatar">
            <i class="bi bi-${role === 'user' ? 'person-fill' : 'robot'}"></i>
        </div>
        <div class="message-content">${renderMessageContent(content, role)}</div>
    `;
    
    messagesContainer.appendChild(messageDiv);
    
    // Add usage info if this is an assistant message with usage data
    if (role === 'assistant' && usageData) {
        const usageDiv = document.createElement('div');
        usageDiv.className = 'message-usage';
        usageDiv.innerHTML = `
            <div class="usage-item">
                <i class="bi bi-arrow-down-circle"></i>
                <span> Input: ${usageData.input_tokens.toLocaleString()}</span>
            </div>
            <div class="usage-item">
                <i class="bi bi-arrow-up-circle"></i>
                <span> Output: ${usageData.output_tokens.toLocaleString()}</span>
            </div>
            <div class="usage-cost">
                <i class="bi bi-cash"></i>
                تێچوو: $${usageData.cost.toFixed(4)}
            </div>
        `;
        messagesContainer.appendChild(usageDiv);
    }
    
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Load chat history
async function loadChatHistory() {
    try {
        const response = await fetch('api/get_sessions.php');
        
        if (!response.ok) {
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            const chatList = document.getElementById('chatList');
            chatList.innerHTML = '';
            
            if (data.sessions.length === 0) {
                chatList.innerHTML = '<div class="text-center text-secondary p-3"><small>هیچ چاتێک نییە</small></div>';
                return;
            }
            
            data.sessions.forEach(session => {
                const chatItem = document.createElement('div');
                chatItem.className = 'chat-item';
                if (currentSessionId == session.id) {
                    chatItem.classList.add('active');
                }
                chatItem.innerHTML = `
                    <div class="chat-item-title">${session.title || getSectionName(session.section)}</div>
                    <div class="chat-item-meta">${session.created_at}</div>
                `;
                chatItem.onclick = () => loadChat(session.id);
                chatList.appendChild(chatItem);
            });
        }
    } catch (error) {
    }
}

// Load specific chat
async function loadChat(sessionId) {
    try {
        const response = await fetch(`api/get_messages.php?session_id=${sessionId}`);
        
        if (!response.ok) {
            alert('هەڵەیەک ڕوویدا لە بارکردنی چات');
            return;
        }
        
        const data = await response.json();
        
        if (data.success) {
            currentSessionId = sessionId;
            currentSection = data.section;
            
            const messagesContainer = document.getElementById('messagesContainer');
            messagesContainer.innerHTML = '';
            
            data.messages.forEach(msg => {
                addMessageToUI(msg.role, msg.content);
            });
            
            document.getElementById('inputArea').style.display = 'block';
            
            // Update active state
            document.querySelectorAll('.chat-item').forEach(item => {
                item.classList.remove('active');
            });
            event.target.closest('.chat-item')?.classList.add('active');
        }
    } catch (error) {
    }
}

// Toggle sidebar on mobile
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('active');
}

// Escape HTML
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML.replace(/\n/g, '<br>');
}

// Escape HTML for safe storage
function escapeHtmlForAttribute(html) {
    return html
        .replace(/\\/g, '\\\\')
        .replace(/`/g, '\\`')
        .replace(/\$/g, '\\$')
        .replace(/\n/g, '\\n')
        .replace(/\r/g, '\\r');
}

// Store HTML blocks globally with unique IDs
const htmlBlocksStore = new Map();

// Render message content with markdown and HTML preview
function renderMessageContent(content, role = 'assistant') {
    // بۆ پەیامی بەکارهێنەر، تەنها markdown ساکار بەکاربێنە
    if (role === 'user') {
        return marked.parse(content);
    }

    // بۆ وەڵامی AI، HTML بدۆزەوە و لە جیا نیشانی بدە
    const htmlPattern = /```html\n([\s\S]*?)```/gi;
    let match;
    let lastIndex = 0;
    let result = '';
    let htmlCounter = 0;

    // دۆزینەوەی هەموو بلۆکەکانی HTML
    while ((match = htmlPattern.exec(content)) !== null) {
        // زیادکردنی تێکستی پێش HTML بە markdown
        if (match.index > lastIndex) {
            result += marked.parse(content.substring(lastIndex, match.index));
        }

        // زیادکردنی HTML preview box
        const htmlContent = match[1].trim();
        const htmlId = 'html-' + Date.now() + '-' + htmlCounter++;
        const fileName = 'preview-' + htmlCounter + '.html';

        // هەڵگرتنی HTML لە Map بە شێوەیەکی سەلامەت
        htmlBlocksStore.set(htmlId, {
            content: htmlContent,
            fileName: fileName
        });

        result += `
            <div class="html-preview-container" data-html-id="${htmlId}">
                <div class="html-preview-header" onclick="openHtmlModalById('${htmlId}')">
                    <div class="html-preview-title">
                        <i class="bi bi-code-slash"></i>
                        <span>وەڵام بە دیزاینەوە</span>
                    </div>
                    <div class="html-preview-toggle">
                        <span><i class="bi bi-eye"></i> کلیک بکە بۆ بینینی پێشبینی</span>
                    </div>
                </div>
            </div>
        `;

        lastIndex = htmlPattern.lastIndex;
    }

    // زیادکردنی باقیماوەی تێکست
    if (lastIndex < content.length) {
        result += marked.parse(content.substring(lastIndex));
    }

    // ئەگەر HTML نەدۆزرایەوە، تەواوی ناوەڕۆک بە markdown ڕەندەر بکە
    if (lastIndex === 0) {
        result = marked.parse(content);
    }

    // زیادکردنی دوگمەی کۆپی بۆ بلۆکەکانی کۆد
    result = addCopyButtonsToCodeBlocks(result);

    return result;
}

// Track blob URLs for cleanup
let currentBlobUrl = null;

// Open HTML modal by ID (safer method)
function openHtmlModalById(htmlId) {
    const htmlBlock = htmlBlocksStore.get(htmlId);
    if (!htmlBlock) {
        return;
    }
    openHtmlModal(htmlBlock.content, htmlBlock.fileName);
}

// Open HTML in fullscreen modal
function openHtmlModal(htmlContent, fileName = 'preview.html') {
    currentHtmlContent = htmlContent;
    currentHtmlFileName = fileName;

    const modal = document.getElementById('htmlPreviewModal');
    const iframe = document.getElementById('htmlModalIframe');
    const loading = document.getElementById('htmlModalLoading');
    const error = document.getElementById('htmlModalError');

    // پاککردنەوەی blob URL کۆن ئەگەر هەبێت
    if (currentBlobUrl) {
        URL.revokeObjectURL(currentBlobUrl);
        currentBlobUrl = null;
    }

    // پیشاندانی modal و loading
    modal.classList.add('show');
    loading.classList.remove('hidden');
    error.classList.remove('show');
    document.body.style.overflow = 'hidden';

    try {
        // دروستکردنی blob بۆ HTML
        const blob = new Blob([htmlContent], { type: 'text/html' });
        currentBlobUrl = URL.createObjectURL(blob);

        // بارکردنی لە iframe
        iframe.onload = function() {
            // شاردنەوەی loading کاتێک بارکرا
            setTimeout(() => {
                loading.classList.add('hidden');
            }, 300);
        };

        iframe.onerror = function() {
            // نیشاندانی error ئەگەر هەڵە هەبێت
            loading.classList.add('hidden');
            error.classList.add('show');
        };

        iframe.src = currentBlobUrl;

    } catch (err) {
        loading.classList.add('hidden');
        error.classList.add('show');
    }
}

// Close HTML modal
function closeHtmlModal() {
    const modal = document.getElementById('htmlPreviewModal');
    const iframe = document.getElementById('htmlModalIframe');
    const loading = document.getElementById('htmlModalLoading');
    const error = document.getElementById('htmlModalError');

    modal.classList.remove('show');
    document.body.style.overflow = '';

    // پاککردنەوەی blob URL
    if (currentBlobUrl) {
        URL.revokeObjectURL(currentBlobUrl);
        currentBlobUrl = null;
    }

    // Reset states
    loading.classList.remove('hidden');
    error.classList.remove('show');

    // پاککردنەوەی iframe دوای delay
    setTimeout(() => {
        iframe.src = '';
        iframe.onload = null;
        iframe.onerror = null;
    }, 300);
}

// Copy HTML code to clipboard
function copyHtmlCode() {
    if (!currentHtmlContent) {
        alert('هیچ ناوەڕۆکێکی HTML نییە بۆ کۆپیکردن');
        return;
    }

    navigator.clipboard.writeText(currentHtmlContent).then(() => {
        // گۆڕینی دوگمە بۆ نیشاندانی success
        const copyBtn = document.querySelector('.html-modal-btn.copy');
        const originalHTML = copyBtn.innerHTML;

        copyBtn.innerHTML = '<i class="bi bi-check-lg"></i><span>کۆپیکرا!</span>';
        copyBtn.style.background = 'linear-gradient(135deg, #28a745, #218838)';

        setTimeout(() => {
            copyBtn.innerHTML = originalHTML;
            copyBtn.style.background = '';
        }, 2000);
    }).catch(err => {
        alert('نەتوانرا کۆدەکە کۆپی بکرێت');
    });
}

// Download HTML file
function downloadHtmlFile() {
    if (!currentHtmlContent) {
        alert('هیچ ناوەڕۆکێکی HTML نییە بۆ دابەزاندن');
        return;
    }
 
    // دروستکردنی blob
    const blob = new Blob([currentHtmlContent], { type: 'text/html' });
    const url = URL.createObjectURL(blob);

    // دروستکردنی لینکی download
    const a = document.createElement('a');
    a.href = url;
    a.download = currentHtmlFileName;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);

    // پاککردنەوەی URL
    setTimeout(() => URL.revokeObjectURL(url), 100);
}

// داخستنی modal بە ESC کلیل
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const modal = document.getElementById('htmlPreviewModal');
        if (modal.classList.contains('show')) {
            closeHtmlModal();
        }
    }
});

// زیادکردنی دوگمەی کۆپی بۆ بلۆکەکانی کۆد
function addCopyButtonsToCodeBlocks(html) {
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = html;
    
    const codeBlocks = tempDiv.querySelectorAll('pre code');
    codeBlocks.forEach((codeBlock, index) => {
        const pre = codeBlock.parentElement;
        const wrapper = document.createElement('div');
        wrapper.className = 'code-block-wrapper';
        
        const copyBtn = document.createElement('button');
        copyBtn.className = 'copy-code-btn';
        copyBtn.textContent = 'کۆپی';
        copyBtn.onclick = function() {
            const code = codeBlock.textContent;
            navigator.clipboard.writeText(code).then(() => {
                copyBtn.textContent = '✓ کۆپیکرا';
                copyBtn.classList.add('copied');
                setTimeout(() => {
                    copyBtn.textContent = 'کۆپی';
                    copyBtn.classList.remove('copied');
                }, 2000);
            });
        };
        
        pre.parentNode.insertBefore(wrapper, pre);
        wrapper.appendChild(copyBtn);
        wrapper.appendChild(pre);
    });
    
    return tempDiv.innerHTML;
}

// Show guide modal
function showGuideModal() {
    const modal = document.getElementById('guideModal');
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
}

// Close guide modal
function closeGuideModal() {
    const modal = document.getElementById('guideModal');
    modal.classList.remove('show');
    document.body.style.overflow = '';
}

// داخستنی guide modal بە ESC یان کلیککردن لە دەرەوە
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const guideModal = document.getElementById('guideModal');
        if (guideModal.classList.contains('show')) {
            closeGuideModal();
        }
    }
});

document.getElementById('guideModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeGuideModal();
    }
});
</script>

</body>
</html>
