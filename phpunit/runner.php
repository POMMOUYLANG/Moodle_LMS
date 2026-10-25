<?php

declare(strict_types=1);

$source = '/source';
$configsource = '/opt/rtc-phpunit/config.php';
$stateconfig = '/var/phpunit-state/phpunit.xml';
$statepath = dirname($stateconfig);
$arguments = array_slice($argv, 1);

if ($arguments === []) {
    fwrite(STDERR, "Pass a Moodle CLI script or PHPUnit command.\n");
    exit(64);
}

if (!is_file($source . '/vendor/autoload.php')
        || !is_file($source . '/vendor/bin/phpunit')
        || !is_file($source . '/composer.phar')) {
    fwrite(STDERR, "Moodle Composer files are missing; this runner will not download or install dependencies.\n");
    exit(66);
}

if ($arguments[0] === 'vendor/bin/phpunit' && !is_file($stateconfig)) {
    fwrite(STDERR, "The isolated PHPUnit site has not been initialized; run phpunit/run.ps1 -Action Initialize first.\n");
    exit(65);
}

if (!is_dir($statepath) && !mkdir($statepath, 0770, true) && !is_dir($statepath)) {
    fwrite(STDERR, "Unable to access isolated PHPUnit state storage.\n");
    exit(73);
}

$workspace = sys_get_temp_dir() . '/rtc-moodle-phpunit-' . bin2hex(random_bytes(12));
if (!mkdir($workspace, 0770, true)) {
    fwrite(STDERR, "Unable to create disposable Moodle source workspace.\n");
    exit(73);
}

$excluded = [
    'config.php',
    'phpunit.xml',
    '.phpunit.result.cache',
    '.phpunit.cache',
];
$rootiterator = new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator(
    $rootiterator,
    static function (SplFileInfo $entry) use ($source, $excluded): bool {
        $relative = substr($entry->getPathname(), strlen($source) + 1);
        if (in_array($relative, $excluded, true)) {
            return false;
        }
        $firstsegment = explode(DIRECTORY_SEPARATOR, $relative, 2)[0];
        return $firstsegment !== '.git';
    }
);
$iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::SELF_FIRST);

foreach ($iterator as $entry) {
    $sourcepath = $entry->getPathname();
    $relative = substr($sourcepath, strlen($source) + 1);
    $targetpath = $workspace . DIRECTORY_SEPARATOR . $relative;

    if ($entry->isLink()) {
        $linktarget = readlink($sourcepath);
        if ($linktarget === false || !symlink($linktarget, $targetpath)) {
            fwrite(STDERR, "Unable to copy a Moodle source symlink into the isolated workspace.\n");
            exit(74);
        }
    } elseif ($entry->isDir()) {
        if (!mkdir($targetpath, 0777, true) && !is_dir($targetpath)) {
            fwrite(STDERR, "Unable to copy a Moodle source directory into the isolated workspace.\n");
            exit(74);
        }
    } elseif ($entry->isFile() && !copy($sourcepath, $targetpath)) {
        fwrite(STDERR, "Unable to copy a Moodle source file into the isolated workspace.\n");
        exit(74);
    }
}

if (is_file($stateconfig) && !copy($stateconfig, $workspace . '/phpunit.xml')) {
    fwrite(STDERR, "Unable to load the isolated PHPUnit suite configuration.\n");
    exit(74);
}
if (!copy($configsource, $workspace . '/config.php')) {
    fwrite(STDERR, "Unable to install the isolated PHPUnit Moodle configuration.\n");
    exit(74);
}

$process = proc_open(
    array_merge(['php'], $arguments),
    [0 => STDIN, 1 => STDOUT, 2 => STDERR],
    $pipes,
    $workspace
);
if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start the requested Moodle CLI process.\n");
    exit(70);
}
$result = proc_close($process);

if (is_file($workspace . '/phpunit.xml') && !copy($workspace . '/phpunit.xml', $stateconfig)) {
    fwrite(STDERR, "Unable to save isolated PHPUnit suite configuration.\n");
    exit(74);
}

exit($result);
