<?php

# PHP Coreutils
# A lightweight, pure-PHP implementation of classic Unix core utilities,
# designed for portability and environments without shell access.
#
# The MIT License
#
# Copyright (c) 2026 Daniel Dias Rodrigues
#
# Permission is hereby granted, free of charge, to any person obtaining a
# copy of this software and associated documentation files (the
# "Software"), to deal in the Software without restriction, including
# without limitation the rights to use, copy, modify, merge, publish,
# distribute, sublicense, and/or sell copies of the Software, and to
# permit persons to whom the Software is furnished to do so, subject to
# the following conditions:
#
# The above copyright notice and this permission notice shall be included
# in all copies or substantial portions of the Software.
#
# THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
# OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
# MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.
# IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY
# CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT,
# TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE
# SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

/** Canonical name => [short spelling, long spelling, requires a value]. */
function coreutilsOptionDefinitions(string $command): array
{
    $common = ['help' => [null, 'help', false]];
    if ($command === 'du') {
        return [
            'all' => ['a', 'all', false],
            'summarize' => ['s', 'summarize', false],
            'total' => ['c', 'total', false],
            'human-readable' => ['h', 'human-readable', false],
            'si' => [null, 'si', false],
            'bytes' => ['b', 'bytes', false],
            'apparent-size' => [null, 'apparent-size', false],
            'block-size' => ['B', 'block-size', true],
            'kibibytes' => ['k', null, false],
            'mebibytes' => ['m', null, false],
            'max-depth' => ['d', 'max-depth', true],
            'dereference' => ['L', 'dereference', false],
            'dereference-args' => ['D', 'dereference-args', false],
            'dereference-args-alias' => ['H', null, false],
            'no-dereference' => ['P', 'no-dereference', false],
            'count-links' => ['l', 'count-links', false],
            'separate-dirs' => ['S', 'separate-dirs', false],
            'one-file-system' => ['x', 'one-file-system', false],
            'null' => ['0', 'null', false],
        ] + $common;
    }
    if ($command === 'chmod') {
        return [
            'recursive' => ['R', 'recursive', false],
            'verbose' => ['v', 'verbose', false],
            'changes' => ['c', 'changes', false],
            'reference' => [null, 'reference', true],
            'mode' => [null, null, true],
        ] + $common;
    }
    if ($command === 'stat') {
        return [
            'dereference' => ['L', 'dereference', false],
            'format' => ['c', 'format', true],
        ] + $common;
    }
    if ($command === 'echo') {
        return [
            'no-newline' => ['n', null, false],
            'escapes' => ['e', null, false],
            'literal' => ['E', null, false],
            'version' => [null, 'version', false],
            'posixly-correct' => [null, null, false],
        ] + $common;
    }
    if ($command === 'cat') {
        return [
            'number' => ['n', 'number', false],
            'number-nonblank' => ['b', 'number-nonblank', false],
            'squeeze-blank' => ['s', 'squeeze-blank', false],
            'show-ends' => ['E', 'show-ends', false],
            'show-tabs' => ['T', 'show-tabs', false],
        ] + $common;
    }
    if (in_array($command, ['head', 'tail'], true)) {
        return [
            'lines' => ['n', 'lines', true],
            'bytes' => ['c', 'bytes', true],
            'quiet' => ['q', 'quiet', false],
            'silent' => [null, 'silent', false],
            'verbose' => ['v', 'verbose', false],
            'zero-terminated' => ['z', 'zero-terminated', false],
        ] + $common;
    }
    if ($command === 'touch') {
        return [
            'access' => ['a', null, false],
            'modification' => ['m', null, false],
            'no-create' => ['c', 'no-create', false],
            'reference' => ['r', 'reference', true],
            'timestamp' => ['t', null, true],
        ] + $common;
    }
    if ($command === 'basename') {
        return [
            'multiple' => ['a', 'multiple', false],
            'suffix' => ['s', 'suffix', true],
            'zero' => ['z', 'zero', false],
        ] + $common;
    }
    if ($command === 'dirname') {
        return ['zero' => ['z', 'zero', false]] + $common;
    }
    if ($command === 'pwd') {
        return [
            'logical' => ['L', 'logical', false],
            'physical' => ['P', 'physical', false],
        ] + $common;
    }
    if ($command === 'find') {
        return [
            'type' => ['type', 'type', true],
            'name' => ['name', 'name', true],
        ] + $common;
    }
    if ($command === 'ls') {
        return [
            'all' => ['a', 'all', false],
            'long' => ['l', null, false],
            'omit-owner' => ['g', null, false],
            'omit-group' => ['o', null, false],
            'no-group' => ['G', null, false],
            'human-readable' => ['h', 'human-readable', false],
            'si' => [null, 'si', false],
            'group-directories-first' => [null, 'group-directories-first', false],
        ] + $common;
    }
    if ($command === 'mkdir') {
        return [
            'parents' => ['p', 'parents', false],
            'mode' => ['m', 'mode', true],
        ] + $common;
    }
    if ($command === 'rm') {
        return [
            'recursive' => ['r', 'recursive', false],
            'force' => ['f', 'force', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    if ($command === 'rmdir') {
        return [
            'parents' => ['p', 'parents', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    if ($command === 'cp') {
        return [
            'recursive' => ['r', 'recursive', false],
            'no-clobber' => ['n', 'no-clobber', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    if ($command === 'mv') {
        return [
            'force' => ['f', 'force', false],
            'no-clobber' => ['n', 'no-clobber', false],
            'verbose' => ['v', 'verbose', false],
        ] + $common;
    }
    return [];
}

function coreutilsHelp(string $command): string
{
    if ($command === 'du') {
        return "Usage: du [OPTION]... [FILE]...\n"
            . "Estimate space usage recursively; the default operand is .\n\n"
            . "  -a, --all              include files as well as directories\n"
            . "  -s, --summarize        show only each operand's total\n"
            . "  -c, --total            append a grand total\n"
            . "  -h, --human-readable   scale by 1024; --si scales by 1000\n"
            . "  -b, --bytes            apparent size in bytes\n"
            . "      --apparent-size    use logical size instead of allocated blocks\n"
            . "  -B, --block-size SIZE  positive integer with optional K/M/G/T/P/E, KB or KiB unit\n"
            . "  -k / -m                use 1024 / 1048576 byte units\n"
            . "  -d, --max-depth N      limit printed depth; still count deeper entries\n"
            . "  -L, --dereference      follow all symbolic links\n"
            . "  -D, -H, --dereference-args  follow only explicit link operands\n"
            . "  -P, --no-dereference   count symbolic links themselves (default)\n"
            . "  -l, --count-links      count repeated inodes; directory cycles are skipped\n"
            . "  -S, --separate-dirs    exclude subdirectories from directory rows\n"
            . "  -x, --one-file-system  do not enter directories on other devices\n"
            . "  -0, --null             terminate output rows with NUL\n"
            . "      --help             show this help\n"
            . "      --                 end options\n\n"
            . "Default units are 1024 bytes, rounded up; environment block sizes are ignored.\n"
            . "Last scaling and link options win; -b always enables apparent size.\n"
            . "Repeated inodes are counted once unless -l; hidden entries are included.\n"
            . "Directory rows follow their children in byte-sorted order. No contents are read.\n"
            . "Missing allocated-block metadata is ? with status 1; use -b on such platforms.\n"
            . "Unknown or overflowing sizes propagate as ?; filesystem failures retain partial totals.\n"
            . "Summarize conflicts with --all and nonzero --max-depth. No shell is executed.\n";
    }
    if ($command === 'chmod') {
        return "Usage: chmod [OPTION]... MODE FILE...\n"
            . "   or: chmod [OPTION]... --reference=FILE FILE...\n"
            . "Change permission bits using octal or symbolic modes.\n\n"
            . "  -R, --recursive       change directories and their contents\n"
            . "  -v, --verbose         report every successful operation\n"
            . "  -c, --changes         report only actual mode changes; last -v/-c wins\n"
            . "      --reference FILE  copy the referenced target's permission bits\n"
            . "      --help             show this help\n"
            . "      --                 end options; needed before modes such as -w\n\n"
            . "Modes: 755, 0644, +110, =755, u+x, go-w, a=rw, a+rwX, g=u.\n"
            . "Symbolic clauses use u/g/o/a, +/-/=, rwxXst or one copy source u/g/o.\n"
            . "Omitted u/g/o/a respects umask; directory setuid/setgid bits follow GNU rules.\n"
            . "Explicit links are followed; nested links are skipped. Directory access is granted\n"
            . "before traversal; restrictions are deferred until children are processed.\n"
            . "Recursive filesystem roots are refused. No shell is executed.\n";
    }
    if ($command === 'stat') {
        return "Usage: stat [OPTION]... FILE...\n"
            . "Display entry metadata without reading file contents.\n\n"
            . "  -L, --dereference     inspect targets instead of symbolic links\n"
            . "  -c, --format FORMAT   use FORMAT, followed by a newline for each entry\n"
            . "      --help             show this help\n"
            . "      --                 end options\n\n"
            . "Formats: %n %N %s %a %A %f %F %u %U %g %G %h %i %d %D %r %R\n"
            . "         %b %B %o %x %X %y %Y %z %Z %w %W %%.\n"
            . "Field widths up to 8192, left alignment (-) and zero padding (0) are supported.\n"
            . "Unavailable block information is ?; birth time is - / 0 (not exposed by PHP).\n"
            . "Times use whole seconds and the application's timezone; ctime is status change.\n"
            . "Backslashes in FORMAT stay literal. Filesystem statistics and --printf are not supported.\n";
    }
    if ($command === 'echo') {
        return "Usage: echo [SHORT-OPTION]... [STRING]... [> FILE | >> FILE]\n"
            . "   or: echo --help | --version\n"
            . "Join strings with spaces and append a newline by default.\n\n"
            . "  -n          omit the trailing newline\n"
            . "  -e          interpret backslash escapes\n"
            . "  -E          keep backslash escapes literal (default; last -e/-E wins)\n"
            . "  --help      show help when it is the sole argument\n"
            . "  --version   identify this PHP implementation when it is the sole argument\n\n"
            . 'Escapes: \\a \\b \\c \\e \\f \\n \\r \\t \\v \\\\ \\0NNN \\NNN \\xHH' . "\n"
            . 'Octal uses up to 3 digits, hexadecimal up to 2; \\c stops all output.' . "\n"
            . "Only leading clusters of n/e/E are options; -- and unknown options are text.\n"
            . "Unquoted > overwrites; >> appends. Only one local regular-file target is supported.\n"
            . "Quoted or escaped > characters are text. No shell is executed.\n"
            . "POSIXLY_CORRECT enables escapes and restricts option recognition as in GNU echo.\n";
    }
    if ($command === 'cat') {
        return "Usage: cat [OPTION]... FILE...\n"
            . "Concatenate local regular files in operand order.\n\n"
            . "  -n, --number           number all output lines\n"
            . "  -b, --number-nonblank  number nonempty lines; overrides -n\n"
            . "  -s, --squeeze-blank    suppress repeated empty lines\n"
            . "  -E, --show-ends        display $ before each newline\n"
            . "  -T, --show-tabs        display tabs as ^I\n"
            . "      --help             show this help\n"
            . "      --                 end options\n\n"
            . "Files are read in binary mode; no separators or final newline are added.\n"
            . "Line state continues across files. FILE is required; - is a literal filename.\n";
    }
    if (in_array($command, ['head', 'tail'], true)) {
        $selection = $command === 'head' ? 'first' : 'last';
        return "Usage: $command [OPTION]... FILE...\n"
            . "Read the $selection 10 lines of each local regular file by default.\n\n"
            . "  -n, --lines NUM        select NUM lines\n"
            . "  -c, --bytes NUM        select NUM bytes\n"
            . "  -q, --quiet, --silent  never show filename headers\n"
            . "  -v, --verbose          always show filename headers\n"
            . "  -z, --zero-terminated  use NUL instead of newline as record delimiter\n"
            . "      --help             show this help\n"
            . "      --                 end options\n\n"
            . ($command === 'head' ? "A negative NUM selects all but the last NUM lines or bytes.\n"
                : "A +NUM selects from line or byte NUM (one-based; +0 also means the start).\n")
            . "Counts are decimal integers without suffixes; the last -n or -c wins.\n"
            . "Headers appear with multiple operands unless -q or -v overrides this.\n"
            . "FILE is required; - is a literal filename. Continuous following is not supported.\n";
    }
    if ($command === 'touch') {
        return "Usage: touch [OPTION]... FILE...\n"
            . "Create empty files or update access and modification times.\n\n"
            . "  -a                     change only access time\n"
            . "  -m                     change only modification time\n"
            . "  -c, --no-create        skip missing files without creating them\n"
            . "  -r, --reference FILE   copy times from FILE\n"
            . "  -t STAMP               use [[CC]YY]MMDDhhmm[.ss]\n"
            . "      --help             show this help\n"
            . "      --                 end options\n\n"
            . "With neither -a nor -m, or with both, update both times.\n"
            . "-r and -t cannot be combined; repeated values use the last alias.\n"
            . "-t uses the application's timezone; an omitted year uses the current year.\n"
            . "Symbolic links are followed; existing content is never truncated.\n";
    }
    if ($command === 'basename') {
        return "Usage: basename [OPTION]... NAME [SUFFIX]\n"
            . "   or: basename -a [OPTION]... NAME...\n"
            . "Strip directories and an optional suffix from Unix path text.\n\n"
            . "  -a, --multiple       treat all operands as names\n"
            . "  -s, --suffix SUFFIX  remove SUFFIX; implies -a (last suffix wins)\n"
            . "  -z, --zero           end each result with NUL instead of newline\n"
            . "      --help           show this help\n"
            . "      --               end options\n\n"
            . "Options must precede operands. Paths need not exist; cwd is ignored.\n"
            . "Only / is a separator on every platform; // is treated as /.\n";
    }
    if ($command === 'dirname') {
        return "Usage: dirname [OPTION]... NAME...\n"
            . "Strip the final component from each Unix path; use . when absent.\n\n"
            . "  -z, --zero    end each result with NUL instead of newline\n"
            . "      --help    show this help\n"
            . "      --        end options\n\n"
            . "Paths need not exist; cwd is ignored.\n"
            . "Only / is a separator on every platform; // is treated as /.\n";
    }
    if ($command === 'pwd') {
        return "Usage: pwd [OPTION]...\n"
            . "Print the working directory; no operands are accepted.\n\n"
            . "  -L, --logical     preserve a valid absolute logical cwd or PWD\n"
            . "                    otherwise fall back to the physical path\n"
            . "  -P, --physical    resolve symbolic links (default)\n"
            . "      --help        show this help\n"
            . "      --            end options\n"
            . "The last -L or -P wins. An explicit cwd takes precedence over PWD.\n";
    }
    if ($command === 'find') {
        return "Usage: find [PATH]... [-type TYPES] [-name PATTERN]\n"
            . "Recursively list entries, including starting paths and hidden names.\n"
            . "The default path is .; symbolic links are not traversed.\n\n"
            . "  -type, --type TYPES    f: files, d: directories, l: symbolic links\n"
            . "                         comma-separated types match any listed type (f,l)\n"
            . "  -name, --name PATTERN  match the basename (case-sensitive): *, ?, [abc]\n"
            . "                         quote patterns, e.g. -name '*.php'\n"
            . "      --help             show this help\n"
            . "      --                 end options\n\n"
            . "Paths must precede predicates; -type and -name may appear in either order.\n"
            . "All predicates, including repeated ones, are combined with AND.\n"
            . "Omitted predicates do not restrict the search.\n";
    }
    if ($command === 'ls') {
        return "Usage: ls [OPTION]... [FILE]...\n"
            . "List files; list the current directory when FILE is omitted.\n\n"
            . "  -a, --all                  include names beginning with .\n"
            . "  -l                         use a long listing\n"
            . "  -g                         long listing without owner\n"
            . "  -o                         long listing without group\n"
            . "  -G                         omit group in a long listing\n"
            . "  -h, --human-readable       scale sizes by 1024\n"
            . "      --si                   scale sizes by 1000\n"
            . "      --group-directories-first\n"
            . "      --help                 show this help\n"
            . "      --                     end options\n";
    }
    if ($command === 'rm') {
        return "Usage: rm [OPTION]... FILE...\n"
            . "Remove entries; source links themselves are removed.\n\n"
            . "  -r, --recursive    remove directories and their contents\n"
            . "  -f, --force        ignore missing entries and missing operands\n"
            . "  -v, --verbose      report removed entries\n"
            . "      --help         show this help\n"
            . "      --             end options\n";
    }
    if ($command === 'rmdir') {
        return "Usage: rmdir [OPTION]... DIRECTORY...\n"
            . "Remove empty real directories only.\n\n"
            . "  -p, --parents      also remove empty parents; stop before cwd or root\n"
            . "  -v, --verbose      report removed directories\n"
            . "      --help         show this help\n"
            . "      --             end options\n";
    }
    if ($command === 'cp') {
        return "Usage: cp [OPTION]... SOURCE... DESTINATION\n"
            . "Copy files; multiple sources require an existing destination directory.\n\n"
            . "  -r, --recursive    copy directories and preserve source symbolic links\n"
            . "  -n, --no-clobber   skip existing destination files\n"
            . "  -v, --verbose      report copied entries and created directories\n"
            . "      --help         show this help\n"
            . "      --             end options\n";
    }
    if ($command === 'mv') {
        return "Usage: mv [OPTION]... SOURCE... DESTINATION\n"
            . "Rename or move files, directories and symbolic links on the same filesystem.\n\n"
            . "  -f, --force         replace existing files (default)\n"
            . "  -n, --no-clobber    skip existing destinations\n"
            . "  -v, --verbose       report completed moves\n"
            . "      --help          show this help\n"
            . "      --              end options\n"
            . "The last -f or -n wins. Multiple sources require an existing directory.\n";
    }
    return "Usage: mkdir [OPTION]... DIRECTORY...\n"
        . "Create directories.\n\n"
        . "  -p, --parents        create missing parents; accept existing directories\n"
        . "  -m, --mode=MODE      set the final directory mode (3 or 4 octal digits)\n"
        . "                       existing directories and parent modes are unaffected\n"
        . "      --help           show this help\n"
        . "      --               end options\n";
}
