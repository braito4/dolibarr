<?php
/* Copyright (C) 2026 Dolibarr contributors */

use PHPUnit\Framework\TestCase;

global $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/resourcerequirementmanager.class.php';
require_once $documentRoot.'/resource/class/dolresource.class.php';

/**
 * Tests for product and service resource requirements.
 *
 * @backupGlobals disabled
 */
class ResourceServiceRelationTest extends TestCase
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
	 * Default groups make resources of one role ordered alternatives without
	 * mixing independent roles.
	 *
	 * @return void
	 */
	public function testDefaultRequirementGroupUsesResourceRole(): void
	{
		$this->assertSame(
			'preferred_capacity',
			ResourceRequirementManager::getDefaultRequirementGroup('capacity')
		);
		$this->assertSame(
			'preferred_equipment',
			ResourceRequirementManager::getDefaultRequirementGroup('equipment')
		);
		$this->assertSame(
			'preferred_capacity',
			ResourceRequirementManager::getDefaultRequirementGroup('unknown')
		);
	}

	/**
	 * The strictest linked resource controls the service time interface.
	 *
	 * @return void
	 */
	public function testRequirementsBuildTemporalPolicy(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, position, scheduling_mode, start_input_mode, end_input_mode, time_precision)';
		$sql .= " VALUES (99101, 'service', 99201, 'dolresource', 'requirement', 1, 'same_as_parent', 'date', 'calculated', 'day')";
		$this->assertTrue((bool) $this->db->query($sql));
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, position, scheduling_mode, start_input_mode, end_input_mode, time_precision)';
		$sql .= " VALUES (99101, 'service', 99202, 'dolresource', 'requirement', 2, 'next_available', 'datetime', 'datetime', 'second')";
		$this->assertTrue((bool) $this->db->query($sql));

		$policy = (new ResourceRequirementManager($this->db))->getTemporalPolicy('service', 99101);

		$this->assertTrue($policy['has_requirements']);
		$this->assertSame('datetime', $policy['start_input_mode']);
		$this->assertSame('datetime', $policy['end_input_mode']);
		$this->assertSame('second', $policy['time_precision']);
		$this->assertTrue($policy['show_start']);
		$this->assertTrue($policy['show_end']);
		$this->assertTrue($policy['automatic']);
	}

	/**
	 * Volume awareness metadata is returned with service requirements.
	 *
	 * @return void
	 */
	public function testVolumeAwarenessMetadataIsLoaded(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, context_scope, demand_source, capacity_metrics, required_location, selection_policy)';
		$sql .= " VALUES (99102, 'service', 99203, 'dolresource', 'requirement', 'same_proposal', 'product_lines', 'volume_weight', 'Madrid depot', 'smallest_sufficient')";
		$this->assertTrue((bool) $this->db->query($sql));

		$requirements = (new Dolresource($this->db))->getElementResources('service', 99102, 'dolresource');

		$this->assertCount(1, $requirements);
		$this->assertSame('same_proposal', $requirements[0]['context_scope']);
		$this->assertSame('product_lines', $requirements[0]['demand_source']);
		$this->assertSame('volume_weight', $requirements[0]['capacity_metrics']);
		$this->assertSame('Madrid depot', $requirements[0]['required_location']);
		$this->assertSame('smallest_sufficient', $requirements[0]['selection_policy']);
	}

	/**
	 * Generic element links keep their original semantics and are not returned
	 * as product/service requirements.
	 *
	 * @return void
	 */
	public function testGenericLinksRemainSeparateFromRequirements(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, busy)';
		$sql .= " VALUES (99103, 'action', 99204, 'dolresource', 'link', 1)";
		$this->assertTrue((bool) $this->db->query($sql));

		$relations = (new Dolresource($this->db))->getElementResources('action', 99103, 'dolresource');

		$this->assertCount(1, $relations);
		$this->assertSame('link', $relations[0]['relation_kind']);
	}

	/**
	 * Invalid relation semantics are rejected by the domain method, including
	 * callers that bypass the web controller.
	 *
	 * @return void
	 */
	public function testInvalidRelationKindIsRejected(): void
	{
		$relation = new Dolresource($this->db);
		$relation->relation_kind = 'invalid';

		$this->assertSame(-1, $relation->updateElementResource());
	}
}
