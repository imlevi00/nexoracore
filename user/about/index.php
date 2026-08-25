<?php
/**
 * دەربارەی نێکزۆراکۆر - user/about/index.php
 */

require_once '../../config/config.php';
require_once '../../config/security.php';
require_once '../../includes/permissions.php';

// تاقیکردنی دەسەڵاتی بەکارهێنەر
SessionManager::requireAuth('user');
$currentUser = getCurrentUser();
enforceAuthorizationOrDeny($currentUser, 'dashboard.view', [
    'route' => '/user/about/index.php',
    'request_method' => $_SERVER['REQUEST_METHOD'] ?? 'GET'
], 'redirect');
?>

<!DOCTYPE html>
<html lang="ku" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php echo function_exists('kasher_get_theme_bootstrap_markup') ? kasher_get_theme_bootstrap_markup() : ''; ?>
    <title>دەربارەی NexoraCore - سیستەمی پێشکەوتووی بازرگانی و کاشێر</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <link href="<?php echo asset('css/website-about-responsive.css'); ?>" rel="stylesheet">
    
    <style>
        :root {
            --nx-primary: #5B73E8;
            --nx-primary-dark: #4355b9;
            --nx-secondary: #8B5CF6;
            --nx-accent: #06B6D4;
            --nx-dark: #0f172a;
            --nx-surface: #ffffff;
            --nx-surface-alt: #f8fafc;
            --nx-border: rgba(148, 163, 184, 0.2);
            --nx-text: #1e293b;
            --nx-text-muted: #64748b;
            --nx-gradient-hero: linear-gradient(135deg, #1e1b4b 0%, #312e81 35%, #4338ca 70%, #6366f1 100%);
            --nx-gradient-accent: linear-gradient(135deg, #6366f1 0%, #8b5cf6 50%, #d946ef 100%);
            --nx-shadow-sm: 0 4px 6px -1px rgba(0,0,0,0.05), 0 2px 4px -2px rgba(0,0,0,0.05);
            --nx-shadow-md: 0 10px 25px -5px rgba(0,0,0,0.08), 0 8px 10px -6px rgba(0,0,0,0.04);
            --nx-shadow-lg: 0 20px 35px -10px rgba(99, 102, 241, 0.15);
        }

        body.about-module-page {
            background-color: #f8fafc;
            color: var(--nx-text);
            font-family: 'Segoe UI', system-ui, -apple-system, Tahoma, sans-serif;
            overflow-x: hidden;
        }

        /* Hero Header */
        .nexora-hero {
            background: var(--nx-gradient-hero);
            color: #ffffff;
            padding: 85px 0 100px;
            position: relative;
            overflow: hidden;
        }

        .nexora-hero::before {
            content: '';
            position: absolute;
            top: -20%;
            right: -10%;
            width: 500px;
            height: 500px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.4) 0%, rgba(0,0,0,0) 70%);
            filter: blur(40px);
            z-index: 1;
            pointer-events: none;
        }

        .nexora-hero::after {
            content: '';
            position: absolute;
            bottom: -30%;
            left: -10%;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(6, 182, 212, 0.25) 0%, rgba(0,0,0,0) 70%);
            filter: blur(50px);
            z-index: 1;
            pointer-events: none;
        }

        .nexora-hero-pattern {
            position: absolute;
            inset: 0;
            background-image: radial-gradient(rgba(255, 255, 255, 0.12) 1px, transparent 1px);
            background-size: 28px 28px;
            opacity: 0.6;
            z-index: 1;
        }

        .hero-inner {
            position: relative;
            z-index: 2;
        }

        .brand-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255, 255, 255, 0.12);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.25);
            padding: 8px 18px;
            border-radius: 50px;
            font-size: 0.95rem;
            font-weight: 600;
            color: #e0e7ff;
            margin-bottom: 24px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.1);
        }

        .brand-badge .pulse-dot {
            width: 9px;
            height: 9px;
            background-color: #10b981;
            border-radius: 50%;
            box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7);
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.7); }
            70% { transform: scale(1); box-shadow: 0 0 0 10px rgba(16, 185, 129, 0); }
            100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); }
        }

        .brand-title {
            font-size: 3.4rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            line-height: 1.15;
            margin-bottom: 20px;
            background: linear-gradient(135deg, #ffffff 40%, #c7d2fe 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .brand-tagline {
            font-size: 1.3rem;
            color: #c7d2fe;
            line-height: 1.6;
            max-width: 780px;
            margin: 0 auto 36px;
            font-weight: 400;
        }

        /* Stat Badges in Hero */
        .hero-stats-wrap {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            max-width: 960px;
            margin: 0 auto;
        }

        .hero-stat-pill {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(14px);
            border: 1px solid rgba(255, 255, 255, 0.16);
            border-radius: 18px;
            padding: 20px 16px;
            text-align: center;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .hero-stat-pill:hover {
            background: rgba(255, 255, 255, 0.16);
            transform: translateY(-4px);
            border-color: rgba(255, 255, 255, 0.35);
        }

        .hero-stat-number {
            font-size: 1.85rem;
            font-weight: 800;
            color: #ffffff;
            display: block;
            margin-bottom: 4px;
            line-height: 1;
        }

        .hero-stat-label {
            font-size: 0.88rem;
            color: #cbd5e1;
            font-weight: 500;
        }

        /* Section Styling */
        .section-wrap {
            padding: 65px 0;
            position: relative;
        }

        .section-header {
            text-align: center;
            max-width: 720px;
            margin: 0 auto 45px;
        }

        .section-tag {
            display: inline-block;
            background: rgba(99, 102, 241, 0.1);
            color: #4f46e5;
            font-weight: 700;
            font-size: 0.85rem;
            padding: 6px 14px;
            border-radius: 30px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 12px;
        }

        .section-title {
            font-size: 2.15rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 14px;
            letter-spacing: -0.5px;
        }

        .section-desc {
            font-size: 1.05rem;
            color: var(--nx-text-muted);
            line-height: 1.6;
        }

        /* Nexora Feature Cards */
        .nx-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 32px 28px;
            height: 100%;
            display: flex;
            flex-direction: column;
            box-shadow: var(--nx-shadow-sm);
            transition: all 0.35s cubic-bezier(0.4, 0, 0.2, 1);
            position: relative;
            overflow: hidden;
        }

        .nx-card::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            left: 0;
            height: 4px;
            background: var(--nx-gradient-accent);
            opacity: 0;
            transition: opacity 0.35s ease;
        }

        .nx-card:hover {
            transform: translateY(-8px);
            box-shadow: var(--nx-shadow-lg);
            border-color: #cbd5e1;
        }

        .nx-card:hover::before {
            opacity: 1;
        }

        .card-icon-box {
            width: 60px;
            height: 60px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.7rem;
            margin-bottom: 22px;
            transition: transform 0.3s ease;
        }

        .nx-card:hover .card-icon-box {
            transform: scale(1.08) rotate(-4deg);
        }

        .icon-blue { background: rgba(59, 130, 246, 0.12); color: #2563eb; }
        .icon-purple { background: rgba(139, 92, 246, 0.12); color: #7c3aed; }
        .icon-cyan { background: rgba(6, 182, 212, 0.12); color: #0891b2; }
        .icon-emerald { background: rgba(16, 185, 129, 0.12); color: #059669; }
        .icon-amber { background: rgba(245, 158, 11, 0.12); color: #d97706; }
        .icon-rose { background: rgba(244, 63, 94, 0.12); color: #e11d48; }

        .nx-card-title {
            font-size: 1.3rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 12px;
        }

        .nx-card-text {
            font-size: 0.96rem;
            color: var(--nx-text-muted);
            line-height: 1.6;
            margin-bottom: 20px;
            flex-grow: 1;
        }

        .nx-card-list {
            list-style: none;
            padding: 0;
            margin: 0;
            border-top: 1px solid #f1f5f9;
            padding-top: 16px;
        }

        .nx-card-list li {
            font-size: 0.9rem;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
            font-weight: 500;
        }

        .nx-card-list li:last-child {
            margin-bottom: 0;
        }

        .nx-card-list li i {
            color: #10b981;
            font-size: 1.05rem;
            flex-shrink: 0;
        }

        /* Identity Banner */
        .identity-banner {
            background: linear-gradient(135deg, #0f172a 0%, #1e1b4b 100%);
            border-radius: 28px;
            padding: 50px 40px;
            color: #ffffff;
            position: relative;
            overflow: hidden;
            box-shadow: 0 20px 40px -15px rgba(15, 23, 42, 0.4);
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .identity-banner::after {
            content: '';
            position: absolute;
            left: -50px;
            bottom: -50px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(99, 102, 241, 0.3) 0%, transparent 70%);
            pointer-events: none;
        }

        .identity-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 18px;
            background: linear-gradient(135deg, #ffffff, #c7d2fe);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .identity-lead {
            font-size: 1.08rem;
            line-height: 1.8;
            color: #cbd5e1;
            margin-bottom: 24px;
        }

        .value-pill {
            background: rgba(255, 255, 255, 0.08);
            border: 1px solid rgba(255, 255, 255, 0.15);
            padding: 14px 18px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: #f8fafc;
            font-weight: 600;
            font-size: 0.95rem;
            backdrop-filter: blur(8px);
            transition: all 0.25s ease;
        }

        .value-pill:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(-4px);
        }

        .value-pill i {
            color: #38bdf8;
            font-size: 1.3rem;
        }

        /* Pillars Section */
        .pillar-card {
            background: #ffffff;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            padding: 24px;
            text-align: center;
            height: 100%;
            box-shadow: var(--nx-shadow-sm);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
        }

        .pillar-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--nx-shadow-md);
        }

        .pillar-icon {
            font-size: 2.2rem;
            margin-bottom: 16px;
            display: inline-block;
        }

        .pillar-title {
            font-size: 1.15rem;
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 10px;
        }

        .pillar-desc {
            font-size: 0.9rem;
            color: var(--nx-text-muted);
            line-height: 1.55;
            margin: 0;
        }

        /* Action & Quick Navigation */
        .action-bar-wrap {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            padding: 30px;
            box-shadow: var(--nx-shadow-sm);
            margin-top: 30px;
        }

        .btn-nx-primary {
            background: var(--nx-gradient-accent);
            color: #ffffff;
            border: none;
            padding: 12px 28px;
            font-weight: 700;
            border-radius: 12px;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(99, 102, 241, 0.35);
            display: inline-flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }

        .btn-nx-primary:hover {
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 8px 22px rgba(99, 102, 241, 0.45);
        }

        .btn-nx-outline {
            background: transparent;
            color: #475569;
            border: 1px solid #cbd5e1;
            padding: 12px 24px;
            font-weight: 600;
            border-radius: 12px;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-nx-outline:hover {
            background: #f8fafc;
            color: #0f172a;
            border-color: #94a3b8;
        }

        /* Floating Dashboard Action */
        .back-to-dashboard {
            position: fixed;
            bottom: 30px;
            left: 30px;
            background: var(--nx-gradient-accent);
            color: white;
            width: 58px;
            height: 58px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4);
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: 1000;
            border: 2px solid rgba(255,255,255,0.4);
            text-decoration: none;
        }

        .back-to-dashboard:hover {
            transform: scale(1.1) rotate(-8deg);
            color: white;
            box-shadow: 0 12px 30px rgba(99, 102, 241, 0.55);
        }

        /* Footer */
        .nexora-footer {
            background: #0b0f19;
            color: #94a3b8;
            padding: 35px 0;
            border-top: 1px solid #1e293b;
            font-size: 0.92rem;
        }

        .nexora-footer a {
            color: #cbd5e1;
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .nexora-footer a:hover {
            color: #ffffff;
        }

        /* Responsive Breakpoints */
        @media (max-width: 991.98px) {
            .brand-title {
                font-size: 2.6rem;
            }
            .hero-stats-wrap {
                grid-template-columns: repeat(2, 1fr);
            }
            .section-title {
                font-size: 1.8rem;
            }
        }

        @media (max-width: 767.98px) {
            .nexora-hero {
                padding: 60px 0 70px;
            }
            .brand-title {
                font-size: 2rem;
            }
            .brand-tagline {
                font-size: 1.05rem;
                margin-bottom: 24px;
            }
            .hero-stats-wrap {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            .hero-stat-pill {
                padding: 14px;
            }
            .identity-banner {
                padding: 30px 20px;
            }
            .identity-title {
                font-size: 1.5rem;
            }
            .section-wrap {
                padding: 45px 0;
            }
            .nx-card {
                padding: 24px 20px;
            }
            .action-bar-wrap {
                padding: 20px;
                text-align: center;
            }
            .action-bar-wrap .d-flex {
                flex-direction: column;
                gap: 12px;
            }
            .btn-nx-primary, .btn-nx-outline {
                width: 100%;
                justify-content: center;
            }
            .back-to-dashboard {
                bottom: 20px;
                left: 20px;
                width: 48px;
                height: 48px;
            }
        }
    </style>
</head>
<body class="about-module-page">
    <?php include_once '../../includes/navigation.php'; ?>

    <!-- Hero Section -->
    <header class="nexora-hero">
        <div class="nexora-hero-pattern"></div>
        <div class="container hero-inner text-center">
            
            <!-- Brand Badge -->
            <div class="brand-badge">
                <span class="pulse-dot"></span>
                <span>سیستەمی پێشکەوتووی نەوەی نوێ</span>
                <i class="bi bi-stars text-warning"></i>
            </div>
            
            <!-- Brand Headline -->
            <h1 class="brand-title">NexoraCore</h1>
            <p class="brand-tagline">
                پلاتفۆرمی ژیر و تەواوکاری بەڕێوەبردنی خاڵی فرۆشتن (POS)، کۆگا، ژمێریاری و شیکاری دارایی؛ 
                دروستکراو بۆ خێرایی بێوێنە، پاراستنی داتا و بەهێزکردنی بڕیارەکانی بازرگانی.
            </p>

            <!-- Key Hero Metrics -->
            <div class="hero-stats-wrap">
                <div class="hero-stat-pill">
                    <span class="hero-stat-number"><i class="bi bi-lightning-charge-fill text-warning me-1"></i> < 0.1s</span>
                    <span class="hero-stat-label">خێرایی تۆمارکردنی وەسڵ</span>
                </div>
                <div class="hero-stat-pill">
                    <span class="hero-stat-number"><i class="bi bi-shield-check text-success me-1"></i> 99.9%</span>
                    <span class="hero-stat-label">بەردەوامی و پارێزراوی داتا</span>
                </div>
                <div class="hero-stat-pill">
                    <span class="hero-stat-number"><i class="bi bi-boxes text-info me-1"></i> فرە-کۆگا</span>
                    <span class="hero-stat-label">بەڕێوەبردنی لق و ئینڤێنتۆری</span>
                </div>
                <div class="hero-stat-pill">
                    <span class="hero-stat-number"><i class="bi bi-graph-up-arrow text-primary me-1"></i> زیرەک</span>
                    <span class="hero-stat-label">ڕاپۆرت و شیکاری دارایی</span>
                </div>
            </div>

        </div>
    </header>

    <!-- Main Content Container -->
    <main class="container py-4">

        <!-- Brand Identity & Philosophy Section -->
        <section class="section-wrap pt-0">
            <div class="identity-banner">
                <div class="row align-items-center g-4">
                    <div class="col-lg-7">
                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill mb-3">
                            <i class="bi bi-gem me-1"></i> فەلسەفەی براندی NexoraCore
                        </span>
                        <h2 class="identity-title">پێکەوەبەستنی هەموو بەشەکانی بازرگانیەکەت لە یەک ناوەنددا</h2>
                        <p class="identity-lead">
                            ناوی <strong>NexoraCore</strong> لە دوو چەمکی سەرەکییەوە سەرچاوەی گرتووە: 
                            <strong class="text-white">Nexus</strong> بە واتای پەیوەستکردن و یەکخستنی تەواوی بەشە بازرگانییەکان، و 
                            <strong class="text-white">Core</strong> وەک ناوەندێکی پۆڵایین و خێرا بۆ هەموو مامەڵە، کۆگا و بڕیارە داراییەکان. 
                            ئامانجمان سادەکردنەوەی ئاڵۆزترین پرۆسە ژمێریارییەکانە تا خاوەن کارەکان بتوانن بە متمانەوە گەشە بکەن.
                        </p>
                        <div class="d-flex flex-wrap gap-2">
                            <div class="value-pill">
                                <i class="bi bi-cpu-fill"></i>
                                <span>کارپێکردنی خێرا و سەردەمیانە</span>
                            </div>
                            <div class="value-pill">
                                <i class="bi bi-fingerprint"></i>
                                <span>ئاسایشی ئاستی بەرز و دەسەڵاتی بەکارهێنەران</span>
                            </div>
                            <div class="value-pill">
                                <i class="bi bi-infinity"></i>
                                <span>گونجاوی لەگەڵ هەموو قەبارەیەکی بازرگانی</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-5 text-center d-none d-lg-block">
                        <div class="p-4" style="background: rgba(255,255,255,0.04); border-radius: 24px; border: 1px solid rgba(255,255,255,0.1);">
                            <i class="bi bi-motherboard text-info" style="font-size: 5rem;"></i>
                            <h4 class="text-white mt-3 fw-bold">Nexora Business Intelligence</h4>
                            <p class="text-light mb-0 small">سیستەمێکی بەهێز بۆ کۆنترۆڵکردنی کاشێر، قەرز، کڕیاران و مەخزەن بەوپەڕی وردی.</p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Core System Capabilities / Features Grid -->
        <section class="section-wrap">
            <div class="section-header">
                <span class="section-tag">تایبەتمەندییە سەرەکییەکان</span>
                <h2 class="section-title">توانست و بەشەکانی سیستەمی NexoraCore</h2>
                <p class="section-desc">
                    هەر بەشێکی سیستەمەکە بە وردی ئەندازەیی دیزاین کراوە بۆ ئەوەی کارەکانی ڕۆژانەت بە خێرایی و بێ هەڵە ئەنجام بدەیت.
                </p>
            </div>

            <div class="row g-4">
                <!-- Feature 1: POS & Invoicing -->
                <div class="col-lg-4 col-md-6">
                    <div class="nx-card">
                        <div class="card-icon-box icon-blue">
                            <i class="bi bi-receipt-cutoff"></i>
                        </div>
                        <h3 class="nx-card-title">سیستەمی کاشێری خێرا (POS)</h3>
                        <p class="nx-card-text">
                            تۆمارکردنی فرۆشتن بە دەست لێدان و بارکۆد بە کەمترین چرکە، پاشەکەوتکردن و گەڕانەوەی وەسڵ و پشتگیری پرینتەری پسووڵەی جۆراوجۆر.
                        </p>
                        <ul class="nx-card-list">
                            <li><i class="bi bi-check2-circle"></i> فرۆشتنی نەقد، قەرز و داشکاندنی زیرەک</li>
                            <li><i class="bi bi-check2-circle"></i> چاپکردنی ڕاستەوخۆ (80mm / 58mm / A4)</li>
                            <li><i class="bi bi-check2-circle"></i> گەڕاندنەوەی کاڵا و کۆنترۆڵی دەستکاری</li>
                        </ul>
                    </div>
                </div>

                <!-- Feature 2: Smart Inventory -->
                <div class="col-lg-4 col-md-6">
                    <div class="nx-card">
                        <div class="card-icon-box icon-purple">
                            <i class="bi bi-box-seam-fill"></i>
                        </div>
                        <h3 class="nx-card-title">کۆگا و ئینڤێنتۆری ورد</h3>
                        <p class="nx-card-text">
                            بەڕێوەبردنی ژمارەی کاڵاکان لە کاتی ڕاستەقینەدا، دیاریکردنی سنوری کەمیی کاڵا، و بەرواری بەسەرچوون بە ئاگادارکەرەوەی ئۆتۆماتیک.
                        </p>
                        <ul class="nx-card-list">
                            <li><i class="bi bi-check2-circle"></i> بەدواداچوونی جموجۆڵی کاڵا و کاڵای بەسەرچوو</li>
                            <li><i class="bi bi-check2-circle"></i> دروستکردن و پرینتکردنی بارکۆدی تایبەت</li>
                            <li><i class="bi bi-check2-circle"></i> گواستنەوەی نێوان کۆگاکان و لقەکان</li>
                        </ul>
                    </div>
                </div>

                <!-- Feature 3: Reports & Analytics -->
                <div class="col-lg-4 col-md-6">
                    <div class="nx-card">
                        <div class="card-icon-box icon-emerald">
                            <i class="bi bi-bar-chart-line-fill"></i>
                        </div>
                        <h3 class="nx-card-title">شیکاری و ڕاپۆرتی دارایی</h3>
                        <p class="nx-card-text">
                            تێگەیشتنی تەواو لە قازانج و زیان، داهاتی پوخت، کاڵا پڕفرۆشەکان و چارتە گرافیکییەکان بۆ بڕیاردانی دروستی بازرگانی.
                        </p>
                        <ul class="nx-card-list">
                            <li><i class="bi bi-check2-circle"></i> ڕاپۆرتی ڕۆژانە، هەفتانە، مانگانە و ساڵانە</li>
                            <li><i class="bi bi-check2-circle"></i> حیسابکردنی قازانجی ڕاستەقینە و خەرجییەکان</li>
                            <li><i class="bi bi-check2-circle"></i> هەناردەکردن بۆ Excel و PDF</li>
                        </ul>
                    </div>
                </div>

                <!-- Feature 4: Customers & Debt CRM -->
                <div class="col-lg-4 col-md-6">
                    <div class="nx-card">
                        <div class="card-icon-box icon-cyan">
                            <i class="bi bi-people-fill"></i>
                        </div>
                        <h3 class="nx-card-title">بەڕێوەبردنی کڕیاران و قەرز</h3>
                        <p class="nx-card-text">
                            پرۆفایلی تایبەت بە هەر کڕیارێک و دابینکەرێک، تۆمارکردنی مێژووی کڕینەکان، ئاستی قەرز و سیستەمی ئاگادارکردنەوەی شایستەکان.
                        </p>
                        <ul class="nx-card-list">
                            <li><i class="bi bi-check2-circle"></i> پسووڵەی قەرز و دانەوەی بەشەکی</li>
                            <li><i class="bi bi-check2-circle"></i> ئاگادارکردنەوە لە ڕێگەی نامە و پەیام</li>
                            <li><i class="bi bi-check2-circle"></i> پۆلێنکردنی کڕیارە VIP و هەمیشەییەکان</li>
                        </ul>
                    </div>
                </div>

                <!-- Feature 5: Security & Multi-Role -->
                <div class="col-lg-4 col-md-6">
                    <div class="nx-card">
                        <div class="card-icon-box icon-rose">
                            <i class="bi bi-shield-lock-fill"></i>
                        </div>
                        <h3 class="nx-card-title">ئاستی پاراستن و بەکارهێنەران</h3>
                        <p class="nx-card-text">
                            دیاریکردنی دەسەڵاتی جیاواز بۆ بەڕێوەبەر، کاشێر و ستاف؛ سڕینەوەی دەستکاری نایاسایی و چاودێری هەموو جموجۆڵەکانی سیستەم.
                        </p>
                        <ul class="nx-card-list">
                            <li><i class="bi bi-check2-circle"></i> ڕێگەپێدانی ورد بەپێی ڕۆڵ (Role-Based Permissions)</li>
                            <li><i class="bi bi-check2-circle"></i> لۆگی چاودێری (Activity Audit Logs)</li>
                            <li><i class="bi bi-check2-circle"></i> پاراستنی کۆدی سێشن و دژە دەستکاری (CSRF & XSS)</li>
                        </ul>
                    </div>
                </div>

                <!-- Feature 6: Backup & Cloud Reliability -->
                <div class="col-lg-4 col-md-6">
                    <div class="nx-card">
                        <div class="card-icon-box icon-amber">
                            <i class="bi bi-cloud-arrow-up-fill"></i>
                        </div>
                        <h3 class="nx-card-title">پاشەکەوت و بەردەوامی (Backup)</h3>
                        <p class="nx-card-text">
                            هەڵگرتنی ئۆتۆماتیکی داتابەیس، توانای گەڕاندنەوەی داتا بە خێرایی لە کاتی لەناکاو، و گونجاندن لەگەڵ هەموو ئامێرەکان.
                        </p>
                        <ul class="nx-card-list">
                            <li><i class="bi bi-check2-circle"></i> پاشەکەوتکردنی داتا (Database Backup) بە یەک کلیک</li>
                            <li><i class="bi bi-check2-circle"></i> دیزاینی گونجاو لەسەر مۆبایل، تابلێت و کۆمپیوتەر</li>
                            <li><i class="bi bi-check2-circle"></i> نوێکردنەوە و گەشەپێدانی بەردەوام</li>
                        </ul>
                    </div>
                </div>
            </div>
        </section>

        <!-- Pillars of Excellence -->
        <section class="section-wrap pt-0">
            <div class="section-header mb-4">
                <span class="section-tag">بۆچی NexoraCore؟</span>
                <h2 class="section-title">بنەما سەرەکییەکانی دروستکردنی سیستەمەکە</h2>
            </div>

            <div class="row g-3">
                <div class="col-md-3 col-sm-6">
                    <div class="pillar-card">
                        <span class="pillar-icon text-primary"><i class="bi bi-speedometer2"></i></span>
                        <h4 class="pillar-title">خێرایی لە جێبەجێکردن</h4>
                        <p class="pillar-desc">کەمترین کات لە ڕیزەکانی کڕیاران و وەڵامدانەوەی دەستبەجێی فەرمانەکان.</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="pillar-card">
                        <span class="pillar-icon text-purple" style="color: #8b5cf6;"><i class="bi bi-ui-checks-grid"></i></span>
                        <h4 class="pillar-title">سادەیی و ئاسانی</h4>
                        <p class="pillar-desc">دیزاینێکی ڕوون کە بەکارهێنەر بە کەمترین فێرکاری دەتوانێت بەکاریبهێنێت.</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="pillar-card">
                        <span class="pillar-icon text-success"><i class="bi bi-check-all"></i></span>
                        <h4 class="pillar-title">وردبینی بێ هەڵە</h4>
                        <p class="pillar-desc">حیساباتی ژمێریاری و باڵانسی کۆگا بەوپەڕی متمانە و بەڵگەوە.</p>
                    </div>
                </div>
                <div class="col-md-3 col-sm-6">
                    <div class="pillar-card">
                        <span class="pillar-icon text-info"><i class="bi bi-headset"></i></span>
                        <h4 class="pillar-title">پشتگیری و نوێکاری</h4>
                        <p class="pillar-desc">پابەندبوون بە باشترکردنی سیستەم و بەردەستبوونی یارمەتی تەکنیکی.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Quick Access & Support Bar -->
        <section class="mb-5">
            <div class="action-bar-wrap">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
                    <div>
                        <h4 class="fw-bold text-dark mb-1">
                            <i class="bi bi-info-circle text-primary me-2"></i>
                            پرسیار یان پێویستت بە یارمەتی هەیە دەربارەی سیستەمەکە؟
                        </h4>
                        <p class="text-muted mb-0">سەردانی بەشی پشتگیری بکە یان پرسیار و وەڵامە باوەکان بخوێنەرەوە.</p>
                    </div>
                    <div class="d-flex gap-2 flex-wrap">
                        <a href="<?php echo url('user/support/index.php'); ?>" class="btn-nx-outline">
                            <i class="bi bi-heart-fill text-danger"></i> بەشی پاڵپشتی
                        </a>
                        <a href="<?php echo url('questions_and_answers.html'); ?>" target="_blank" class="btn-nx-outline">
                            <i class="bi bi-question-circle"></i> پرسیار و وەڵامەکان
                        </a>
                        <a href="<?php echo url('user/dashboard/index.php'); ?>" class="btn-nx-primary">
                            <i class="bi bi-speedometer2"></i> چوون بۆ داشبۆرد
                        </a>
                    </div>
                </div>
            </div>
        </section>

    </main>

    <!-- Floating Back to Dashboard Button -->
    <a href="<?php echo url('user/dashboard/index.php'); ?>" class="back-to-dashboard" title="گەڕانەوە بۆ داشبۆرد">
        <i class="bi bi-house-door-fill" style="font-size: 1.4rem;"></i>
    </a>

    <!-- Footer -->
    <footer class="nexora-footer">
        <div class="container text-center">
            <p class="mb-2 fw-semibold text-white">
                <i class="bi bi-cpu text-primary me-1"></i> NexoraCore - سیستەمی بەڕێوەبردنی خاڵی فرۆشتن و ژمێریاری
            </p>
            <p class="mb-0 small text-muted">
                هەموو مافەکان پارێزراوە بۆ NexoraCore &copy; <?php echo date('Y'); ?>
            </p>
        </div>
    </footer>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
