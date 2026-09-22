# PHP Coreutils

A lightweight, pure-PHP implementation of classic Unix core utilities.

## Purpose

PHP Coreutils provides familiar file operations in environments where shell
access is limited or unavailable, including shared hosting and web-based file
managers. It never executes a shell or calls external programs.

The goal is practical, predictable behavior: do one thing well, keep interfaces
simple, and let applications compose the returned data. This is a library, not
a shell emulator or a complete GNU Coreutils replacement.

Currently implemented: **ls** and **mkdir**.

## Requirements

* PHP **8.0 or newer**. No Composer or third-party runtime packages are required.
* `intl` is optional. An explicit locale enables localized sorting and dates
  when the extension is available; otherwise sorting uses byte order and dates
  use English month names.
* `posix` is optional. The text formatter uses numeric UID/GID values when
  names cannot be looked up, including on Windows.

The library does not change the process locale, timezone, current directory or
umask, and does not read or start a session. It uses the application's timezone.

## Installation

```sh
git clone https://github.com/nerun/php-coreutils.git
```

Load all functions with:

```php
require_once __DIR__ . '/php-coreutils/src/bootstrap.php';
```

Alternatively, include `src/ls.php` or `src/mkdir.php` individually. For text or
HTML formatting, also include `src/lib/format.php`.

## Usage

Both commands accept an input array and an optional working directory. Relative
operands use that directory; absolute operands remain absolute. Omitting the
working directory uses `getcwd()`.

```php
$result = ls(parseCommand('ls -lah'), __DIR__);
echo coreutilsText($result);

$result = _mkdir(parseCommand('mkdir -p -m0700 backups/daily'), __DIR__);
if ($result['status'] !== 0) {
    echo coreutilsText($result);
}
```

`_mkdir()` keeps its original name to avoid a collision with PHP's native
`mkdir()` function. No command prints anything on its own.

For a web interface, use the HTML formatter. It escapes the entire output,
including names, link targets, and error messages:

```php
$cwd = $_SESSION['cwd'] ?? __DIR__; // Session management belongs to the application.
echo coreutilsHtml(ls(parseCommand('ls -l'), $cwd, 'pt_BR'));
```

The third argument to `ls()` is an optional locale. It does not modify
`setlocale()` or depend on an HTTP language header.

Applications can bypass command-string parsing and supply canonical options:

```php
$result = ls([
    'args' => ['.'],
    'options' => ['all' => true, 'long' => true],
], __DIR__);

if ($result['status'] === 0) {
    $entries = $result['data']['directories'][0]['entries'];
    $names = array_column($entries, 'name');
}

$result = _mkdir([
    'args' => ['private'],
    'options' => ['parents' => true, 'mode' => '0700'],
], __DIR__);
```

## Return values

Every command returns an array with these keys:

| Key | Meaning |
| --- | --- |
| `command` | `ls` or `mkdir`. |
| `status` | `0`: success; `1`: filesystem error, possibly with partial success; `2`: invalid input. |
| `data` | Structured results described below. Names and sizes are never HTML-escaped or preformatted. |
| `errors` | Errors containing `code`, `message`, and `path` (which can be `null`). |
| `options` | Validated canonical options. |
| `help` | Help text for `--help`, otherwise `null`. |

`ls()` also records the requested `locale` for the formatter.

* `ls`: `data.files` contains explicitly selected files and links;
  `data.directories` contains groups with `name`, `path`, `entries`, `blocks`,
  and `readable`. Each entry has `name`, `path`, `permissions`, `mode`, `nlink`,
  `uid`, `gid`, `size` (bytes), `mtime` (Unix timestamp), `blocks` (512-byte
  units, or `null` when unavailable), and `target` (link target or `null`).
* `mkdir`: `data.created` includes every directory actually created, including
  parents; `data.existing` lists requested directories accepted by `-p`.

Syntax and option errors are checked before filesystem changes. Filesystem
errors do not roll back earlier creations: remaining operands are still tried.
A directory can appear in `created` even if applying its explicit mode failed;
check `status` and `errors`. These status codes are the library contract, not a
promise of exact GNU exit-status compatibility.

