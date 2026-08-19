<?php
/* Copyright (C) 2026 Dolibarr contributors */

use PHPUnit\Framework\TestCase;

global $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/resourcerequirementmanager.class.php';

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
	 * The strictest linked resource controls the service time interface.
	 *
	 * @return void
	 */
	public function testRequirementsBuildTemporalPolicy(): void
	{
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, position, scheduling_mode, start_input_mode, end_input_mode, time_precision)';
		$sql .= " VALUES (99101, 'product', 99201, 'dolresource', 'requirement', 1, 'same_as_parent', 'date', 'calculated', 'day')";
		$this->assertTrue((bool) $this->db->query($sql));
		$sql = 'INSERT INTO '.$this->db->prefix().'element_resources';
		$sql .= ' (element_id, element_type, resource_id, resource_type, relation_kind, position, scheduling_mode, start_input_mode, end_input_mode, time_precision)';
		$sql .= " VALUES (99101, 'product', 99202, 'dolresource', 'requirement', 2, 'next_available', 'datetime', 'datetime', 'second')";
		$this->assertTrue((bool) $this->db->query($sql));

		$policy = (new ResourceRequirementManager($this->db))->getTemporalPolicy('product', 99101);

		$this->assertTrue($policy['has_requirements']);
		$this->assertSame('datetime', $policy['start_input_mode']);
		$this->assertSame('datetime', $policy['end_input_mode']);
		$this->assertSame('second', $policy['time_precision']);
		$this->assertTrue($policy['show_start']);
		$this->assertTrue($policy['show_end']);
		$this->assertTrue($policy['automatic']);
	}
}
