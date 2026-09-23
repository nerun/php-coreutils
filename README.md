# PHP Coreutils

A lightweight, pure-PHP implementation of classic Unix core utilities.

## Purpose

PHP Coreutils provides familiar file operations in environments where shell
access is limited or unavailable, including shared hosting and web-based file
managers. It never executes a shell or calls external programs.

The goal is practical, predictable behavior: do one thing well, keep interfaces
simple, and let applications compose the returned data. This is a library, not
a shell emulator or a complete GNU Coreutils replacement.

Currently implemented: **ls**, **mkdir**, **mv**, **cp**, **rm** and **rmdir**.

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

Alternatively, include each command file (`src/ls.php`, `src/mkdir.php`, `src/mv.php`,
`src/cp.php`, `src/rm.php` or `src/rmdir.php`) individually. For text or
HTML formatting, also include `src/lib/format.php`.

## Usage

All commands accept an input array and an optional working directory. Relative
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
`mkdir()` function. Similarly, `_rmdir()` avoids the native `rmdir()` name.
No command prints anything on its own.

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
| `command` | `ls`, `mkdir`, `mv`, `cp`, `rm` or `rmdir`. |
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

* `mv`: `data.moved` contains `source` and `destination` absolute path pairs;
  `data.skipped` adds `reason: destination-exists` for destinations skipped by `-n`.

* `cp`: `data.copied` lists copied files and links as `source` / `destination`
  absolute path pairs; `data.created` lists newly created directories using the
  same pairs; `data.skipped` adds `reason: destination-exists` for `-n` skips.
  A created directory is recorded even if copying one of its children fails.

* `rm` / `rmdir`: `data.removed` lists absolute paths actually deleted, in
  deletion order (children before their directory). `data.skipped` contains
  `{path, reason: not-found}` entries for missing paths ignored by `rm -f`.

Syntax and option errors are checked before filesystem changes. Filesystem
errors do not roll back earlier changes: remaining operands are still tried.
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
| mv | `-f`, `--force` | `force` |
| mv | `-n`, `--no-clobber` | `no-clobber` |
| mv | `-v`, `--verbose` | `verbose` |
| cp | `-r`, `--recursive` | `recursive` |
| cp | `-n`, `--no-clobber` | `no-clobber` |
| cp | `-v`, `--verbose` | `verbose` |
| rm | `-r`, `--recursive` | `recursive` |
| rm | `-f`, `--force` | `force` |
| rm | `-v`, `--verbose` | `verbose` |
| rmdir | `-p`, `--parents` | `parents` |
| rmdir | `-v`, `--verbose` | `verbose` |
| all | `--help` | `help` |

Boolean canonical options accept `true` or `false`. `mode` accepts a string of
three or four octal digits; symbolic modes are not implemented. The last
occurrence of a mode alias wins. The last enabled `-h` or `--si` selects the size
format; for programmatic options, array insertion order determines precedence.

Without `-m`, creation uses `0777` filtered by the process umask. With `-m`, the
explicit mode is applied only to a newly created final directory. Missing
parents use default permissions, with owner write/search access ensured.
Existing directories are never chmodded. Windows permissions follow PHP and
Windows semantics; Unix permission bits cannot provide equivalent guarantees.

## Moving and renaming

```php
$result = mv(parseCommand('mv old.txt new.txt'), $cwd);
$result = mv(parseCommand('mv -nv file.txt folder/'), $cwd);
$result = mv(parseCommand('mv one.txt two.txt folder/'), $cwd);
echo coreutilsHtml($result);
```

Two operands rename an entry or move it into an existing destination directory.
Multiple sources require an existing directory. Files, populated directories
and symbolic links are supported; source links themselves are moved. Relative
link targets remain unchanged and may resolve differently after moving.
Existing files are replaced by default. `-n` skips existing destinations
(including broken links) with status 0; the last enabled `-f` or `-n` wins.
`-f` does not bypass permissions. Successful moves are silent unless `-v` is set.
An earlier destination from the same call will not be overwritten again.

This version uses PHP `rename()` on the same filesystem only. Cross-filesystem
moves return an error without a copy/delete fallback. Existing nonempty
directories, self-moves, and moving a directory into itself are rejected.
A trailing slash on a source symlink is rejected to avoid dereferencing it.
Overwrite behavior and permissions follow the host OS; Windows can impose
additional restrictions. Interactive `-i` is not implemented. The `-n` existence
check is not atomic against concurrent filesystem changes; applications must
serialize conflicting operations when that guarantee is needed.

