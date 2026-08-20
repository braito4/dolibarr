<?php
/* Copyright (C) 2026 Dolibarr contributors */

use PHPUnit\Framework\TestCase;

global $db;
$documentRoot = is_file(dirname(__FILE__).'/../../htdocs/master.inc.php')
	? dirname(__FILE__).'/../../htdocs'
	: dirname(__FILE__).'/../..';
require_once $documentRoot.'/master.inc.php';
require_once $documentRoot.'/resource/class/resourcetimemaskprovider.class.php';

/**
 * Tests for reusable resource availability masks.
 *
 * @backupGlobals disabled
 */
class ResourceAvailabilityCalendarTest extends TestCase
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
	 * A range anchored on Monday may cover the first hours of Tuesday.
	 *
	 * @return void
	 */
	public function testOvernightMaskCoversFollowingLocalDay(): void
	{
		global $conf;
		$resourceId = $this->createResource('PHPUNIT_RESERVATION_MASK');
		$entity = (int) $conf->entity;
		$sql = 'INSERT INTO '.$this->db->prefix().'resource_time_mask (entity, ref, label, timezone, active)';
		$sql .= " VALUES (".$entity.", 'PHPUNIT_MASK', 'PHPUnit overnight mask', 'Europe/Madrid', 1)";
		$this->assertTrue((bool) $this->db->query($sql));
		$maskId = (int) $this->db->last_insert_id($this->db->prefix().'resource_time_mask');

		$sql = 'INSERT INTO '.$this->db->prefix().'resource_time_mask_range';
		$sql .= ' (fk_time_mask, weekday_mask, start_day_offset, start_time, end_day_offset, end_time, slot_duration, active, position)';
		$sql .= ' VALUES ('.$maskId.', 1, 0, 79200, 1, 7200, 30, 1, 1)';
		$this->assertTrue((bool) $this->db->query($sql));

		$provider = new ResourceTimeMaskProvider($this->db);
		$this->assertSame(1, $provider->assignMask($maskId, 'dolresource', $resourceId));
		$ranges = $provider->getRangesCoveringLocalDay('dolresource', $resourceId, '2099-01-06', 'Europe/Madrid');

		$this->assertCount(1, $ranges);
		$timezone = new DateTimeZone('Europe/Madrid');
		$start = (new DateTimeImmutable('@'.$ranges[0]['start']))->setTimezone($timezone);
		$end = (new DateTimeImmutable('@'.$ranges[0]['end']))->setTimezone($timezone);
		$this->assertSame('2099-01-05 22:00', $start->format('Y-m-d H:i'));
		$this->assertSame('2099-01-06 02:00', $end->format('Y-m-d H:i'));
	}

	/**
	 * @param string $ref Resource reference
	 * @return int
	 */
	private function createResource($ref)
	{
		global $conf;
		$sql = 'INSERT INTO '.$this->db->prefix().'resource (entity, ref, available_units, fk_statut)';
		$sql .= " VALUES (".((int) $conf->entity).", '".$this->db->escape($ref)."', 1, 1)";
		$this->assertTrue((bool) $this->db->query($sql));
		return (int) $this->db->last_insert_id($this->db->prefix().'resource');
	}
}
