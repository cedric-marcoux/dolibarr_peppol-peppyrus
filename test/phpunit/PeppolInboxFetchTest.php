<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of class/peppolinboxfetch.class.php (stubbed access point, no network, no database).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/class/peppolinboxfetch.class.php';

/**
 * Stand-in for the user of the scheduled job
 */
class PeppolInboxFetchFakeUser
{
	/** @var bool */
	public $canWrite = true;

	/**
	 * Right check as User::hasRight()
	 *
	 * @param string $module Module
	 * @param string $perm   Permission
	 * @return bool
	 */
	public function hasRight($module, $perm)
	{
		return $module === 'peppolpeppyrus' && $perm === 'write' && $this->canWrite;
	}
}

/**
 * Stand-in for the access point
 */
class PeppolInboxFetchFakeAP
{
	/** @var int Return value of getSupplierInvoicesList() */
	public $ret = 0;

	/** @var array<string,int> */
	public $fetchStats = array('listed' => 0, 'new' => 0, 'retried' => 0, 'failed' => 0);

	/** @var string[] */
	public $errors = array();

	/** @var int */
	public $calls = 0;

	/**
	 * Stubbed fetch
	 *
	 * @return int
	 */
	public function getSupplierInvoicesList()
	{
		$this->calls++;
		return $this->ret;
	}
}

/**
 * Tests of the daily fetch of incoming documents
 */
class PeppolInboxFetchTest extends TestCase
{
	/** @var PeppolInboxFetchFakeAP */
	public static $ap;

	/**
	 * The stubbed access point
	 *
	 * @return PeppolInboxFetchFakeAP
	 */
	public static function ap()
	{
		return self::$ap;
	}

	/**
	 * A fetch with the stubbed access point, module enabled, user with the right
	 *
	 * @return PeppolInboxFetch
	 */
	private function fetcher()
	{
		global $conf, $user;
		$conf->modules = array('peppolpeppyrus' => 'peppolpeppyrus');
		$conf->peppolpeppyrus = new stdClass();
		$conf->peppolpeppyrus->enabled = 1;
		$user = new PeppolInboxFetchFakeUser();
		self::$ap = new PeppolInboxFetchFakeAP();
		$fetch = new PeppolInboxFetch(null);
		$fetch->apFactory = array(self::class, 'ap');
		return $fetch;
	}

	/**
	 * Clean up the globals
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf, $user;
		$conf->modules = array();
		unset($conf->peppolpeppyrus);
		$user = null;
	}

	/**
	 * New and retried documents count as fetched; the job is OK
	 *
	 * @return void
	 */
	public function testFetchOk()
	{
		$fetch = $this->fetcher();
		self::$ap->ret = 3;
		self::$ap->fetchStats = array('listed' => 3, 'new' => 2, 'retried' => 1, 'failed' => 0);
		$this->assertSame(0, $fetch->runDailyFetch());
		$this->assertSame(1, self::$ap->calls);
		$this->assertStringContainsString('3', $fetch->output);
		$this->assertSame('', $fetch->error);
	}

	/**
	 * A document that could not be fetched makes the job report an error (it is tried again next time)
	 *
	 * @return void
	 */
	public function testSomeFailed()
	{
		$fetch = $this->fetcher();
		self::$ap->ret = 2;
		self::$ap->fetchStats = array('listed' => 2, 'new' => 1, 'retried' => 0, 'failed' => 1);
		$this->assertSame(1, $fetch->runDailyFetch());
		$this->assertNotSame('', $fetch->error);
	}

	/**
	 * Access point not reachable or not configured: error with the technical reason
	 *
	 * @return void
	 */
	public function testAccessPointError()
	{
		$fetch = $this->fetcher();
		self::$ap->ret = -10;
		self::$ap->errors = array('Missing API key configuration (PEPPOL_AP_API_KEY)');
		$this->assertSame(1, $fetch->runDailyFetch());
		$this->assertStringContainsString('PEPPOL_AP_API_KEY', $fetch->error);
	}

	/**
	 * Without the right Peppol: write nothing is fetched
	 *
	 * @return void
	 */
	public function testNoRight()
	{
		global $user;
		$fetch = $this->fetcher();
		$user->canWrite = false;
		$this->assertSame(1, $fetch->runDailyFetch());
		$this->assertSame(0, self::$ap->calls);
	}

	/**
	 * Module disabled: nothing happens, no error
	 *
	 * @return void
	 */
	public function testModuleDisabled()
	{
		global $conf;
		$fetch = $this->fetcher();
		$conf->modules = array();
		unset($conf->peppolpeppyrus);
		$this->assertSame(0, $fetch->runDailyFetch());
		$this->assertSame(0, self::$ap->calls);
	}
}
