<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of class/peppolcandidatecheck.class.php (made-up data only, no database).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/class/peppolcandidatecheck.class.php';

/**
 * Result of a SELECT of the fake database (like a result set of DoliDB: also an empty result is a valid result)
 */
class PeppolCandidateCheckFakeResult
{
	/** @var array<int,object> */
	public $rows;

	/**
	 * @param array<int,object> $rows Rows
	 */
	public function __construct($rows)
	{
		$this->rows = $rows;
	}
}

/**
 * Minimal stand-in for the database: remembers the candidates and lookups the class writes.
 * Only the queries of PeppolCandidateCheck::saveCandidate() and saveLookup() are understood.
 */
class PeppolCandidateCheckFakeDb
{
	/** @var array<int,array<string,string>> */
	public $candidates = array();

	/** @var array<int,array<string,string>> */
	public $lookups = array();

	/** @var string[] */
	public $queries = array();

	/**
	 * Run a query
	 *
	 * @param string $sql Query
	 * @return PeppolCandidateCheckFakeResult|bool
	 */
	public function query($sql)
	{
		$this->queries[] = $sql;
		if (preg_match("/^SELECT rowid FROM llx_peppol_candidate WHERE .* fk_soc = (\d+) AND participant_id = '([^']*)'/", $sql, $m)) {
			foreach ($this->candidates as $id => $c) {
				if ($c['fk_soc'] === $m[1] && $c['participant_id'] === $m[2]) {
					return new PeppolCandidateCheckFakeResult(array((object) array('rowid' => $id)));
				}
			}
			return new PeppolCandidateCheckFakeResult(array());
		}
		if (preg_match("/^SELECT rowid FROM llx_peppol_lookup WHERE .* fk_soc = (\d+)/", $sql, $m)) {
			foreach ($this->lookups as $id => $l) {
				if ($l['fk_soc'] === $m[1]) {
					return new PeppolCandidateCheckFakeResult(array((object) array('rowid' => $id)));
				}
			}
			return new PeppolCandidateCheckFakeResult(array());
		}
		if (preg_match("/^INSERT INTO llx_peppol_candidate .* VALUES \(\d+, (\d+), '([^']*)', '[^']*', '[^']*', '([^']*)', (\w+),/", $sql, $m)) {
			$this->candidates[count($this->candidates) + 1] = array('fk_soc' => $m[1], 'participant_id' => $m[2], 'matched_field' => $m[3], 'accepts_invoice' => $m[4]);
			return true;
		}
		if (preg_match("/^INSERT INTO llx_peppol_lookup .* VALUES \(\d+, (\d+), '[^']*', (\d+)\)/", $sql, $m)) {
			$this->lookups[count($this->lookups) + 1] = array('fk_soc' => $m[1], 'nb_found' => $m[2]);
			return true;
		}
		if (preg_match("/^UPDATE llx_peppol_(candidate|lookup) SET/", $sql)) {
			return true;
		}
		return false;
	}

	/**
	 * Next row of a result
	 *
	 * @param PeppolCandidateCheckFakeResult $res Result of query()
	 * @return object|false
	 */
	public function fetch_object($res) // phpcs:ignore PEAR.NamingConventions.ValidFunctionName.ScopeNotCamelCaps -- name of the DoliDB method
	{
		return ($res instanceof PeppolCandidateCheckFakeResult && count($res->rows)) ? array_shift($res->rows) : false;
	}

	/**
	 * Free a result
	 *
	 * @param mixed $res Result
	 * @return void
	 */
	public function free($res)
	{
	}

	/**
	 * Escape a value
	 *
	 * @param string $s Value
	 * @return string
	 */
	public function escape($s)
	{
		return str_replace("'", "''", (string) $s);
	}

	/**
	 * Date for SQL
	 *
	 * @param int $t Timestamp
	 * @return string
	 */
	public function idate($t)
	{
		return gmdate('Y-m-d H:i:s', (int) $t);
	}

	/**
	 * Last error
	 *
	 * @return string
	 */
	public function lasterror()
	{
		return '';
	}
}

/**
 * Tests of the weekly check of third parties against the Peppol directory
 */
class PeppolCandidateCheckTest extends TestCase
{
	/** @var array<int,string> Search terms the stubbed directory received */
	public static $searches = array();

	/** @var array<string,int> Number of HTTP 429 answers left per term */
	public static $rateLimited = array();

	/** @var bool Stubbed directory down */
	public static $down = false;

	/**
	 * Stubbed directory search (made-up participants)
	 *
	 * @param string $term Search term
	 * @return array<string,mixed>
	 */
	public static function directory($term)
	{
		self::$searches[] = $term;
		if (self::$down) {
			return array('error' => 'HTTP error: 503', 'reason' => 'HTTP 503', 'http_code' => 503);
		}
		if (!empty(self::$rateLimited[$term])) {
			self::$rateLimited[$term]--;
			return array('error' => 'HTTP error: 429', 'reason' => 'HTTP 429', 'http_code' => 429);
		}
		$entity = array('name' => array(array('name' => 'Example Company B.V.')), 'countryCode' => 'NL', 'regDate' => '2024-05-01');
		$invoice = array(array('scheme' => 'busdox-docid-qns', 'value' => PeppolLibTest::INVOICE));
		if ($term === '12345678') {
			return array('matches' => array(
				array('participantID' => array('scheme' => 'iso6523-actorid-upis', 'value' => '0106:12345678'), 'docTypes' => $invoice, 'entities' => array($entity)),
				// Another participant in the same result: not this third party, must not be kept
				array('participantID' => array('scheme' => 'iso6523-actorid-upis', 'value' => '0106:99999999'), 'docTypes' => $invoice, 'entities' => array($entity)),
			));
		}
		if ($term === 'NL001234567B01') {
			return array('matches' => array(
				array('participantID' => array('scheme' => 'iso6523-actorid-upis', 'value' => '9944:nl001234567b01'), 'entities' => array($entity)),
			));
		}
		return array('matches' => array());
	}

