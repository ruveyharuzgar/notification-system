<?php
$root = dirname(__DIR__);
$failed = false;
foreach (['app','bootstrap','config','database','routes','tests','public','mock','scripts'] as $dir) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') continue;
        passthru(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file->getPathname()), $code);
        $failed = $failed || $code !== 0;
    }
}
exit($failed ? 1 : 0);
