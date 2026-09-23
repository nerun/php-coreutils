<?php
// Run with: php tests/run.php (no development dependencies required).
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    if (error_reporting() & $severity) throw new ErrorException($message, 0, $severity, $file, $line);
    return false;
});

$initialCwd = getcwd();
$initialLocale = setlocale(LC_ALL, 0);
$initialMask = umask();
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pt-BR,pt;q=0.9';
ob_start();
require_once __DIR__ . '/../src/bootstrap.php';
$bootstrapOutput = ob_get_clean();

class SkippedTest extends RuntimeException {}
function check($condition, string $message = 'assertion failed'): void {
    if (!$condition) throw new RuntimeException($message);
}
function same($expected, $actual): void {
    check($expected === $actual, 'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function skipUnless($condition, string $reason): void {
    if (!$condition) throw new SkippedTest($reason);
}
function modeOf(string $path): int {
    clearstatcache(true, $path);
    return fileperms($path) & 07777;
}
function eraseFixture(string $path): void {
    if (is_link($path) || !is_dir($path)) {
        unlink($path);
        return;
    }
    chmod($path, 0700);
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') eraseFixture($path . '/' . $name);
    }
    rmdir($path);
}
function fixture(callable $test): void {
    $base = sys_get_temp_dir() . '/php-coreutils-' . bin2hex(random_bytes(8));
    mkdir($base, 0700);
    $cwd = getcwd();
    $mask = umask(0022);
    try {
        $test($base);
    } finally {
        chdir($cwd);
        umask($mask);
        eraseFixture($base);
    }
}
function nativePermissionSupport(string $base): void {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'POSIX permission semantics are unavailable on Windows');
    $mask = umask(0077);
    try {
        mkdir($base . '/permission-probe', 0777);
        $mode = modeOf($base . '/permission-probe');
    } finally {
        umask($mask);
    }
    skipUnless($mode === 0700, 'runtime filesystem does not apply a native umask');
}
function symlinkSupport(string $base): void {
    skipUnless(function_exists('symlink'), 'symlink() unavailable');
    $success = coreutilsFsCall(fn() => symlink('missing', $base . '/symlink-probe'));
    skipUnless($success, 'runtime cannot create symlinks');
    unlink($base . '/symlink-probe');
}
function names(array $result): array {
    return array_column($result['data']['directories'][0]['entries'], 'name');
}

