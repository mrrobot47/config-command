<?php

use EE\Tests\TestCase;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Mustangostang\Spyc;

/**
 * `ee config get/set/unset/list` against a sandbox config file (src/Config_Command.php).
 */
class ConfigCommandTest extends TestCase {

	protected $reset_db = false;

	private $env_backup;
	private $config;

	protected function setUp(): void {
		parent::setUp();
		$this->env_backup = getenv( 'EE_CONFIG_PATH' );
		$this->config     = $this->sandbox() . '/config.yml';
		putenv( 'EE_CONFIG_PATH=' . $this->config );
	}

	protected function tearDown(): void {
		putenv( 'EE_CONFIG_PATH=' . $this->env_backup );
		parent::tearDown();
	}

	private function write( $yaml ) {
		file_put_contents( $this->config, $yaml );
	}

	private function load() {
		return Spyc::YAMLLoad( $this->config );
	}

	private function config( $subcommand, array $args = [], array $assoc_args = [] ) {
		$command = new Config_Command();
		if ( 'list' === $subcommand ) {
			ob_start();
			try {
				$command->_list( $args, $assoc_args );
			} finally {
				$output = ob_get_clean();
			}

			return $output;
		}
		$command->$subcommand( $args, $assoc_args );

		return $this->stdout();
	}

	public function test_get_prints_the_value() {
		$this->write( "le-mail: admin@example.test\nlocale: en\n" );

		$this->assertSame( "admin@example.test\n", $this->config( 'get', [ 'le-mail' ] ) );
	}

	public function test_get_of_a_missing_key_is_an_error() {
		$this->write( "locale: en\n" );

		$this->assert_ee_error(
			function () {
				$this->config( 'get', [ 'le-mail' ] );
			},
			"No config value with key 'le-mail' set"
		);
	}

	public function test_set_adds_a_key_and_keeps_the_others() {
		$this->write( "locale: en\nsysctl: true\nports:\n  http: 80\n" );

		$this->config( 'set', [ 'le-mail', 'admin@example.test' ] );

		$this->assertSame(
			[ 'locale' => 'en', 'sysctl' => true, 'ports' => [ 'http' => 80 ], 'le-mail' => 'admin@example.test' ],
			$this->load()
		);
	}

	public function test_set_replaces_an_existing_value() {
		$this->write( "locale: en\nle-mail: old@example.test\n" );

		$this->config( 'set', [ 'le-mail', 'new@example.test' ] );

		$this->assertSame( [ 'locale' => 'en', 'le-mail' => 'new@example.test' ], $this->load() );
	}

	/**
	 * @dataProvider round_trip_values
	 */
	public function test_set_then_get_returns_the_same_string( $value ) {
		$this->write( "locale: en\n" );

		$this->config( 'set', [ 'k', $value ] );

		$this->assertSame( [ 'locale' => 'en', 'k' => $value ], $this->load() );
		$this->assertSame( "$value\n", $this->config( 'get', [ 'k' ] ) );
	}

	public function round_trip_values() {
		$values = [ 'plain', 'a: b', 'a #b', '#x', "it's", 'x"y', '"a" b', 'true', 'false', 'yes', 'no', 'off', 'null', '~', '123', '0', '1.5', '-1', '0755', '1e3', '[a, b]', '{a: b}', '- x', '>', '*star', '&amp', '!tag', '%p', '  padded  ', 'http://user:pw@example.test:8080/p?q=1#frag', "multi\nline" ];

		return array_combine( $values, array_map( function ( $v ) {
			return [ $v ];
		}, $values ) );
	}

	/**
	 * @dataProvider round_trip_keys
	 */
	public function test_keys_with_yaml_syntax_round_trip( $key ) {
		$this->write( "locale: en\n" );

		$this->config( 'set', [ $key, 'v' ] );

		$this->assertSame( [ 'locale' => 'en', $key => 'v' ], $this->load() );
	}

	public function round_trip_keys() {
		return [
			'colon'   => [ 'a: b' ],
			'hash'    => [ '#c' ],
			'space'   => [ 'x y' ],
			'dashes'  => [ 'cloudflare-api-key' ],
		];
	}

	public function test_unset_removes_only_that_key() {
		$this->write( "locale: en\nle-mail: admin@example.test\nsysctl: true\n" );

		$this->config( 'unset', [ 'le-mail' ] );

		$this->assertSame( [ 'locale' => 'en', 'sysctl' => true ], $this->load() );
	}

	public function test_unset_of_a_missing_key_is_an_error_and_leaves_the_file_alone() {
		$yaml = "# keep\nlocale: en\n";
		$this->write( $yaml );

		$this->assert_ee_error(
			function () {
				$this->config( 'unset', [ 'le-mail' ] );
			},
			"No config value with key 'le-mail' set"
		);
		$this->assertSame( $yaml, file_get_contents( $this->config ) );
	}

