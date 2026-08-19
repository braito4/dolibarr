<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file test/phpunit/ResourceReservationTest.php
 * \ingroup test
 * \brief PHPUnit tests for service resource preferences and reservations.
 */

use PHPUnit\Framework\TestCase;

global $conf, $user, $langs, $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/dolresource.class.php';
require_once $documentRoot.'/resource/class/resourcereservationmanager.class.php';
require_once $documentRoot.'/resource/class/resourcesupplyrequestmanager.class.php';
require_once $documentRoot.'/resource/core/triggers/interface_99_modResource_ResourceReservations.class.php';
require_once $documentRoot.'/bookcal/class/bookcalavailabilityprovider.class.php';

if (empty($user->id)) {
	$user->fetch(1);
	$user->loadRights();
}

/**
 * Tests for resource reservation synchronization.
 *
 * @backupGlobals disabled
 */
class ResourceReservationTest extends TestCase
{
	/** @var DoliDB */
	private $db;

	/** @var InterfaceResourceReservations */
	private $trigger;

	/** @var int */
	private $serviceId;

	/** @var int */
	private $firstResourceId;

	/** @var int */
	private $secondResourceId;

	/** @var int */
	private $thirdPartyId;

	/**
	 * Ensure the module is active before opening per-test transactions.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void
	{
		global $conf, $db;
		if (!isModEnabled('resource')) {
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$result = activateModule('modResource');
			self::assertEmpty($result['errors'], implode(', ', $result['errors']));
			$conf->setValues($db);
		}
	}

	/**
	 * Create isolated fixtures inside a rollback-only transaction.
	 *
	 * @return void
	 */
	protected function setUp(): void
	{
		global $db;
		$this->db = $db;
		$this->db->begin();
		$this->trigger = new InterfaceResourceReservations($this->db);

		$this->thirdPartyId = $this->insert('societe', array(
			'nom' => 'PHPUnit Resource Reservation',
			'entity' => 1,
		));
		$this->serviceId = $this->insert('product', array(
			'ref' => 'PHPUNIT_RESOURCE_SERVICE',
			'label' => 'PHPUnit resource service',
			'fk_product_type' => 1,
			'tosell' => 1,
			'duration' => '1d',
			'entity' => 1,
		));
		$this->firstResourceId = $this->createResource('PHPUNIT_RESOURCE_FIRST', 6);
		$this->secondResourceId = $this->createResource('PHPUNIT_RESOURCE_SECOND', 2);

		$this->insertPreference($this->firstResourceId, 1, 2.0);
		$this->insertPreference($this->secondResourceId, 2, 1.0);
	}

	/**
	 * Roll back all fixture and assertion data.
	 *
	 * @return void
	 */
	protected function tearDown(): void
	{
		$this->db->rollback();
	}

