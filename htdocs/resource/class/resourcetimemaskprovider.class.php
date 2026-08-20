<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file resource/class/resourcetimemaskprovider.class.php
 * \ingroup resource
 * \brief Expand reusable calendar masks without materializing slots.
 */

/**
 * Read and expand reusable resource time masks.
 */
class ResourceTimeMaskProvider
{
	/** @var DoliDB */
	private $db;
	/** @var array<string,object|false> */
	private $maskCache = array();
	/** @var array<int,array<int,object>> */
	private $rangeCache = array();
	/** @var array<string,int> */
	private $resourceEntityCache = array();
	/** @var bool */
	private $hasDatabaseError = false;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Return the active mask assigned to a resource.
	 *
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @return object|null Mask row
	 */
	public function getMask($resourceType, $resourceId)
	{
		$cacheKey = $resourceType.':'.((int) $resourceId);
		if (array_key_exists($cacheKey, $this->maskCache)) {
			return $this->maskCache[$cacheKey] ?: null;
		}
		$resourceEntity = $this->getResourceEntity($resourceType, $resourceId);
		if ($resourceEntity <= 0) {
			$this->maskCache[$cacheKey] = false;
			return null;
		}
		$sql = 'SELECT m.rowid, m.ref, m.label, m.timezone';
		$sql .= ' FROM '.$this->db->prefix().'resource_time_mask_assignment a';
		$sql .= ' INNER JOIN '.$this->db->prefix().'resource_time_mask m ON m.rowid = a.fk_time_mask';
		$sql .= ' WHERE a.entity = '.$resourceEntity;
		$sql .= " AND a.resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= ' AND a.resource_id = '.((int) $resourceId);
		$sql .= ' AND m.entity = '.$resourceEntity.' AND m.active = 1';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			$this->maskCache[$cacheKey] = false;
			return null;
		}
		$mask = $resql ? $this->db->fetch_object($resql) : null;
		$this->maskCache[$cacheKey] = $mask ?: false;
		return $mask ?: null;
	}

	/**
	 * Return whether a resource uses a structured calendar mask.
	 *
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @return bool
	 */
	public function hasMask($resourceType, $resourceId)
	{
		return $this->getMask($resourceType, $resourceId) !== null;
	}

	/**
	 * Assign a reusable mask to a resource, replacing its previous mask.
	 *
	 * Permission checks belong to the calling action handler.
	 *
	 * @param int    $maskId       Mask id
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @return int Positive on success, negative on failure
	 */
	public function assignMask($maskId, $resourceType, $resourceId)
	{
		if ($maskId <= 0 || $resourceId <= 0 || !in_array($resourceType, array('dolresource', 'bookcal_calendar'), true)) {
			return -1;
		}
		$resourceEntity = $this->getResourceEntity($resourceType, $resourceId);
		if ($resourceEntity <= 0) {
			return -1;
		}
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'resource_time_mask';
		$sql .= ' WHERE rowid = '.((int) $maskId).' AND entity = '.$resourceEntity.' AND active = 1';
		$resql = $this->db->query($sql);
		if (!$resql || !$this->db->fetch_object($resql)) {
			if (!$resql) {
				$this->registerDatabaseError(__METHOD__);
			}
			return -1;
		}
		$sql = 'SELECT rowid FROM '.$this->db->prefix().'resource_time_mask_assignment';
		$sql .= ' WHERE entity = '.$resourceEntity;
		$sql .= " AND resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= ' AND resource_id = '.((int) $resourceId);
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return -1;
		}
		$assignment = $resql ? $this->db->fetch_object($resql) : null;
		if ($assignment) {
			$sql = 'UPDATE '.$this->db->prefix().'resource_time_mask_assignment';
			$sql .= ' SET fk_time_mask = '.((int) $maskId).' WHERE rowid = '.((int) $assignment->rowid);
		} else {
			$sql = 'INSERT INTO '.$this->db->prefix().'resource_time_mask_assignment';
			$sql .= ' (entity, fk_time_mask, resource_type, resource_id) VALUES (';
			$sql .= $resourceEntity.', '.((int) $maskId).", '".$this->db->escape($resourceType)."', ".((int) $resourceId).')';
		}
		if (!$this->db->query($sql)) {
			return -1;
		}
		$this->maskCache = array();
		return 1;
	}

	/**
	 * Return the mask timezone or a caller-provided fallback.
	 *
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @param string $fallback     Fallback IANA timezone
	 * @return string
	 */
	public function getTimezone($resourceType, $resourceId, $fallback = 'UTC')
	{
		$mask = $this->getMask($resourceType, $resourceId);
		$timezone = $mask && !empty($mask->timezone) ? (string) $mask->timezone : $fallback;
		try {
			return (new DateTimeZone($timezone))->getName();
		} catch (Exception $exception) {
			return 'UTC';
		}
	}

	/**
	 * Expand the mask ranges anchored on one local calendar day.
	 *
	 * No generated range is written to the database.
	 *
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @param string $dayKey       Local anchor day formatted as Y-m-d
	 * @param string $timezoneName Fallback IANA timezone
	 * @return array<int,array{start:int,end:int,slot_duration:int}>
	 */
	public function getRangesForLocalDay($resourceType, $resourceId, $dayKey, $timezoneName = 'UTC')
	{
		$mask = $this->getMask($resourceType, $resourceId);
		if (!$mask) {
			return array();
		}
		$timezone = new DateTimeZone($this->getTimezone($resourceType, $resourceId, $timezoneName));
		$anchor = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, $timezone);
		if (!($anchor instanceof DateTimeImmutable) || $anchor->format('Y-m-d') !== $dayKey) {
			return array();
		}
		$weekday = (int) $anchor->format('N');
		$ranges = array();
		foreach ($this->getMaskRanges((int) $mask->rowid) as $range) {
			if (((int) $range->weekday_mask & (1 << ($weekday - 1))) === 0) {
				continue;
			}
			$start = $this->buildLocalTimestamp($anchor, (int) $range->start_day_offset, (int) $range->start_time);
			$end = $this->buildLocalTimestamp($anchor, (int) $range->end_day_offset, (int) $range->end_time);
			if ($start <= 0 || $end <= $start || (int) $range->slot_duration <= 0) {
				continue;
			}
			$ranges[] = array(
				'start' => $start,
				'end' => $end,
				'slot_duration' => (int) $range->slot_duration,
			);
		}

		return $ranges;
	}

	/**
	 * Expand ranges that intersect one local day, including ranges anchored earlier.
	 *
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @param string $dayKey       Local target day formatted as Y-m-d
	 * @param string $timezoneName Fallback IANA timezone
	 * @return array<int,array{start:int,end:int,slot_duration:int}>
	 */
	public function getRangesCoveringLocalDay($resourceType, $resourceId, $dayKey, $timezoneName = 'UTC')
	{
		$mask = $this->getMask($resourceType, $resourceId);
		if (!$mask) {
			return array();
		}
		$timezone = new DateTimeZone($this->getTimezone($resourceType, $resourceId, $timezoneName));
		$targetDay = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, $timezone);
		if (!($targetDay instanceof DateTimeImmutable) || $targetDay->format('Y-m-d') !== $dayKey) {
			return array();
		}
		$maximumEndDayOffset = 0;
		foreach ($this->getMaskRanges((int) $mask->rowid) as $range) {
			$maximumEndDayOffset = max($maximumEndDayOffset, (int) $range->end_day_offset);
		}
		$maximumEndDayOffset = min(366, $maximumEndDayOffset);
		$targetStart = $targetDay->getTimestamp();
		$targetEnd = $targetDay->modify('+1 day')->getTimestamp();
		$ranges = array();
		$seen = array();
		for ($dayOffset = 0; $dayOffset <= $maximumEndDayOffset; $dayOffset++) {
			$anchorKey = $targetDay->modify('-'.$dayOffset.' days')->format('Y-m-d');
			foreach ($this->getRangesForLocalDay($resourceType, $resourceId, $anchorKey, $timezone->getName()) as $range) {
				if ($range['end'] <= $targetStart || $range['start'] >= $targetEnd) {
					continue;
				}
				$key = $range['start'].':'.$range['end'].':'.$range['slot_duration'];
				if (!isset($seen[$key])) {
					$seen[$key] = true;
					$ranges[] = $range;
				}
			}
		}
		return $ranges;
	}

	/**
	 * Return whether a mask lookup failed during this request.
	 *
	 * @return bool
	 */
	public function hasDatabaseError()
	{
		return $this->hasDatabaseError;
	}

	/**
	 * Load active definitions once per mask.
	 *
	 * @param int $maskId Mask id
	 * @return array<int,object>
	 */
	private function getMaskRanges($maskId)
	{
		if (isset($this->rangeCache[$maskId])) {
			return $this->rangeCache[$maskId];
		}
		$this->rangeCache[$maskId] = array();
		$sql = 'SELECT weekday_mask, start_day_offset, start_time, end_day_offset, end_time, slot_duration';
		$sql .= ' FROM '.$this->db->prefix().'resource_time_mask_range';
		$sql .= ' WHERE fk_time_mask = '.((int) $maskId).' AND active = 1';
		$sql .= ' ORDER BY position, rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return $this->rangeCache[$maskId];
		}
		while ($resql && ($range = $this->db->fetch_object($resql))) {
			$this->rangeCache[$maskId][] = $range;
		}
		return $this->rangeCache[$maskId];
	}

	/**
	 * Build a timestamp from an anchor, day offset and second-of-day value.
	 *
	 * @param DateTimeImmutable $anchor    Local midnight
	 * @param int               $dayOffset Relative day offset
	 * @param int               $time      Seconds since local midnight
	 * @return int
	 */
	private function buildLocalTimestamp(DateTimeImmutable $anchor, $dayOffset, $time)
	{
		if ($dayOffset < 0 || $time < 0 || $time > 86399) {
			return 0;
		}
		$hour = (int) floor($time / 3600);
		$minute = (int) floor(($time % 3600) / 60);
		$second = $time % 60;
		return $anchor->modify('+'.$dayOffset.' days')->setTime($hour, $minute, $second)->getTimestamp();
	}

	/**
	 * Resolve the owning entity of a supported resource implementation.
	 *
	 * @param string $resourceType Resource object type
	 * @param int    $resourceId   Resource object id
	 * @return int Entity id, or zero when inaccessible/not found
	 */
	private function getResourceEntity($resourceType, $resourceId)
	{
		$cacheKey = $resourceType.':'.((int) $resourceId);
		if (array_key_exists($cacheKey, $this->resourceEntityCache)) {
			return $this->resourceEntityCache[$cacheKey];
		}
		if ($resourceType === 'dolresource') {
			$sql = 'SELECT entity FROM '.$this->db->prefix().'resource';
			$sql .= ' WHERE rowid = '.((int) $resourceId).' AND entity IN ('.getEntity('resource').')';
		} elseif ($resourceType === 'bookcal_calendar') {
			$sql = 'SELECT entity FROM '.$this->db->prefix().'bookcal_calendar';
			$sql .= ' WHERE rowid = '.((int) $resourceId).' AND entity IN ('.getEntity('calendar', 0).')';
		} else {
			return 0;
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return 0;
		}
		$resource = $this->db->fetch_object($resql);
		$this->resourceEntityCache[$cacheKey] = $resource ? (int) $resource->entity : 0;
		return $this->resourceEntityCache[$cacheKey];
	}

	/**
	 * Record a database lookup failure so callers can fail closed.
	 *
	 * @param string $method Method reporting the failure
	 * @return void
	 */
	private function registerDatabaseError($method)
	{
		$this->hasDatabaseError = true;
		dol_syslog($method.': '.$this->db->lasterror(), LOG_ERR);
	}
}
