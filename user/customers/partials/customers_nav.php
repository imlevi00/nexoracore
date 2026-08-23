<?php
/**
 * Navbar for customers module — responsive collapse
 *
 * Before include, optionally set:
 *   $customersNavId    — unique collapse id (default: customersNav)
 *   $customersNavLinks — array of ['href','icon','text','class'?]
 */
$customersNavId = $customersNavId ?? 'customersNav';
if (!isset($customersNavLinks)) {
    $customersNavLinks = [
        ['href' => url('user/customers/main.php'), 'icon' => 'bi-grid', 'text' => 'سەنتەری کڕیاران'],
        ['href' => url('user/dashboard/index.php'), 'icon' => 'bi-house', 'text' => 'داشبۆرد'],
    ];
}
?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary sticky-top customers-module-navbar">
    <div class="container-fluid">
        <a class="navbar-brand" href="<?php echo url('user/dashboard/index.php'); ?>">
            <i class="bi bi-shop"></i>
            <?php echo htmlspecialchars($currentUser['business_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
        </a>

        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse"
                data-bs-target="#<?php echo htmlspecialchars($customersNavId, ENT_QUOTES, 'UTF-8'); ?>"
                aria-controls="<?php echo htmlspecialchars($customersNavId, ENT_QUOTES, 'UTF-8'); ?>"
                aria-expanded="false" aria-label="کردنەوەی مێنو">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="<?php echo htmlspecialchars($customersNavId, ENT_QUOTES, 'UTF-8'); ?>">
            <div class="navbar-nav ms-auto">
                <?php foreach ($customersNavLinks as $link): ?>
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
