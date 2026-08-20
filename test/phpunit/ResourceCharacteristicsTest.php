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
		$resource->max_payload_weight = 500.0;
		$resource->cooldown_minutes = 30;
		$resource->applyTypeCapabilities();

		$this->assertNull($resource->max_users);
		$this->assertSame(0, $resource->allow_overflow);
		$this->assertNull($resource->metric_value);
		$this->assertNull($resource->max_payload_weight);
		$this->assertSame(30, $resource->cooldown_minutes);
	}

	/**
	 * Volume resources retain their declared capacity.
	 *
	 * @return void
	 */
	public function testVolumeCapacityRetainsMetricValue(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'c_type_resource';
		$sql .= ' (code, label, capacity_mode, metric_label, metric_unit, supports_cooldown, active)';
		$sql .= " VALUES ('PHPUNIT_VEHICLE', 'Vehicle', 'volume', 'Load volume', 'm3', 0, 1)";
		$this->assertTrue((bool) $this->db->query($sql));

		$resource = new Dolresource($this->db);
		$resource->fk_code_type_resource = 'PHPUNIT_VEHICLE';
		$resource->metric_value = 12.5;
		$resource->allow_overflow = 1;
		$resource->max_payload_weight = 950.0;
		$resource->operational_location = 'Madrid depot';
		$resource->applyTypeCapabilities();

		$this->assertSame('volume', $resource->capacity_mode);
		$this->assertSame(12.5, $resource->metric_value);
		$this->assertSame(1, $resource->allow_overflow);
		$this->assertSame(950.0, $resource->max_payload_weight);
		$this->assertSame('Madrid depot', $resource->operational_location);
		$this->assertSame('m3', $resource->metric_unit);
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

	/**
	 * Missing types fail closed while an explicitly untyped resource remains valid.
	 *
	 * @return void
	 */
	public function testUnknownAndNullResourceTypes(): void
	{
		$resource = new Dolresource($this->db);
		$resource->fk_code_type_resource = 'PHPUNIT_UNKNOWN_TYPE';
		$this->assertSame(-1, $resource->applyTypeCapabilities(true));

		$untypedResource = new Dolresource($this->db);
		$untypedResource->fk_code_type_resource = null;
		$this->assertSame(1, $untypedResource->applyTypeCapabilities(true));
		$this->assertSame('none', $untypedResource->capacity_mode);
	}

	/**
	 * Existing resources may retain an inactive historical type, but it cannot
	 * be selected for a new resource or a type change.
	 *
	 * @return void
	 */
	public function testInactiveResourceTypeIsReadOnly(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'c_type_resource';
		$sql .= ' (code, label, capacity_mode, supports_cooldown, active)';
		$sql .= " VALUES ('PHPUNIT_INACTIVE', 'Inactive', 'users', 0, 0)";
		$this->assertTrue((bool) $this->db->query($sql));

		$resource = new Dolresource($this->db);
		$resource->fk_code_type_resource = 'PHPUNIT_INACTIVE';
		$this->assertSame(1, $resource->applyTypeCapabilities(false));
		$this->assertSame('users', $resource->capacity_mode);
		$this->assertSame(-1, $resource->applyTypeCapabilities(true));
	}
}