	/**
	 * A check with the stubbed directory and the fake database
	 *
	 * @param PeppolCandidateCheckFakeDb $db Fake database
	 * @return PeppolCandidateCheck
	 */
	private function check($db)
	{
		self::$searches = array();
		self::$rateLimited = array();
		self::$down = false;
		$check = new PeppolCandidateCheck($db);
		$check->pause = 0;
		$check->retryWait = 0;
		$check->searchFunction = array(self::class, 'directory');
		return $check;
	}

	/**
	 * A made-up third party
	 *
	 * @param int    $id      Id
	 * @param string $vat     VAT number
	 * @param string $profid1 Professional ID 1
	 * @return stdClass
	 */
	private function thirdparty($id, $vat, $profid1)
	{
		$soc = new stdClass();
		$soc->id = $id;
		$soc->name = 'Example Company B.V.';
		$soc->tva_intra = $vat;
		$soc->idprof1 = $profid1;
		$soc->country_code = 'NL';
		return $soc;
	}

	/**
	 * Searches by VAT number and KvK number only (never by name) and keeps only the IDs with exactly that number
	 *
	 * @return void
	 */
	public function testKeepsOnlyExactMatches()
	{
		$db = new PeppolCandidateCheckFakeDb();
		$check = $this->check($db);
		$res = $check->checkThirdparty($this->thirdparty(1, 'NL 0012.34.567.B01', '1234 5678'), true);

		$this->assertSame(array('found' => 2, 'new' => 2, 'error' => false, 'reason' => ''), $res);
		$this->assertSame(array('NL001234567B01', '12345678'), self::$searches);
		$ids = array_column($db->candidates, 'participant_id');
		sort($ids);
		$this->assertSame(array('0106:12345678', '9944:nl001234567b01'), $ids);
		$byid = array_column($db->candidates, null, 'participant_id');
		$this->assertSame('idprof1', $byid['0106:12345678']['matched_field']);
		$this->assertSame('1', $byid['0106:12345678']['accepts_invoice']);
		$this->assertSame('NULL', $byid['9944:nl001234567b01']['accepts_invoice']);
		$this->assertSame(array('fk_soc' => '1', 'nb_found' => '2'), $db->lookups[1]);
	}

	/**
	 * A second check of the same third party updates the candidates instead of adding them again
	 *
	 * @return void
	 */
	public function testNoDuplicatesOnSecondCheck()
	{
		$db = new PeppolCandidateCheckFakeDb();
		$check = $this->check($db);
		$check->checkThirdparty($this->thirdparty(1, 'NL001234567B01', '12345678'), true);
		$res = $check->checkThirdparty($this->thirdparty(1, 'NL001234567B01', '12345678'), true);

		$this->assertSame(array('found' => 2, 'new' => 0, 'error' => false, 'reason' => ''), $res);
		$this->assertCount(2, $db->candidates);
		$this->assertCount(1, $db->lookups);
	}

	/**
	 * When the directory cannot be reached nothing is stored, so the third party is checked again next time
	 *
	 * @return void
	 */
	public function testDirectoryDownStoresNothing()
	{
		$db = new PeppolCandidateCheckFakeDb();
		$check = $this->check($db);
		self::$down = true;
		$res = $check->checkThirdparty($this->thirdparty(1, 'NL001234567B01', '12345678'), true);

		$this->assertTrue($res['error']);
		$this->assertSame('HTTP 503', $check->lastErrorReason);
		$this->assertSame('HTTP 503', $res['reason']);
		$this->assertCount(0, $db->candidates);
		$this->assertCount(0, $db->lookups);
	}

	/**
	 * After HTTP 429 (too many requests) the search is tried once more
	 *
	 * @return void
	 */
	public function testRetryAfterTooManyRequests()
	{
		$db = new PeppolCandidateCheckFakeDb();
		$check = $this->check($db);
		self::$rateLimited = array('12345678' => 1);
		$res = $check->checkThirdparty($this->thirdparty(1, '', '12345678'), true);

		$this->assertFalse($res['error']);
		$this->assertSame(1, $res['found']);
		$this->assertSame(array('12345678', '12345678'), self::$searches);

		// Twice 429: no second retry, the check stops with the reason
		self::$searches = array();
		self::$rateLimited = array('NL007777777B01' => 2);
		$res = $check->checkThirdparty($this->thirdparty(2, 'NL007777777B01', ''), true);
		$this->assertTrue($res['error']);
		$this->assertSame('HTTP 429', $check->lastErrorReason);
		$this->assertCount(2, self::$searches);
	}

	/**
	 * The result message is readable, also without translation
	 *
	 * @return void
	 */
	public function testResultMessage()
	{
		$check = $this->check(new PeppolCandidateCheckFakeDb());
		$check->lastErrorReason = 'HTTP 503';
		$msg = $check->resultMessage(array('checked' => 3, 'found' => 2, 'new' => 1, 'errors' => 1));
		$this->assertStringContainsString('3', $msg);
		$this->assertStringContainsString('(HTTP 503)', $msg);
		$this->assertStringNotContainsString('peppolCandidateCheck', $msg);
	}
}
