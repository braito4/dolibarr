<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file test/phpunit/ResourceLifecycleInvariantTest.php
 * \ingroup test
 * \brief Tests for resource capacity, outage and deletion invariants.
 */

use PHPUnit\Framework\TestCase;

global $conf, $user, $langs, $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/dolresource.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Verify invariants enforced by the resource domain object.
 *
 * @backupGlobals disabled
 */
class ResourceLifecycleInvariantTest extends TestCase
{
	/** @var DoliDB */
	private $db;

	/** @var int */
	private $resourceId;

	/**
	 * Create one capacity-aware resource inside a rollback-only transaction.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		$this->db = $db;
		$this->db->begin();
		$this->insert('c_type_resource', array(
			'code' => 'PHPUNIT_RESOURCE_INVARIANT',
			'label' => 'PHPUnit resource invariant',
			'capacity_mode' => 'users',
			'supports_cooldown' => 1,
			'active' => 1,
		));
		$this->resourceId = $this->createResource('PHPUNIT_RESOURCE_INVARIANT_A', 4, 1, 0);
	}

	/**
	 * Roll back every fixture row.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		$this->db->rollback();
	}

	/**
	 * Physical inventory exhaustion is busy even when nominal user capacity
	 * still has room.
	 *
	 * @return void
	 */
	public function testIsBusyWhenPhysicalUnitIsExhausted(): void
	{
		$this->createAssignment($this->resourceId, 910001, '2099-05-01 09:00:00', '2099-05-01 10:00:00', 1, 1);
		$resource = $this->fetchResource($this->resourceId);

		$this->assertTrue($resource->isBusy('2099-05-01 09:30:00', '2099-05-01 09:45:00'));
	}

	/**
	 * A direct DAO update cannot reduce a pool below overlapping confirmed use.
	 *
	 * @return void
	 */
	public function testDirectUpdateRejectsInventoryBelowConfirmedAssignments(): void
	{
		$sql = 'UPDATE '.$this->db->prefix().'resource SET available_units=2';
		$sql .= ' WHERE rowid='.((int) $this->resourceId);
		$this->assertTrue((bool) $this->db->query($sql), (string) $this->db->lasterror());
		$this->createAssignment($this->resourceId, 910002, '2099-05-02 09:00:00', '2099-05-02 10:00:00', 1, 1);
		$this->createAssignment($this->resourceId, 910003, '2099-05-02 09:00:00', '2099-05-02 10:00:00', 1, 1);
		$resource = $this->fetchResource($this->resourceId);
		$resource->available_units = 1;

		$this->assertLessThan(0, $resource->update(null, 1));
		$this->assertSame(2, $this->fetchInteger('resource', 'available_units', $this->resourceId));
	}

	/**
	 * Setting a resource out of service through the DAO atomically moves a
	 * confirmed assignment to an equivalent alternative and applies its cooldown.
	 *
	 * @return void
	 */
	public function testDirectOutOfServiceUpdateReassignsEquivalentRequirement(): void
	{
		global $conf, $user;

		$thirdPartyId = $this->insert('societe', array(
			'nom' => 'PHPUnit resource invariant',
			'entity' => (int) $conf->entity,
		));
		$serviceId = $this->insert('product', array(
			'ref' => 'PHPUNIT_RESOURCE_INVARIANT_SERVICE',
			'label' => 'PHPUnit resource invariant service',
			'fk_product_type' => 1,
			'tosell' => 1,
			'entity' => (int) $conf->entity,
		));
		$replacementId = $this->createResource('PHPUNIT_RESOURCE_INVARIANT_B', 4, 1, 30);
		$this->createRequirement($serviceId, $this->resourceId, 1);
		$this->createRequirement($serviceId, $replacementId, 2);
		$contractId = $this->insert('contrat', array(
			'ref' => 'PHPUNIT-RESOURCE-INVARIANT-CONTRACT',
			'entity' => (int) $conf->entity,
			'fk_soc' => $thirdPartyId,
			'fk_user_author' => (int) $user->id,
			'statut' => 1,
		));
		$lineId = $this->insert('contratdet', array(
			'fk_contrat' => $contractId,
			'fk_product' => $serviceId,
			'product_type' => 1,
			'qty' => 1,
			'date_ouverture_prevue' => '2099-05-03 09:00:00',
			'date_fin_validite' => '2099-05-03 10:00:00',
			'fk_user_author' => (int) $user->id,
		));
		$assignmentId = $this->createAssignment(
			$this->resourceId,
			$lineId,
			'2099-05-03 09:00:00',
			'2099-05-03 10:00:00',
			1,
			1,
			'contratdet',
			'preferred_room'
		);
		$resource = $this->fetchResource($this->resourceId);
		$resource->status = Dolresource::STATUS_OUT_OF_SERVICE;

		$this->assertSame(1, $resource->update(null, 1), (string) $resource->error);
		$this->assertSame(1, $resource->out_of_service_impact_count);
		$sql = 'SELECT resource_id, resource_units_used, cooldown_minutes_applied, date_end';
		$sql .= ' FROM '.$this->db->prefix().'element_resources WHERE rowid='.((int) $assignmentId);
		$resql = $this->db->query($sql);
		$this->assertNotFalse($resql, (string) $this->db->lasterror());
		$assignment = $this->db->fetch_object($resql);
		$this->assertSame($replacementId, (int) $assignment->resource_id);
		$this->assertSame(1, (int) $assignment->resource_units_used);
		$this->assertSame(30, (int) $assignment->cooldown_minutes_applied);
		$this->assertSame('2099-05-03 10:30:00', $assignment->date_end);
		$this->assertSame(Dolresource::STATUS_OUT_OF_SERVICE, $this->fetchInteger('resource', 'fk_statut', $this->resourceId));
	}