$tests = [];
$tests['loading has no output or global locale/cwd/umask changes'] = function () use ($bootstrapOutput, $initialLocale, $initialCwd, $initialMask) {
    same('', $bootstrapOutput);
    same($initialLocale, setlocale(LC_ALL, 0));
    same($initialCwd, getcwd());
    same($initialMask, umask());
    check(!defined('CURRENT_LOCALE'));
};
$tests['tokenizer preserves quotes, spaces and empty operands'] = function () {
    $parsed = parseCommand('mkdir "one two" \'three four\' five\\ six ""');
    same([], $parsed['errors']);
    same(['one two', 'three four', 'five six', ''], $parsed['args']);
};
$tests['tokenizer handles tabs, newlines and adjacent quotes'] = function () {
    same(['ab cd', 'next'], parseCommand("mkdir\t'a'\"b c\"d\nnext")['args']);
};
$tests['double quotes preserve ordinary backslashes'] = function () {
    same(['C:\\temp\\name'], parseCommand('mkdir "C:\\temp\\name"')['args']);
};
$tests['unterminated input is rejected'] = function () {
    foreach (['mkdir "open', "mkdir 'open", 'mkdir trailing\\', "mkdir bad\0name", '   '] as $input) {
        check(count(parseCommand($input)['errors']) > 0, $input);
    }
};
$tests['combined short flags and long aliases normalize'] = function () {
    same(['long' => true, 'all' => true, 'human-readable' => true], parseCommand('ls -lah')['options']);
    same(['all' => true, 'human-readable' => true], parseCommand('ls --all --human-readable')['options']);
    same(['parents' => true, 'mode' => '0700'], parseCommand('mkdir -pm0700 target')['options']);
};
$tests['last mode alias wins in either order'] = function () {
    same('0755', parseCommand('mkdir -m700 --mode=0755 a')['options']['mode']);
    same('0700', parseCommand('mkdir --mode=755 -m 0700 a')['options']['mode']);
};
$tests['end-of-options preserves dash-prefixed operands'] = function () {
    same(['-m', '--all'], parseCommand('mkdir -- -m --all')['args']);
};
$tests['invalid option spelling and unexpected values are rejected'] = function () {
    foreach (['ls --all=x', 'ls --l', 'ls --a', 'mkdir --p a', 'ls -z', 'unknown a'] as $input) {
        check(count(parseCommand($input)['errors']) > 0, $input);
    }
};
$tests['missing option values are rejected before creation'] = fn() => fixture(function ($base) {
    foreach (['mkdir target -m', 'mkdir target --mode', 'mkdir -m -- target'] as $command) {
        same(2, _mkdir(parseCommand($command), $base)['status']);
        check(!file_exists($base . '/target'));
    }
});
$tests['invalid modes and missing operands never create directories'] = fn() => fixture(function ($base) {
    foreach (['mkdir', 'mkdir -m888 bad', 'mkdir --mode=u=rwx bad', 'mkdir --mode= bad', 'mkdir -m"755\n" bad'] as $command) {
        same(2, _mkdir(parseCommand($command), $base)['status']);
        check(!file_exists($base . '/bad'));
    }
});
$tests['a malformed later operand prevents all mutations'] = fn() => fixture(function ($base) {
    same(2, _mkdir(parseCommand('mkdir first ""'), $base)['status']);
    check(!file_exists($base . '/first'));
    same(2, _mkdir(['args' => ['first', "bad\0name"]], $base)['status']);
    check(!file_exists($base . '/first'));
});
$tests['programmatic input validates values and obsolete option fields'] = function () {
    same(2, _mkdir(['args' => ['x'], 'options' => ['mode' => null]])['status']);
    same(2, ls(['options' => ['long' => 'yes']])['status']);
    same(2, ls(['flags' => ['l']])['status']);
    same(2, ls(parseCommand('mkdir x'))['status']);
};
$tests['URL wrappers and empty paths are rejected'] = function () {
    foreach (['php://memory', 'file:///tmp', ''] as $path) {
        same(2, ls(['args' => [$path]])['status']);
        same(2, _mkdir(['args' => [$path]])['status']);
    }
};
$tests['both commands use the explicit cwd, independent of sessions'] = fn() => fixture(function ($base) {
    $_SESSION['cwd'] = $base . '/not-the-working-directory';
    $cwd = getcwd();
    ob_start();
    $created = _mkdir(parseCommand('mkdir alpha'), $base);
    $listed = ls(parseCommand('ls'), $base);
    same('', ob_get_clean());
    same(0, $created['status']);
    same(['alpha'], names($listed));
    same($cwd, getcwd());
    unset($_SESSION);
});
$tests['absolute operands are not prefixed with cwd'] = fn() => fixture(function ($base) {
    same(0, _mkdir(['args' => [$base . '/absolute']], __DIR__)['status']);
    same(0, ls(['args' => [$base . '/absolute']], __DIR__)['status']);
});
$tests['default cwd uses getcwd without requiring a session'] = fn() => fixture(function ($base) {
    chdir($base);
    same(0, _mkdir(parseCommand('mkdir default'))['status']);
    same(['default'], names(ls(parseCommand('ls'))));
});
$tests['invalid cwd is a controlled error'] = fn() => fixture(function ($base) {
    same(1, ls(parseCommand('ls'), $base . '/missing')['status']);
    same(1, _mkdir(parseCommand('mkdir no'), $base . '/missing')['status']);
});
$tests['mkdir reports partial success and continues after a filesystem error'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/file', 'x');
    $result = _mkdir(parseCommand('mkdir file/child good'), $base);
    same(1, $result['status']);
    same(1, count($result['errors']));
    same([$base . '/good'], $result['data']['created']);
});
$tests['mkdir existing directory requires parents option'] = fn() => fixture(function ($base) {
    mkdir($base . '/existing');
    same(1, _mkdir(parseCommand('mkdir existing'), $base)['status']);
    $result = _mkdir(parseCommand('mkdir -p existing'), $base);
    same(0, $result['status']);
    same([], $result['data']['created']);
    same([$base . '/existing'], $result['data']['existing']);
});
$tests['default mkdir respects umask'] = fn() => fixture(function ($base) {
    nativePermissionSupport($base);
    umask(0077);
    same(0, _mkdir(parseCommand('mkdir private'), $base)['status']);
    same(0700, modeOf($base . '/private'));
    same(0077, umask());
});
$tests['explicit mode overrides umask only for the final directory'] = fn() => fixture(function ($base) {
    nativePermissionSupport($base);
    umask(0022);
    same(0, _mkdir(parseCommand('mkdir -p -m0700 parent/child'), $base)['status']);
    same(0755, modeOf($base . '/parent'));
    same(0700, modeOf($base . '/parent/child'));
    umask(0077);
    same(0, _mkdir(parseCommand('mkdir -m0770 exact'), $base)['status']);
    same(0770, modeOf($base . '/exact'));
});
$tests['existing parents and final directories retain their permissions'] = fn() => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'POSIX permission semantics are unavailable on Windows');
    mkdir($base . '/existing');
    chmod($base . '/existing', 0750);
    same(0, _mkdir(parseCommand('mkdir -p -m0700 existing existing/new'), $base)['status']);
    same(0750, modeOf($base . '/existing'));
    same(0700, modeOf($base . '/existing/new'));
});
$tests['recursive parents keep owner write/search under restrictive umask'] = fn() => fixture(function ($base) {
    nativePermissionSupport($base);
    umask(0777);
    same(0, _mkdir(parseCommand('mkdir -p -m0700 restrictive/child'), $base)['status']);
    same(0300, modeOf($base . '/restrictive'));
    same(0700, modeOf($base . '/restrictive/child'));
});
$tests['recursive creation and dash-prefixed names work'] = fn() => fixture(function ($base) {
    $result = _mkdir(parseCommand('mkdir --parents a/b/c -- -dash'), $base);
    same(0, $result['status']);
    same(4, count($result['data']['created']));
    check(is_dir($base . '/a/b/c'));
    check(is_dir($base . '/-dash'));
});
$tests['ls filters hidden entries and supports all'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/visible', 'x');
    file_put_contents($base . '/.hidden', 'x');
    same(['visible'], names(ls(parseCommand('ls'), $base)));
    same(['.', '..', '.hidden', 'visible'], names(ls(parseCommand('ls -a'), $base)));
});
$tests['directory grouping retains sorted order within groups'] = fn() => fixture(function ($base) {
    foreach (['z-dir', 'b-dir'] as $dir) mkdir($base . '/' . $dir);
    foreach (['a-file', 'y-file'] as $file) file_put_contents($base . '/' . $file, 'x');
    same(['b-dir', 'z-dir', 'a-file', 'y-file'], names(ls(parseCommand('ls --group-directories-first'), $base)));
});
$tests['single directory long listing always has a total'] = fn() => fixture(function ($base) {
    same("total 0\n", coreutilsText(ls(parseCommand('ls -l'), $base)));
});
$tests['standalone file blocks do not contaminate directory totals'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/large', str_repeat('x', 32768));
    mkdir($base . '/empty');
    $result = ls(parseCommand('ls -l large empty'), $base);
    same(0, $result['data']['directories'][0]['blocks']);
    check(strpos(coreutilsText($result), "empty:\ntotal 0\n") !== false);
});
$tests['separate directory totals agree with their metadata'] = fn() => fixture(function ($base) {
    mkdir($base . '/a'); mkdir($base . '/b');
    file_put_contents($base . '/a/one', str_repeat('x', 8192));
    file_put_contents($base . '/b/two', 'y');
    $result = ls(parseCommand('ls -l a b'), $base);
    foreach ($result['data']['directories'] as $dir) {
        if ($dir['entries'][0]['blocks'] !== null) same($dir['entries'][0]['blocks'], $dir['blocks']);
        else same(null, $dir['blocks']);
    }
});
$tests['broken and ordinary links retain link metadata'] = fn() => fixture(function ($base) {
    symlinkSupport($base);
    file_put_contents($base . '/target', str_repeat('x', 100));
    symlink('target', $base . '/link');
    symlink('missing', $base . '/broken');
    $result = ls(parseCommand('ls -l link broken'), $base);
    same(0, $result['status']);
    foreach ($result['data']['files'] as $entry) same('l', $entry['permissions'][0]);
    check(strpos(coreutilsText($result), 'broken -> missing') !== false);
    check(strpos(coreutilsText($result), 'link -> target') !== false);
    same(6, $result['data']['files'][1]['size']);
});
$tests['directory symlinks follow only in short listing or with trailing slash'] = fn() => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/target'); file_put_contents($base . '/target/item', 'x');
    symlink('target', $base . '/link');
    same(['item'], names(ls(parseCommand('ls link'), $base)));
    same('l', ls(parseCommand('ls -l link'), $base)['data']['files'][0]['permissions'][0]);
    same(['item'], names(ls(parseCommand('ls -l link/'), $base)));
});
$tests['path resolution preserves symlink/.. semantics'] = fn() => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/real'); mkdir($base . '/real/child');
    file_put_contents($base . '/real/marker', 'ok');
    symlink('real/child', $base . '/link');
    $result = ls(parseCommand('ls -l link/../marker'), $base);
    same(0, $result['status']);
    same(2, $result['data']['files'][0]['size']);
});
$tests['mkdir follows directory links and rejects dangling links'] = fn() => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/real'); symlink('real', $base . '/link'); symlink('absent', $base . '/broken');
    same(0, _mkdir(parseCommand('mkdir -p link/new'), $base)['status']);
    check(is_dir($base . '/real/new'));
    same(1, _mkdir(parseCommand('mkdir -p broken'), $base)['status']);
    check(is_link($base . '/broken'));
});
$tests['ls errors are reusable and do not leak into a later call'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/present', 'ok');
    $first = ls(parseCommand('ls -l missing present'), $base);
    same(1, $first['status']);
    same(1, count($first['data']['files']));
    $second = ls(parseCommand('ls'), $base);
    same(0, $second['status']);
    same(['present'], names($second));
});
$tests['unreadable directory returns an error without breaking the caller'] = fn() => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'POSIX permission semantics are unavailable on Windows');
    mkdir($base . '/denied'); chmod($base . '/denied', 0000);
    $readable = coreutilsFsCall(fn() => scandir($base . '/denied'));
    skipUnless($readable === false, 'process can bypass filesystem permissions');
    $result = ls(parseCommand('ls -l denied'), $base);
    same(1, $result['status']);
    same(false, $result['data']['directories'][0]['readable']);
});
$tests['HTML renderer escapes errors and preserves raw result data'] = fn() => fixture(function ($base) {
    $result = ls(['args' => ['<img src=x onerror=alert(1)>']], $base);
    $html = coreutilsHtml($result);
    check(strpos($html, '<img') === false);
    check(strpos($html, '&lt;img') !== false);
    check(strpos($result['errors'][0]['message'], '<img') !== false);
});
$tests['HTML renderer escapes real file names and link targets'] = fn() => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'Windows forbids angle brackets in filenames');
    $name = '<img src=x onerror=alert(1)>';
    file_put_contents($base . '/' . $name, 'x');
    $result = ls(parseCommand('ls -l'), $base);
    same($name, names($result)[0]);
    check(strpos(coreutilsHtml($result), '<img') === false);
    check(strpos(coreutilsHtml($result), '&lt;img') !== false);
    symlinkSupport($base);
    symlink($name, $base . '/link');
    check(strpos(coreutilsHtml(ls(parseCommand('ls -l link'), $base)), '<img') === false);
});
$tests['text renderer quotes embedded newlines'] = fn() => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'Windows forbids newline filenames');
    file_put_contents($base . "/a\nb", 'x');
    same('"a\\nb"' . "\n", coreutilsText(ls(parseCommand('ls'), $base)));
});
$tests['long output works without assuming intl or POSIX'] = fn() => fixture(function ($base) {
    file_put_contents($base . '/data', 'x');
    foreach (['ls -l', 'ls -o', 'ls -g', 'ls -lG', 'ls -go', 'ls -lh', 'ls -l --si'] as $command) {
        $result = ls(parseCommand($command), $base, 'pt_BR');
        same(0, $result['status']);
        check(strpos(coreutilsText($result), 'data') !== false);
    }
});
$tests['last human-size option wins and scaling is deterministic'] = function () {
    same('1.0K', coreutilsHumanSize(1024));
    same('1.5K', coreutilsHumanSize(1536));
    same('1.1K', coreutilsHumanSize(1025));
    same('1.0k', coreutilsHumanSize(1000, true));
    same('human-readable', coreutilsLsSettings(parseCommand('ls --si -h')['options'])['size']);
    same('si', coreutilsLsSettings(parseCommand('ls -h --si')['options'])['size']);
};
$tests['help succeeds without operands or filesystem access'] = function () {
    foreach (['ls' => 'ls', 'mkdir' => '_mkdir'] as $command => $function) {
        $result = $function(parseCommand($command . ' --help'), '/does-not-exist');
        same(0, $result['status']);
        check(strpos(coreutilsText($result), 'Usage: ' . $command) === 0);
    }
};
$tests['filesystem warning capture restores the caller error handler'] = function () {
    $called = false;
    set_error_handler(function () use (&$called) { $called = true; return true; });
    try {
        try {
            coreutilsFsCall(function () { throw new RuntimeException('probe'); });
        } catch (RuntimeException $expected) {}
        trigger_error('handler probe', E_USER_WARNING);
        same(true, $called);
    } finally {
        restore_error_handler();
    }
};
$tests['Unix cwd ending in a backslash remains intact'] = fn() => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'Unix filename test');
    mkdir($base . '/tail\\');
    same(0, _mkdir(parseCommand('mkdir inside'), $base . '/tail\\')['status']);
    check(is_dir($base . '/tail\\/inside'));
});

require __DIR__ . '/mv.php';
require __DIR__ . '/cp.php';
require __DIR__ . '/removal.php';
require __DIR__ . '/find.php';

$passed = $failed = $skipped = 0;
foreach ($tests as $name => $test) {
    try {
        $test();
        $passed++;
        echo "PASS $name\n";
    } catch (SkippedTest $error) {
        $skipped++;
        echo "SKIP $name: {$error->getMessage()}\n";
    } catch (Throwable $error) {
        $failed++;
        echo "FAIL $name: {$error->getMessage()} ({$error->getFile()}:{$error->getLine()})\n";
    }
}
echo "PHP " . PHP_VERSION . ": $passed passed, $failed failed, $skipped skipped\n";
exit($failed ? 1 : 0);