## Copying

```php
$result = cp(parseCommand('cp original.txt backup.txt'), $cwd);
$result = cp(parseCommand('cp one.txt two.txt backups/'), $cwd);
$result = cp(parseCommand('cp -rnv documents backups/'), $cwd);
echo coreutilsHtml($result);
```

`cp()` keeps the source intact. Two operands copy to a new name or into an
existing destination directory. Multiple sources require an existing directory.
Directories require `-r` / `--recursive`; hidden entries and empty directories
are included, and existing destination directories are merged. Missing parents
of the top-level destination are not automatically created.

Existing regular files are overwritten by default. `-n` skips existing leaves
with status 0 while still merging directories; `-v` reports copied files/links
and newly created directories. Without `-v`, successful copies are silent.
Same-file copies (including hard links), copying a directory into itself, and
replacing an earlier file copied in the same call are rejected.

Without `-r`, source symlinks to regular files are followed. With `-r`, source
symlinks themselves are copied, including broken links and links to directories;
their target text is unchanged. Destination leaf symlinks are rejected (or
skipped with `-n`), rather than written through or replaced. An explicitly
supplied destination directory link is followed, but links encountered in the
destination tree are not traversed. Type collisions and special files such as
FIFOs, sockets and devices are rejected. Slash-suffixed source links and source
operands ending in `.`, `..`, or the filesystem root are not supported; supply
a named source entry.

Copies may cross filesystems, subject to host permissions. Metadata preservation
(`-p` / `-a`), `-f`, `-i`, `-R`, ACLs and extended attributes are not implemented.
New directories use `0777` filtered by umask; file creation permissions follow
PHP `copy()` and the host. Ownership, timestamps and source modes are not
explicitly preserved. Existing directory permissions remain unchanged.

Copies are not transactional: an I/O failure can leave a partial destination;
completed copies are not rolled back and remaining entries are still attempted.
Existence and identity checks are not atomic against concurrent filesystem
changes. Applications must serialize conflicting operations when necessary.

## Removing files and directories

```php
$result = rm(parseCommand('rm obsolete.txt'), $cwd);
$result = rm(parseCommand('rm -rv old-backup'), $cwd);
$result = _rmdir(parseCommand('rmdir empty-folder'), $cwd);
$result = _rmdir(parseCommand('rmdir -pv empty/parent/child'), $cwd);
echo coreutilsHtml($result);
```

`rm()` removes files and symbolic links. Directories require `-r` /
`--recursive`, which includes hidden entries and removes children before the
parent directory. Final symbolic links are unlinked, including broken links
and links to directories; recursion does not follow them. Intermediate path
components still follow the filesystem's normal symlink semantics. A trailing
slash on a file or symlink is rejected, rather than dereferencing it.

`-f` / `--force` accepts missing operands and ignores paths proven absent by
listing an accessible parent. Permission errors, invalid paths and attempts to
remove a directory without `-r` remain errors. If absence cannot be established
(for example because a parent cannot be listed), an error is returned.

`_rmdir()` only removes empty real directories. Files, symbolic links and
nonempty directories are errors. `-p` / `--parents` removes the requested
directory and then its empty parents; it stops before the explicit cwd, its
ancestors or the filesystem root. A nonempty parent stops that operand and
returns an error, while earlier removals remain recorded.

Both commands refuse root paths, final `.` / `..` components, the explicit
working directory and its ancestors. These protections cannot be disabled
with `-f`. They do not turn the working directory into a filesystem jail.
Both support multiple operands, `--`, `--help`, and `-v` / `--verbose`.
There are no interactive prompts; `-i`, `-R` and other unlisted GNU options
are not implemented. Successful removal is silent unless `-v` is enabled.

Deletion is permanent and is not rolled back on partial failure. Remaining
operands (and sibling entries during recursion) are still attempted. No chmod
or permission escalation is performed. Checks and removal are separate PHP
filesystem operations: callers must prevent concurrent changes to paths when
processing untrusted trees. Windows-specific filesystem semantics have not
been tested in the WASM test environment.

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
* Pass the same explicit `$cwd` to all commands. They no longer read
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