	public function test_list_as_json_keeps_the_loaded_types() {
		$this->write( "locale: en\nsysctl: true\nport: 8080\n" );

		$items = json_decode( $this->config( 'list', [], [ 'format' => 'json' ] ), true );

		$this->assertSame(
			[
				[ 'key' => 'locale', 'value' => 'en' ],
				[ 'key' => 'sysctl', 'value' => true ],
				[ 'key' => 'port', 'value' => 8080 ],
			],
			$items
		);
	}

	public function test_list_as_text_prints_key_value_lines() {
		$this->write( "locale: en\nle-mail: admin@example.test\n" );

		$this->assertSame( '', $this->config( 'list', [], [ 'format' => 'text' ] ) );
		$this->assertSame( "locale: en\nle-mail: admin@example.test\n", $this->stdout() );
	}

	public function test_list_of_an_empty_config_is_an_error() {
		$this->write( "# nothing yet\n" );

		$this->assert_ee_error(
			function () {
				$this->config( 'list', [], [ 'format' => 'json' ] );
			},
			'No config values found!'
		);
	}

	public function test_without_ee_config_path_the_file_under_ee_root_dir_is_used() {
		putenv( 'EE_CONFIG_PATH' );
		$default = EE_ROOT_DIR . '/config/config.yml';
		file_put_contents( $default, "locale: en\n" );
		try {
			$this->config( 'set', [ 'le-mail', 'admin@example.test' ] );
			$this->assertSame( [ 'locale' => 'en', 'le-mail' => 'admin@example.test' ], Spyc::YAMLLoad( $default ) );
			$this->assertFileDoesNotExist( $this->config );
		} finally {
			unlink( $default );
		}
	}

	/**
	 * CFG-1: isset() treats a key whose value is null as not set, so `get` fails although `list` shows the key.
	 *
	 * @group known-bug
	 * @group CFG-1
	 */
	public function test_get_of_a_null_value_is_not_an_error() {
		$this->write( "locale: en\nproxy: ~\n" );

		try {
			$this->config( 'get', [ 'proxy' ] );
		} catch ( EE\ExitException $e ) {
			$this->fail( 'get proxy exited: ' . $this->stderr() );
		}
		$this->assertStringNotContainsString( 'No config value', $this->stderr() );
	}

	/**
	 * CFG-1: `unset` can never remove a key whose value is null.
	 *
	 * @group known-bug
	 * @group CFG-1
	 */
	public function test_unset_removes_a_null_value() {
		$this->write( "locale: en\nproxy: null\n" );

		try {
			$this->config( 'unset', [ 'proxy' ] );
		} catch ( EE\ExitException $e ) {
			$this->fail( 'unset proxy exited: ' . $this->stderr() );
		}
		$this->assertSame( [ 'locale' => 'en' ], $this->load() );
	}

	/**
	 * CFG-2: `get` of a nested value prints `Array` (plus an "Array to string conversion" warning).
	 *
	 * @group known-bug
	 * @group CFG-2
	 */
	public function test_get_of_a_nested_value_prints_its_content() {
		$this->write( "ports:\n  http: 80\n  https: 443\n" );

		$output = $this->config( 'get', [ 'ports' ] );

		$this->assertStringNotContainsString( 'Array', $output );
		$this->assertStringContainsString( '443', $output );
	}

	/**
	 * CFG-2: `list --format=text` prints `key: Array` for nested values.
	 *
	 * @group known-bug
	 * @group CFG-2
	 */
	public function test_list_as_text_prints_nested_values() {
		$this->write( "locale: en\nports:\n  https: 443\n" );

		$this->config( 'list', [], [ 'format' => 'text' ] );

		$this->assertStringNotContainsString( 'Array', $this->stdout() );
		$this->assertStringContainsString( '443', $this->stdout() );
	}

	/**
	 * CFG-3: `set` re-dumps the whole file, dropping the header and comments and rewriting unrelated `yes` values.
	 *
	 * @group known-bug
	 * @group CFG-3
	 */
	public function test_set_keeps_comments_and_unrelated_lines() {
		$this->write( "---\n# Managed by ops, see runbook\nlocale: en\nsysctl: yes\n" );

		$this->config( 'set', [ 'le-mail', 'admin@example.test' ] );

		$lines = explode( "\n", file_get_contents( $this->config ) );
		$this->assertContains( '# Managed by ops, see runbook', $lines );
		$this->assertContains( 'sysctl: yes', $lines );
		$this->assertSame( 'admin@example.test', $this->load()['le-mail'] );
	}

	/**
	 * CFG-3: `unset` re-dumps the whole file too.
	 *
	 * @group known-bug
	 * @group CFG-3
	 */
	public function test_unset_keeps_comments_and_unrelated_lines() {
		$this->write( "# Managed by ops\nlocale: en\nsysctl: on\nle-mail: admin@example.test\n" );

		$this->config( 'unset', [ 'le-mail' ] );

		$this->assertSame( "# Managed by ops\nlocale: en\nsysctl: on\n", file_get_contents( $this->config ) );
	}

