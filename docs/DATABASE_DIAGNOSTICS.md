# EWO database diagnostics

Run from the project root with PHP 8.5 and CI_ENVIRONMENT set to development.

```powershell
C:\xampp8512\php\php.exe spark ewo:diagnose
C:\xampp8512\php\php.exe spark ewo:diagnose --inventory
```

The development CLI command checks the effective CI4 connection group and session key format without printing their values. MySQL probes are restricted to loopback and query column metadata in a read-only transaction. SQLite fixture files are opened read-only. The inventory option separately inspects known schemas via the local default group. Authentication continues to use only database.ewo. Exit success requires a configured key and compatible, accessible EWO group. [CI4 Spark commands](https://codeigniter4.github.io/userguide/cli/cli_commands.html)

| Evidence on 19 November 2026 WIB | Result |
| --- | --- |
| CLI | Root .env readable. The effective database.ewo fields are populated, the configured schema is eworeb, and the key matches the expected 64-hex format. The inventory default group is also populated. |
| Apache | The login form and assets are served under /eworeb/. A CSRF-valid empty form advances past the configuration guard to field validation, confirming required settings are present in the web runtime. |
| MySQL TCP 3310 | Both the configured group and the inventory group connected. The configured schema eworeb lacks users.dept_id, posts.corp_id, posts.dept_id and posts.is_scheduled. The command exits 1 for incomplete schema compatibility. |
| ewohotel_tentrem | All seven table and column checks passed. The 29 required columns shared with eworeb match in type, nullability, and collation. Suitability for write-capable tests is pending owner confirmation. |

Set the dedicated group in the ignored local .env when a compatible, isolated database is approved. A missing group gives the existing configuration 503. A database failure during login now gives a separate generic 503. Fixture tests verify CLI substitution, authentication and dashboard access. Successful MySQL application login and dashboard parity on MySQL remain unverified. Browser URLs under localhost/eworeb/ resolved to the localhost origin with the configured alternate hostname.