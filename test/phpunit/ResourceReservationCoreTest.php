<?php
/* Copyright (C) 2026 Dolibarr contributors */

use PHPUnit\Framework\TestCase;

global $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/resourcereservationmanager.class.php';

/**
 * Focused tests for the shared resource reservation primitives.
 *
 * @backupGlobals disabled
 */
class ResourceReservationCoreTest extends TestCase
{
	/** @var DoliDB */
	private $db;

	/** @return void */
	protected function setUp(): void
	{
		global $db;
		$this->db = $db;
		$this->db->begin();
	}

	/** @return void */
	protected function tearDown(): void
	{
		$this->db->rollback();
	}

	/**
	 * Decimal Dolibarr durations and custom metrics are normalized by the core.
	 *
	 * @return void
	 */
	public function testDurationAndCustomCapacity(): void
	{
		$manager = new ResourceReservationManager($this->db);
		$this->assertSame(90, $manager->durationStringToMinutes('1.5h'));
		$this->assertSame(75, $manager->calculateDuration(array(
			'setup_duration' => 5,
			'duration_base' => 10,
			'duration_per_unit' => 20,
			'cleanup_duration' => 0,
		), 3));

		$resource = (object) array('capacity_mode' => 'custom', 'metric_value' => 12.5, 'max_users' => null);
		$this->assertSame(12.5, $manager->getResourceMaximumCapacity($resource));
	}

	/**
	 * Intervals are half-open and both capacity and physical units are enforced.
	 *
	 * @return void
	 */
	public function testHalfOpenCapacityAndUnitAccounting(): void
	{
		$resourceId = $this->createResource('PHPUNIT_RESERVATION_CORE', 2, 1);
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, capacity_used, resource_units_used, date_start, date_end, reservation_status)';
		$sql .= " VALUES (99001, 'propaldet', ".((int) $resourceId).", 'dolresource', 'assignment', 2, 1, '2099-01-05 09:00:00', '2099-01-05 10:00:00', 'confirmed')";
		$this->assertTrue((bool) $this->db->query($sql));

		$manager = new ResourceReservationManager($this->db);
		$this->assertFalse($manager->canReserve('dolresource', $resourceId, '2099-01-05 09:30:00', '2099-01-05 10:30:00', 1, null, 1));
		$this->assertTrue($manager->canReserve('dolresource', $resourceId, '2099-01-05 10:00:00', '2099-01-05 11:00:00', 2, null, 1));
	}

	/**
	 * @param string $ref Resource reference
	 * @param int    $capacityPerUnit User capacity per physical unit
	 * @param int    $availableUnits Number of interchangeable units
	 * @return int
	 */
	private function createResource($ref, $capacityPerUnit, $availableUnits)
	{
		global $conf;
		$typeCode = substr($ref.'_TYPE', 0, 32);
		$sql = 'INSERT INTO '.$this->db->prefix().'c_type_resource (code, label, capacity_mode, active)';
		$sql .= " VALUES ('".$this->db->escape($typeCode)."', 'PHPUnit type', 'users', 1)";
		$this->assertTrue((bool) $this->db->query($sql));

		$sql = 'INSERT INTO '.$this->db->prefix().'resource (entity, ref, fk_code_type_resource, max_users, available_units, fk_statut)';
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', '".$this->db->escape($typeCode)."', ".((int) $capacityPerUnit).', '.((int) $availableUnits).', 1)';
		$this->assertTrue((bool) $this->db->query($sql));
		return (int) $this->db->last_insert_id($this->db->prefix().'resource');
	}
}
