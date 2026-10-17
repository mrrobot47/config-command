# config-command Behat coverage

Suite for `ee config` (`src/Config_Command.php`). Every scenario except those in `config-global.feature` points `EE_CONFIG_PATH` at a throwaway file under `sys_get_temp_dir()`, so `/opt/easyengine/config/config.yml` is never written. The context snapshots the real config before each scenario, restores it byte-for-byte (content and mode) afterwards, and fails any scenario not tagged `@destructive` that changed it. No sites, containers, `/etc/hosts` entries, LE certificates or remote backups are involved; the config commands don't even need Docker.

## Inventory

| Item | Source | Covered by |
|---|---|---|
| `ee config` (no subcommand): usage of all subcommands, exit 0 | core `CompositeCommand::show_usage()` | config.feature: "Running ee config without a subcommand…" |
| `ee help config`, `ee help config <sub>` (synopsis, list formats) | docblocks | config.feature: "ee help config lists…", outline "ee help config <subcommand>…", "…documents every supported format" |
| Unknown subcommand (with and without suggestion) | core `Runner::find_command_to_run()` | config.feature: two "unknown config subcommand" scenarios |
| No Docker requirement check for `config` | core `Runner::init_ee()` | config.feature: "config commands skip the Docker requirement check" (docker/docker-compose shims that always fail) |
| `get <config-key>`: string, value with colon, quoted number, integer, boolean, empty value, bootstrap default | `get()` | config-get.feature: "Get an existing string value" … "Get the defaults that the bootstrap guarantees" |
| `get`: read-only (file byte-identical) | `get()` | config-get.feature: "Get does not modify the config file" |
| `get`: missing key error, case sensitivity, dotted keys are literal | `get()` isset check | config-get.feature: "Getting a missing key fails", "Keys are case-sensitive", "Dotted keys are literal…" |
| `get`: no arg (usage), two args, unknown flag | core `Subcommand::validate_args()` | config-get.feature: "Get without a key…", "Get with two keys…", "Get rejects unknown flags" |
| `get` on a config path that doesn't exist yet | `get()` + bootstrap | config-get.feature: "Get works with a freshly created config file" |
| `get` of a null value | `get()` isset | config-get.feature `@known-bug` CFG-1 |
| `get` of a nested map | `get()` → `EE::log(array)` | config-get.feature `@known-bug` CFG-2 |
| `get --quiet` | `get()` uses `EE::log` | config-get.feature `@known-bug` CFG-5 |
| `get` copies the value into ee.log | `EE::log` → file logger | config-get.feature `@known-bug` CFG-8 |
| `set <key> <value>`: new key (exact file content, no output), read back, overwrite keeps position, idempotent | `set()` | config-set.feature: first four scenarios |
| `set`: numeric stored as quoted string, empty value, dotted key literal, replacing a nested map, multi-line value, leading/trailing spaces | `set()` + Spyc dump | config-set.feature: "Numeric values…", "An empty value…", "Dotted keys…", "Setting a key that holds a nested map…", "A multi-line value…", "A value with leading and trailing spaces…" |
| `set`: 24 YAML-special values round-trip (true/false/yes/no/on/null/~/0/0123/1e3/1.50/`a: b`/`a #b`/`[1,2]`/`{a: b}`/-dash/spaces/email/URL with `#`/unicode/`%p`/`&`/`*`/`!`) | Spyc quoting | config-set.feature outline "Special value <value> round-trips unchanged" |
| `set`: missing value / no args (usage, file untouched), unquoted spaces, flag-looking value, unknown flag | core validation | config-set.feature: "Set without a value…", "Set without arguments…", "An unquoted value with spaces…", "A value that looks like a flag…", "Set rejects unknown flags" |
| `set` creates a missing file and directories | `set()` + bootstrap + Symfony `dumpFile()` | config-set.feature: "Set creates a missing config file…" |
| `set` keeps the file mode | Symfony `dumpFile()` | config-set.feature: "Set keeps the file mode" |
| `EE_CONFIG_PATH` given inline for one invocation | `set()` path resolution | config-set.feature: "Set with EE_CONFIG_PATH on the command line…" |
| `set` of `"quoted"` and `\|` values | Spyc dump | config-set.feature `@known-bug` CFG-6 (outline, 2 examples) |
| `set` drops comments / rewrites unrelated `y`/`off` values | whole-file Spyc rewrite | config-set.feature `@known-bug` CFG-3 (2 scenarios) |
| `set` on a symlinked config | Symfony `dumpFile()` rename | config-set.feature `@known-bug` CFG-7 |
| `set` secret ends up in ee.log (argv debug line) | core `Runner::start()` | config-set.feature `@known-bug` CFG-8 |
| `unset <key>`: removes only that key (exact content, no output), then get fails, twice (second fails), missing key (file byte-identical), case sensitivity, nested map, empty-string value | `unset()` | config-unset.feature: first seven scenarios |
| `unset`: no arg, two args, unknown flag | core validation | config-unset.feature: "Unset without a key…", "Unset with two keys…", "Unset rejects unknown flags" |
| `unset` keeps the file mode | Symfony `dumpFile()` | config-unset.feature: "Unset keeps the file mode" |
| `unset locale` is undone by the next run | core `ensure_present_in_config()` | config-unset.feature: "Unsetting a bootstrap default is undone…" (documents current behaviour) |
| `unset` of null values | `unset()` isset | config-unset.feature `@known-bug` CFG-1 |
| `unset` drops comments / replaces a symlink | whole-file rewrite | config-unset.feature `@known-bug` CFG-3, CFG-7 |
| `list` default format and `--format=table` | `_list()` + Formatter | config-list.feature: "The default format…", "--format=table…" |
| `list --format=json|csv|yaml|text` | `_list()` | config-list.feature: one scenario per format |
| `list`: read-only, reflects set/unset, value types in JSON, nested maps JSON-encoded in table/csv | `_list()` | config-list.feature: "List does not modify…", "List reflects set and unset", "List shows booleans…", "Nested maps are JSON-encoded…" |
| `list` on an empty file ("No config values found!") | `_list()` empty branch | config-list.feature: "An empty config file still lists the bootstrap defaults" (the error branch is unreachable, see below) |
| `list`: removed `count` format (db58c81), unknown format, misspelt flag suggestion, `--fields`, positional arg | synopsis validation | config-list.feature: five scenarios |
| `list --format=json --quiet` | Formatter echoes directly | config-list.feature: "Machine-readable formats still print with --quiet" |
| `list --format=text` with nested map | `_list()` string concat | config-list.feature `@known-bug` CFG-2 |
| `list` shows null keys that get can't read | isset vs list | config-list.feature `@known-bug` CFG-1 |
| `list` (table) with `--quiet` | `EE::line` goes through the logger | config-list.feature `@known-bug` CFG-5 |
| Bootstrap: missing file/dirs created with `locale`/`ee_installer_version`, empty file, missing defaults appended, file untouched when defaults exist | core `Runner::ensure_present_in_config()` | config-path.feature: first four scenarios |
| `EE_CONFIG_PATH` switching per invocation | all subcommands | config-path.feature: "EE_CONFIG_PATH switches files per invocation" |
| Config keys that are global runtime parameters: hand-written `disabled_commands` list, `quiet: true` | core Configurator/Runner | config-path.feature: "A hand-written disabled_commands list…", "quiet in the config file silences…" |
| `set quiet false`, `set disabled_commands …` | set writes strings | config-path.feature `@known-bug` CFG-4 (2 scenarios) |
| Default path (no `EE_CONFIG_PATH`, empty `EE_CONFIG_PATH`): list, set/get/unset, mode kept | path fallback | config-global.feature (`@destructive`, 4 scenarios, restored by the context) |
| Config not readable/writable errors | core `ensure_present_in_config()` | not covered: the suite runs as root, which bypasses file permissions; core code, not this package |
| `~/easyengine` config root on macOS | core `init-ee.php` | not covered: Linux-only CI |

