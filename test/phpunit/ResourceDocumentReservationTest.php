<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file test/phpunit/ResourceDocumentReservationTest.php
 * \ingroup test
 * \brief Tests for document-driven resource reservation lifecycle.
 */

use PHPUnit\Framework\TestCase;

global $conf, $user, $langs, $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/core/triggers/interface_99_modResource_ResourceReservations.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Tests for proposal, order and contract resource assignments.
 *
 * @backupGlobals disabled
 */
class ResourceDocumentReservationTest extends TestCase
{
	/** @var DoliDB */
	private $db;

	/** @var InterfaceResourceReservations */
	private $trigger;

	/** @var int */
	private $thirdPartyId;

	/** @var int */
	private $serviceId;

	/** @var int */
	private $roomResourceId;

	/** @var int */
	private $operatorResourceId;

	/** @var mixed Previous in-memory module state */
	private $previousResourceModuleEnabled;

	/** @var bool Whether the resource module had an in-memory state */
	private $hadResourceModuleState;

	/**
	 * Create isolated document, service and resource fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		$this->db = $db;
		$this->db->begin();
		$this->hadResourceModuleState = array_key_exists('resource', $conf->modules);
		$this->previousResourceModuleEnabled = $this->hadResourceModuleState ? $conf->modules['resource'] : null;
		$conf->modules['resource'] = 1;
		$this->trigger = new InterfaceResourceReservations($this->db);

		$entity = (int) $conf->entity;
		$this->thirdPartyId = $this->insert('societe', array(
			'nom' => 'PHPUnit document reservations',
			'entity' => $entity,
		));
		$this->serviceId = $this->insert('product', array(
			'ref' => 'PHPUNIT_DOCUMENT_RESOURCE_SERVICE',
			'label' => 'PHPUnit document resource service',
			'fk_product_type' => 1,
			'tosell' => 1,
			'duration' => '1h',
			'entity' => $entity,
		));

		$this->insert('c_type_resource', array(
			'code' => 'PHPUNIT_DOC_ROOM_TYPE',
			'label' => 'PHPUnit document room',
			'capacity_mode' => 'users',
			'active' => 1,
		));
		$this->insert('c_type_resource', array(
			'code' => 'PHPUNIT_DOC_OPERATOR_TYPE',
			'label' => 'PHPUnit document operator',
			'capacity_mode' => 'users',
			'active' => 1,
		));
		$this->roomResourceId = $this->createResource('PHPUNIT_DOCUMENT_ROOM', 'PHPUNIT_DOC_ROOM_TYPE', 4);
		$this->operatorResourceId = $this->createResource('PHPUNIT_DOCUMENT_OPERATOR', 'PHPUNIT_DOC_OPERATOR_TYPE', 1);
		$this->insertRequirement($this->roomResourceId, 'capacity', 'preferred_room');
		$this->insertRequirement($this->operatorResourceId, 'operator', 'preferred_operator');
	}

	/**
	 * Roll back fixture data and restore the in-memory module state.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$this->db->rollback();
		if (!$this->hadResourceModuleState) {
			unset($conf->modules['resource']);
		} else {
			$conf->modules['resource'] = $this->previousResourceModuleEnabled;
		}
	}

	/**
	 * Proposal assignments remain provisional and preserve independent room and
	 * operator requirement groups until the proposal is canceled.
	 *
	 * @return void
	 */
	public function testProposalLifecycleKeepsRoomAndOperatorProvisional(): void
	{
		$document = $this->createProposalLine('2099-03-02 09:00:00', '2099-03-02 10:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINEPROPAL_INSERT', $document['line_id']));
		$this->assertRoleAssignments('propaldet', $document['line_id'], 'provisional');

		$this->assertSame(1, $this->runObjectTrigger('PROPAL_VALIDATE', $document['document_id']));
		$this->assertRoleAssignments('propaldet', $document['line_id'], 'provisional');

