<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// A package autoload file may already define it.
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	public $command;
	public $webroot_path;
	public $ee_path;

	/**
	 * Every site created by this suite starts with this prefix; cleanup only touches these.
	 */
	const SITE_PREFIX = 'bh-config-command-';

	public $commands = [];

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * @When /I run '(.*)'|"(.*)"/
	 */
	public function iRun($command)
	{
		$command = $this->replaceVariables( $command );

		// sudo resets the environment, so the command would silently use the real config.
		if ( $this->tempConfigPath && preg_match( '/(^|[\s;&|])sudo\s+(?!-E\b)/', $command ) ) {
			throw new Exception( "Refusing to run '$command': sudo drops EE_CONFIG_PATH. Drop sudo (the suite runs as root)." );
		}

		// Not EE::launch(): it copies the command's STDOUT/STDERR into ee.log, which would pollute log assertions.
		$this->commands[] = \EE\Process::create( $command, null, null )->run();
	}

	/**
	 * @When I create subsite :subsite in :site
	 */
	public function iCreateSubsiteInOfType($subsite, $site )
	{
		$php_container = implode( explode( '.', $subsite ) ) . '_php_1';

		EE::launch( "docker exec -it --user='www-data' $php_container sh -c 'wp site create --slug=$site'", false, true );
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /(STDOUT|STDERR) should return exactly/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) of command :index should return exactly/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		$expected_out = isset($expected_output->getStrings()[0]) ? $expected_output->getStrings()[0] : '';
		if ( $expected_out !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /(STDOUT|STDERR) should return something like/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$expected_out = isset($expected_output->getStrings()[0]) ? $expected_output->getStrings()[0] : '';
		if (strpos($command_output, $expected_out) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /(STDOUT|STDERR) of command :index should return something like/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$expected_out = isset($expected_output->getStrings()[0]) ? $expected_output->getStrings()[0] : '';
		if (strpos($command_output, $expected_out) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("sudo bin/ee site list");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		$containers = $table->getHash();
		$site_name = implode(explode('.', $site));

		foreach ($containers as $container) {

			$sevice = $container['container'];
			$container_name = $site_name . '_' . $sevice . '_1';

			exec("docker inspect -f '{{.State.Running}}' $container_name > /dev/null 2>&1", $exec_out, $return);
			if (!$return) {
				throw new Exception("$container_name has not been removed!");
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		curl_close($ch);

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		$this->commands = [];
		chdir($this->ee_path);
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$labels = $pyStringNode->getStrings();
		$label_string = implode(' -f label=',$labels);

		$result = EE::launch( "docker ps -qf label=$label_string | wc -l", false, true );
		$running_containers = (int) trim($result->stdout);

		if($expected_running_containers === $running_containers) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		// Avoid invoking ee (and the live stack) when this suite left no sites behind.
		if ( ! glob( EE_SITE_ROOT . '/' . self::SITE_PREFIX . '*' ) ) {
			return;
		}

		$result = EE::launch( 'bin/ee site list --format=text', false, true );
		foreach ( array_filter( array_map( 'trim', explode( "\n", $result->stdout ) ) ) as $site ) {
			if ( 0 === strpos( $site, self::SITE_PREFIX ) ) {
				exec( 'bin/ee site delete ' . escapeshellarg( $site ) . ' --yes' );
			}
		}

		if(file_exists('ee.phar')) {
			unlink('ee.phar');
		}
		if(file_exists('ee-old.phar')) {
			unlink('ee-old.phar');
		}
	}

	/*
	 * ---------------------------------------------------------------------
	 * Shared steps (added for config-command). Generic enough to be reused.
	 *
	 * Placeholders usable in commands and paths: {CONFIG_PATH}, {CONFIG_DIR},
	 * {REAL_CONFIG_PATH}, {EE_LOG}, {RUN_ID}, {FAILING_DOCKER_DIR}.
	 * ---------------------------------------------------------------------
	 */

	/** @var string|null EE_CONFIG_PATH exported for this scenario. */
	private $tempConfigPath = null;

	/** @var string|null Per-scenario scratch dir (holds the temp config). */
	private $scratchDir = null;

	/** @var string|null|false Real config content at scenario start (null = file absent, false = not snapshotted). */
	private $realConfigSnapshot = false;

	/** @var int|null Real config mode at scenario start. */
	private $realConfigMode = null;

	/** @var array Remembered file sizes, keyed by path. */
	private $rememberedSizes = [];

	/** @var array Extra placeholder values set by steps. */
	private $variables = [];

	/** @var array Environment variables exported by steps, unset after the scenario. */
	private $exportedEnv = [];

	private static function realConfigPath() {
		return EE_ROOT_DIR . '/config/config.yml';
	}

	private function replaceVariables( $string ) {
		$vars = array_merge(
			[
				'{REAL_CONFIG_PATH}' => self::realConfigPath(),
				'{EE_LOG}'           => EE_ROOT_DIR . '/logs/ee.log',
				'{RUN_ID}'           => $this->runId(),
			],
			$this->variables
		);
		if ( $this->tempConfigPath ) {
			$vars['{CONFIG_PATH}'] = $this->tempConfigPath;
			$vars['{CONFIG_DIR}']  = dirname( $this->tempConfigPath );
		}

		return strtr( $string, $vars );
	}

	private function runId() {
		static $id = null;
		if ( null === $id ) {
			$id = getmypid() . '-' . substr( md5( uniqid( '', true ) ), 0, 8 );
		}

		return $id;
	}

	private function scratchDir() {
		if ( ! $this->scratchDir ) {
			$this->scratchDir = sys_get_temp_dir() . '/' . self::SITE_PREFIX . $this->runId() . '-' . substr( md5( uniqid( '', true ) ), 0, 6 );
			if ( ! mkdir( $this->scratchDir, 0700, true ) ) {
				throw new Exception( "Unable to create scratch dir {$this->scratchDir}" );
			}
		}

		return $this->scratchDir;
	}

	private function useTempConfig( $path ) {
		$this->tempConfigPath = $path;
		putenv( 'EE_CONFIG_PATH=' . $path );
		$_ENV['EE_CONFIG_PATH'] = $path;
	}

	private function lastCommand() {
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private static function stripAnsi( $text ) {
		return preg_replace( '/\e\[[0-9;]*[A-Za-z]/', '', $text );
	}

	private function lastOutput( $stream ) {
		$result = $this->lastCommand();

		return self::stripAnsi( 'STDOUT' === $stream ? $result->stdout : $result->stderr );
	}

	private function describeLastCommand() {
		$result = $this->lastCommand();

		return sprintf(
			"Command: %s\nExit code: %d\nSTDOUT:\n%s\nSTDERR:\n%s",
			$result->command,
			$result->return_code,
			self::stripAnsi( $result->stdout ),
			self::stripAnsi( $result->stderr )
		);
	}

	private static function loadYamlFile( $path ) {
		if ( ! is_file( $path ) ) {
			throw new Exception( "YAML file $path does not exist." );
		}

		// Same parser EE uses, so assertions see what EE sees.
		return \Mustangostang\Spyc::YAMLLoad( $path );
	}

	private static function normalizeForCompare( $value ) {
		if ( is_array( $value ) ) {
			$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
			if ( ! $is_list ) {
				ksort( $value );
			}
			foreach ( $value as $k => $v ) {
				$value[ $k ] = self::normalizeForCompare( $v );
			}
		}

		return $value;
	}

	/**
	 * @BeforeScenario
	 */
	public function snapshotRealConfig( BeforeScenarioScope $scope ) {
		// An EE_CONFIG_PATH inherited from the caller's shell must not leak into scenarios.
		putenv( 'EE_CONFIG_PATH' );
		unset( $_ENV['EE_CONFIG_PATH'] );

		$path                     = self::realConfigPath();
		$this->realConfigSnapshot = is_file( $path ) ? file_get_contents( $path ) : null;
		$this->realConfigMode     = is_file( $path ) ? ( fileperms( $path ) & 0777 ) : null;
	}

	/**
	 * Restores the real config and fails any non-@destructive scenario that changed it.
	 *
	 * @AfterScenario
	 */
	public function restoreConfigState( AfterScenarioScope $scope ) {
		putenv( 'EE_CONFIG_PATH' );
		unset( $_ENV['EE_CONFIG_PATH'] );
		$this->tempConfigPath  = null;
		$this->variables       = [];
		$this->rememberedSizes = [];
		foreach ( $this->exportedEnv as $name ) {
			putenv( $name );
		}
		$this->exportedEnv = [];

		if ( $this->scratchDir && 0 === strpos( $this->scratchDir, sys_get_temp_dir() . '/' . self::SITE_PREFIX ) ) {
			exec( 'rm -rf ' . escapeshellarg( $this->scratchDir ) );
		}
		$this->scratchDir = null;

		if ( false === $this->realConfigSnapshot ) {
			return;
		}

		$path    = self::realConfigPath();
		$current = is_file( $path ) ? file_get_contents( $path ) : null;
		if ( $current === $this->realConfigSnapshot && ! is_link( $path ) ) {
			return;
		}

		if ( null === $this->realConfigSnapshot ) {
			@unlink( $path );
		} else {
			if ( is_link( $path ) ) {
				unlink( $path );
			}
			file_put_contents( $path, $this->realConfigSnapshot );
			chmod( $path, $this->realConfigMode );
		}

		$tags = array_merge( $scope->getFeature()->getTags(), $scope->getScenario()->getTags() );
		if ( ! in_array( 'destructive', $tags, true ) ) {
			throw new Exception( "Scenario modified the real $path without the @destructive tag. It has been restored.\nContent it wrote:\n" . var_export( $current, true ) );
		}
	}

	/**
	 * @Given a temporary ee config file with:
	 */
	public function aTemporaryEeConfigFileWith( PyStringNode $content ) {
		$path = $this->scratchDir() . '/config.yml';
		file_put_contents( $path, $this->replaceVariables( $content->getRaw() ) . "\n" );
		$this->useTempConfig( $path );
	}

	/**
	 * @Given an empty temporary ee config file
	 */
	public function anEmptyTemporaryEeConfigFile() {
		$path = $this->scratchDir() . '/config.yml';
		file_put_contents( $path, '' );
		$this->useTempConfig( $path );
	}

	/**
	 * @Given a temporary ee config path that does not exist yet
	 */
	public function aTemporaryEeConfigPathThatDoesNotExistYet() {
		$this->useTempConfig( $this->scratchDir() . '/missing/nested/config.yml' );
	}

	/**
	 * @Given the temporary ee config file is a symlink
	 */
	public function theTemporaryEeConfigFileIsASymlink() {
		$path   = $this->requireTempConfig();
		$target = dirname( $path ) . '/real-target.yml';
		rename( $path, $target );
		symlink( $target, $path );
		$this->variables['{CONFIG_TARGET}'] = $target;
	}

	/**
	 * @Then the temporary ee config file should be a symlink
	 */
	public function theTemporaryEeConfigFileShouldBeASymlink() {
		$path = $this->requireTempConfig();
		if ( ! is_link( $path ) ) {
			throw new Exception( "$path is no longer a symlink; it was replaced by a regular file with:\n" . file_get_contents( $path ) );
		}
	}

	private function requireTempConfig() {
		if ( ! $this->tempConfigPath ) {
			throw new Exception( 'No temporary ee config file is set up for this scenario.' );
		}

		return $this->tempConfigPath;
	}

	/**
	 * @Then the real ee config file should be unchanged
	 */
	public function theRealEeConfigFileShouldBeUnchanged() {
		$path    = self::realConfigPath();
		$current = is_file( $path ) ? file_get_contents( $path ) : null;
		if ( $current !== $this->realConfigSnapshot ) {
			throw new Exception( "The real $path changed.\nBefore:\n" . var_export( $this->realConfigSnapshot, true ) . "\nNow:\n" . var_export( $current, true ) );
		}
	}

	/**
	 * @Then the real ee config file should have the same values as before
	 */
	public function theRealEeConfigFileShouldHaveTheSameValuesAsBefore() {
		$before = null === $this->realConfigSnapshot ? [] : \Mustangostang\Spyc::YAMLLoadString( $this->realConfigSnapshot );
		$now    = self::loadYamlFile( self::realConfigPath() );
		if ( $before !== $now ) {
			throw new Exception( "Real config values differ.\nBefore: " . json_encode( $before ) . "\nNow: " . json_encode( $now ) );
		}
	}

	/**
	 * Lets a command use a value without it appearing in the command line (which the runner logs).
	 *
	 * @Given the environment variable :name is set to :value
	 */
	public function theEnvironmentVariableIsSetTo( $name, $value ) {
		if ( 'EE_CONFIG_PATH' === $name ) {
			throw new Exception( 'Use a temporary ee config step to set EE_CONFIG_PATH.' );
		}
		putenv( $name . '=' . $this->replaceVariables( $value ) );
		$this->exportedEnv[] = $name;
	}

	/**
	 * @Then the real ee config file should have the same mode as before
	 */
	public function theRealEeConfigFileShouldHaveTheSameModeAsBefore() {
		clearstatcache();
		$path   = self::realConfigPath();
		$actual = is_file( $path ) ? ( fileperms( $path ) & 0777 ) : null;
		if ( $actual !== $this->realConfigMode ) {
			throw new Exception( sprintf( 'Mode of %s changed from %s to %s', $path, var_export( $this->realConfigMode, true ), null === $actual ? 'missing' : sprintf( '%04o', $actual ) ) );
		}
	}

	/**
	 * @Given a directory in PATH where docker and docker-compose always fail
	 */
	public function aDirectoryInPathWhereDockerAndDockerComposeAlwaysFail() {
		$dir = $this->scratchDir() . '/failing-docker-bin';
		mkdir( $dir, 0700, true );
		foreach ( [ 'docker', 'docker-compose' ] as $bin ) {
			file_put_contents( "$dir/$bin", "#!/bin/sh\necho 'fake $bin: unavailable' >&2\nexit 1\n" );
			chmod( "$dir/$bin", 0755 );
		}
		$this->variables['{FAILING_DOCKER_DIR}'] = $dir;
	}

	/**
	 * @Given the file :path has mode :mode
	 */
	public function theFileHasMode( $path, $mode ) {
		$path = $this->replaceVariables( $path );
		if ( ! chmod( $path, octdec( $mode ) ) ) {
			throw new Exception( "Unable to chmod $path to $mode" );
		}
	}

	/**
	 * @Then the file :path should have mode :mode
	 */
	public function theFileShouldHaveMode( $path, $mode ) {
		$path = $this->replaceVariables( $path );
		clearstatcache();
		$actual = sprintf( '%04o', fileperms( $path ) & 0777 );
		if ( sprintf( '%04o', octdec( $mode ) ) !== $actual ) {
			throw new Exception( "Expected $path to have mode $mode, got $actual" );
		}
	}

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist( $path ) {
		$path = $this->replaceVariables( $path );
		if ( ! file_exists( $path ) ) {
			throw new Exception( "$path does not exist." );
		}
	}

	/**
	 * @Then the file :path should not exist
	 */
	public function theFileShouldNotExist( $path ) {
		$path = $this->replaceVariables( $path );
		if ( file_exists( $path ) ) {
			throw new Exception( "$path exists, with:\n" . ( is_file( $path ) ? file_get_contents( $path ) : '(not a regular file)' ) );
		}
	}

	/**
	 * Exact match, ignoring trailing newlines.
	 *
	 * @Then the file :path should contain exactly:
	 */
	public function theFileShouldContainExactly( $path, PyStringNode $expected ) {
		$path = $this->replaceVariables( $path );
		if ( ! is_file( $path ) ) {
			throw new Exception( "$path does not exist." );
		}
		$actual = file_get_contents( $path );
		if ( rtrim( $expected->getRaw(), "\n" ) !== rtrim( $actual, "\n" ) ) {
			throw new Exception( "Content of $path differs.\nExpected:\n" . $expected->getRaw() . "\nActual:\n" . $actual );
		}
	}

	/**
	 * @Then the file :path should contain:
	 */
	public function theFileShouldContain( $path, PyStringNode $expected ) {
		$path   = $this->replaceVariables( $path );
		$actual = is_file( $path ) ? file_get_contents( $path ) : '';
		if ( false === strpos( $actual, $this->replaceVariables( $expected->getRaw() ) ) ) {
			throw new Exception( "$path does not contain:\n" . $expected->getRaw() . "\nActual:\n" . $actual );
		}
	}

	/**
	 * @Then the file :path should not contain:
	 */
	public function theFileShouldNotContain( $path, PyStringNode $unexpected ) {
		$path   = $this->replaceVariables( $path );
		$actual = is_file( $path ) ? file_get_contents( $path ) : '';
		if ( false !== strpos( $actual, $this->replaceVariables( $unexpected->getRaw() ) ) ) {
			throw new Exception( "$path unexpectedly contains:\n" . $unexpected->getRaw() . "\nActual:\n" . $actual );
		}
	}

	/**
	 * @Given I remember the size of the file :path
	 */
	public function iRememberTheSizeOfTheFile( $path ) {
		$path = $this->replaceVariables( $path );
		clearstatcache();
		$this->rememberedSizes[ $path ] = is_file( $path ) ? filesize( $path ) : 0;
	}

	/**
	 * @Then the text appended to the file :path should not contain :text
	 */
	public function theTextAppendedToTheFileShouldNotContain( $path, $text ) {
		$path = $this->replaceVariables( $path );
		$text = $this->replaceVariables( $text );
		if ( ! isset( $this->rememberedSizes[ $path ] ) ) {
			throw new Exception( "Size of $path was not remembered earlier in the scenario." );
		}
		clearstatcache();
		$content = is_file( $path ) ? file_get_contents( $path ) : '';
		// A rotated (shrunk) file is checked as a whole.
		$appended = strlen( $content ) >= $this->rememberedSizes[ $path ] ? substr( $content, $this->rememberedSizes[ $path ] ) : $content;
		$lines    = array_filter(
			explode( "\n", $appended ),
			function ( $line ) use ( $text ) {
				return false !== strpos( $line, $text );
			}
		);
		if ( $lines ) {
			throw new Exception( "$path gained lines containing '$text':\n" . implode( "\n", $lines ) );
		}
	}

	/**
	 * @Then the YAML file :path should have key :key with value :value
	 */
	public function theYamlFileShouldHaveKeyWithValue( $path, $key, $value ) {
		$path   = $this->replaceVariables( $path );
		$config = self::loadYamlFile( $path );
		if ( ! array_key_exists( $key, $config ) ) {
			throw new Exception( "Key '$key' not found in $path. Keys: " . implode( ', ', array_keys( $config ) ) );
		}
		if ( $config[ $key ] !== $value ) {
			throw new Exception( "Key '$key' in $path is " . var_export( $config[ $key ], true ) . ', expected ' . var_export( $value, true ) );
		}
	}

	/**
	 * @Then the YAML file :path should have key :key
	 */
	public function theYamlFileShouldHaveKey( $path, $key ) {
		$path   = $this->replaceVariables( $path );
		$config = self::loadYamlFile( $path );
		if ( ! array_key_exists( $key, $config ) ) {
			throw new Exception( "Key '$key' not found in $path. Keys: " . implode( ', ', array_keys( $config ) ) );
		}
	}

	/**
	 * @Then the YAML file :path should not have key :key
	 */
	public function theYamlFileShouldNotHaveKey( $path, $key ) {
		$path   = $this->replaceVariables( $path );
		$config = self::loadYamlFile( $path );
		if ( array_key_exists( $key, $config ) ) {
			throw new Exception( "Key '$key' unexpectedly present in $path with value " . var_export( $config[ $key ], true ) );
		}
	}

	/**
	 * Values in the table are JSON literals, so types are checked too ("3" vs 3).
	 *
	 * @Then the YAML file :path should contain exactly these entries:
	 */
	public function theYamlFileShouldContainExactlyTheseEntries( $path, TableNode $table ) {
		$path     = $this->replaceVariables( $path );
		$config   = self::loadYamlFile( $path );
		$expected = [];
		foreach ( $table->getHash() as $row ) {
			$decoded = json_decode( $row['value'], true );
			if ( null === $decoded && 'null' !== trim( $row['value'] ) ) {
				throw new Exception( "Invalid JSON literal in table: {$row['value']}" );
			}
			$expected[ $row['key'] ] = $decoded;
		}
		if ( $expected !== $config ) {
			throw new Exception( "Entries of $path differ (order and types matter).\nExpected: " . json_encode( $expected ) . "\nActual:   " . json_encode( $config ) );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be:$/
	 */
	public function outputShouldBe( $stream, PyStringNode $expected ) {
		$expected = $this->replaceVariables( $expected->getRaw() );
		if ( trim( $expected ) !== trim( $this->lastOutput( $stream ) ) ) {
			throw new Exception( "$stream differs from:\n$expected\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should (contain|not contain):$/
	 */
	public function outputShouldContain( $stream, $action, PyStringNode $expected ) {
		$expected = $this->replaceVariables( $expected->getRaw() );
		$found    = false !== strpos( $this->lastOutput( $stream ), $expected );
		if ( ( 'contain' === $action ) !== $found ) {
			throw new Exception( "$stream should $action:\n$expected\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should be empty$/
	 */
	public function outputShouldBeEmpty( $stream ) {
		if ( '' !== trim( $this->lastOutput( $stream ) ) ) {
			throw new Exception( "$stream is not empty.\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then /^the exit code should be (\d+)$/
	 */
	public function theExitCodeShouldBe( $code ) {
		if ( (int) $code !== $this->lastCommand()->return_code ) {
			throw new Exception( "Expected exit code $code.\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * Strict structural comparison; object key order is ignored, list order is not.
	 *
	 * @Then STDOUT should be JSON equal to:
	 */
	public function stdoutShouldBeJsonEqualTo( PyStringNode $expected ) {
		$actual = json_decode( $this->lastOutput( 'STDOUT' ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'STDOUT is not valid JSON: ' . json_last_error_msg() . "\n\n" . $this->describeLastCommand() );
		}
		$wanted = json_decode( $this->replaceVariables( $expected->getRaw() ), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new Exception( 'Expected value is not valid JSON: ' . json_last_error_msg() );
		}
		if ( self::normalizeForCompare( $wanted ) !== self::normalizeForCompare( $actual ) ) {
			throw new Exception( 'JSON differs from: ' . json_encode( $wanted ) . "\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDOUT should be YAML equal to:
	 */
	public function stdoutShouldBeYamlEqualTo( PyStringNode $expected ) {
		$actual = \Mustangostang\Spyc::YAMLLoadString( $this->lastOutput( 'STDOUT' ) );
		$wanted = \Mustangostang\Spyc::YAMLLoadString( $this->replaceVariables( $expected->getRaw() ) );
		if ( self::normalizeForCompare( $wanted ) !== self::normalizeForCompare( $actual ) ) {
			throw new Exception( 'YAML differs from: ' . json_encode( $wanted ) . "\nParsed actual: " . json_encode( $actual ) . "\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * Accepts both the ASCII renderer (TTY) and the tab-separated one (pipe); rows must match exactly and in order.
	 *
	 * @Then STDOUT should be a table with rows:
	 */
	public function stdoutShouldBeATableWithRows( TableNode $expected ) {
		$rows = [];
		foreach ( explode( "\n", $this->lastOutput( 'STDOUT' ) ) as $line ) {
			$line = rtrim( $line, "\r" );
			if ( '' === trim( $line ) || preg_match( '/^\+[-+]+\+$/', trim( $line ) ) ) {
				continue;
			}
			if ( '|' === substr( $line, 0, 1 ) ) {
				$rows[] = array_map( 'trim', explode( '|', trim( trim( $line ), '|' ) ) );
			} else {
				$rows[] = explode( "\t", $line );
			}
		}
		$wanted = array_map(
			function ( $row ) {
				return array_map( [ $this, 'replaceVariables' ], $row );
			},
			$expected->getRows()
		);
		if ( $wanted !== $rows ) {
			throw new Exception( 'Table differs. Expected rows: ' . json_encode( $wanted ) . "\nParsed rows: " . json_encode( $rows ) . "\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDOUT should be CSV with rows:
	 */
	public function stdoutShouldBeCsvWithRows( TableNode $expected ) {
		$rows = [];
		foreach ( explode( "\n", rtrim( $this->lastOutput( 'STDOUT' ), "\n" ) ) as $line ) {
			$rows[] = str_getcsv( $line, ',', '"', '\\' );
		}
		$wanted = array_map(
			function ( $row ) {
				return array_map( [ $this, 'replaceVariables' ], $row );
			},
			$expected->getRows()
		);
		if ( $wanted !== $rows ) {
			throw new Exception( 'CSV differs. Expected rows: ' . json_encode( $wanted ) . "\nParsed rows: " . json_encode( $rows ) . "\n\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * For `ee config list --format=json`-style output: [{"key":..,"value":..}] in file order.
	 *
	 * @Then STDOUT should be JSON listing the entries of the YAML file :path
	 */
	public function stdoutShouldBeJsonListingTheEntriesOfTheYamlFile( $path ) {
		$config   = self::loadYamlFile( $this->replaceVariables( $path ) );
		$expected = [];
		foreach ( $config as $key => $value ) {
			$expected[] = [
				'key'   => $key,
				'value' => $value,
			];
		}
		$actual = json_decode( $this->lastOutput( 'STDOUT' ), true );
		if ( $expected !== $actual ) {
			throw new Exception( 'Expected: ' . json_encode( $expected ) . "\n\n" . $this->describeLastCommand() );
		}
	}
}
