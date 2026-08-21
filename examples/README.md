# Examples

Run examples from the package root after installing dependencies:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 php examples/basic-ledger.php
```

| Script | Shows | Needs server? |
|---|---|---|
| `basic-ledger.php` | `append()`, `diff()`, `RatchetGate`, `trend()` end to end | No |