		$this->assertSame(1, $this->runObjectTrigger('PROPAL_CANCEL', $document['document_id']));
		$this->assertSame(0, $this->countAssignments('propaldet', $document['line_id']));
	}

	/**
	 * Order validation confirms both semantic requirements; unvalidation returns
	 * them to provisional state and cancellation releases them.
	 *
	 * @return void
	 */
	public function testOrderLifecycleConfirmsAndReleasesAssignments(): void
	{
		$document = $this->createOrderLine('2099-03-03 09:00:00', '2099-03-03 10:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINEORDER_INSERT', $document['line_id']));
		$this->assertRoleAssignments('commandedet', $document['line_id'], 'provisional');

		$this->assertSame(1, $this->runObjectTrigger('ORDER_VALIDATE', $document['document_id']));
		$this->assertRoleAssignments('commandedet', $document['line_id'], 'confirmed');

		$this->assertSame(1, $this->runObjectTrigger('ORDER_UNVALIDATE', $document['document_id']));
		$this->assertRoleAssignments('commandedet', $document['line_id'], 'provisional');

		$this->assertSame(1, $this->runObjectTrigger('ORDER_CANCEL', $document['document_id']));
		$this->assertSame(0, $this->countAssignments('commandedet', $document['line_id']));
	}

	/**
	 * Contract validation confirms both assignments; reopening makes them
	 * provisional and deleting the contract releases them.
	 *
	 * @return void
	 */
	public function testContractLifecycleConfirmsAndReleasesAssignments(): void
	{
		$document = $this->createContractLine('2099-03-04 09:00:00', '2099-03-04 10:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $document['line_id']));
		$this->assertRoleAssignments('contratdet', $document['line_id'], 'provisional');

		$this->assertSame(1, $this->runObjectTrigger('CONTRACT_VALIDATE', $document['document_id']));
		$this->assertRoleAssignments('contratdet', $document['line_id'], 'confirmed');

		$this->assertSame(1, $this->runObjectTrigger('CONTRACT_REOPEN', $document['document_id']));
		$this->assertRoleAssignments('contratdet', $document['line_id'], 'provisional');

		$this->assertSame(1, $this->runObjectTrigger('CONTRACT_DELETE', $document['document_id']));
		$this->assertSame(0, $this->countAssignments('contratdet', $document['line_id']));
	}

	/**
	 * A confirmed document is rejected when one mandatory semantic role is
	 * outside the resource opening schedule.
	 *
	 * @return void
	 */
	public function testConfirmedContractRejectsOperatorOutsideOpeningHours(): void
	{
		global $conf;

		$this->insert('resource_time_slot', array(
			'entity' => (int) $conf->entity,
			'fk_resource' => $this->operatorResourceId,
			'label' => 'PHPUnit operator opening hours',
			'slot_type' => 'absolute',
			'availability_status' => 'available',
			'date_start' => '2099-03-05 09:00:00',
			'date_end' => '2099-03-05 10:00:00',
			'active' => 1,
		));
		$document = $this->createContractLine('2099-03-05 11:00:00', '2099-03-05 12:00:00', 1);

		$this->assertSame(-1, $this->runLineTrigger('LINECONTRACT_INSERT', $document['line_id']));
		$this->assertSame(0, $this->countAssignments('contratdet', $document['line_id']));
		$this->assertNotEmpty($this->trigger->errors);
	}

	/**
	 * An available independent role must not hide another mandatory
	 * requirement group whose only resource is unavailable.
	 *
	 * @return void
	 */
	public function testMandatoryUnavailableGroupCannotBeHiddenByAnotherRole(): void
	{
		$sql = 'UPDATE '.$this->db->prefix().'resource SET fk_statut = 2';
		$sql .= ' WHERE rowid = '.((int) $this->roomResourceId);
		$this->assertTrue((bool) $this->db->query($sql), (string) $this->db->lasterror());
		$document = $this->createContractLine('2099-03-06 09:00:00', '2099-03-06 10:00:00', 1);

		$this->assertSame(-1, $this->runLineTrigger('LINECONTRACT_INSERT', $document['line_id']));
		$this->assertSame(0, $this->countAssignments('contratdet', $document['line_id']));
		$this->assertNotEmpty($this->trigger->errors);
	}

	/**
	 * @param string $ref      Resource reference
	 * @param string $typeCode Resource type code
	 * @param int    $capacity Maximum users
	 * @return int
	 */
	private function createResource($ref, $typeCode, $capacity)
	{
		global $conf;

		return $this->insert('resource', array(
			'entity' => (int) $conf->entity,
			'ref' => $ref,
			'fk_code_type_resource' => $typeCode,
			'max_users' => $capacity,
			'available_units' => 1,
			'fk_statut' => 1,
		));
	}

	/**
	 * @param int    $resourceId Resource id
	 * @param string $role       Semantic resource role
	 * @param string $group      Alternative requirement group
	 * @return void
	 */
	private function insertRequirement($resourceId, $role, $group)
	{
		$this->insert('element_resources', array(
			'element_id' => $this->serviceId,
			'element_type' => 'service',
			'resource_id' => $resourceId,
			'resource_type' => 'dolresource',
			'busy' => 0,
			'mandatory' => 1,
			'position' => 1,
			'relation_kind' => 'requirement',
			'resource_role' => $role,
			'requirement_group' => $group,
			'quantity_required' => 1,
			'users_per_service_unit' => 1,
			'scheduling_mode' => 'same_as_parent',
			'start_input_mode' => 'datetime',
			'end_input_mode' => 'datetime',
			'time_precision' => 'minute',
			'simultaneous' => 1,
			'allow_split' => 0,
			'capacity_metrics' => 'units',
		));
	}

	/**
	 * @param string $dateStart Service start
	 * @param string $dateEnd   Service end
	 * @return array{document_id:int,line_id:int}
	 */
	private function createProposalLine($dateStart, $dateEnd)
	{
		global $conf;

		$proposalId = $this->insert('propal', array(
			'ref' => 'PHPUNIT-RESOURCE-PROPAL',
			'entity' => (int) $conf->entity,
			'fk_soc' => $this->thirdPartyId,
			'fk_statut' => 0,
		));
		$lineId = $this->insert('propaldet', array(
			'fk_propal' => $proposalId,
			'fk_product' => $this->serviceId,
			'product_type' => 1,
			'qty' => 1,
			'date_start' => $dateStart,
			'date_end' => $dateEnd,
		));
		return array('document_id' => $proposalId, 'line_id' => $lineId);
	}

	/**
	 * @param string $dateStart Service start
	 * @param string $dateEnd   Service end
	 * @return array{document_id:int,line_id:int}
	 */
	private function createOrderLine($dateStart, $dateEnd)
	{
		global $conf;

		$orderId = $this->insert('commande', array(
			'ref' => 'PHPUNIT-RESOURCE-ORDER',
			'entity' => (int) $conf->entity,
			'fk_soc' => $this->thirdPartyId,
			'fk_statut' => 0,
		));
		$lineId = $this->insert('commandedet', array(
			'fk_commande' => $orderId,
			'fk_product' => $this->serviceId,
			'product_type' => 1,
			'qty' => 1,
			'date_start' => $dateStart,
			'date_end' => $dateEnd,
		));
		return array('document_id' => $orderId, 'line_id' => $lineId);
	}

	/**
	 * @param string $dateStart Service start
	 * @param string $dateEnd   Service end
	 * @param int    $status    Contract status
	 * @return array{document_id:int,line_id:int}
	 */
	private function createContractLine($dateStart, $dateEnd, $status = 0)
	{
		global $conf, $user;

		$contractId = $this->insert('contrat', array(
			'ref' => 'PHPUNIT-RESOURCE-CONTRACT',
			'entity' => (int) $conf->entity,
			'fk_soc' => $this->thirdPartyId,
			'fk_user_author' => (int) $user->id,
			'statut' => $status,
		));
		$lineId = $this->insert('contratdet', array(
			'fk_contrat' => $contractId,
			'fk_product' => $this->serviceId,
			'product_type' => 1,
			'qty' => 1,
			'date_ouverture_prevue' => $dateStart,
			'date_fin_validite' => $dateEnd,
			'fk_user_author' => (int) $user->id,
		));
		return array('document_id' => $contractId, 'line_id' => $lineId);
	}

	/**
	 * Assert room and operator assignments and their independent groups.
	 *
	 * @param string $elementType Source line element type
	 * @param int    $lineId      Source line id
	 * @param string $status      Expected reservation status
	 * @return void
	 */
	private function assertRoleAssignments($elementType, $lineId, $status)
	{
		$assignments = $this->fetchAssignments($elementType, $lineId);
		$this->assertCount(2, $assignments);
		$byRole = array();
		foreach ($assignments as $assignment) {
			$byRole[$assignment->resource_role] = $assignment;
			$this->assertSame($status, $assignment->reservation_status);
		}

		$this->assertArrayHasKey('capacity', $byRole);
		$this->assertSame($this->roomResourceId, (int) $byRole['capacity']->resource_id);
		$this->assertSame('preferred_room', $byRole['capacity']->requirement_group);
		$this->assertArrayHasKey('operator', $byRole);
		$this->assertSame($this->operatorResourceId, (int) $byRole['operator']->resource_id);
		$this->assertSame('preferred_operator', $byRole['operator']->requirement_group);
	}

	/**
	 * @param string $elementType Source line element type
	 * @param int    $lineId      Source line id
	 * @return array<int,object>
	 */
	private function fetchAssignments($elementType, $lineId)
	{
		$sql = 'SELECT resource_id, resource_role, requirement_group, reservation_status';
		$sql .= ' FROM '.$this->db->prefix().'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."'";
		$sql .= ' AND element_id = '.((int) $lineId);
		$sql .= " AND resource_type = 'dolresource' AND relation_kind = 'assignment'";
		$sql .= ' ORDER BY resource_role, rowid';
		$resql = $this->db->query($sql);
		$this->assertNotFalse($resql, (string) $this->db->lasterror());
		$assignments = array();
		while ($resql && ($assignment = $this->db->fetch_object($resql))) {
			$assignments[] = $assignment;
		}
		return $assignments;
	}

	/**
	 * @param string $elementType Source line element type
	 * @param int    $lineId      Source line id
	 * @return int
	 */
	private function countAssignments($elementType, $lineId)
	{
		return count($this->fetchAssignments($elementType, $lineId));
	}

	/**
	 * @param string $action Trigger action
	 * @param int    $lineId Source line id
	 * @return int
	 */
	private function runLineTrigger($action, $lineId)
	{
		global $conf, $langs, $user;

		$object = new stdClass();
		$object->id = $lineId;
		$object->context = array();
		return $this->trigger->runTrigger($action, $object, $user, $langs, $conf);
	}

	/**
	 * @param string $action     Trigger action
	 * @param int    $documentId Parent document id
	 * @return int
	 */
	private function runObjectTrigger($action, $documentId)
	{
		global $conf, $langs, $user;

		$object = new stdClass();
		$object->id = $documentId;
		return $this->trigger->runTrigger($action, $object, $user, $langs, $conf);
	}

	/**
	 * Insert a row using the Dolibarr database abstraction.
	 *
	 * @param string              $table  Table without prefix
	 * @param array<string,mixed> $values Column values
	 * @return int Inserted row id
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
