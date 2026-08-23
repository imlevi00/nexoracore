<?php
/**
 * Navbar for products module — responsive collapse
 *
 * Before include, optionally set:
 *   $productsNavId    — unique collapse id (default: productsNav)
 *   $productsNavLinks — array of ['href','icon','text','class'?]
 */
$productsNavId = $productsNavId ?? 'productsNav';
if (!isset($productsNavLinks)) {
    $productsNavLinks = [
        ['href' => url('user/products/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کاڵاکان'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top products-module-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/dashboard/index.php'); ?>">
            <i class="bi bi-shop"></i>
            <?php echo htmlspecialchars($currentUser['business_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                data-bs-target="#<?php echo htmlspecialchars($productsNavId, ENT_QUOTES, 'UTF-8'); ?>"
                aria-controls="<?php echo htmlspecialchars($productsNavId, ENT_QUOTES, 'UTF-8'); ?>"
                aria-expanded="false" aria-label="کردنەوەی مێنو">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="<?php echo htmlspecialchars($productsNavId, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="navbar-nav ms-auto">
                <?php foreach ($productsNavLinks as $link): ?>
                    <a class="nav-link<?php echo !empty($link['class']) ? ' ' . htmlspecialchars($link['class'], ENT_QUOTES, 'UTF-8') : ''; ?>"
                       href="<?php echo htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8'); ?>">
                        <i class="bi <?php echo htmlspecialchars($link['icon'], ENT_QUOTES, 'UTF-8'); ?>"></i>
                        <?php echo htmlspecialchars($link['text'], ENT_QUOTES, 'UTF-8'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</nav>
