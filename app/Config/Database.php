<?php

namespace Config;

use CodeIgniter\Database\Config;

/**
 * Database Configuration
 */
class Database extends Config
{
	/**
	 * The directory that holds the Migrations
	 * and Seeds directories.
	 *
	 * @var string
	 */
	public $filesPath = APPPATH . 'Database' . DIRECTORY_SEPARATOR;

	/**
	 * Lets you choose which connection group to
	 * use if no other is specified.
	 *
	 * @var string
	 */
	public $defaultGroup = 'default';

	/**
	 * The default database connection.
	 *
	 * @var array
	 */
	public $default = [
		'DSN'      => '',
		'hostname' => '',
		'username' => '',
		'password' => '',
		'database' => '',
		'DBDriver' => 'MySQLi',
		'DBPrefix' => 'ikontrol_',
		'pConnect' => false,
		'DBDebug'  => (ENVIRONMENT !== 'production'),
		'charset'  => 'utf8',
		'DBCollat' => 'utf8_general_ci',
		'swapPre'  => '',
		'encrypt'  => false,
		'compress' => false,
		'strictOn' => false,
		'failover' => [],
		'port'     => 3306,
	];

	/**
	 * This database connection is used when
	 * running PHPUnit database tests.
	 *
	 * @var array
	 */
	public $tests = [
		'DSN'      => '',
		'hostname' => '127.0.0.1',
		'username' => '',
		'password' => '',
		'database' => ':memory:',
		'DBDriver' => 'SQLite3',
		'DBPrefix' => 'db_',  // Needed to ensure we're working correctly with prefixes live. DO NOT REMOVE FOR CI DEVS
		'pConnect' => false,
		'DBDebug'  => (ENVIRONMENT !== 'production'),
		'charset'  => 'utf8',
		'DBCollat' => 'utf8_general_ci',
		'swapPre'  => '',
		'encrypt'  => false,
		'compress' => false,
		'strictOn' => false,
		'failover' => [],
		'port'     => 3306,
	];

	/**
	 * Read-only legacy FC2 connection. Credentials must belong to a MySQL
	 * account granted SELECT only; the application never migrates this group.
	 */
	public $fc2_legacy = [
		'DSN' => '', 'hostname' => '', 'username' => '', 'password' => '', 'database' => '',
		'DBDriver' => 'MySQLi', 'DBPrefix' => '', 'pConnect' => false,
		'DBDebug' => false, 'charset' => 'utf8mb4', 'DBCollat' => 'utf8mb4_unicode_ci',
		'swapPre' => '', 'encrypt' => false, 'compress' => false, 'strictOn' => true,
		'failover' => [], 'port' => 3306,
	];

	/**
	 * Isolated local build target. It intentionally reuses only the local
	 * database transport credentials, never fiscal/PAC/FC2 configuration.
	 */
	public $clean_build = [];

	/** Isolated destination used only for the DOLD visual preview. */
	public $dold_preview = [];

	//--------------------------------------------------------------------

	public function __construct()
	{
		parent::__construct();
		$this->fc2_legacy['hostname'] = (string) env('FC2_DB_HOST', '');
		$this->fc2_legacy['port'] = (int) env('FC2_DB_PORT', 3306);
		$this->fc2_legacy['database'] = (string) env('FC2_DB_DATABASE', '');
		$this->fc2_legacy['username'] = (string) env('FC2_DB_USERNAME', '');
		$this->fc2_legacy['password'] = (string) env('FC2_DB_PASSWORD', '');
		$this->fc2_legacy['charset'] = (string) env('FC2_DB_CHARSET', 'utf8mb4');
		$this->clean_build = $this->default;
		$this->clean_build['database'] = 'ikontrol20_clean';
		$this->clean_build['DBPrefix'] = 'ikontrol_';
		$this->clean_build['charset'] = 'utf8mb4';
		$this->clean_build['DBCollat'] = 'utf8mb4_general_ci';
		$this->clean_build['pConnect'] = false;
		$this->dold_preview = $this->default;
		$this->dold_preview['database'] = 'ikontrol20_dold_preview';
		$this->dold_preview['DBPrefix'] = 'ikontrol_';
		$this->dold_preview['charset'] = 'utf8mb4';
		$this->dold_preview['DBCollat'] = 'utf8mb4_general_ci';
		$this->dold_preview['pConnect'] = false;

		// Ensure that we always set the database group to 'tests' if
		// we are currently running an automated test suite, so that
		// we don't overwrite live data on accident.
		if (ENVIRONMENT === 'testing')
		{
			$this->defaultGroup = 'tests';
		}
	}

	//--------------------------------------------------------------------

}
