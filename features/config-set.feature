Feature: ee config set
  Writes one top-level key to the config file at EE_CONFIG_PATH (a throwaway file here).

  Background:
    Given a temporary ee config file with:
      """
      locale: en_US
      ee_installer_version: stable
      """

  Scenario: Set a new key appends it and prints nothing
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And STDOUT should be empty
    And STDERR should be empty
    And the file '{CONFIG_PATH}' should contain exactly:
      """
      locale: en_US
      ee_installer_version: stable
      le-mail: admin@bh-config-command.test
      """
    And the real ee config file should be unchanged

  Scenario: A value that was set can be read back
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    And I run 'bin/ee config get le-mail'
    Then the exit code should be 0
    And STDOUT should be:
      """
      admin@bh-config-command.test
      """

  Scenario: Overwriting a key keeps its position in the file
    When I run 'bin/ee config set locale fr_FR'
    Then the exit code should be 0
    And the file '{CONFIG_PATH}' should contain exactly:
      """
      locale: fr_FR
      ee_installer_version: stable
      """

  Scenario: Setting the same value twice is idempotent
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    And I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And the file '{CONFIG_PATH}' should contain exactly:
      """
      locale: en_US
      ee_installer_version: stable
      le-mail: admin@bh-config-command.test
      """

  Scenario: Numeric values are stored as quoted strings
    When I run 'bin/ee config set no-of-backups 3'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should contain exactly these entries:
      | key                  | value    |
      | locale               | "en_US"  |
      | ee_installer_version | "stable" |
      | no-of-backups        | "3"      |
    And the file '{CONFIG_PATH}' should contain:
      """
      no-of-backups: "3"
      """

  Scenario: An empty value is stored and read back as empty
    When I run 'bin/ee config set bh-empty ""'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key 'bh-empty' with value ''
    When I run 'bin/ee config get bh-empty'
    Then the exit code should be 0
    And STDOUT should be empty

  Scenario: Dotted keys are stored literally at the top level
    When I run 'bin/ee config set bh.dotted.key v1'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key 'bh.dotted.key' with value 'v1'

  Scenario: Setting a key that holds a nested map replaces the whole map with a string
    Given a temporary ee config file with:
      """
      locale: en_US
      ee_installer_version: stable
      parent:
        child: x
      """
    When I run 'bin/ee config set parent flat'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key 'parent' with value 'flat'

  Scenario: A multi-line value round-trips
    When I run 'bin/ee config set bh-multiline "$(printf "line1\nline2")"'
    Then the exit code should be 0
    When I run 'bin/ee config get bh-multiline'
    Then STDOUT should be:
      """
      line1
      line2
      """

  Scenario Outline: Special value <value> round-trips unchanged
    When I run 'bin/ee config set bh-special "<value>"'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key 'bh-special' with value '<value>'
    When I run 'bin/ee config get bh-special'
    Then the exit code should be 0
    And STDOUT should be:
      """
      <value>
      """

    Examples:
      | value                        |
      | true                         |
      | false                        |
      | yes                          |
      | no                           |
      | on                           |
      | null                         |
      | ~                            |
      | 0                            |
      | 0123                         |
      | 1e3                          |
      | 1.50                         |
      | a: b                         |
      | a #b                         |
      | [1,2]                        |
      | {a: b}                       |
      | -dash                        |
      | with spaces                  |
      | x@bh-config-command.test     |
      | http://bh.test/a?b=c#d       |
      | ünïcode                      |
      | %p                           |
      | &anchor                      |
      | *alias                       |
      | !tag                         |

  Scenario: A value with leading and trailing spaces keeps them
    When I run 'bin/ee config set bh-spaces "  padded  "'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key 'bh-spaces' with value '  padded  '

  Scenario: Set without a value prints the usage and leaves the file alone
    When I run 'bin/ee config set le-mail'
    Then the exit code should be 1
    And STDOUT should be:
      """
      usage: ee config set <key> <value>
      """
    And the file '{CONFIG_PATH}' should contain exactly:
      """
      locale: en_US
      ee_installer_version: stable
      """

  Scenario: Set without arguments prints the usage
    When I run 'bin/ee config set'
    Then the exit code should be 1
    And STDOUT should be:
      """
      usage: ee config set <key> <value>
      """

  Scenario: An unquoted value with spaces is rejected as extra arguments
    When I run 'bin/ee config set bh-key two words'
    Then the exit code should be 1
    And STDERR should be:
      """
      Error: Too many positional arguments: words
      """
    And the YAML file '{CONFIG_PATH}' should not have key 'bh-key'

  Scenario: A value that looks like a flag is not taken as the value
    When I run 'bin/ee config set bh-key --bh-flag'
    Then the exit code should be 1
    And STDOUT should be:
      """
      usage: ee config set <key> <value>
      """
    And the YAML file '{CONFIG_PATH}' should not have key 'bh-key'

  Scenario: Set rejects unknown flags
    When I run 'bin/ee config set bh-key v --bh-flag=1'
    Then the exit code should be 1
    And STDERR should contain:
      """
      unknown --bh-flag parameter
      """
    And the YAML file '{CONFIG_PATH}' should not have key 'bh-key'

  Scenario: Set creates a missing config file and its directories
    Given a temporary ee config path that does not exist yet
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should contain exactly these entries:
      | key                  | value                          |
      | locale               | "en_US"                        |
      | ee_installer_version | "stable"                       |
      | le-mail              | "admin@bh-config-command.test" |

  Scenario: Set keeps the file mode
    Given the file '{CONFIG_PATH}' has mode '0600'
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And the file '{CONFIG_PATH}' should have mode '0600'

  Scenario: Set with EE_CONFIG_PATH on the command line writes only that file
    When I run 'EE_CONFIG_PATH={CONFIG_DIR}/other.yml bin/ee config set bh-key other'
    Then the exit code should be 0
    And the YAML file '{CONFIG_DIR}/other.yml' should have key 'bh-key' with value 'other'
    And the YAML file '{CONFIG_PATH}' should not have key 'bh-key'
    And the real ee config file should be unchanged

  Scenario Outline: Key <key> that needs YAML quoting round-trips
    When I run 'bin/ee config set "<key>" bh-value'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key '<key>' with value 'bh-value'
    When I run 'bin/ee config get "<key>"'
    Then the exit code should be 0
    And STDOUT should be:
      """
      bh-value
      """
    When I run 'bin/ee config unset "<key>"'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should not have key '<key>'

    Examples:
      | key        |
      | bh key     |
      | bh: key    |
      | #bh-key    |
      | bh#key     |
      | @bh-key    |

  # CFG-10: keys aren't validated; a newline in the key writes YAML that Spyc can't parse, so every later ee command dies.
  @known-bug
  Scenario: A key containing a newline is rejected and the config stays readable
    When I run 'bin/ee config set "$(printf "bh-key\nlocale: fr_FR")" v'
    Then the exit code should be 1
    When I run 'bin/ee config get locale'
    Then the exit code should be 0
    And STDOUT should be:
      """
      en_US
      """

  # CFG-6: Spyc dumps these values unquoted, so they come back changed ("dq" loses quotes, | becomes empty).
  @known-bug
  Scenario Outline: Value <value> round-trips unchanged
    When I run 'bin/ee config set bh-special <shell>'
    Then the exit code should be 0
    When I run 'bin/ee config get bh-special'
    Then STDOUT should be:
      """
      <value>
      """

    Examples:
      | shell         | value    |
      | '"quoted"'    | "quoted" |
      | '\|'          | \|       |

  # CFG-3: set rewrites the whole file through Spyc; comments are dropped.
  @known-bug
  Scenario: Set keeps comments in the config file
    Given a temporary ee config file with:
      """
      # Managed by bh-config-command
      locale: en_US
      ee_installer_version: stable
      """
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And the file '{CONFIG_PATH}' should contain:
      """
      # Managed by bh-config-command
      """

  # CFG-3: unquoted YAML 1.1 booleans in other keys are rewritten as true/false.
  @known-bug
  Scenario: Set does not change unrelated hand-written values
    Given a temporary ee config file with:
      """
      locale: en_US
      ee_installer_version: stable
      bh-answer: y
      bh-switch: off
      """
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And the file '{CONFIG_PATH}' should contain:
      """
      bh-answer: y
      bh-switch: off
      """

  # CFG-7: Symfony dumpFile() renames a temp file over the path, replacing a symlinked config.
  @known-bug
  Scenario: Set writes through a symlinked config file
    Given the temporary ee config file is a symlink
    When I run 'bin/ee config set le-mail admin@bh-config-command.test'
    Then the exit code should be 0
    And the temporary ee config file should be a symlink
    And the YAML file '{CONFIG_TARGET}' should have key 'le-mail' with value 'admin@bh-config-command.test'

  # CFG-8: the runner logs argv at debug level to ee.log, so secrets passed to `config set` are persisted there.
  @known-bug
  Scenario: Set does not copy the value into ee.log
    Given the environment variable 'BH_SECRET' is set to 'bh-config-command-secret-{RUN_ID}'
    And I remember the size of the file '{EE_LOG}'
    When I run 'bin/ee config set cloudflare-api-key "$BH_SECRET"'
    Then the exit code should be 0
    And the YAML file '{CONFIG_PATH}' should have key 'cloudflare-api-key' with value 'bh-config-command-secret-{RUN_ID}'
    And the text appended to the file '{EE_LOG}' should not contain 'bh-config-command-secret-{RUN_ID}'
