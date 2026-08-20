<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file test/phpunit/ResourceAgendaReservationTest.php
 * \ingroup test
 * \brief Tests for Agenda links backed by the common resource availability engine.
 */

use PHPUnit\Framework\TestCase;

global $conf, $user, $langs, $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/resourcereservationmanager.class.php';
require_once $documentRoot.'/resource/core/triggers/interface_99_modResource_ResourceReservations.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Tests for shared Agenda/resource occupancy rules.
 *
 * @backupGlobals disabled
 */
class ResourceAgendaReservationTest extends TestCase
{
	/** @var DoliDB */
	private $db;

	/** @var InterfaceResourceReservations */
	private $trigger;

	/** @var int */
	private $resourceId;

	/** @var mixed */
	private $previousModuleState;

	/** @var bool */
	private $hadModuleState;

	/** @var mixed */
	private $previousAgendaCheck;

	/** @var bool */
	private $hadAgendaCheck;

	/**
	 * Create one physical resource and enable legacy Agenda checks.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $conf, $db;

		$this->db = $db;
		$this->db->begin();
		$this->hadModuleState = array_key_exists('resource', $conf->modules);
		$this->previousModuleState = $this->hadModuleState ? $conf->modules['resource'] : null;
		$conf->modules['resource'] = 1;
		$this->hadAgendaCheck = isset($conf->global->RESOURCE_USED_IN_EVENT_CHECK);
		$this->previousAgendaCheck = $this->hadAgendaCheck ? $conf->global->RESOURCE_USED_IN_EVENT_CHECK : null;
		$conf->global->RESOURCE_USED_IN_EVENT_CHECK = '1';
		$this->trigger = new InterfaceResourceReservations($this->db);

		$entity = (int) $conf->entity;
		$this->insert('c_type_resource', array(
			'code' => 'PHPUNIT_AGENDA_RESOURCE',
			'label' => 'PHPUnit Agenda resource',
			'capacity_mode' => 'users',
			'active' => 1,
		));
		$this->resourceId = $this->insert('resource', array(
			'entity' => $entity,
			'ref' => 'PHPUNIT_AGENDA_RESOURCE',
			'fk_code_type_resource' => 'PHPUNIT_AGENDA_RESOURCE',
			'max_users' => 1,
			'available_units' => 1,
			'fk_statut' => 1,
		));
	}

	/**
	 * Roll back fixtures and restore configuration state.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		global $conf;

		$this->db->rollback();
		if ($this->hadModuleState) {
			$conf->modules['resource'] = $this->previousModuleState;
		} else {
			unset($conf->modules['resource']);
		}
		if ($this->hadAgendaCheck) {
			$conf->global->RESOURCE_USED_IN_EVENT_CHECK = $this->previousAgendaCheck;
		} else {
			unset($conf->global->RESOURCE_USED_IN_EVENT_CHECK);
		}
	}

	/**
	 * Legacy Agenda links retain half-open interval semantics.
	 *
	 * @return void
	 */
	public function testLegacyAgendaLinkBlocksOverlapButAllowsAdjacency(): void
	{
		$actionId = $this->createAction('2099-04-01 09:00:00', '2099-04-01 10:00:00');
		$this->createActionLink($actionId);
		$manager = new ResourceReservationManager($this->db);

		$this->assertFalse($manager->canReserveExclusiveAction($this->resourceId, 0, '2099-04-01 09:30:00', '2099-04-01 10:30:00'));
		$this->assertTrue($manager->canReserveExclusiveAction($this->resourceId, 0, '2099-04-01 10:00:00', '2099-04-01 11:00:00'));
	}

