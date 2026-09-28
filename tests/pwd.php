<?php

$tests['pwd physical default and explicit cwd have no output or process changes'] = fn () => fixture(function ($base) {
    $cwd = getcwd();
    $environment = getenv('PWD');
    ob_start();
    $result = pwd(parseCommand('pwd'), $base);
    $output = ob_get_clean();
    same('', $output);
    same(0, $result['status']);
    same(realpath($base), $result['data']['path']);
    same($cwd, getcwd());
    same($environment, getenv('PWD'));
    same($result['data'], pwd(parseCommand('pwd -P'), $base)['data']);
    same(realpath($cwd), pwd(parseCommand('pwd'))['data']['path']);
    same(realpath($base), pwd(parseCommand('pwd --'), $base)['data']['path']);
});

$tests['pwd preserves logical directory links and resolves physical ones'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/real');
    mkdir($base . '/real/child');
    symlink('real', $base . '/alias');
    symlink('alias', $base . '/chain');
    $logical = $base . '/chain/child';
    foreach (['pwd -L', 'pwd --logical', 'pwd -PL', 'pwd -LPL', 'pwd --physical -L'] as $command) {
        $result = pwd(parseCommand($command), $logical);
        same(0, $result['status']);
        same($logical, $result['data']['path']);
    }
    foreach (['pwd', 'pwd -P', 'pwd --physical', 'pwd -LP', 'pwd -PLP', 'pwd --logical -P'] as $command) {
        same(realpath($logical), pwd(parseCommand($command), $logical)['data']['path']);
    }
    same($logical, pwd(['options' => ['physical' => true, 'logical' => true]], $logical)['data']['path']);
    same($logical, pwd(['options' => ['logical' => true, 'physical' => false]], $logical)['data']['path']);
    same(realpath($logical), pwd(['options' => ['logical' => false]], $logical)['data']['path']);
});

$tests['pwd logical uses PWD only when it identifies the current process directory'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/real');
    symlink('real', $base . '/alias');
    chdir($base . '/real');
    $previous = getenv('PWD');
    try {
        putenv('PWD=' . $base . '/alias');
        same($base . '/alias', pwd(parseCommand('pwd -L'))['data']['path']);
        same(realpath($base . '/real'), pwd(parseCommand('pwd -P'))['data']['path']);
        same($base, pwd(parseCommand('pwd -L'), $base)['data']['path']);
        same(1, pwd(parseCommand('pwd -L'), $base . '/missing')['status']);
        foreach (['', '.', 'alias', $base, $base . '/missing', $base . '/alias/.',
            $base . '/alias/../real', 'file://' . $base . '/real'] as $invalid) {
            putenv('PWD=' . $invalid);
            $result = pwd(parseCommand('pwd -L'));
            same(0, $result['status']);
            same(realpath($base . '/real'), $result['data']['path']);
            same($invalid, getenv('PWD'));
        }
        putenv('PWD');
        same(realpath($base . '/real'), pwd(parseCommand('pwd -L'))['data']['path']);
    } finally {
        putenv($previous === false ? 'PWD' : 'PWD=' . $previous);
    }
});

$tests['pwd relative and dot paths retain filesystem symlink semantics'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/real');
    mkdir($base . '/real/child');
    symlink('real/child', $base . '/alias');
    chdir($base);
    foreach (['pwd -L', 'pwd -P'] as $command) {
        same(realpath($base . '/real/child'), pwd(parseCommand($command), 'alias')['data']['path']);
        same(realpath($base . '/real'), pwd(parseCommand($command), $base . '/alias/..')['data']['path']);
        same(realpath($base . '/real/child'), pwd(parseCommand($command), $base . '/alias/.')['data']['path']);
    }
});

$tests['pwd rejects operands and malformed options before inspecting cwd'] = function () {
    foreach (['pwd extra', 'pwd -- -L', 'pwd -l', 'pwd --logical=yes', 'pwd -z', 'pwd ""'] as $command) {
        $result = pwd(parseCommand($command), '/does-not-exist');
        same(2, $result['status']);
        same(null, $result['data']['path']);
    }
    foreach ([['options' => ['logical' => 'yes']], ['options' => ['unknown' => true]],
        ['args' => [null]], ['flags' => ['L']], parseCommand('ls')] as $input) {
        same(2, pwd($input)['status']);
    }
};

$tests['pwd invalid cwd and broken links produce controlled reusable errors'] = fn () => fixture(function ($base) {
    file_put_contents($base . '/file', 'x');
    foreach (['', $base . '/missing', $base . '/file', "bad\0path", 'file://' . $base] as $directory) {
        foreach (['pwd -L', 'pwd -P'] as $command) {
            $result = pwd(parseCommand($command), $directory);
            same(1, $result['status']);
            same(null, $result['data']['path']);
            check(count($result['errors']) > 0);
            check(strpos(coreutilsText($result), 'pwd: ') === 0);
        }
    }
    same(0, pwd(parseCommand('pwd'), $base)['status']);
    symlinkSupport($base);
    symlink('missing', $base . '/broken');
    same(1, pwd(parseCommand('pwd -L'), $base . '/broken')['status']);
});

$tests['pwd repeated calls observe changed link targets'] = fn () => fixture(function ($base) {
    symlinkSupport($base);
    mkdir($base . '/one');
    mkdir($base . '/two');
    symlink('one', $base . '/alias');
    same(realpath($base . '/one'), pwd(parseCommand('pwd -P'), $base . '/alias')['data']['path']);
    unlink($base . '/alias');
    symlink('two', $base . '/alias');
    same(realpath($base . '/two'), pwd(parseCommand('pwd -P'), $base . '/alias')['data']['path']);
});

$tests['pwd text preserves spaces and HTML escapes the raw path'] = fn () => fixture(function ($base) {
    skipUnless(DIRECTORY_SEPARATOR !== '\\', 'Windows forbids angle brackets in filenames');
    $path = $base . '/<project & "docs">';
    mkdir($path);
    $result = pwd(parseCommand('pwd -L'), $path);
    same($path, $result['data']['path']);
    same($path . "\n", coreutilsText($result));
    check(strpos(coreutilsHtml($result), '<project') === false);
    check(strpos(coreutilsHtml($result), '&lt;project &amp; &quot;docs&quot;&gt;') !== false);
});

$tests['pwd logical handles roots and trailing separators'] = function () {
    $root = realpath(DIRECTORY_SEPARATOR);
    skipUnless($root !== false, 'filesystem root is inaccessible');
    foreach (['pwd -L', 'pwd -P'] as $command) {
        same($root, pwd(parseCommand($command), $root)['data']['path']);
    }
    fixture(function ($base) {
        same($base . '/', pwd(parseCommand('pwd -L'), $base . '/')['data']['path']);
        same(realpath($base), pwd(parseCommand('pwd -P'), $base . '/')['data']['path']);
    });
};

$tests['pwd help succeeds without filesystem access'] = function () {
    $result = pwd(parseCommand('pwd --help'), '/does-not-exist');
    same(0, $result['status']);
    same(null, $result['data']['path']);
    check(strpos(coreutilsText($result), 'Usage: pwd') === 0);
    check(strpos($result['help'], '-L, --logical') !== false);
    check(strpos($result['help'], '-P, --physical') !== false);
};
