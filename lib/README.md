# Vendored libraries

| Library | Version | Licence | Source |
| --- | --- | --- | --- |
| Parsedown | 1.8.0 (2026-02-16) | MIT, see `LICENSE-parsedown.txt` | https://github.com/erusev/parsedown/tree/1.8.0 |
| ParsedownExtra | 0.9.0 (2026-02-16) | MIT, see `LICENSE-parsedown-extra.txt` | https://github.com/erusev/parsedown-extra/tree/0.9.0 |

These are the only third-party runtime code in the plugin.

## Local changes

Many WordPress plugins ship their own global `Parsedown` class, often an older 1.7.x release. If one of them loads first, our copy can't be loaded, and ParsedownExtra 0.9.0 throws because it needs Parsedown 1.8.0. To avoid that clash, both files are moved into a namespace. Nothing else changes:

1. `Parsedown.php`: `namespace Hive\Compliance\Lib;` is added before `class Parsedown`.
2. `ParsedownExtra.php`: the same namespace line is added. The three references to global classes are made fully qualified: `\Exception`, `\DOMDocument` and `\DOMElement`.

SHA-256 of the upstream files, before these changes:

```
34075a6f176841dbb91ca6a26ac7455b5bb576e368cfadb8249edb79d67b1a06  Parsedown.php
792160e8a490d222e1bcf03e5b257f05b4e220cb1873b023559c1fa1d61d86c8  ParsedownExtra.php
```

To update, download the new upstream files, re-apply the two changes above, and run the test suite. The render tests cover safe mode, header attributes and escaping.
