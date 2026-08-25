<?php
/**
 * پەڕەی سەرەکی - index.php
 */
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/security.php';
require_once 'config/config.php';
require_once 'config/security.php';

if (isUser()) {
    redirect('user/dashboard/index.php');
}

$loginUrl    = url('user/auth/login.php');
$registerUrl = url('user/auth/register.php');
$termsUrl    = url('terms_and_conditions.html');
$qaUrl       = url('questions_and_answers.html');
$logoUrl     = asset('images/logo.png');
?>
<!DOCTYPE html>
<html lang="ckb" dir="rtl">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title><?php echo SITE_NAME; ?> — سیستەمی داتابەیس و کاشیر بۆ بازرگانی</title>
  <meta name="description" content="<?php echo SITE_NAME; ?> سیستەمی داتابەیس و خاڵی فرۆشتن (کاشیر) دروستدەکات کە بازرگانییە مۆدێرنەکان بەهێز دەکات — لە قاوەخانەوە بۆ زنجیرە فرۆشگاکان." />
  <meta name="keywords" content="کاشێر, سیستەمی فرۆشتن, بەڕێوەبردنی کاڵا, POS, سیستەمی کاشێری, NexoraCore" />
  <meta name="author" content="Amir Technology" />

  <meta property="og:type" content="website" />
  <meta property="og:url" content="<?php echo url(); ?>" />
  <meta property="og:title" content="<?php echo SITE_NAME; ?> - سیستەمی NexoraCore" />
  <meta property="og:description" content="سیستەمێکی تەواو بۆ بەڕێوەبردنی کاڵا، فرۆشتن، کڕیاران و ئامارەکان بە شێوەیەکی ئاسان و کارامە" />
  <meta property="og:image" content="<?php echo $logoUrl; ?>" />
  <meta property="og:site_name" content="<?php echo SITE_NAME; ?>" />
  <meta property="og:locale" content="ku_IQ" />

  <meta property="twitter:card" content="summary_large_image" />
  <meta property="twitter:url" content="<?php echo url(); ?>" />
  <meta property="twitter:title" content="<?php echo SITE_NAME; ?> - سیستەمی NexoraCore" />
  <meta property="twitter:description" content="سیستەمێکی تەواو بۆ بەڕێوەبردنی کاڵا، فرۆشتن، کڕیاران و ئامارەکان" />
  <meta property="twitter:image" content="<?php echo $logoUrl; ?>" />

  <link rel="icon" type="image/png" href="<?php echo $logoUrl; ?>" />
  <link rel="apple-touch-icon" href="<?php echo $logoUrl; ?>" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" />
  <link rel="stylesheet" href="<?php echo asset('css/landing-site.css'); ?>" />
