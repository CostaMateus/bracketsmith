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
- It is possible to pass specific files:
  `vendor/bin/bracketsmith app/Models/User.php`

---

## 🧪 Tests

```bash
composer test
```

---

## 📄 License

MIT © [Mateus Costa](https://github.com/costamateus)