	/**
	 * A proposal creates a non-blocking provisional reservation and copies line data.
	 *
	 * @return void
	 */
	public function testProposalCreatesProvisionalReservation(): void
	{
		$lineId = $this->createProposalLine(2.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');
		$this->assertSame(1, $this->runLineTrigger('LINEPROPAL_INSERT', $lineId));

		$reservation = $this->fetchReservation('propaldet', $lineId);
		$this->assertSame($this->firstResourceId, (int) $reservation->resource_id);
		$this->assertSame('provisional', $reservation->reservation_status);
		$this->assertEquals(2.0, $reservation->service_quantity);
		$this->assertEquals(4.0, $reservation->capacity_used);
		$this->assertSame('1d', $reservation->service_duration);
		$this->assertSame('2026-10-10 08:00:00', $reservation->date_start);
		$this->assertSame('2026-10-11 08:00:00', $reservation->date_end);
	}

	/**
	 * Proposal validation checks confirmed capacity but never turns its hold
	 * into a confirmed reservation.
	 *
	 * @return void
	 */
	public function testProposalValidationRechecksCapacityAndRemainsProvisional(): void
	{
		$lineId = $this->createProposalLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');
		$this->assertSame(1, $this->runLineTrigger('LINEPROPAL_INSERT', $lineId));
		$sql = 'SELECT fk_propal FROM '.MAIN_DB_PREFIX.'propaldet WHERE rowid = '.((int) $lineId);
		$proposalId = (int) $this->db->fetch_object($this->db->query($sql))->fk_propal;

		$this->assertSame(1, $this->runObjectTrigger('PROPAL_VALIDATE', $proposalId));
		$this->assertSame('provisional', $this->fetchReservation('propaldet', $lineId)->reservation_status);

		$this->insertReservation($this->firstResourceId, 'contratdet', 999011, 6.0, 'confirmed');
		$this->insertReservation($this->secondResourceId, 'contratdet', 999012, 2.0, 'confirmed');
		$this->assertSame(-1, $this->runObjectTrigger('PROPAL_VALIDATE', $proposalId));
		$this->assertNull($this->fetchReservation('propaldet', $lineId));
		$this->assertNotEmpty($this->trigger->errors);
	}

	/**
	 * Provisional proposal capacity must not block a confirmed contract.
	 *
	 * @return void
	 */
	public function testProvisionalReservationDoesNotBlockContract(): void
	{
		$this->insertReservation($this->firstResourceId, 'propaldet', 999001, 100.0, 'provisional');
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$reservation = $this->fetchReservation('contratdet', $lineId);
		$this->assertSame($this->firstResourceId, (int) $reservation->resource_id);
		$this->assertSame('confirmed', $reservation->reservation_status);
	}

	/**
	 * A full preferred resource makes the allocator select the next preference.
	 *
	 * @return void
	 */
	public function testContractFallsBackToNextPreferredResource(): void
	{
		$this->insertReservation($this->firstResourceId, 'contratdet', 999002, 5.0, 'confirmed');
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$reservation = $this->fetchReservation('contratdet', $lineId);
		$this->assertSame($this->secondResourceId, (int) $reservation->resource_id);
		$this->assertEquals(1.0, $reservation->capacity_used);
	}

	/**
	 * Multiple whole service units use different alternatives when one resource
	 * cannot hold the complete line quantity.
	 *
	 * @return void
	 */
	public function testContractQuantitySplitsAcrossAlternativeRooms(): void
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET users_per_service_unit = 2';
		$sql .= ' WHERE element_id = '.((int) $this->serviceId);
		$this->assertTrue((bool) $this->db->query($sql));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'resource SET max_users = 2';
		$sql .= ' WHERE rowid IN ('.((int) $this->firstResourceId).','.((int) $this->secondResourceId).')';
		$this->assertTrue((bool) $this->db->query($sql));
		$lineId = $this->createContractLine(2.0, '2026-10-10 00:00:00', '2026-10-11 00:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertSame(2, $this->countReservations('contratdet', $lineId));
		$sql = 'SELECT resource_id, service_quantity, capacity_used FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = 'contratdet' AND element_id = ".((int) $lineId).' ORDER BY resource_id';
		$resql = $this->db->query($sql);
		$first = $this->db->fetch_object($resql);
		$second = $this->db->fetch_object($resql);
		$this->assertNotSame((int) $first->resource_id, (int) $second->resource_id);
		$this->assertEquals(1.0, $first->service_quantity);
		$this->assertEquals(2.0, $first->capacity_used);
		$this->assertEquals(1.0, $second->service_quantity);
		$this->assertEquals(2.0, $second->capacity_used);
	}

	/**
	 * Confirmed reservations outside the requested period do not consume capacity.
	 *
	 * @return void
	 */
	public function testNonOverlappingReservationDoesNotBlockCapacity(): void
	{
		$this->insertReservation($this->firstResourceId, 'contratdet', 999003, 6.0, 'confirmed', '2026-09-01 08:00:00', '2026-09-02 08:00:00');
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertSame($this->firstResourceId, (int) $this->fetchReservation('contratdet', $lineId)->resource_id);
	}

	/**
	 * Resources manually marked out of service are skipped by the allocator.
	 *
	 * @return void
	 */
	public function testOutOfServiceResourceIsSkipped(): void
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'resource SET fk_statut = '.Dolresource::STATUS_OUT_OF_SERVICE;
		$sql .= ' WHERE rowid = '.((int) $this->firstResourceId);
		$this->assertTrue((bool) $this->db->query($sql));
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertSame($this->secondResourceId, (int) $this->fetchReservation('contratdet', $lineId)->resource_id);
	}

	/**
	 * Busy is calculated from confirmed overlapping capacity, not stored as a manual status.
	 *
	 * @return void
	 */
	public function testBusyIsCalculatedFromConfirmedCapacity(): void
	{
		$this->insertReservation($this->firstResourceId, 'contratdet', 999006, 6.0, 'confirmed');
		$this->insertReservation($this->firstResourceId, 'propaldet', 999007, 20.0, 'provisional');
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch($this->firstResourceId));

