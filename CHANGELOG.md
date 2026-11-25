# Changelog

## Unreleased

- Reject unknown CLI options before selecting any targets, accept options after
  paths and honor `--` for dash-prefixed file names. Misspelled dry-run flags no
  longer trigger writes to default directories.
- Reject unknown configuration keys instead of silently falling back to defaults.
- Require explicitly configured and API-selected paths to exist. Missing default
  directories remain optional; an explicit missing directory now reports failure.
- Replace changed files using a complete same-directory temporary write followed
  by rename. Failed writes/replacements leave the original intact and clean up
  the temporary file. Preserve basic permission/owner/group metadata, resolve
  file symlinks and refuse multiply hard-linked files. Check for intervening
  content edits before replacement; simultaneous writers still require external
  coordination. Extended ACLs/xattrs and power-loss durability are not guaranteed.
- Normalize horizontal bracket spacing to one space for non-empty arrays and
  index/destructuring expressions, and compact empty inline arrays to `[]`.
  Preserve line breaks/indentation and complete attribute groups, including all
  arrays, spaces, strings and comments inside their arguments.
- Fix nested interpolated strings, backticks and heredocs being mistaken for
  ordinary PHP code. Preserve their contents using a stack of lexical contexts;
  continue formatting arrays after the outer string closes.
- Parse proposed changes with the selected PHP runtime before accepting them,
  including dry-runs. On a parse error, return failure without overwriting the
  original file. This check applies to changed output, not every existing file.
- Add regression coverage for the previously valid-to-invalid transformation,
  nested interpolation and heredoc combinations, preservation, idempotence and
  rejected invalid output.

## 0.2.1 - 2026-11-25

### Compatibility investigation

- Latest public stable baseline: `v0.2.0` (`f86b7e0`, 2025-11-07).
  `main` already contained the unpublished tokenizer fix `c69e67b`.
- This is a standalone PHP library/CLI, not a Laravel integration package.
  No Illuminate, Symfony or other runtime package dependency needs upgrading.
  Laravel 12/13 project files can be processed independently of framework boot.
- Keep PHP 8.1 compatibility and constrain the supported major to `^8.1` instead
  of promising untested PHP 9 compatibility with `>=8.1`. PHP 8.1 is end of life;
  its continued installability is not a recommendation to deploy that runtime.
- Declare `ext-tokenizer`, used by the formatter already present in `main`.
- The previous dev requirement `phpunit/phpunit ^13.2` needs PHP >=8.4.1;
  a clean dev installation on PHP 8.1–8.3 failed Composer resolution.
  Allow `^10.5 || ^11.5 || ^12.5 || ^13.2` so each PHP runtime can resolve a
  compatible test runner without raising the production PHP minimum.
- Run PHPUnit with Composer's `@php` and an explicit autoload bootstrap so
  the selected interpreter is used consistently.

### Fixes

- Include the tokenizer-based spacing correction already in `main` in a stable
  release. Strings, interpolated strings, comments, regex literals, heredocs and
  nowdocs are preserved rather than being rewritten by the old regular expression.
- Return CLI exit status 1 when processing fails; successful dry-runs still
  return 0 even when changes would be made.
- Propagate file failures through directory processing rather than reporting a
  successful directory operation.
- Process individual files in configured `include` lists as documented.
- Normalize Windows/Unix separators when matching directory-traversal exclusions.
- Reject malformed/unreadable configuration and invalid path lists before
  processing anything. Previously malformed JSON fell back to default directories
  and could rewrite files outside the intended selection.
- Check file read/write results and only count successfully written changes
  (or planned dry-run changes). Write failures are reported instead of silently
  claiming success. Writes remain in place, not a transactional multi-file edit.

### Validation and limitations

The original suite passed 2 tests / 6 assertions on PHP 8.4.16. Six additional
regression cases were observed failing before fixes and passing afterwards.
The expanded suite checks CLI behavior and token-stream equivalence, preserving
all non-whitespace tokens and string contents, and verifies formatting idempotence.

| PHP runtime tested locally (Windows) | PHPUnit | Result |
| --- | --- | --- |
| 8.1.34 | 10.5.67 | 13 tests / 35 assertions pass |
| 8.2.36 | 11.5.58 | 13 tests / 35 assertions pass |
| 8.3.37 | 12.5.45 | 13 tests / 35 assertions pass |
| 8.4.16 | 13.4.8 | 13 tests / 35 assertions pass |
| 8.5.13 | 13.4.8 | 13 tests / 35 assertions pass |
| 8.6.0 | 13.4.8 | 13 tests / 35 assertions pass |

The suite fails on deprecations, warnings and risky tests. Composer 2.9.3 itself
emits PHP 8.6 deprecation notices (including `spl_object_hash`); the formatter
and PHPUnit checks pass. This is a tooling limitation, not a formatter call.
The local Composer lock was refreshed and its known-advisory audit passes;
the ignored lock is not part of the release.

A disposable consumer installed the package without dev dependencies and ran
its generated Composer CLI proxy on PHP 8.1–8.6. Dry-runs preserved the original
file; a real run produced the expected spacing. Composer also resolved this
release together with Laravel 12.74.0 and 13.44.1 in separate dry-run graphs.
Those dependency-resolution checks do not claim a full Laravel application test.

Local tests use isolated project copies and disposable files, not real Laravel
applications. CI independently resolves dependencies on Linux and Windows for
PHP 8.1–8.6. Files containing syntax newer than the selected PHP tokenizer are
not guaranteed to be understood. This formatter does not validate or execute
the application's PHP and does not replace PHP linting or a full style checker.

Missing default directories remain non-fatal. Explicit file arguments continue
to bypass directory-traversal exclusions. Includes accept concrete files and
directories, not globs; the earlier README's claim of pattern support was
incorrect. There is no new Laravel provider, runtime dependency, backup system
or source-code execution in the formatter.

### Primary sources

Checked on 2026-11-25:

- [PHP supported versions](https://www.php.net/supported-versions.php)
- [Laravel release requirements](https://laravel.com/docs/13.x/releases)
- [PHP tokenizer](https://www.php.net/manual/en/function.token-get-all.php)
- [PHPUnit package requirements](https://repo.packagist.org/p2/phpunit/phpunit.json)
- [Published BracketSmith versions](https://packagist.org/packages/costamateus/bracketsmith)

The version comes from the Git tag. The library does not distribute a Composer
lock file; dev dependencies are resolved for the selected PHP runtime.