	/**
	 * CFG-6: Spyc dumps these values unquoted (or as a bare block indicator), so they don't come back as set.
	 * `0x1F` (dumped bare, loaded as int 31) has the same cause and isn't listed in issues.md yet.
	 *
	 * @group known-bug
	 * @group CFG-6
	 * @dataProvider lossy_values
	 */
	public function test_set_then_get_returns_the_same_string_for_quote_and_block_characters( $value ) {
		$this->write( "locale: en\n" );

		$this->config( 'set', [ 'k', $value ] );

		$this->assertSame( [ 'locale' => 'en', 'k' => $value ], $this->load() );
	}

	public function lossy_values() {
		return [
			'double-quoted' => [ '"quoted"' ],
			'pipe'          => [ '|' ],
			'lone quote'    => [ '"' ],
			'hex'           => [ '0x1F' ],
		];
	}

	/**
	 * CFG-7: dumpFile() renames a temp file over a symlinked config, so the link is replaced and its target keeps the old content.
	 *
	 * @group known-bug
	 * @group CFG-7
	 */
	public function test_set_writes_through_a_symlinked_config() {
		$target = $this->sandbox() . '/real.yml';
		file_put_contents( $target, "locale: en\n" );
		symlink( $target, $this->config );

		$this->config( 'set', [ 'le-mail', 'admin@example.test' ] );

		clearstatcache();
		$this->assertTrue( is_link( $this->config ), 'config.yml is no longer a symlink' );
		$this->assertSame( [ 'locale' => 'en', 'le-mail' => 'admin@example.test' ], Spyc::YAMLLoad( $target ) );
	}

	/**
	 * CFG-8: `get` prints through EE::log(), which also writes the value to ee.log.
	 *
	 * @group known-bug
	 * @group CFG-8
	 */
	public function test_get_does_not_write_the_value_to_the_log_file() {
		$this->write( "cloudflare-api-key: cf-s3cr3t-token\n" );
		$handler = new TestHandler();
		$backup  = self::get_static( 'EE', 'file_logger' );
		EE::set_file_logger( new Logger( 'ee', [ $handler ] ) );
		try {
			$output = $this->config( 'get', [ 'cloudflare-api-key' ] );
		} finally {
			EE::set_file_logger( $backup );
		}

		$this->assertSame( "cf-s3cr3t-token\n", $output );
		$logged = implode( "\n", array_column( $handler->getRecords(), 'message' ) );
		$this->assertStringNotContainsString( 'cf-s3cr3t-token', $logged );
	}

	/**
	 * CFG-8: `list --format=text` prints through EE::log(), which also writes every value to ee.log.
	 *
	 * @group known-bug
	 * @group CFG-8
	 */
	public function test_list_text_does_not_write_the_values_to_the_log_file() {
		$this->write( "le-mail: admin@example.test\ncloudflare-api-key: cf-s3cr3t-token\n" );
		$handler = new TestHandler();
		$backup  = self::get_static( 'EE', 'file_logger' );
		EE::set_file_logger( new Logger( 'ee', [ $handler ] ) );
		try {
			$this->config( 'list', [], [ 'format' => 'text' ] );
		} finally {
			EE::set_file_logger( $backup );
		}

		$this->assertSame( "le-mail: admin@example.test\ncloudflare-api-key: cf-s3cr3t-token\n", $this->stdout() );
		$logged = implode( "\n", array_column( $handler->getRecords(), 'message' ) );
		$this->assertStringNotContainsString( 'cf-s3cr3t-token', $logged );
	}

	/**
	 * CFG-10: keys aren't validated, so a key can break the file for every later load or overwrite another key.
	 * The leading-space case (nests under and replaces the previous key) isn't listed in issues.md yet.
	 *
	 * @group known-bug
	 * @group CFG-10
	 * @dataProvider hostile_keys
	 */
	public function test_set_with_a_hostile_key_keeps_the_config_loadable_and_intact( $key ) {
		$this->write( "locale: en\nle-mail: admin@example.test\n" );

		try {
			$this->config( 'set', [ $key, 'v' ] );
		} catch ( EE\ExitException $e ) {
			// Rejecting the key is fine.
		}

		try {
			$config = $this->load();
		} catch ( Exception $e ) {
			$this->fail( 'The config no longer loads: ' . $e->getMessage() . "\n" . file_get_contents( $this->config ) );
		}
		$this->assertSame( 'en', $config['locale'] );
		$this->assertSame( 'admin@example.test', $config['le-mail'] );
	}

	public function hostile_keys() {
		return [
			'newline'       => [ "k\nlocale: fr" ],
			'leading space' => [ ' k' ],
		];
	}
}