</head>
<body>
  <!-- سەرپەڕە -->
  <header class="site-header" id="top">
    <div class="container nav">
      <a href="<?php echo url(); ?>" class="brand">
        <span class="brand-mark"><img src="<?php echo $logoUrl; ?>" alt="<?php echo SITE_NAME; ?>"></span>
        <span class="brand-name">Nexora<span>Core</span></span>
      </a>
      <nav class="nav-links" id="navLinks">
        <a href="#products">بەرهەمەکان</a>
        <a href="#features">تایبەتمەندییەکان</a>
        <a href="#businesses">بازرگانییەکان</a>
        <a href="#pricing">نرخەکان</a>
        <a href="<?php echo $loginUrl; ?>" class="btn btn-ghost btn-sm">داخڵبوون</a>
        <a href="<?php echo $registerUrl; ?>" class="btn btn-primary btn-sm">داواکردنی دیمۆ</a>
      </nav>
      <button class="nav-toggle" id="navToggle" aria-label="لیستی ناڤیگەیشن">
        <span></span><span></span><span></span>
      </button>
    </div>
  </header>

  <!-- هێرۆ -->
  <section class="hero">
    <div class="container hero-grid">
      <div class="hero-copy">
        <div class="hero-badges">
          <span class="hero-badge">POS + Database</span>
          <span class="hero-badge outline">هاوکات · ئۆفلاین</span>
        </div>
        <p class="eyebrow">سیستەمی داتابەیس و کاشیر</p>
        <h1>سیستەمی کارپێکردن بۆ ئەو بازرگانییانەی خۆشت دەوێن.</h1>
        <p class="lead">
          <?php echo SITE_NAME; ?> کاشیر، کۆگا (ئینڤێنتۆری)، و داتای کڕیارەکانت لە یەک پلاتفۆرمی
          زۆر خێرادا کۆدەکاتەوە. فرۆشتن تۆمار بکە، کۆگا بەدواداچوون بکە، و کڕیارەکانت
          بناسە — هەموو لە یەک داتابەیسی بۆ گەشەکردن دروستکراو.
        </p>
        <div class="hero-actions">
          <a href="<?php echo $registerUrl; ?>" class="btn btn-primary">دەستپێکردنی تاقیکردنەوەی بەخۆڕایی</a>
          <a href="#products" class="btn btn-ghost">بەرهەمەکان ببینە ←</a>
        </div>
        <div class="hero-stats hero-stats-grid">
          <div class="stat-tile"><strong>+۱۲٬۰۰۰</strong><span>بازرگانی بەهێزکراو</span></div>
          <div class="stat-tile"><strong>٪۹۹.۹۹</strong><span>کاتی کارکردن (SLA)</span></div>
          <div class="stat-tile"><strong>۴۸</strong><span>وڵات خزمەت پێکراو</span></div>
        </div>
      </div>
      <div class="hero-visual" aria-hidden="true">
        <div class="pos-card">
          <div class="pos-head">
            <span class="dot"></span><span class="dot"></span><span class="dot"></span>
            <p><?php echo SITE_NAME; ?> POS</p>
          </div>
          <div class="pos-body">
            <div class="pos-row"><span>فلات وایت ×۲</span><span>$۹.۰۰</span></div>
            <div class="pos-row"><span>کرواسان</span><span>$۴.۵۰</span></div>
            <div class="pos-row"><span>کۆڵد برو</span><span>$۵.۲۵</span></div>
            <div class="pos-row muted"><span>کۆی بەشەکە</span><span>$۱۸.۷۵</span></div>
            <div class="pos-row total"><span>کۆی گشتی</span><span>$۲۰.۲۵</span></div>
            <button class="pos-pay">پارەوەرگرتن $۲۰.۲۵</button>
          </div>
        </div>
        <div class="db-chip">
          <span class="pulse"></span> داتابەیسی زیندوو · هاوکاتکردنی ۳ فرۆشگا
        </div>
      </div>
    </div>
  </section>

  <!-- متمانە -->
  <section class="trust">
    <div class="container">
      <p class="trust-label">متمانەی ئەو تیمانەی پێکراوە کە کاری قەبارە بەرزیان بەڕێوەدەبەن</p>
      <div class="trust-logos">
        <span>Brew&amp;Co</span><span>UrbanMart</span><span>Fork &amp; Fire</span>
        <span>Peak Pharmacy</span><span>Nova Retail</span><span>Green Grocer</span>
      </div>
    </div>
  </section>

  <!-- بەرهەمەکان -->
  <section class="section" id="products">
    <div class="container">
      <div class="section-head">
        <p class="eyebrow">ئەوەی دروستی دەکەین</p>
        <h2>دوو سیستەم. یەک سەرچاوەی ڕاستی.</h2>
        <p class="section-sub">کاشیرەکەمان و داتابەیسەکەمان پێکەوە دیزاین کراون، بۆیە هەموو فرۆشتنێک داتاکەت لە کاتی ڕاستەقینەدا نوێدەکاتەوە.</p>
      </div>
      <div class="product-grid">
        <article class="product-card">
          <div class="product-icon">🧾</div>
          <h3><?php echo SITE_NAME; ?> Register</h3>
          <p>سیستەمێکی خێرای کاشیر و خاڵی فرۆشتن کە بەبێ ئینتەرنێتیش کاردەکات. حیساب دابەش بکە، پارە بە کارت یان کاش وەربگرە، داشکاندن جێبەجێ بکە، و پسوڵە لە چرکەیەکدا چاپ یان ئیمەیڵ بکە.</p>
          <ul class="check-list">
            <li>پەرداخی ئامادە بۆ تابلێت یان تێرمیناڵ</li>
            <li>پارەدان بە کارت، کاش، جزدان و QR</li>
            <li>بەبێ ئینتەرنێت کاردەکات، دواتر هاوکات دەبێت</li>
          </ul>
        </article>
        <article class="product-card featured">
          <div class="product-icon">🗄️</div>
          <h3><?php echo SITE_NAME; ?> Database</h3>
          <p>داتابەیسێکی بەڕێوەبراوی بازرگانی بۆ کۆگا، کڕیارەکان و داواکارییەکان. ڕێکخراو، گەڕانی ئاسان، و پارێزراو — لەگەڵ باکئەپی خۆکار و دەستپێگەیشتنی پێگەبەند.</p>
          <ul class="check-list">
            <li>کۆگا و ئاگادارکردنەوەی کاتی ڕاست</li>
            <li>پرۆفایلی کڕیار و مێژووی دڵسۆزی</li>
            <li>کۆدکراو، باکئەپکراو، و تۆماری چاودێری</li>
          </ul>
        </article>
      </div>
    </div>
  </section>

  <!-- تایبەتمەندییەکان -->
  <section class="section alt" id="features">
    <div class="container">
      <div class="section-head">
        <p class="eyebrow">بۆچی <?php echo SITE_NAME; ?></p>
        <h2>هەموو ئەوەی بازرگانییەکی گەشەسەندوو پێویستیەتی</h2>
      </div>
      <div class="feature-grid">
        <div class="feature"><div class="feature-ic">⚡</div><h4>پەرداخی خێرا</h4><p>مامەڵەی کەمتر لە چرکەیەک تەنانەت لە ڕۆژە قەرەباڵغەکاندا، بەبێ دواکەوتن.</p></div>
        <div class="feature"><div class="feature-ic">📊</div><h4>شیکاری زیندوو</h4><p>فرۆشتن، باشترین بەرهەم، و کاتە قەرەباڵغەکان لە هەموو شوێنێک لە یەک داشبۆرد ببینە.</p></div>
        <div class="feature"><div class="feature-ic">🔒</div><h4>پاراستنی ئاستی بانک</h4><p>کۆدکردنی سەرتاسەری، پارەدانی پارێزراو، و دەسەڵاتی ورد بۆ ستاف.</p></div>
        <div class="feature"><div class="feature-ic">🔄</div><h4>هاوکاتکردنی کاتی ڕاست</h4><p>هەموو فرۆشتنێک کۆگا و ڕاپۆرتەکان لە هەموو فرۆشگاکاندا دەستبەجێ نوێدەکاتەوە.</p></div>
        <div class="feature"><div class="feature-ic">🧩</div><h4>یەکخستنەکان</h4><p>ژمێریاری، فرۆشتنی ئۆنلاین، و ئەپی گەیاندن بە چەند کلیکێک ببەستەوە.</p></div>
        <div class="feature"><div class="feature-ic">📱</div><h4>لە هەر شوێنێک کاردەکات</h4><p>لەسەر تێرمیناڵ، تابلێت، یان مۆبایل بەکاریبهێنە — داتابەیسەکە هاوکات دەمێنێتەوە.</p></div>
      </div>
    </div>
  </section>

  <!-- بازرگانییەکان -->
  <section class="section" id="businesses">
    <div class="container">
      <div class="section-head">
        <p class="eyebrow">بەهێزکراو بە <?php echo SITE_NAME; ?></p>
        <h2>ئەو بازرگانییانەی لەسەر پلاتفۆرمەکەمان کاردەکەن</h2>
        <p class="section-sub">لە قاوەخانەی تاک-فرۆشگاوە بۆ زنجیرە فرۆشگا فرەشوێنەکان، هەزاران تیم متمانە بە <?php echo SITE_NAME; ?> دەکەن بۆ بەڕێوەبردنی ڕۆژەکەیان.</p>
      </div>

      <div class="filter-bar" id="filterBar">
        <button class="chip active" data-filter="all">هەموو</button>
        <button class="chip" data-filter="قاوەخانە">قاوەخانە</button>
        <button class="chip" data-filter="فرۆشگا">فرۆشگا</button>
        <button class="chip" data-filter="چێشتخانە">چێشتخانە</button>
        <button class="chip" data-filter="بەقاڵی">بەقاڵی</button>
        <button class="chip" data-filter="دەرمانخانە">دەرمانخانە</button>
      </div>

      <div class="business-grid" id="businessGrid"><!-- بە JS دادەمەزرێت --></div>
    </div>
  </section>

  <!-- نرخەکان -->
  <section class="section alt" id="pricing">
    <div class="container">
      <div class="section-head">
        <p class="eyebrow">نرخەکان</p>
        <h2>پلانی سادە کە لەگەڵت گەشە دەکات</h2>
      </div>
      <div class="price-grid">
        <article class="price-card">
          <h3>دەستپێک</h3>
          <p class="price"><span>$۲۹</span>/مانگانە</p>
          <p class="price-desc">بۆ تاک فرۆشگایەک کە تازە دەستپێدەکات.</p>
          <ul class="check-list">
            <li>۱ تێرمیناڵی کاشیر</li>
            <li>داتابەیس و کۆگای بنەڕەتی</li>
            <li>پشتگیری بە ئیمەیڵ</li>
          </ul>
          <a href="<?php echo $registerUrl; ?>" class="btn btn-ghost btn-block">هەڵبژاردنی دەستپێک</a>
        </article>
        <article class="price-card featured">
          <span class="badge">بەناوبانگترین</span>
          <h3>گەشە</h3>
          <p class="price"><span>$۷۹</span>/مانگانە</p>
          <p class="price-desc">بۆ بازرگانییە گەشەسەندووەکان بە چەند تێرمیناڵ.</p>
          <ul class="check-list">
            <li>تا ۵ تێرمیناڵ</li>
            <li>شیکاری پێشکەوتوو و دڵسۆزی</li>
            <li>پشتگیری بە پێشینە</li>
          </ul>
          <a href="<?php echo $registerUrl; ?>" class="btn btn-primary btn-block">هەڵبژاردنی گەشە</a>
        </article>
        <article class="price-card">
          <h3>کۆمپانیا</h3>
          <p class="price"><span>تایبەت</span></p>
          <p class="price-desc">بۆ زنجیرەکان و کاری قەبارە بەرز.</p>
          <ul class="check-list">
            <li>تێرمیناڵ و فرۆشگای بێسنوور</li>
            <li>خۆشەی داتابەیسی تایبەت</li>
            <li>پشتگیری ۲۴/۷ و SLA</li>
          </ul>
          <a href="#contact" class="btn btn-ghost btn-block">قسە لەگەڵ فرۆشتن</a>
        </article>
      </div>
    </div>
  </section>

  <!-- پەیوەندی / بانگهێشت -->
  <section class="section cta" id="contact">
    <div class="container cta-inner">
      <h2>ئامادەیت بازرگانییەکەت لەسەر <?php echo SITE_NAME; ?> بەڕێوەببەیت؟</h2>
      <p>دیمۆیەک داوا بکە و لە کەمتر لە ڕۆژێکدا داتابەیس و سیستەمی کاشیرت بۆ دادەمەزرێنین.</p>
      <form class="cta-form" id="demoForm" novalidate>
        <input type="text" name="business" placeholder="ناوی بازرگانی" required />
        <input type="email" name="email" placeholder="ئیمەیڵی کار" required />
        <button type="submit" class="btn btn-primary">داواکردنی دیمۆ</button>
      </form>
      <p class="form-note" id="formNote" role="status"></p>
    </div>
  </section>

  <!-- پێپەڕە -->
  <footer class="site-footer">
    <div class="container footer-grid">
      <div>
        <a href="<?php echo url(); ?>" class="brand">
          <span class="brand-mark"><img src="<?php echo $logoUrl; ?>" alt="<?php echo SITE_NAME; ?>"></span>
          <span class="brand-name">Nexora<span>Core</span></span>
        </a>
        <p class="footer-tag">سیستەمی داتابەیس و کاشیر بۆ بازرگانی مۆدێرن.</p>
      </div>
      <div>
        <h5>بەرهەم</h5>
        <a href="#products">Register</a>
        <a href="#products">Database</a>
        <a href="#pricing">نرخەکان</a>
      </div>
      <div>
        <h5>کۆمپانیا</h5>
        <a href="#businesses">کڕیارەکان</a>
        <a href="#features">تایبەتمەندییەکان</a>
        <a href="#contact">پەیوەندی</a>
      </div>
      <div>
        <h5>پشتگیری</h5>
        <a href="<?php echo $loginUrl; ?>">داخڵبوون</a>
        <a href="<?php echo $qaUrl; ?>">ناوەندی یارمەتی</a>
        <a href="<?php echo $termsUrl; ?>">یاسا و مەرجەکان</a>
      </div>
    </div>
    <div class="container footer-bottom">
      <p>© <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. هەموو مافەکان پارێزراون.</p>
      <p>دروستکراوە بۆ بازرگانییەکان، لە هەموو شوێنێک.</p>
    </div>
  </footer>

  <script>
    window.NEXORA_REGISTER_URL = <?php echo json_encode($registerUrl); ?>;
  </script>
  <script src="<?php echo asset('js/landing-site.js'); ?>"></script>
</body>
</html>
