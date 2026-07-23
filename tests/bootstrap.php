<?php

declare(strict_types=1);

// The TestKernel runs with debug=false (a debug kernel leaks exception
// handlers that PHPUnit flags as risky), so the compiled container is not
// invalidated on config changes — purge it before every run instead.
$varDir = __DIR__.'/Fixtures/var';
if (is_dir($varDir)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($varDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}

require __DIR__.'/../vendor/autoload.php';