	/**
	 * An active normalized reservation prevents destructive deletion.
	 *
	 * @return void
	 */
	public function testDeleteRejectsActiveReservation(): void
	{
		global $user;

		$this->createAssignment($this->resourceId, 910004, '2099-05-04 09:00:00', '2099-05-04 10:00:00', 1, 1);
		$resource = $this->fetchResource($this->resourceId);

		$this->assertLessThan(0, $resource->delete($user, 1));
		$this->assertSame(1, $this->countRows('resource', 'rowid='.((int) $this->resourceId)));
	}

	/**
	 * A future legacy Agenda link is also an active business reservation.
	 *
	 * @return void
	 */
	public function testDeleteRejectsFutureAgendaLink(): void
	{
		global $conf, $user;

		$actionId = $this->insert('actioncomm', array(
			'ref' => 'PHPUNIT-RESOURCE-INVARIANT-ACTION',
			'entity' => (int) $conf->entity,
			'datep' => '2099-05-05 09:00:00',
			'datep2' => '2099-05-05 10:00:00',
			'fulldayevent' => 0,
			'status' => 2,
			'label' => 'PHPUnit resource invariant action',
		));
		$this->insert('element_resources', array(
			'element_id' => $actionId,
			'element_type' => 'action',
			'resource_id' => $this->resourceId,
			'resource_type' => 'dolresource',
			'busy' => 1,
			'relation_kind' => 'link',
		));
		$resource = $this->fetchResource($this->resourceId);

		$this->assertLessThan(0, $resource->delete($user, 1));
		$this->assertSame(1, $this->countRows('resource', 'rowid='.((int) $this->resourceId)));
	}

	/**
	 * Deleting an unused resource removes calendars and polymorphic relations.
	 *
	 * @return void
	 */
	public function testDeleteCleansResourceCalendarChildren(): void
	{
		global $conf, $user;

		$maskId = $this->insert('resource_time_mask', array(
			'entity' => (int) $conf->entity,
			'ref' => 'PHPUNIT_RESOURCE_INVARIANT_MASK',
			'label' => 'PHPUnit resource invariant mask',
			'active' => 1,
		));
		$this->insert('resource_time_mask_assignment', array(
			'entity' => (int) $conf->entity,
			'fk_time_mask' => $maskId,
			'resource_type' => 'dolresource',
			'resource_id' => $this->resourceId,
		));
		$this->insert('resource_time_slot', array(
			'entity' => (int) $conf->entity,
			'fk_resource' => $this->resourceId,
			'label' => 'PHPUnit maintenance',
			'slot_type' => 'absolute',
			'availability_status' => 'maintenance',
			'date_start' => '2098-05-06 09:00:00',
			'date_end' => '2098-05-06 10:00:00',
			'active' => 1,
		));
		$this->insert('element_resources', array(
			'element_id' => 910005,
			'element_type' => 'service',
			'resource_id' => $this->resourceId,
			'resource_type' => 'dolresource',
			'busy' => 0,
			'relation_kind' => 'requirement',
		));
		$resource = $this->fetchResource($this->resourceId);

		$this->assertSame(1, $resource->delete($user, 1), (string) $resource->error);
		$this->assertSame(0, $this->countRows('resource', 'rowid='.((int) $this->resourceId)));
		$this->assertSame(0, $this->countRows('resource_time_slot', 'fk_resource='.((int) $this->resourceId)));
		$this->assertSame(0, $this->countRows('resource_time_mask_assignment', "resource_type='dolresource' AND resource_id=".((int) $this->resourceId)));
		$this->assertSame(0, $this->countRows('element_resources', "resource_type='dolresource' AND resource_id=".((int) $this->resourceId)));
	}

