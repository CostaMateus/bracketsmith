# 🧰 BracketSmith

**BracketSmith** is a lightweight PHP tool for standardizing array spacing — keeping your square brackets `[ ]` clean and consistent.

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

- `include`: Array of directories/files to process (relative paths or patterns).
- `exclude`: Array of patterns to skip (substrings in file paths).

If no config file is found, default include and exclude patterns are used.

Copy `bracketsmith.json.example` to get started.

---

## 🧪 Tests

```bash
composer test
```

---

## 📄 License

MIT © [Mateus Costa](https://github.com/costamateus)