`coreutilsText($result)` returns presentation text, including diagnostics.
`coreutilsHtml($result)` returns that text escaped inside a `<pre>` element.
Applications needing separate output/error channels should use `data` and
`errors` directly.

## Options

| Command | Short / long option | Canonical option |
| --- | --- | --- |
| ls | `-a`, `--all` | `all` |
| ls | `-l` | `long` |
| ls | `-g` (long format without owner) | `omit-owner` |
| ls | `-o` (long format without group) | `omit-group` |
| ls | `-G` (omit group, without selecting long format) | `no-group` |
| ls | `-h`, `--human-readable` (base 1024) | `human-readable` |
| ls | `--si` (base 1000) | `si` |
| ls | `--group-directories-first` | `group-directories-first` |
| mkdir | `-p`, `--parents` | `parents` |
| mkdir | `-m MODE`, `-mMODE`, `--mode MODE`, `--mode=MODE` | `mode` |
| both | `--help` | `help` |

Boolean canonical options accept `true` or `false`. `mode` accepts a string of
three or four octal digits; symbolic modes are not implemented. The last
occurrence of a mode alias wins. The last enabled `-h` or `--si` selects the size
format; for programmatic options, array insertion order determines precedence.

Without `-m`, creation uses `0777` filtered by the process umask. With `-m`, the
explicit mode is applied only to a newly created final directory. Missing
parents use default permissions, with owner write/search access ensured.
Existing directories are never chmodded. Windows permissions follow PHP and
Windows semantics; Unix permission bits cannot provide equivalent guarantees.

## Parsing and paths

`parseCommand()` returns `command`, `options`, `args`, and `errors`. It supports
single/double quotes, backslash escaping, grouped short options, whitespace
separators and `--` to end options. Empty quoted arguments are preserved and
rejected as filesystem paths; unmatched quotes, missing option values and
unexpected values are errors. It does not expand variables, wildcards or `~`,
or execute substitutions, pipelines or redirections.

Only local filesystem paths are accepted; stream-wrapper URLs and NUL bytes
are rejected. Windows absolute drive and UNC paths are supported; drive-relative
paths such as `C:folder` are rejected. Root-relative Windows paths use the
explicit working directory's drive/share. Relative paths containing `..` retain
filesystem symlink semantics.

`ls -l` reports symbolic links themselves, including broken links. A directory
link supplied to short `ls` is traversed; a trailing separator explicitly asks
for a directory. Links encountered within a directory retain their identity.
Each listed directory has its own long-format total, including empty directories;
`total ?` means block allocation information was unavailable.

Filesystem access remains subject to the PHP account's permissions and hosting
restrictions. The working directory is not a sandbox boundary: applications
must enforce their own allowed-path policy when accepting untrusted requests.

## Migration from the initial implementation

* Replace `ls($input)` used for immediate output with
  `echo coreutilsHtml(ls($input, $cwd))` in web pages, or use `coreutilsText()`.
* Pass the same explicit `$cwd` to both commands. They no longer read
  `$_SESSION['cwd']`.
* `parseCommand()` now returns canonical `options`, replacing `flags`,
  `longFlags`, and `flagsWithValue`. Reparse stored command strings or migrate
  manually constructed arrays; obsolete fields are rejected rather than ignored.
* Inspect the returned status/errors instead of relying on echoed diagnostics.
* Internal printing helpers, error constants, `setAppLocale()` and
  `CURRENT_LOCALE` have been replaced by side-effect-free helpers.

## Tests

```sh
php tests/run.php
php -n tests/run.php
```

The suite has no external dependencies. It covers parsing, paths, permissions,
links, totals, HTML escaping, partial failures and repeated calls. Unsupported
permission/symlink checks are reported as skipped, including when a runtime
cannot enforce Unix permissions or the process can bypass them. A failure exits
with status 1. Run on native PHP to validate actual operating-system semantics.

## License and contributions

MIT; see [LICENSE](LICENSE). Keep contributions small, focused and consistent
with the project's function-based design. External commands are never required
at runtime.
