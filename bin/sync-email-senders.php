<?php
/**
 * Copies mailoptin/wp-email-senders into src/EmailSenders with its namespace renamed to
 * MailOptin\Core\EmailSenders, so this copy never meets another plugin's (FuseWP bundles its own).
 * See EMAIL-SENDERS.md.
 *
 * php bin/sync-email-senders.php /path/to/wp-email-senders           copy it in, and record its version
 * php bin/sync-email-senders.php /path/to/wp-email-senders --check   only report whether the copy matches
 */

$args   = array_values(array_diff(array_slice($argv, 1), ['--check']));
$check  = in_array('--check', $argv, true);
$root   = rtrim($args[0] ?? '', '/');
$source = $root . '/src';
$target = dirname(__DIR__) . '/src/EmailSenders';

if ('' === $root || ! is_dir($source)) {
    fwrite(STDERR, "Usage: php bin/sync-email-senders.php /path/to/wp-email-senders [--check]\n");
    exit(2);
}

/**
 * The package's files as they should be in this plugin: relative path => renamed code.
 */
function mo_email_senders_expected($source)
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ('php' !== $file->getExtension()) continue;

        $code = str_replace('MailOptin\\EmailSenders', 'MailOptin\\Core\\EmailSenders', file_get_contents($file->getPathname()));
        $code = preg_replace('/^<\?php\n/', "<?php\n// Copied from mailoptin/wp-email-senders by bin/sync-email-senders.php. Change it there.\n", $code, 1);

        $files[str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1))] = $code;
    }

    ksort($files);

    return $files;
}

/**
 * The PHP files in the current copy: relative path => code.
 */
function mo_email_senders_current($target)
{
    $files = [];

    if ( ! is_dir($target)) return $files;

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ('php' !== $file->getExtension()) continue;

        $files[str_replace('\\', '/', substr($file->getPathname(), strlen($target) + 1))] = file_get_contents($file->getPathname());
    }

    ksort($files);

    return $files;
}

/**
 * The package's tag (or nearest tag plus commits), commit, and whether it has uncommitted changes.
 */
function mo_email_senders_version($root)
{
    $git = function ($command) use ($root) {
        $output = [];
        exec('git -C ' . escapeshellarg($root) . ' ' . $command . ' 2>/dev/null', $output, $status);

        return 0 === $status ? trim(implode("\n", $output)) : '';
    };

    $commit = $git('rev-parse HEAD');

    return [
        'version' => $git('describe --tags --always') ?: 'unknown',
        'commit'  => $commit ?: 'unknown',
        'dirty'   => '' !== $commit && '' !== $git('status --porcelain -- src'),
    ];
}

$expected = mo_email_senders_expected($source);
$version  = mo_email_senders_version($root);

if ($check) {
    $current = mo_email_senders_current($target);
    $changed = [];

    foreach ($expected as $path => $code) {
        if ( ! isset($current[$path])) {
            $changed[] = "missing  $path";
        } elseif ($current[$path] !== $code) {
            $changed[] = "differs  $path";
        }
    }

    foreach (array_diff_key($current, $expected) as $path => $code) {
        $changed[] = "extra    $path";
    }

    if (empty($changed)) {
        echo "src/EmailSenders matches wp-email-senders {$version['version']}.\n";
        exit(0);
    }

    echo "src/EmailSenders doesn't match wp-email-senders {$version['version']}:\n  " . implode("\n  ", $changed) . "\n";
    echo "Run the script without --check to copy it in, or check out the version this plugin is meant to ship.\n";
    exit(1);
}

if ($version['dirty']) {
    fwrite(STDERR, "Warning: wp-email-senders has uncommitted changes in src/. The copy won't match any commit.\n");
}

// Start from empty, so files removed from the package go from here too.
if (is_dir($target)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
}

foreach ($expected as $path => $code) {
    $dest = $target . '/' . $path;

    if ( ! is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);

    file_put_contents($dest, $code);
}

file_put_contents($target . '/VERSION', implode("\n", [
    'Copied from mailoptin/wp-email-senders. Change the package, not this copy: see EMAIL-SENDERS.md.',
    'version: ' . $version['version'] . ($version['dirty'] ? ' (with uncommitted changes)' : ''),
    'commit:  ' . $version['commit'],
    'synced:  ' . gmdate('Y-m-d H:i:s') . ' UTC',
    '',
]));

echo 'Copied ' . count($expected) . " files from wp-email-senders {$version['version']} to src/EmailSenders\n";
