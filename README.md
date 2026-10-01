# PHP Coreutils

A lightweight, pure-PHP implementation of classic Unix core utilities.

## Purpose

PHP Coreutils provides familiar file operations in environments where shell
access is limited or unavailable, including shared hosting and web-based file
managers. It never executes a shell or calls external programs.

The goal is practical, predictable behavior: do one thing well, keep interfaces
simple, and let applications compose the returned data. This is a library, not
a shell emulator or a complete GNU Coreutils replacement.

Currently implemented: **ls**, **mkdir**, **mv**, **cp**, **rm**, **rmdir**, **find**, **pwd**, **basename**, **dirname** and **touch**.

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
`src/cp.php`, `src/rm.php`, `src/rmdir.php`, `src/find.php`, `src/pwd.php`,
`src/basename.php`, `src/dirname.php` or `src/touch.php`) individually. For text or
HTML formatting, also include `src/lib/format.php`.

## Usage

All commands accept an input array and an optional working directory. Relative
operands use that directory; absolute operands remain absolute. Omitting the
working directory uses `getcwd()`. The text-only commands `basename` and
`dirname` accept the same argument but ignore it, without accessing the filesystem.

```php
$result = ls(parseCommand('ls -lah'), __DIR__);
echo coreutilsText($result);

$result = _mkdir(parseCommand('mkdir -p -m0700 backups/daily'), __DIR__);
if ($result['status'] !== 0) {
    echo coreutilsText($result);
}
```

`_mkdir()` keeps its original name to avoid a collision with PHP's native
`mkdir()` function. Similarly, `_rmdir()`, `_basename()`, `_dirname()` and `_touch()` avoid
collisions with the corresponding native PHP functions.
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
| `command` | `ls`, `mkdir`, `mv`, `cp`, `rm`, `rmdir`, `find`, `pwd`, `basename`, `dirname` or `touch`. |
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

* `find`: `data.entries` contains matching entries with `name` (displayed path),
  `path` (absolute lookup path), and `type` (file type letter).

* `pwd`: `data.path` contains the absolute working directory, or `null` on
  error or when displaying help.

* `basename` / `dirname`: `data.entries` contains `input` / `output` string
  pairs in operand order. The output is unquoted and has no record terminator.

* `touch`: `data.created` lists operand paths whose targets were absent before
  a successful touch; `data.updated` lists existing targets successfully touched.
  Both contain absolute operand paths, including link paths when supplied.
  `data.skipped` contains `{path, reason: not-found}` entries skipped by `-c`.

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
| find | `-type TYPES`, `--type TYPES`, `--type=TYPES` | `type` |
| find | `-name PATTERN`, `--name PATTERN`, `--name=PATTERN` | `name` |
| pwd | `-L`, `--logical` | `logical` |
| pwd | `-P`, `--physical` | `physical` |
| basename | `-a`, `--multiple` | `multiple` |
| basename | `-s SUFFIX`, `-sSUFFIX`, `--suffix SUFFIX`, `--suffix=SUFFIX` | `suffix` |
| basename / dirname | `-z`, `--zero` | `zero` |
| touch | `-a` | `access` |
| touch | `-m` | `modification` |
| touch | `-c`, `--no-create` | `no-create` |
| touch | `-r FILE`, `-rFILE`, `--reference FILE`, `--reference=FILE` | `reference` |
| touch | `-t STAMP`, `-tSTAMP` | `timestamp` |
| all | `--help` | `help` |

Boolean canonical options accept `true` or `false`. `mode` accepts a string of
three or four octal digits; symbolic modes are not implemented. The last
occurrence of a mode alias wins. The last enabled `-h` or `--si` selects the size
format; for programmatic options, array insertion order determines precedence.

For `mkdir`, without `-m`, creation uses `0777` filtered by the process umask. With `-m`, the
explicit mode is applied only to a newly created final directory. Missing
parents use default permissions, with owner write/search access ensured.
Existing directories are never chmodded. Windows permissions follow PHP and
Windows semantics; Unix permission bits cannot provide equivalent guarantees.

## Creating files and changing timestamps

```php
$result = _touch(parseCommand('touch notes.txt "draft copy.txt"'), __DIR__);
$result = _touch(parseCommand('touch -c -m notes.txt'), __DIR__);
$result = _touch(parseCommand('touch -r original.txt copy.txt'), __DIR__);
$result = _touch(parseCommand('touch -t202609301430.00 notes.txt'), __DIR__);
echo coreutilsText($result); // Empty on success; diagnostics on failure.
```

`touch` creates an empty file when its target does not exist. It never truncates
existing content, changes permissions, or creates missing parent directories.
Creation permissions follow PHP and the process umask (normally `0666 & ~umask`
on Unix). Existing directories can also have their timestamps updated.

By default, both access (`atime`) and modification (`mtime`) times are updated
to the current time. `-a` changes only access time; `-m` changes only modification
time. With both flags, both times change. On existing entries the unselected
time is preserved; on newly created files it uses the current time.

