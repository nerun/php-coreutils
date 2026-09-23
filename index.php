<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('pcre.jit', '0');

require_once __DIR__ . '/src/bootstrap.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['cwd']) || !is_string($_SESSION['cwd']) || $_SESSION['cwd'] === '') {
    $_SESSION['cwd'] = __DIR__;
}

$cwd = $_SESSION['cwd'];
session_write_close();

function escapeHtml(string $text): string {
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function prompt(string $input, string $cwd): void {
    echo '<div>$ ' . escapeHtml($input) . '</div>';

    $args = parseCommand($input);

    switch ($args['command']) {
        case 'ls':
            $result = ls($args, $cwd);
            break;

        case 'rm':
            $result = rm($args, $cwd);
            break;

        case 'rmdir':
            $result = _rmdir($args, $cwd);
            break;

        case 'cp':
            $result = cp($args, $cwd);
            break;

        case 'mv':
            $result = mv($args, $cwd);
            break;

        case 'mkdir':
            $result = _mkdir($args, $cwd);
            break;

        default:
            $result = coreutilsResult('nsh');
            $errors = $args['errors'] ?: [
                coreutilsError('nsh', 'unknown-command', 'command not found: ' . $args['command']),
            ];
            foreach ($errors as $error) {
                coreutilsAddError($result, $error, 2);
            }
            break;
    }

    // Os comandos retornam dados; o formatador produz a saída HTML com escape.
    echo coreutilsHtml($result);

    echo '<pre class="debug"><b>DEBUG: $args</b>' . "\n\n";
    echo escapeHtml(print_r($args, true));
    echo "\n<b>Status:</b> " . (int) $result['status'];
    echo '</pre>';
}

$locales = str_replace(';', ";\n", (string) setlocale(LC_ALL, 0));
?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>PHP Coreutils</title>
        <style>
            body {
                font-family: monospace;
                font-size: 12pt;
            }

            .debug {
                margin-left: 30px;
                background-color: #E1E1E1;
                padding: 10px;
                white-space: pre-wrap;
            }
        </style>
    </head>
    <body>
        <h1>SAÍDA</h1>

        <?php
            prompt('mkdir -p teste/a/c/d/e', $cwd);
            prompt('ls', $cwd);
        ?>

        <hr>
        <h1>DEBUG</h1>
        <div>CWD = <?= escapeHtml($cwd) ?></div>
        <pre><?= escapeHtml($locales) ?></pre>
    </body>
</html>