## Counts

7 features, 127 scenarios (outline examples expanded): `@known-bug` 20, `@destructive` 4, `@le` 0, `@remote` 0. 103 scenarios run with neither tag.

Coverage estimate: about 95% of the package's reachable behaviour (every subcommand, argument, format and error path in `Config_Command.php`, plus the core bootstrap/validation behaviour they depend on). The only uncovered package branch is `list`'s "No config values found!", which the bootstrap makes unreachable.

Estimated runtime: about 215 `bin/ee` invocations with no Docker work, roughly 1.5 to 3 minutes.

## Known bugs exposed (proposed IDs, not yet in issues.md)

- CFG-1 (Low): `get` and `unset` use `isset()`, so a key whose value is null (`k: ~`, `k: null`) is "not set" although `list` shows it, and it can't be removed with `unset`. Fix: `array_key_exists()`.
- CFG-2 (Low): non-scalar values: `get` prints `Array` (PHP "Array to string conversion"), `list --format=text` prints `key: Array`.
- CFG-3 (Medium): `set`/`unset` rewrite the whole file through Spyc: comments are dropped, and hand-written unquoted `y`/`yes`/`on`/`off`/`no` in unrelated keys become `true`/`false`. The bootstrap writer also adds `---`, which `set` then removes.
- CFG-4 (Medium): `set` always writes strings. `ee config set disabled_commands "<cmd>"` makes every later ee command fatal on PHP 8 (TypeError from `in_array()` in `Runner::is_command_disabled()`), including the `ee config unset` needed to repair it; on 7.4 it's a hidden warning and nothing is disabled. `ee config set quiet false` enables quiet mode because `'false'` is truthy.
- CFG-5 (Low): `get` prints through `EE::log`, and `list` table/text through the logger, so `--quiet` (or `quiet` in the config) hides exactly the output that was asked for; json/csv/yaml still print.
- CFG-6 (Low): the values `"quoted"` (loses its quotes) and `|` (becomes empty) don't round-trip through `set` then `get` (Spyc dumper).
- CFG-7 (Low): `set`/`unset` replace a symlinked config file with a regular file (Symfony `dumpFile()` renames a temp file over the path), while the bootstrap writer writes through the link.
- CFG-8 (Medium, security): secrets reach `/opt/easyengine/logs/ee.log`: `get` logs the value (`EE::log` also writes to the file logger at INFO), and core logs the full argv at DEBUG, so `ee config set cloudflare-api-key <key>` persists the key.
- CFG-9 (Info): `ee help config get` says "Set a config value"; the group description says "Manges"; the README documents only `set`/`get`.

## Notes

- Asserted as current behaviour, not tagged as bugs: `set`/`unset` print nothing on success (other ee commands print `Success:`); unsetting `locale`/`ee_installer_version` is undone by the next invocation. Seen in the code but not tested: `ee config set debug true` stores the string `'true'`, which the logger treats as a debug group name, so it doesn't enable debug output.
- The CSV scenarios may also expose PHP 8.4+'s `fputcsv()` `$escape` deprecation if `display_errors` is on (it's off on this host's CLI).
- New shared steps are in the "Shared steps" section of `bootstrap/FeatureContext.php`. The inherited "I run" step now expands `{…}` placeholders, runs the command without copying its output into ee.log, and refuses `sudo` while a temporary config is active, because sudo drops `EE_CONFIG_PATH`.
- The site-command pattern of deleting every site after each feature is replaced by deleting only `bh-config-command-*` sites, and only when such a site directory exists, so this suite never calls `ee site` at all in practice.
