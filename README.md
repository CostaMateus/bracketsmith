# 🧰 BracketSmith

**BracketSmith** is a lightweight PHP tool for standardizing array spacing — keeping your square brackets `[ ]` clean and consistent.

---

## Compatibility

Version **0.2.1** supports PHP **8.1–8.6** and requires the native `tokenizer`
extension. PHP 8.1 is retained for existing users but is already end of life;
prefer a supported PHP release for new installations.

BracketSmith has no Laravel or other runtime package dependency. It can format
files in Laravel 12/13 projects without a service provider or Artisan bootstrap.
The default directories follow common Laravel layouts; the selected CLI PHP
runtime determines which PHP syntax can be understood by its tokenizer.

Development dependencies resolve PHPUnit 10.5, 11.5, 12.5 or 13.2+ according to
the PHP version. Tests run on PHP 8.1–8.6 on Linux and Windows in CI.
See [CHANGELOG.md](CHANGELOG.md) for the compatibility findings and validation.

---

## 🚀 Installations

```bash
composer require costamateus/bracketsmith
```

Or for global use:

```bash
composer global require costamateus/bracketsmith
```

---

## 💡 CLI usage

```bash
vendor/bin/bracketsmith --dry-run
```

Optional parameters:

- `--dry-run` → only checks, without altering files.
- `--verbose` → shows processed files.
- `--help` → displays help information.
- It is possible to pass specific files or directories:
  ```bash
  vendor/bin/bracketsmith app/Models/User.php    # Process single file
  vendor/bin/bracketsmith app/Models/            # Process directory
  ```

Exit status is `0` on successful processing (including a dry-run that finds
changes), and `1` for configuration or processing failures. Dry-run never rewrites
files. Options must precede file/directory arguments.

---

## ⚙️ Configuration

You can customize which directories and files to process by creating a `bracketsmith.json` file in your project root.

Example `bracketsmith.json`:

```json
{
	"include": ["app/", "routes/"],
	"exclude": ["vendor/", "storage/", "node_modules/"]
}
```

- `include`: List of directory/file paths to process. Glob patterns are not supported.
- `exclude`: Array of patterns to skip (substrings in file paths).

If no config file is found, default include and exclude patterns are used.
If a configuration file exists but contains invalid JSON, invalid path lists or
cannot be read, processing stops with an error. It does not fall back to default
directories and modify unintended files. Exclusions accept either Windows or Unix
directory separators during directory traversal; explicit file arguments are
processed directly.

Copy `bracketsmith.json.example` to get started.

---

## 🧪 Tests

```bash
composer install
composer test
```

Tests exercise formatting, token preservation, CLI exit codes, dry-run,
idempotence, configuration validation, directory errors and cross-platform
exclusions using disposable files. They never format an existing application.

---

## 📄 License

MIT © [Mateus Costa](https://github.com/costamateus)
