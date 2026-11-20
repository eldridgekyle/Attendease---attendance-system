<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$obsoleteRoute = $root . '/profile_set_face.php';
if (is_file($obsoleteRoute)) {
    throw new RuntimeException('The retired face-profile route still exists.');
}

$forbidden = [
    'biometric profile',
    'profile & set face',
    'profile_set_face.php',
    'face profile is registered',
    'your face profile is ready',
    'refresh face registration',
    'getusermedia',
    'qr attendance',
    'pin attendance',
];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || in_array('tests', explode(DIRECTORY_SEPARATOR, substr($file->getPathname(), strlen($root) + 1)), true)) {
        continue;
    }
    if (!in_array(strtolower($file->getExtension()), ['php','js','css'], true)) {
        continue;
    }
    $contents = strtolower((string)file_get_contents($file->getPathname()));
    foreach ($forbidden as $phrase) {
        if (str_contains($contents, $phrase)) {
            throw new RuntimeException('Retired attendance UI or route reference found in ' . $file->getPathname());
        }
    }
}

echo "Legacy biometric, face-profile, QR, and PIN application references are absent.\n";
