<?php
// SPDX-License-Identifier: GPL-3.0-or-later
/**
 * Tests of class/ap-peppyrus.class.php: error messages of the access point (made-up responses, no network).
 */

use PHPUnit\Framework\TestCase;

require_once DOL_DOCUMENT_ROOT.'/custom/peppolpeppyrus/class/ap-peppyrus.class.php';

/**
 * Makes the protected method reachable for the test
 */
class ApPeppyrusTestDubbel extends \custom\peppolpeppyrus\Peppyrus
{
	/**
	 * Read an error message of the access point
	 *
	 * @param string $content Response
	 * @return string
	 */
	public function lees($content)
	{
		return $this->parseValidationError($content);
	}
}

/**
 * Text from the access point is escaped before it is shown (from the original module, #19)
 */
class ApPeppyrusTest extends TestCase
{
	/**
	 * HTML in an error message is escaped; only the line breaks added by the module stay HTML
	 *
	 * @return void
	 */
	public function testParseValidationErrorEscapes()
	{
		$ap = new ApPeppyrusTestDubbel(null);
		$uit = $ap->lees('{"message":"<b>Fout</b>","errors":["<img src=x onerror=alert(1)>"]}');
		$this->assertStringNotContainsString('<b>', $uit);
		$this->assertStringNotContainsString('<img', $uit);
		$this->assertStringContainsString('<br>- ', $uit);

		$uit = $ap->lees('<html><script>x</script>Geen JSON</html>');
		$this->assertStringNotContainsString('<script', $uit);
	}
}