`-c` / `--no-create` skips proven missing targets without an error; inaccessible
paths, invalid traversal and link loops still produce diagnostics. `-r` / `--reference`
copies the selected times from a local file or directory, resolved against the
same cwd as the operands. The reference is read once before any changes, so a
missing reference produces status 1 without modifying operands, even with `-c`.

`-t` accepts `[[CC]YY]MMDDhhmm[.ss]` in the application's timezone: month, day,
hour and minute are required; seconds default to `00`. Eight digits use the
current year, ten digits use a two-digit year (`00`–`68`: 2000–2068;
`69`–`99`: 1969–1999), and twelve digits specify the four-digit year.
Invalid dates, including rollovers and leap-second values, return status 2
before filesystem changes. `-r` and `-t` cannot be combined; repeated reference
or timestamp options use the last value.

Symbolic links are followed, including reference links. A dangling link can
cause its target to be created; with `-c`, it is skipped and left intact.
Link timestamps themselves are not changed. Timestamp precision and range,
permissions and link behavior remain subject to PHP and the host filesystem;
timestamps are supplied as whole seconds. Classification in `data` uses the
target's state before the operation, not an atomic creation guarantee. The `-c`
existence check is also non-atomic; callers must coordinate concurrent changes.

`-d` / `--date`, `-h` / `--no-dereference`, `--time`, and GNU's special standard
output handling for the operand `-` are not implemented. Here `-` is a literal
filename. Neither birth time nor an arbitrary status-change time can be set.
The command produces no output itself and successful operations are silent
through the formatters. Use `data` to inspect created, updated or skipped paths.

## Basenames and directory names

```php
echo coreutilsText(_basename(parseCommand('basename /srv/docs/report.txt')));
// report.txt
echo coreutilsText(_basename(parseCommand('basename /srv/docs/report.txt .txt')));
// report
echo coreutilsText(_basename(parseCommand('basename -s .txt docs/a.txt docs/b.txt')));
// a and b, one per line
echo coreutilsText(_dirname(parseCommand('dirname /srv/docs/report.txt')));
// /srv/docs
echo coreutilsText(_dirname(parseCommand('dirname docs/a.txt backups/b.txt')));
// docs and backups, one per line
```

Both commands operate only on Unix path text. They never check existence, resolve
symbolic links, normalize `.` / `..`, or use `$cwd`. Only `/` separates components
on every platform; backslashes and drive prefixes are ordinary characters, not
native Windows path syntax. Quote literal backslashes when using `parseCommand()`.
Repeated leading slashes and all-slash paths use Linux-style root semantics:
`//` is treated as `/` when the result is a root, not a special network root.
Internal separators in a retained directory prefix are preserved.

`basename NAME [SUFFIX]` accepts one name and an optional literal suffix.
`-a` treats every operand as a name; `-s SUFFIX` also enables multiple names.
The suffix is case-sensitive and removed once, only if it matches the end of
the basename and would not remove the entire name. The last `-s` / `--suffix`
wins; an empty suffix removes nothing. Options must precede the first operand:
`basename file.txt -z` treats `-z` as a suffix, not an option. Use
`basename -z file.txt` to select NUL termination.

`dirname` accepts one or more names. Trailing slashes are ignored before removing
the last component. Names without a directory component yield `.`; root yields
`/`. Empty string operands are valid: `basename ""` returns an empty string and
`dirname ""` returns `.`. NUL bytes and nonstring operands are invalid input.
URL-like strings are accepted as text; no stream wrapper is ever opened.

Both commands support `--help` and `--`. Missing operands, invalid options or
malformed input return status 2 with no entries. They produce no output directly.
`coreutilsText()` returns each raw result followed by a newline, or NUL with
`-z`; use the latter for text consumers handling embedded newlines. The HTML
formatter escapes the output as usual; use newline mode for readable web output.

Canonical input uses the existing array interface:

```php
$result = _basename([
    'args' => ['docs/a.txt', 'docs/b.txt'],
    'options' => ['suffix' => '.txt'],
]);
$names = array_column($result['data']['entries'], 'output'); // ['a', 'b']
```

## Working directory

```php
$result = pwd(parseCommand('pwd -L'), $cwd);
echo coreutilsHtml($result);

$result = pwd(parseCommand('pwd -P'), $cwd);
$path = $result['data']['path'];

$result = pwd(['options' => ['logical' => true]], $cwd);
```

`pwd()` returns the supplied working directory, or the process directory from
`getcwd()` if `$cwd` is omitted. It does not change directories or read sessions.
The default is `-P`: an absolute physical path with symbolic links resolved.
`-L` preserves the explicit `$cwd` when it is absolute and has no `.` or `..`
components. Without an explicit `$cwd`, it uses the environment variable `PWD`
only if it meets those rules and resolves to the current process directory.
Missing, stale or invalid `PWD` values fall back to the physical path.

