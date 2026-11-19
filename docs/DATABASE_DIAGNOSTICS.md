# EWO database diagnostics

Run from the project root with PHP 8.5 and CI_ENVIRONMENT set to development.

```powershell
C:\xampp8512\php\php.exe spark ewo:diagnose
C:\xampp8512\php\php.exe spark ewo:diagnose --inventory
```

The development CLI command checks the effective CI4 connection group and session key format without printing their values. MySQL probes are restricted to loopback and query column metadata in a read-only transaction. SQLite fixture files are opened read-only. The inventory option separately inspects known schemas via the local default group. Authentication continues to use only database.ewo. Exit success requires a configured key and compatible, accessible EWO group. [CI4 Spark commands](https://codeigniter4.github.io/userguide/cli/cli_commands.html)

| Evidence on 19 November 2026 WIB | Result |
| --- | --- |
| CLI | Root .env readable, 64-character hexadecimal key set, database.ewo.database and database.ewo.username empty. The default group is populated. |
| Apache | Login page available. A CSRF-valid submission with empty credentials returns 503 for missing configuration before authentication. |
| MySQL TCP 3310 | Metadata query succeeded. eworeb lacks users.dept_id, posts.corp_id, posts.dept_id and posts.is_scheduled. |
| ewohotel_tentrem | All seven table and column checks passed. Its suitability as an isolated test copy is pending owner confirmation. |

Set the dedicated group in the ignored local .env when a compatible, isolated database is approved. A missing group gives the existing configuration 503. A database failure during login now gives a separate generic 503. Fixture tests verify CLI substitution, authentication and dashboard access. Metadata results do not constitute a MySQL authentication test. No schema was selected automatically.