		$this->assertTrue($resource->isBusy('2026-10-10 08:00:00', '2026-10-11 08:00:00'));
		$this->assertFalse($resource->isBusy('2026-12-01 08:00:00', '2026-12-02 08:00:00'));
	}

	/**
	 * A full resource reports Occupied for the reserved interval only.
	 *
	 * @return void
	 */
	public function testAvailabilityStatusIsOccupiedForReservedPeriod(): void
	{
		$this->insertReservation($this->secondResourceId, 'contratdet', 999008, 2.0, 'confirmed');
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch($this->secondResourceId));

		$this->assertStringContainsString('Occupied', $resource->getLibAvailabilityStatus('2026-10-10 08:00:00', '2026-10-11 08:00:00'));
		$this->assertStringContainsString('Free', $resource->getLibAvailabilityStatus('2026-12-01 08:00:00', '2026-12-02 08:00:00'));
	}

	/**
	 * Overflow is an explicit resource allocation policy and persists with the resource.
	 *
	 * @return void
	 */
	public function testResourceOverflowPolicyCanBePersisted(): void
	{
		global $user;
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch($this->firstResourceId));
		$this->assertSame(0, $resource->allow_overflow);

		$resource->allow_overflow = 1;
		$this->assertGreaterThan(0, $resource->update($user));

		$reloadedResource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $reloadedResource->fetch($this->firstResourceId));
		$this->assertSame(1, $reloadedResource->allow_overflow);
	}

	/**
	 * Unknown resources can identify an internal or external person in charge.
	 *
	 * @return void
	 */
	public function testUnknownResourceStatusProvider(): void
	{
		global $user;
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch($this->firstResourceId));
		$this->assertFalse($resource->hasStatusProvider());

		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'c_type_contact';
		$sql .= " WHERE element = 'dolresource' AND code = 'USERINCHARGE'";
		$type = $this->db->fetch_object($this->db->query($sql));
		$this->assertNotNull($type);
		$this->insert('element_contact', array(
			'element_id' => $this->firstResourceId,
			'fk_c_type_contact' => $type->rowid,
			'fk_socpeople' => $user->id,
			'statut' => 4,
		));
		$this->assertTrue($resource->hasStatusProvider());
	}

	/**
	 * The trigger rejects a contract line when every preferred resource is full.
	 *
	 * @return void
	 */
	public function testContractFailsWhenAllPreferredResourcesAreFull(): void
	{
		$this->insertReservation($this->firstResourceId, 'contratdet', 999004, 6.0, 'confirmed');
		$this->insertReservation($this->secondResourceId, 'contratdet', 999005, 2.0, 'confirmed');
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');

		$this->assertSame(-1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertNull($this->fetchReservation('contratdet', $lineId));
		$this->assertNotEmpty($this->trigger->errors);
	}

	/**
	 * Draft contracts remain provisional and capacity is enforced on validation.
	 *
	 * @return void
	 */
	public function testDraftContractIsRecheckedWhenValidated(): void
	{
		$this->insertReservation($this->firstResourceId, 'contratdet', 999009, 6.0, 'confirmed');
		$this->insertReservation($this->secondResourceId, 'contratdet', 999010, 2.0, 'confirmed');
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00', 0);

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertSame('provisional', $this->fetchReservation('contratdet', $lineId)->reservation_status);
		$sql = 'SELECT fk_contrat FROM '.MAIN_DB_PREFIX.'contratdet WHERE rowid = '.((int) $lineId);
		$contractId = (int) $this->db->fetch_object($this->db->query($sql))->fk_contrat;
		$this->assertSame(-1, $this->runObjectTrigger('CONTRACT_VALIDATE', $contractId));
		$this->assertNull($this->fetchReservation('contratdet', $lineId));
		$this->assertNotEmpty($this->trigger->errors);
	}

	/**
	 * A provisional line may overlap other drafts but cannot exceed one
	 * resource's intrinsic capacity.
	 *
	 * @return void
	 */
	public function testDraftQuantityStillSplitsAcrossAlternativeRooms(): void
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET users_per_service_unit = 2';
		$sql .= ' WHERE element_id = '.((int) $this->serviceId);
		$this->assertTrue((bool) $this->db->query($sql));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'resource SET max_users = 2';
		$sql .= ' WHERE rowid IN ('.((int) $this->firstResourceId).','.((int) $this->secondResourceId).')';
		$this->assertTrue((bool) $this->db->query($sql));
		$lineId = $this->createContractLine(2.0, '2026-10-10 00:00:00', '2026-10-11 00:00:00', 0);

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertSame(2, $this->countReservations('contratdet', $lineId));
		$sql = 'SELECT COUNT(DISTINCT resource_id) as nb, MAX(capacity_used) as maximum_used';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = 'contratdet' AND element_id = ".((int) $lineId);
		$row = $this->db->fetch_object($this->db->query($sql));
		$this->assertSame(2, (int) $row->nb);
		$this->assertEquals(2.0, $row->maximum_used);
	}

	/**
	 * Modifying a line replaces the reservation with current quantity and dates.
	 *
	 * @return void
	 */
	public function testModifySynchronizesReservation(): void
	{
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');
		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));

		$sql = 'UPDATE '.MAIN_DB_PREFIX.'contratdet SET qty = 2, date_ouverture_prevue = \'2026-11-01 09:00:00\', date_fin_validite = \'2026-11-02 09:00:00\'';
		$sql .= ' WHERE rowid = '.((int) $lineId);
		$this->assertTrue((bool) $this->db->query($sql));
		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_MODIFY', $lineId));

		$reservation = $this->fetchReservation('contratdet', $lineId);
		$this->assertEquals(2.0, $reservation->service_quantity);
		$this->assertEquals(4.0, $reservation->capacity_used);
		$this->assertSame('2026-11-01 09:00:00', $reservation->date_start);
		$this->assertSame('2026-11-02 09:00:00', $reservation->date_end);
		$this->assertSame(1, $this->countReservations('contratdet', $lineId));
	}

	/**
	 * Deleting a line removes its reservation.
	 *
	 * @return void
	 */
	public function testDeleteRemovesReservation(): void
	{
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');
		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$this->assertSame(1, $this->countReservations('contratdet', $lineId));

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_DELETE', $lineId));
		$this->assertSame(0, $this->countReservations('contratdet', $lineId));
	}

	/**
	 * Taking a resource out of service reassigns future reservations to the next preference.
	 *
	 * @return void
	 */
	public function testOutOfServiceReassignsToNextPreference(): void
	{
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');
		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$manager = new ResourceReservationManager($this->db);
		$impact = $manager->previewOutOfService($this->firstResourceId);

		$this->assertCount(1, $impact);
		$this->assertSame($this->secondResourceId, (int) $impact[0]['replacement']['resource_id']);
		$this->assertSame(1, $manager->applyOutOfService($this->firstResourceId, $impact));
		$this->assertSame($this->secondResourceId, (int) $this->fetchReservation('contratdet', $lineId)->resource_id);
		$this->assertSame(Dolresource::STATUS_OUT_OF_SERVICE, $this->fetchResourceStatus($this->firstResourceId));
	}

	/**
	 * Reservations without a replacement remain visible as unavailable.
	 *
	 * @return void
	 */
	public function testOutOfServiceMarksReservationUnavailableWithoutReplacement(): void
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'resource SET fk_statut='.Dolresource::STATUS_OUT_OF_SERVICE;
		$sql .= ' WHERE rowid='.((int) $this->secondResourceId);
		$this->assertTrue((bool) $this->db->query($sql));
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-11 08:00:00');
		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$manager = new ResourceReservationManager($this->db);
		$impact = $manager->previewOutOfService($this->firstResourceId);

		$this->assertCount(1, $impact);
		$this->assertNull($impact[0]['replacement']);
		$this->assertSame(1, $manager->applyOutOfService($this->firstResourceId, $impact));
		$reservation = $this->fetchReservation('contratdet', $lineId);
		$this->assertSame($this->firstResourceId, (int) $reservation->resource_id);
		$this->assertSame('unavailable', $reservation->reservation_status);
	}

	/**
	 * Resource preferences are returned in configured position order.
	 *
	 * @return void
	 */
	public function testPreferencesAreOrderedByPosition(): void
	{
		$resource = new Dolresource($this->db);
		$preferences = $resource->getElementResources('product', $this->serviceId, 'dolresource');

		$this->assertCount(2, $preferences);
		$this->assertSame($this->firstResourceId, (int) $preferences[0]['resource_id']);
		$this->assertSame(1, (int) $preferences[0]['position']);
		$this->assertSame($this->secondResourceId, (int) $preferences[1]['resource_id']);
		$this->assertSame(2, (int) $preferences[1]['position']);
	}

	/**
	 * Requirement duration includes setup, base, per-unit and cleanup time.
	 *
	 * @return void
	 */
	public function testRequirementDurationCalculation(): void
	{
		$manager = new ResourceReservationManager($this->db);
		$duration = $manager->calculateDuration(array(
			'setup_duration' => 10,
			'duration_base' => 20,
			'duration_per_unit' => 15,
			'cleanup_duration' => 5,
		), 3);
		$this->assertSame(80, $duration);
	}

	/**
	 * The strictest associated requirements define the service-line time UI.
	 *
	 * @return void
	 */
	public function testTemporalPolicyCombinesResourceRequirements(): void
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET start_input_mode = \'date\',';
		$sql .= " end_input_mode = 'calculated', time_precision = 'hour'";
		$sql .= ' WHERE element_id = '.((int) $this->serviceId).' AND position = 1';
		$this->assertTrue((bool) $this->db->query($sql));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET start_input_mode = \'datetime\',';
		$sql .= " end_input_mode = 'datetime', time_precision = 'second'";
		$sql .= ' WHERE element_id = '.((int) $this->serviceId).' AND position = 2';
		$this->assertTrue((bool) $this->db->query($sql));

		$policy = (new ResourceReservationManager($this->db))->getTemporalPolicy('product', $this->serviceId);
		$this->assertTrue($policy['has_requirements']);
		$this->assertSame('datetime', $policy['start_input_mode']);
		$this->assertSame('datetime', $policy['end_input_mode']);
		$this->assertSame('second', $policy['time_precision']);
		$this->assertTrue($policy['show_start']);
		$this->assertTrue($policy['show_end']);
		$this->assertFalse($policy['calculate_end']);
	}

	/**
	 * Resource type metadata and machine cooldown persist on the resource.
	 *
	 * @return void
	 */
	public function testMachineResourceModelCanBePersisted(): void
	{
		global $user;
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch($this->firstResourceId));
		$resource->fk_code_type_resource = 'RES_MACHINES';
		$resource->max_users = null;
		$resource->metric_value = 1200.5;
		$resource->cooldown_minutes = 30;
		$this->assertGreaterThan(0, $resource->update($user));

		$reloaded = new Dolresource($this->db);
		$this->assertGreaterThan(0, $reloaded->fetch($this->firstResourceId));
		$this->assertSame('none', $reloaded->capacity_mode);
		$this->assertSame(1, $reloaded->supports_cooldown);
		$this->assertNull($reloaded->metric_value);
		$this->assertSame(30, $reloaded->cooldown_minutes);
	}

	/**
	 * A resource type clears characteristics that do not apply to it.
	 *
	 * @return void
	 */
	public function testResourceTypeCapabilitiesLimitStoredValues(): void
	{
		global $user;
		$resource = new Dolresource($this->db);
		$this->assertGreaterThan(0, $resource->fetch($this->firstResourceId));
		$resource->fk_code_type_resource = 'RES_CARS';
		$resource->max_users = 9;
		$resource->allow_overflow = 1;
		$resource->metric_value = 12500.5;
		$resource->cooldown_minutes = 45;
		$this->assertGreaterThan(0, $resource->update($user));

		$reloaded = new Dolresource($this->db);
		$this->assertGreaterThan(0, $reloaded->fetch($this->firstResourceId));
		$this->assertSame('custom', $reloaded->capacity_mode);
		$this->assertNull($reloaded->max_users);
		$this->assertSame(0, $reloaded->allow_overflow);
		$this->assertEquals(12500.5, $reloaded->metric_value);
		$this->assertSame(0, $reloaded->cooldown_minutes);
	}

	/**
	 * Cooldown extends resource occupation without changing the service line dates.
	 *
	 * @return void
	 */
	public function testMachineCooldownExtendsReservationBlock(): void
	{
		$sql = 'UPDATE '.MAIN_DB_PREFIX."resource SET fk_code_type_resource = 'RES_MACHINES', max_users = NULL, cooldown_minutes = 30";
		$sql .= ' WHERE rowid = '.((int) $this->firstResourceId);
		$this->assertTrue((bool) $this->db->query($sql));
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET users_per_service_unit = 1';
		$sql .= ' WHERE element_id = '.((int) $this->serviceId).' AND position = 1';
		$this->assertTrue((bool) $this->db->query($sql));
		$lineId = $this->createContractLine(1.0, '2026-10-10 08:00:00', '2026-10-10 09:00:00');

		$this->assertSame(1, $this->runLineTrigger('LINECONTRACT_INSERT', $lineId));
		$reservation = $this->fetchReservation('contratdet', $lineId);
		$this->assertSame('2026-10-10 08:00:00', $reservation->date_start);
		$this->assertSame('2026-10-10 09:30:00', $reservation->date_end);
	}

	/**
	 * Both absolute and weekly slot definitions can be persisted.
	 *
	 * @return void
	 */
	public function testResourceTimeSlotDefinitions(): void
	{
		$absoluteId = $this->insert('resource_time_slot', array(
			'entity' => 1,
			'fk_resource' => $this->firstResourceId,
			'label' => 'Absolute slot',
			'slot_type' => 'absolute',
			'date_start' => '2026-10-10 08:00:00',
			'date_end' => '2026-10-10 12:00:00',
			'capacity' => 4,
			'active' => 1,
		));
		$weeklyId = $this->insert('resource_time_slot', array(
			'entity' => 1,
			'fk_resource' => $this->firstResourceId,
			'label' => 'Weekly slot',
			'slot_type' => 'weekly',
			'weekday' => 1,
			'time_start' => 28800,
			'time_end' => 43200,
			'active' => 1,
		));

		$this->assertGreaterThan(0, $absoluteId);
		$this->assertGreaterThan(0, $weeklyId);
		$sql = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'resource_time_slot WHERE rowid IN ('.((int) $absoluteId).','.((int) $weeklyId).')';
		$this->assertSame(2, (int) $this->db->fetch_object($this->db->query($sql))->nb);
	}

	/**
	 * BookCal slots use the resource timezone instead of the visitor timezone.
	 *
	 * @return void
	 */
	public function testBookCalSlotsRespectVisitorTimezone(): void
	{
		global $user;
		$calendarId = $this->insert('bookcal_calendar', array(
			'entity' => 1,
			'ref' => 'PHPUNIT-BOOKCAL',
			'label' => 'PHPUnit BookCal',
			'timezone' => 'Europe/Madrid',
			'date_creation' => '2026-08-19 10:00:00',
			'fk_user_creat' => $user->id,
			'status' => 1,
			'type' => 3,
			'visibility' => 1,
		));
		$this->insert('bookcal_availabilities', array(
			'label' => 'PHPUnit range',
			'date_creation' => '2026-08-19 10:00:00',
			'fk_user_creat' => $user->id,
			'status' => 1,
			'start' => '2026-08-20',
			'end' => '2026-08-20',
			'duration' => 60,
			'startHour' => 9,
			'endHour' => 11,
			'fk_bookcal_calendar' => $calendarId,
		));
		$previousTimezone = isset($_SESSION['dol_tz_string']) ? $_SESSION['dol_tz_string'] : null;
		$_SESSION['dol_tz_string'] = 'America/New_York';
		$provider = new BookCalAvailabilityProvider($this->db);
		$dayStart = gmmktime(0, 0, 0, 8, 20, 2026);
		$slots = $provider->getSlots($calendarId, $dayStart);
		$slotStart = $provider->getLocalTimestamp($calendarId, $dayStart, '09:00');
		if ($previousTimezone === null) {
			unset($_SESSION['dol_tz_string']);
		} else {
			$_SESSION['dol_tz_string'] = $previousTimezone;
		}

		$this->assertSame(array('09:00', '10:00'), array_keys($slots));
		$this->assertSame(array(60, 60), array_values($slots));
		$this->assertSame('2026-08-20 07:00:00', gmdate('Y-m-d H:i:s', $slotStart));
		$this->assertSame('2026-08-20 09:00', $provider->formatLocalTimestamp($calendarId, $slotStart));
	}

	/**
	 * An end hour equal to or before the start hour denotes the following day.
	 *
	 * @return void
	 */
	public function testBookCalSupportsOvernightSlots(): void
	{
		global $user;
		$calendarId = $this->insert('bookcal_calendar', array(
			'entity' => 1,
			'ref' => 'PHPUNIT-BOOKCAL-OVERNIGHT',
			'label' => 'PHPUnit overnight BookCal',
			'timezone' => 'Europe/Madrid',
			'date_creation' => '2026-08-19 10:00:00',
			'fk_user_creat' => $user->id,
			'status' => 1,
			'type' => 3,
			'visibility' => 1,
		));
		$this->insert('bookcal_availabilities', array(
			'label' => 'Hotel night',
			'date_creation' => '2026-08-19 10:00:00',
			'fk_user_creat' => $user->id,
			'status' => 1,
			'start' => '2026-08-20',
			'end' => '2026-08-20',
			'duration' => 1439,
			'startHour' => 12,
			'endHour' => 12,
			'fk_bookcal_calendar' => $calendarId,
		));
		$dayStart = gmmktime(0, 0, 0, 8, 20, 2026);
		$provider = new BookCalAvailabilityProvider($this->db);
		$slots = $provider->getSlots($calendarId, $dayStart);
		$slotStart = $provider->getLocalTimestamp($calendarId, $dayStart, '12:00');

		$this->assertSame(array('12:00' => 1439), $slots);
		$this->assertTrue($provider->isAvailable($calendarId, $slotStart, $slotStart + (1439 * 60)));
	}

	/**
	 * BookCal only offers sellable services linked to the selected calendar.
	 *
	 * @return void
	 */
	public function testBookCalListsLinkedServices(): void
	{
		global $user;
		$calendarId = $this->insert('bookcal_calendar', array(
			'entity' => 1,
			'ref' => 'PHPUNIT-BOOKCAL-SERVICES',
			'label' => 'PHPUnit BookCal services',
			'timezone' => 'Europe/Madrid',
			'date_creation' => '2026-08-19 10:00:00',
			'fk_user_creat' => $user->id,
			'status' => 1,
			'type' => 3,
			'visibility' => 1,
		));
		$this->insert('element_resources', array(
			'element_id' => $this->serviceId,
			'element_type' => 'product',
			'resource_id' => $calendarId,
			'resource_type' => 'bookcal_calendar',
			'relation_kind' => 'requirement',
		));
		$provider = new BookCalAvailabilityProvider($this->db);
		$services = $provider->getServices($calendarId);

		$this->assertArrayHasKey($this->serviceId, $services);
		$this->assertSame('PHPUNIT_RESOURCE_SERVICE', $services[$this->serviceId]['ref']);
		$this->assertTrue($provider->isServiceLinked($calendarId, $this->serviceId));
		$this->assertFalse($provider->isServiceLinked($calendarId, $this->serviceId + 9999));
	}

	/**
	 * Unknown availability keeps all future procurement links and lifecycle data.
	 *
	 * @return void
	 */
	public function testUnknownAvailabilityCanLinkSupplierWorkflow(): void
	{
		global $user, $langs, $conf;
		$assignmentId = $this->insert('element_resources', array(
			'element_id' => 99881,
			'element_type' => 'propaldet',
			'resource_id' => $this->firstResourceId,
			'resource_type' => 'dolresource',
			'relation_kind' => 'assignment',
			'capacity_used' => 2,
			'date_start' => '2026-10-10 08:00:00',
			'date_end' => '2026-10-11 08:00:00',
			'reservation_status' => ResourceReservationManager::STATUS_AWAITING_SUPPLY,
		));
		$manager = new ResourceSupplyRequestManager($this->db);
		$requestId = $manager->create(array(
			'fk_element_resource' => $assignmentId,
			'fk_resource' => $this->firstResourceId,
			'request_type' => 'supplier',
			'fk_soc_supplier' => $this->thirdPartyId,
			'fk_product_supplier' => $this->serviceId,
			'quantity_requested' => 2,
			'date_start' => '2026-10-10 08:00:00',
			'date_end' => '2026-10-11 08:00:00',
			'timezone' => 'Europe/Madrid',
		), $user);
		$this->assertGreaterThan(0, $requestId);
		$this->assertSame(1, $manager->linkSupplierOrder($requestId, 8801, 8802, $user));

		$order = new stdClass();
		$order->id = 8801;
		$this->assertSame(1, $this->trigger->runTrigger('ORDER_SUPPLIER_APPROVE', $order, $user, $langs, $conf));
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'resource_supply_request WHERE rowid='.((int) $requestId);
		$request = $this->db->fetch_object($this->db->query($sql));
		$this->assertSame(ResourceSupplyRequestManager::STATUS_REQUESTED, $request->request_status);
		$this->assertSame('ORDER_SUPPLIER_APPROVE', $request->supplier_order_status);
		$this->assertSame(ResourceReservationManager::STATUS_AWAITING_SUPPLY, $this->fetchReservation('propaldet', 99881)->reservation_status);
	}

	/**
	 * Bulk availability loading ignores reservations that ended in the past.
	 *
	 * @return void
	 */
	public function testBulkAvailabilityOnlyLoadsRequestedFutureWindow(): void
	{
		$this->insertReservation($this->firstResourceId, 'contratdet', 99891, 5.0, 'confirmed', '2025-01-01 08:00:00', '2025-01-02 08:00:00');
		$this->insertReservation($this->firstResourceId, 'contratdet', 99892, 2.0, 'confirmed', '2026-10-10 09:00:00', '2026-10-10 10:00:00');
		$this->insertReservation($this->secondResourceId, 'contratdet', 99893, 1.0, 'confirmed', '2026-11-10 09:00:00', '2026-11-10 10:00:00');
		$manager = new ResourceReservationManager($this->db);
		$assignments = $manager->loadConfirmedAssignments(
			'dolresource',
			array($this->firstResourceId, $this->secondResourceId),
			'2026-10-10 08:00:00',
			'2026-10-11 08:00:00'
		);

		$this->assertCount(1, $assignments[$this->firstResourceId]);
		$this->assertArrayNotHasKey($this->secondResourceId, $assignments);
		$this->assertEquals(2.0, $manager->getOccupiedCapacityFromAssignments($assignments, $this->firstResourceId, '2026-10-10 08:00:00', '2026-10-11 08:00:00'));
	}

	/** @return int */
	private function createResource($ref, $capacity)
	{
		return $this->insert('resource', array('entity' => 1, 'ref' => $ref, 'max_users' => $capacity, 'fk_code_type_resource' => 'RES_ROOMS', 'fk_statut' => Dolresource::STATUS_FREE));
	}

	/** @return void */
	private function insertPreference($resourceId, $position, $usersPerUnit)
	{
		$this->insert('element_resources', array(
			'element_id' => $this->serviceId,
			'element_type' => 'product',
			'resource_id' => $resourceId,
			'resource_type' => 'dolresource',
			'busy' => 0,
			'mandatory' => 1,
			'position' => $position,
			'relation_kind' => 'requirement',
			'requirement_group' => 'preferred_room',
			'users_per_service_unit' => $usersPerUnit,
		));
	}

	/** @return int */
	private function createProposalLine($qty, $dateStart, $dateEnd)
	{
		$proposalId = $this->insert('propal', array('ref' => 'PHPUNIT-PROPAL', 'entity' => 1, 'fk_statut' => 0));
		return $this->insert('propaldet', array(
			'fk_propal' => $proposalId,
			'fk_product' => $this->serviceId,
			'product_type' => 1,
			'qty' => $qty,
			'date_start' => $dateStart,
			'date_end' => $dateEnd,
		));
	}

	/** @return int */
	private function createContractLine($qty, $dateStart, $dateEnd, $status = 1)
	{
		global $user;
		$contractId = $this->insert('contrat', array('ref' => 'PHPUNIT-CONTRACT', 'fk_soc' => $this->thirdPartyId, 'fk_user_author' => $user->id, 'entity' => 1, 'statut' => $status));
		return $this->insert('contratdet', array(
			'fk_contrat' => $contractId,
			'fk_product' => $this->serviceId,
			'product_type' => 1,
			'qty' => $qty,
			'date_ouverture_prevue' => $dateStart,
			'date_fin_validite' => $dateEnd,
		));
	}

	/** @return void */
	private function insertReservation($resourceId, $elementType, $elementId, $capacity, $status, $dateStart = '2026-10-10 08:00:00', $dateEnd = '2026-10-11 08:00:00')
	{
		$this->insert('element_resources', array(
			'element_id' => $elementId,
			'element_type' => $elementType,
			'resource_id' => $resourceId,
			'resource_type' => 'dolresource',
			'relation_kind' => 'assignment',
			'capacity_used' => $capacity,
			'date_start' => $dateStart,
			'date_end' => $dateEnd,
			'reservation_status' => $status,
		));
	}

	/** @return int */
	private function runLineTrigger($action, $lineId)
	{
		global $user, $langs, $conf;
		$object = new stdClass();
		$object->id = $lineId;
		$object->context = array();
		return $this->trigger->runTrigger($action, $object, $user, $langs, $conf);
	}

	/** @return int */
	private function runObjectTrigger($action, $objectId)
	{
		global $user, $langs, $conf;
		$object = new stdClass();
		$object->id = $objectId;
		return $this->trigger->runTrigger($action, $object, $user, $langs, $conf);
	}

	/** @return object|null */
	private function fetchReservation($elementType, $lineId)
	{
		$sql = 'SELECT * FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."' AND element_id = ".((int) $lineId);
		$resql = $this->db->query($sql);
		return $resql ? ($this->db->fetch_object($resql) ?: null) : null;
	}

	/** @return int */
	private function countReservations($elementType, $lineId)
	{
		$sql = 'SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."' AND element_id = ".((int) $lineId);
		return (int) $this->db->fetch_object($this->db->query($sql))->nb;
	}

	/** @return int */
	private function fetchResourceStatus($resourceId)
	{
		$sql = 'SELECT fk_statut FROM '.MAIN_DB_PREFIX.'resource WHERE rowid='.((int) $resourceId);
		return (int) $this->db->fetch_object($this->db->query($sql))->fk_statut;
	}

	/**
	 * Insert a row with escaped values.
	 *
	 * @param string $table Table without prefix
	 * @param array<string,mixed> $values Column values
	 * @return int Inserted id
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
				$sqlValues[] = "'".$this->db->escape($value)."'";
			}
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.$this->db->sanitize($table);
		$sql .= ' ('.implode(',', $columns).') VALUES ('.implode(',', $sqlValues).')';
		$this->assertTrue((bool) $this->db->query($sql), (string) $this->db->lasterror());
		return (int) $this->db->last_insert_id(MAIN_DB_PREFIX.$table);
	}
}
