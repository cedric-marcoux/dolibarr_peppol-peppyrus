<?php
/**
 * Tests of class/peppolautosend.class.php: hours of sending (no network, no database).
 */

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/class/peppolautosend.class.php';

/**
 * Automatic sending of customer invoices: when to send
 */
class PeppolAutoSendTest extends TestCase
{
	/**
	 * Hours from the setting: 0-23 only, sorted, no duplicates; empty or invalid gives 9
	 *
	 * @return void
	 */
	public function testHours()
	{
		$this->assertSame(array(9), PeppolAutoSend::hours(''));
		$this->assertSame(array(9), PeppolAutoSend::hours('abc'));
		$this->assertSame(array(8, 13, 17), PeppolAutoSend::hours('17, 8;13 8'));
		$this->assertSame(array(0, 23), PeppolAutoSend::hours('23,0,24,-1,7.5'));
	}

	/**
	 * Only at an hour of sending, and only once per hour
	 *
	 * @return void
	 */
	public function testSlotToSend()
	{
		$now = dol_mktime(13, 20, 0, 11, 5, 2026, 'tzserver');
		$this->assertSame('2026-11-05 13', PeppolAutoSend::slotToSend(array(9, 13), $now, ''));
		$this->assertSame('', PeppolAutoSend::slotToSend(array(9, 13), $now, '2026-11-05 13'));
		$this->assertSame('2026-11-05 13', PeppolAutoSend::slotToSend(array(13), $now, '2026-11-04 13'));
		$this->assertSame('', PeppolAutoSend::slotToSend(array(9), $now, ''));
	}

	/**
	 * Module disabled: nothing happens, no error
	 *
	 * @return void
	 */
	public function testModuleDisabled()
	{
		global $conf;
		$conf->modules = array();
		$send = new PeppolAutoSend(null);
		$called = false;
		$send->apFactory = function () use (&$called) {
			$called = true;
			return null;
		};
		$this->assertSame(0, $send->runAutoSend());
		$this->assertFalse($called);
	}
}