	/**
	 * @param int $resourceId Resource id
	 * @return Dolresource
	 */
	private function fetchResource($resourceId)
	{
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch((int) $resourceId));
		return $resource;
	}

	/**
	 * @param string $ref            Reference
	 * @param int    $maxUsers       Capacity per unit
	 * @param int    $availableUnits Physical unit count
	 * @param int    $cooldown       Cooldown in minutes
	 * @return int
	 */
	private function createResource($ref, $maxUsers, $availableUnits, $cooldown)
	{
		global $conf;

		return $this->insert('resource', array(
			'entity' => (int) $conf->entity,
			'ref' => $ref,
			'fk_code_type_resource' => 'PHPUNIT_RESOURCE_INVARIANT',
			'max_users' => (int) $maxUsers,
			'available_units' => (int) $availableUnits,
			'cooldown_minutes' => (int) $cooldown,
			'fk_statut' => Dolresource::STATUS_FREE,
		));
	}

	/**
	 * @param int $serviceId  Service id
	 * @param int $resourceId Candidate resource id
	 * @param int $position   Preference position
	 * @return void
	 */
	private function createRequirement($serviceId, $resourceId, $position)
	{
		$this->insert('element_resources', array(
			'element_id' => (int) $serviceId,
			'element_type' => 'service',
			'resource_id' => (int) $resourceId,
			'resource_type' => 'dolresource',
			'busy' => 0,
			'mandatory' => 1,
			'position' => (int) $position,
			'relation_kind' => 'requirement',
			'resource_role' => 'capacity',
			'requirement_group' => 'preferred_room',
			'quantity_required' => 1,
			'users_per_service_unit' => 1,
			'allow_split' => 0,
		));
	}

	/**
	 * @param int         $resourceId Resource id
	 * @param int         $elementId  Source element id
	 * @param string      $dateStart  Interval start
	 * @param string      $dateEnd    Interval end
	 * @param float       $capacity   Capacity consumed
	 * @param int         $units      Physical units consumed
	 * @param string      $elementType Source element type
	 * @param string|null $group      Requirement alternative group
	 * @return int
	 */
	private function createAssignment($resourceId, $elementId, $dateStart, $dateEnd, $capacity, $units, $elementType = 'contratdet', $group = null)
	{
		return $this->insert('element_resources', array(
			'element_id' => (int) $elementId,
			'element_type' => $elementType,
			'resource_id' => (int) $resourceId,
			'resource_type' => 'dolresource',
			'busy' => 1,
			'position' => 1,
			'relation_kind' => 'assignment',
			'resource_role' => 'capacity',
			'requirement_group' => $group,
			'service_quantity' => 1,
			'users_per_service_unit' => 1,
			'capacity_used' => (float) $capacity,
			'resource_units_used' => (int) $units,
			'cooldown_minutes_applied' => 0,
			'load_volume_used' => 0,
			'payload_weight_used' => 0,
			'date_start' => $dateStart,
			'date_end' => $dateEnd,
			'reservation_status' => 'confirmed',
		));
	}

	/**
	 * @param string $table  Table without prefix
	 * @param string $column Integer column
	 * @param int    $rowid  Row id
	 * @return int
	 */
	private function fetchInteger($table, $column, $rowid)
	{
		$sql = 'SELECT '.$this->db->sanitize($column).' as value FROM '.$this->db->prefix().$this->db->sanitize($table);
		$sql .= ' WHERE rowid='.((int) $rowid);
		$resql = $this->db->query($sql);
		$this->assertNotFalse($resql, (string) $this->db->lasterror());
		$row = $this->db->fetch_object($resql);
		return $row ? (int) $row->value : 0;
	}

	/**
	 * @param string $table Table without prefix
	 * @param string $where Trusted fixture predicate
	 * @return int
	 */
	private function countRows($table, $where)
	{
		$sql = 'SELECT COUNT(*) as nb FROM '.$this->db->prefix().$this->db->sanitize($table).' WHERE '.$where;
		$resql = $this->db->query($sql);
		$this->assertNotFalse($resql, (string) $this->db->lasterror());
		$row = $this->db->fetch_object($resql);
		return $row ? (int) $row->nb : 0;
	}

	/**
	 * @param string              $table  Table without prefix
	 * @param array<string,mixed> $values Column values
	 * @return int
	 */
	private function insert($table, array $values)
	{
		$columns = array();
		$sqlValues = array();
		foreach ($values as $column => $value) {
			$columns[] = $this->db->sanitize($column);
			if ($value === null) {
				$sqlValues[] = 'NULL';
			} elseif (is_int($value) || is_float($value)) {
				$sqlValues[] = (string) $value;
			} else {
				$sqlValues[] = "'".$this->db->escape((string) $value)."'";
			}
		}
		$sql = 'INSERT INTO '.$this->db->prefix().$this->db->sanitize($table);
		$sql .= ' ('.implode(',', $columns).') VALUES ('.implode(',', $sqlValues).')';
		$this->assertTrue((bool) $this->db->query($sql), (string) $this->db->lasterror());
		return (int) $this->db->last_insert_id($this->db->prefix().$table);
	}
}