For example, if `/home/user/project` links to `/srv/project`, passing
`/home/user/project` as `$cwd` yields that path with `-L` and `/srv/project`
with `-P`. Relative working directories or paths containing `.` / `..` use
physical resolution in either mode, preserving the library's filesystem path
semantics. An invalid explicit `$cwd` produces status 1, not a fallback to `PWD`.

The last enabled `-L` / `-P` wins, including grouped flags such as `-PL`.
Programmatic options use array insertion order; `false` options are ignored.
The default is always physical, independent of `POSIXLY_CORRECT`.
Operands and invalid options produce status 2; `--help` needs no valid cwd.
Text output is the path followed by a newline, without quoting; HTML output
escapes it using the shared formatter. Native Windows path behavior has not
been validated in the WASM test environment.

## Finding entries

```php
$result = find(parseCommand('find . -type f,l'), $cwd);
echo coreutilsHtml($result);

$result = find(parseCommand('find documents backups -type d'), $cwd);
$result = find(parseCommand('find documents -type f,l -name "*.php"'), $cwd);
$result = find(parseCommand('find documents -name "*.php" -type f'), $cwd);
$result = find(['args' => ['.'], 'options' => ['type' => 'l']], $cwd);
```

`find()` recursively lists starting paths and their descendants. With no path,
it searches `.`. With no type filter, it includes all entry types. Hidden names
are included; `.` and `..` directory entries are not revisited. Traversal is
depth-first, with parents before children and siblings sorted in byte order.
Multiple starting paths are searched in operand order, without deduplication.

`-type f` selects regular files, `-type d` real directories, and `-type l`
symbolic links, including broken links. Comma-separated types are alternatives:
`-type f,l` selects files or links. Empty or unsupported types are invalid input.
All predicates are combined with AND, including repeated predicates:
`-type f,l -type l` selects only links, while `-type f -type d` matches nothing.
Each comma-separated type list uses OR; separate predicates must all match.

Paths must precede the first `-type` or `-name` predicate. For example,
`find documents -type f` is valid, but `find -type f documents` is an input
error. The predicates themselves may appear in either order. With no paths,
`find -type f` searches `.`. `--` ends option parsing but does not allow paths
after predicates. Use `find ./-folder -type f` for a filtered search of a
dash-prefixed path, or `find -- -folder` without filters.

`-name` matches only the entry's basename, not the full path or a link's target.
Matching is case-sensitive and uses PHP `fnmatch()` with shell patterns:
`*`, `?`, character sets/ranges (`[abc]`, `[0-9]`), negated sets (`[!a]`), and
backslash escapes. These are wildcard patterns, not regular expressions.
Quote patterns containing spaces; use single quotes to preserve literal
backslashes through command parsing. Leading dots are not special:
`-name '*.php'` also matches `.hidden.php`. An empty pattern matches no names.
Character matching follows the host runtime and its locale; the library does
not change the process locale.

Without `-name`, every name is accepted; without `-type`, every type is accepted.
Nonmatching directories are still traversed so their descendants can match.
Repeated name predicates must all match: `-name '*.php' -name 'test*'` selects
names beginning with `test` and ending with `.php`.

Canonical `type` and `name` options accept a string for one predicate or a
nonempty array of strings for repeated predicates. `parseCommand()` returns a
string for a single occurrence and an array for repeated occurrences:

```php
$result = find([
    'args' => ['documents'],
    'options' => ['type' => ['f,l', 'f'], 'name' => ['*.php', 'test*']],
], $cwd);
```

This selects regular files whose names match both patterns. Invalid predicates
produce status 2 before traversal; a valid search with no matches returns
status 0 and an empty `data.entries` array.

Links encountered during traversal, including starting links, are listed but
never traversed. A trailing separator on a starting link or file is rejected;
it must identify a real directory. Intermediate path components still follow
normal OS resolution rules. This is not a filesystem sandbox or an atomic
snapshot: applications must control access and concurrent filesystem changes.

`data.entries` contains matching entries with `name` (the displayed path,
preserving the relative or absolute starting operand), `path` (absolute lookup
path), and `type` (`f`, `d`, `l`, `p`, `c`, `b`, `s`, or `?` for unknown types).
Text output lists one quoted path per line using the existing name formatter;
HTML output is escaped. Filesystem failures produce status 1 and diagnostics,
while other pending entries and starting paths are still processed.

This subset does not implement `-iname`, `-L`, depth limits, explicit Boolean
operators (`-a`, `-o`, `!`, parentheses), `-exec`, or `-delete`.
Results are collected in memory.

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
rejected as filesystem paths (but accepted by `basename` and `dirname`);
unmatched quotes, missing option values and unexpected values are errors.
It does not expand variables, wildcards or `~`,
or execute substitutions, pipelines or redirections.

`find` requires paths before its predicates, and `basename` stops option parsing
at the first operand. Other commands allow options interspersed with operands.
Repeated options normally use the last value; `find` retains repeated `type`
and `name` predicates and combines them with AND.

For filesystem commands, only local paths are accepted; stream-wrapper URLs and
NUL bytes are rejected. Windows absolute drive and UNC paths are supported; drive-relative
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
