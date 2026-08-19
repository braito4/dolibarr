<?php
/* Copyright (C) 2026 Dolibarr contributors */

use PHPUnit\Framework\TestCase;

global $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/dolresource.class.php';

/**
 * Tests for resource type characteristics.
 *
 * @backupGlobals disabled
 */
class ResourceCharacteristicsTest extends TestCase
{
	/** @var DoliDB */
	private $db;

	/**
	 * Start an isolated transaction.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db;
		$this->db = $db;
		$this->db->begin();
	}

	/**
	 * Roll back fixture data.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		$this->db->rollback();
	}

	/**
	 * Unsupported characteristics are removed according to the type model.
	 *
	 * @return void
	 */
	public function testTypeCapabilitiesClearUnsupportedValues(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'c_type_resource';
		$sql .= ' (code, label, capacity_mode, metric_label, metric_unit, supports_cooldown, active)';
		$sql .= " VALUES ('PHPUNIT_MACHINE', 'Machine', 'none', NULL, NULL, 1, 1)";
		$this->assertTrue((bool) $this->db->query($sql));

		$resource = new Dolresource($this->db);
		$resource->fk_code_type_resource = 'PHPUNIT_MACHINE';
		$resource->max_users = 4;
		$resource->allow_overflow = 1;
		$resource->metric_value = 25.5;
		$resource->cooldown_minutes = 30;
		$resource->applyTypeCapabilities();

		$this->assertNull($resource->max_users);
		$this->assertSame(0, $resource->allow_overflow);
		$this->assertNull($resource->metric_value);
		$this->assertSame(30, $resource->cooldown_minutes);
	}

	/**
	 * Manual statuses expose unknown, free and out-of-service values.
	 *
	 * @return void
	 */
	public function testManualStatusList(): void
	{
		$statuses = Dolresource::getStatusArray();

		$this->assertArrayHasKey(Dolresource::STATUS_UNKNOWN, $statuses);
		$this->assertArrayHasKey(Dolresource::STATUS_FREE, $statuses);
		$this->assertArrayHasKey(Dolresource::STATUS_OUT_OF_SERVICE, $statuses);
		$this->assertArrayNotHasKey(Dolresource::STATUS_OCCUPIED, $statuses);
	}
}