	/**
	 * Moving an Agenda action over a confirmed document assignment is rejected.
	 *
	 * @return void
	 */
	public function testActionModifyRejectsConfirmedAssignmentConflict(): void
	{
		global $conf, $langs, $user;

		$this->insert('element_resources', array(
			'element_id' => 999001,
			'element_type' => 'contratdet',
			'resource_id' => $this->resourceId,
			'resource_type' => 'dolresource',
			'busy' => 1,
			'relation_kind' => 'assignment',
			'capacity_used' => 1,
			'resource_units_used' => 1,
			'date_start' => '2099-04-02 09:00:00',
			'date_end' => '2099-04-02 10:00:00',
			'reservation_status' => 'confirmed',
		));
		$actionId = $this->createAction('2099-04-02 11:00:00', '2099-04-02 12:00:00');
		$this->createActionLink($actionId);
		$action = new stdClass();
		$action->id = $actionId;
		$action->datep = $this->db->jdate('2099-04-02 09:30:00');
		$action->datef = $this->db->jdate('2099-04-02 10:30:00');
		$action->fulldayevent = 0;
		$action->oldcopy = new stdClass();
		$action->oldcopy->datep = $this->db->jdate('2099-04-02 11:00:00');
		$action->oldcopy->datef = $this->db->jdate('2099-04-02 12:00:00');
		$action->oldcopy->fulldayevent = 0;

		$this->assertSame(-1, $this->trigger->runTrigger('ACTION_MODIFY', $action, $user, $langs, $conf));
		$this->assertNotEmpty($this->trigger->errors);
	}

	/**
	 * Editing a previously non-busy Agenda link cannot bypass the shared check.
	 *
	 * @return void
	 */
	public function testElementResourceUpdateRejectsBusyConflict(): void
	{
		global $user;

		$this->insert('element_resources', array(
			'element_id' => 999002,
			'element_type' => 'commandedet',
			'resource_id' => $this->resourceId,
			'resource_type' => 'dolresource',
			'busy' => 1,
			'relation_kind' => 'assignment',
			'capacity_used' => 1,
			'resource_units_used' => 1,
			'date_start' => '2099-04-04 09:00:00',
			'date_end' => '2099-04-04 10:00:00',
			'reservation_status' => 'confirmed',
		));
		$actionId = $this->createAction('2099-04-04 09:30:00', '2099-04-04 10:30:00');
		$linkId = $this->createActionLink($actionId, 0);
		$link = new Dolresource($this->db);
		$this->assertGreaterThan(0, $link->fetchElementResource($linkId));
		$link->busy = 1;

		$this->assertLessThan(0, $link->updateElementResource($user));
		$this->assertNotEmpty($link->errors);
	}

	/**
	 * Removing an Agenda action cleans its legacy resource links.
	 *
	 * @return void
	 */
	public function testActionDeleteRemovesLegacyResourceLinks(): void
	{
		global $conf, $langs, $user;

		$actionId = $this->createAction('2099-04-03 09:00:00', '2099-04-03 10:00:00');
		$this->createActionLink($actionId);
		$action = new stdClass();
		$action->id = $actionId;

		$this->assertSame(1, $this->trigger->runTrigger('ACTION_DELETE', $action, $user, $langs, $conf));
		$this->assertSame(0, $this->countActionLinks($actionId));
	}

	/**
	 * @param string $dateStart Action start
	 * @param string $dateEnd   Action end
	 * @return int
	 */
	private function createAction($dateStart, $dateEnd)
	{
		global $conf;

		return $this->insert('actioncomm', array(
			'ref' => 'PHPUNIT-AGENDA-ACTION',
			'entity' => (int) $conf->entity,
			'datep' => $dateStart,
			'datep2' => $dateEnd,
			'fulldayevent' => 0,
			'status' => 2,
			'label' => 'PHPUnit Agenda reservation',
		));
	}

	/**
	 * @param int $actionId Agenda action id
	 * @param int $busy     Whether the relation blocks availability
	 * @return int
	 */
	private function createActionLink($actionId, $busy = 1)
	{
		return $this->insert('element_resources', array(
			'element_id' => $actionId,
			'element_type' => 'action',
			'resource_id' => $this->resourceId,
			'resource_type' => 'dolresource',
			'busy' => (int) $busy,
			'relation_kind' => 'link',
		));
	}

	/**
	 * @param int $actionId Agenda action id
	 * @return int
	 */
	private function countActionLinks($actionId)
	{
		$sql = 'SELECT COUNT(*) as nb FROM '.$this->db->prefix().'element_resources';
		$sql .= " WHERE element_type = 'action' AND element_id = ".((int) $actionId);
		$sql .= " AND resource_type = 'dolresource' AND (relation_kind = 'link' OR relation_kind IS NULL)";
		$resql = $this->db->query($sql);
		$this->assertNotFalse($resql, (string) $this->db->lasterror());
		$row = $this->db->fetch_object($resql);
		return $row ? (int) $row->nb : 0;
	}

	/**
	 * Insert a fixture row using the Dolibarr database abstraction.
	 *
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
