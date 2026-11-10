<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of core/modules/modPeppolpeppyrus.class.php (no network: the answer of GitHub is stubbed).
 */

use PHPUnit\Framework\TestCase;

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';
require_once dirname(__DIR__, 2).'/core/modules/modPeppolpeppyrus.class.php';

/**
 * The module with a stubbed answer for the file with the latest version
 */
class ModPeppolpeppyrusStub extends modPeppolpeppyrus
{
	/** @var array{http_code?:int,content?:string} */
	public $answer = array();

	/**
	 * Stubbed answer instead of a request to GitHub
	 *
	 * @return array{http_code?:int,content?:string}
	 */
	protected function fetchLastVersion()
	{
		return $this->answer;
	}
}

/**
 * Tests of the module definition
 */
class ModPeppolpeppyrusTest extends TestCase
{
	/**
	 * Only an answer with HTTP 200 and a short version number counts; a newer version gives 1
	 *
	 * @return void
	 */
	public function testCheckForUpdate()
	{
		$mod = new ModPeppolpeppyrusStub(null);
		$this->assertStringStartsWith('https://', $mod->url_last_version);
		$current = $mod->version;
		list($major, $minor) = explode('.', $current);

		$mod->answer = array('http_code' => 200, 'content' => $major.'.'.((int) $minor + 1).".0\n");
		$this->assertSame(1, $mod->checkForUpdate());
		$this->assertTrue($mod->needUpdate);

		$mod->answer = array('http_code' => 200, 'content' => $current."\n");
		$this->assertSame(0, $mod->checkForUpdate());
		$this->assertFalse($mod->needUpdate);

		$mod->answer = array('http_code' => 404, 'content' => '404: Not Found');
		$this->assertSame(-1, $mod->checkForUpdate());

		$mod->answer = array('http_code' => 200, 'content' => '<html>'.str_repeat('x', 40));
		$this->assertSame(-1, $mod->checkForUpdate());

		// Remote content: only the characters of a version number are kept
		$mod->answer = array('http_code' => 200, 'content' => '9.9.9<b>');
		$this->assertSame(1, $mod->checkForUpdate());
		$this->assertSame('9.9.9b', $mod->lastVersion);
	}

	/**
	 * Permission ids are <numero><nn>: changing them would remove every granted permission
	 *
	 * @return void
	 */
	public function testPermissionIds()
	{
		$mod = new modPeppolpeppyrus(null);
		$ids = array_map(function ($r) {
			return $r[0];
		}, $mod->rights);
		$this->assertSame(array(43618101, 43618102, 43618103, 43618104), $ids);
		$this->assertSame('peppolpeppyrus', $mod->rights_class);
		$this->assertSame(trim((string) file_get_contents(dirname(__DIR__, 2).'/VERSION')), $mod->version);
	}

	/**
	 * One menu "Peppol" in Billing with the pages under it, no other menu entries
	 *
	 * @return void
	 */
	public function testOneMenuWithSubPages()
	{
		$mod = new modPeppolpeppyrus(null);
		$this->assertCount(4, $mod->menu);
		$parent = $mod->menu[0];
		$this->assertSame('fk_mainmenu=billing', $parent['fk_menu']);
		$this->assertSame('peppol', $parent['leftmenu']);
		$this->assertSame('/peppolpeppyrus/index.php', $parent['url']);
		$urls = array();
		foreach (array_slice($mod->menu, 1) as $entry) {
			$this->assertSame('fk_mainmenu=billing,fk_leftmenu=peppol', $entry['fk_menu']);
			$this->assertSame('billing', $entry['mainmenu']);
			$this->assertSame(0, $entry['user']);
			$urls[] = $entry['url'];
		}
		$this->assertSame(array('/peppolpeppyrus/peppolimport_list.php', '/peppolpeppyrus/peppol_candidates.php', '/peppolpeppyrus/peppol_list.php'), $urls);
	}

	/**
	 * Scheduled jobs: weekly candidate check, daily fetch, automatic sending and (since 2.12.0) the daily check of failed
	 * deliveries, each with an existing class and method
	 *
	 * @return void
	 */
	public function testCronJobs()
	{
		$mod = new modPeppolpeppyrus(null);
		$this->assertCount(4, $mod->cronjobs);
		$this->assertSame('runDailyCheck', $mod->cronjobs[3]['method']);
		$this->assertSame(1, $mod->cronjobs[3]['status']);
		// Automatic sending is off by default
		$this->assertSame('runAutoSend', $mod->cronjobs[2]['method']);
		$this->assertSame(0, $mod->cronjobs[2]['status']);
		$this->assertSame(3600, $mod->cronjobs[2]['unitfrequency']);
		$this->assertSame(604800, $mod->cronjobs[0]['unitfrequency']);
		$this->assertSame('runDailyFetch', $mod->cronjobs[1]['method']);
		$this->assertSame(86400, $mod->cronjobs[1]['unitfrequency']);
		foreach ($mod->cronjobs as $job) {
			$file = dirname(__DIR__, 2).str_replace('/peppolpeppyrus', '', $job['class']);
			$this->assertFileExists($file);
			require_once $file;
			$this->assertTrue(method_exists($job['objectname'], $job['method']), $job['objectname'].'::'.$job['method']);
		}
	}
}
