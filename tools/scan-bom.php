<?php
/**
 * Scan repository for UTF-8 BOM in text/source files.
 * Usage: php tools/scan-bom.php [--fix]
 */
$root = dirname(__DIR__);
$fix = in_array('--fix', $argv ?? [], true);
$extensions = [
    'php', 'js', 'css', 'html', 'htm', 'json', 'md', 'xml', 'svg',
    'ts', 'tsx', 'vue', 'sql', 'txt', 'yml', 'yaml', 'sh', 'bat', 'mdc',
];
$basenameAllow = ['.htaccess', '.env.example'];
$skipDirs = [
    'node_modules', 'vendor', '.git', 'assets/uploads', 'cache', 'tmp',
    'dist', 'build',
];

$found = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    $rel = str_replace($root . DIRECTORY_SEPARATOR, '', $path);
    $relNorm = str_replace('\\', '/', $rel);

    foreach ($skipDirs as $skip) {
        if (str_contains($relNorm, $skip . '/')) {
            continue 2;
        }
    }

    $base = $file->getBasename();
    $ext = strtolower($file->getExtension());
    $allowed = in_array($ext, $extensions, true) || in_array($base, $basenameAllow, true);
    if (!$allowed) {
        continue;
    }

    $content = file_get_contents($path);
    if ($content === false || strncmp($content, "\xEF\xBB\xBF", 3) !== 0) {
        continue;
    }

    if ($fix) {
        file_put_contents($path, substr($content, 3));
    }
    $found[] = $relNorm;
}

sort($found);
if ($fix) {
    echo 'Fixed ' . count($found) . " file(s)\n";
} else {
    echo 'Found ' . count($found) . " file(s) with BOM\n";
}
foreach ($found as $f) {
    echo $f . "\n";
}
exit(count($found) > 0 && !$fix ? 1 : 0);
