<?php
/* Copyright (C) 2026 Dolibarr contributors */

require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcerequirementmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcetimemaskprovider.class.php';

/**
 * \file resource/class/resourcereservationmanager.class.php
 * \ingroup resource
 * \brief Shared availability, capacity and assignment engine.
 */

/**
 * Manage resource availability and assignment lifecycles.
 */
class ResourceReservationManager extends ResourceRequirementManager
{
	const STATUS_PROVISIONAL = 'provisional';
	const STATUS_CONFIRMED = 'confirmed';
	const STATUS_CANCELED = 'canceled';
	const STATUS_UNAVAILABLE = 'unavailable';

	/** @var DoliDB */
	protected $db;
	/** @var bool */
	protected $hasAvailabilityReadError = false;
	/** @var bool */
	protected $hasOperationReadError = false;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->db = $db;
	}

	/**
	 * Calculate occupied minutes from requirement metadata.
	 *
	 * @param array<string,mixed> $requirement Requirement row
	 * @param float $quantity Sold or produced quantity
	 * @return int
	 */
	public function calculateDuration(array $requirement, $quantity)
	{
		return (int) (max(0, (int) $requirement['setup_duration'])
			+ max(0, (int) $requirement['duration_base'])
			+ (max(0, (int) $requirement['duration_per_unit']) * abs((float) $quantity))
			+ max(0, (int) $requirement['cleanup_duration']));
	}

	/**
	 * @param string $duration Dolibarr duration such as 30i, 2h or 1d
	 * @return int Minutes
	 */
	public function durationStringToMinutes($duration)
	{
		if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)(s|i|mn|min|h|d|w|m|y)$/i', (string) $duration, $matches)) {
			return 0;
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
		return (int) round(convertDurationtoHour((float) $matches[1], strtolower($matches[2])) * 60);
	}

	/**
	 * Calculate the volume of every product line in the same commercial document.
	 *
	 * Volumes are normalized to cubic metres, consistently with
	 * CommonObject::getTotalWeightVolume().
	 *
	 * @param string $elementType propaldet, commandedet or contratdet
	 * @param int    $parentId    Proposal, order or contract id
	 * @return float Volume in cubic metres
	 */
	public function calculateDocumentProductVolume($elementType, $parentId)
	{
		if ($parentId <= 0) {
			return 0.0;
		}

		if ($elementType === 'propaldet') {
			$sql = 'SELECT d.qty, p.volume, p.volume_units FROM '.MAIN_DB_PREFIX.'propaldet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
			$sql .= ' WHERE d.fk_propal = '.((int) $parentId);
		} elseif ($elementType === 'commandedet') {
			$sql = 'SELECT d.qty, p.volume, p.volume_units FROM '.MAIN_DB_PREFIX.'commandedet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
			$sql .= ' WHERE d.fk_commande = '.((int) $parentId);
		} elseif ($elementType === 'contratdet') {
			$sql = 'SELECT d.qty, p.volume, p.volume_units FROM '.MAIN_DB_PREFIX.'contratdet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product';
			$sql .= ' WHERE d.fk_contrat = '.((int) $parentId);
		} else {
			return 0.0;
		}
		$sql .= ' AND d.product_type = 0';
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return PHP_FLOAT_MAX;
		}
		$totalVolume = 0.0;
		while ($resql && ($productLine = $this->db->fetch_object($resql))) {
			$unitFactor = (int) $productLine->volume_units < 50 ? pow(10, (int) $productLine->volume_units) : 1.0;
			$totalVolume += abs((float) $productLine->qty) * (float) $productLine->volume * $unitFactor;
		}

		return $totalVolume;
	}

	/**
	 * Calculate the payload weight of every product line in a document.
	 *
	 * @param string $elementType propaldet, commandedet or contratdet
	 * @param int    $parentId    Proposal, order or contract id
	 * @return float Weight in kilograms
	 */
	public function calculateDocumentProductWeight($elementType, $parentId)
	{
		if ($parentId <= 0) {
			return 0.0;
		}
		if ($elementType === 'propaldet') {
			$sql = 'SELECT d.qty, p.weight, p.weight_units FROM '.MAIN_DB_PREFIX.'propaldet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product WHERE d.fk_propal = '.((int) $parentId);
		} elseif ($elementType === 'commandedet') {
			$sql = 'SELECT d.qty, p.weight, p.weight_units FROM '.MAIN_DB_PREFIX.'commandedet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product WHERE d.fk_commande = '.((int) $parentId);
		} elseif ($elementType === 'contratdet') {
			$sql = 'SELECT d.qty, p.weight, p.weight_units FROM '.MAIN_DB_PREFIX.'contratdet d';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = d.fk_product WHERE d.fk_contrat = '.((int) $parentId);
		} else {
			return 0.0;
		}
		$sql .= ' AND d.product_type = 0';
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return PHP_FLOAT_MAX;
		}
		$totalWeight = 0.0;
		while ($resql && ($productLine = $this->db->fetch_object($resql))) {
			$weightUnits = (int) $productLine->weight_units;
			if ($weightUnits === 99) {
				$unitFactor = 0.45359237;
			} elseif ($weightUnits === 98) {
				$unitFactor = 0.0283495;
			} else {
				$unitFactor = $weightUnits < 50 ? pow(10, $weightUnits) : 1.0;
			}
			$totalWeight += abs((float) $productLine->qty) * (float) $productLine->weight * $unitFactor;
		}
		return $totalWeight;
	}

	/**
	 * Find the first available interval for a resource.
	 *
	 * Resource time-slot rules are optional. With no rules, the resource is
	 * considered continuously open. The search uses 15-minute boundaries.
	 *
	 * @param string $resourceType Resource type
	 * @param int $resourceId Resource id
	 * @param int $earliestStart Earliest timestamp
	 * @param int $latestEnd Latest timestamp
	 * @param int $durationMinutes Required duration
	 * @param float $capacity Required capacity
	 * @param float|null $maximumCapacity Explicit capacity
	 * @param int $requiredUnits Number of homogeneous units required
	 * @param int|null $availableUnits Number of homogeneous units available
	 * @param bool $locking Use current locking reads for concurrent allocation
	 * @return array{date_start:string,date_end:string}|null
	 */
	public function findNextAvailable($resourceType, $resourceId, $earliestStart, $latestEnd, $durationMinutes, $capacity = 1.0, $maximumCapacity = null, $requiredUnits = 1, $availableUnits = null, $locking = false)
	{
		if ($durationMinutes <= 0 || $earliestStart <= 0 || $latestEnd <= $earliestStart) {
			return null;
		}
		$rules = array();
		$maskProvider = new ResourceTimeMaskProvider($this->db);
		$hasTimeMask = $maskProvider->hasMask($resourceType, $resourceId);
		if ($maskProvider->hasDatabaseError()) {
			return null;
		}
		$maskRangesByDay = array();
		$maskTimezone = $maskProvider->getTimezone($resourceType, $resourceId, 'UTC');
		if ($resourceType === 'dolresource') {
			$rules = $this->loadResourceTimeRules($resourceId);
			if ($this->hasAvailabilityReadError) {
				return null;
			}
			$sql = 'SELECT r.max_users, r.metric_value, r.available_units, r.fk_statut, ty.capacity_mode FROM '.MAIN_DB_PREFIX.'resource r';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
			$sql .= ' WHERE r.rowid = '.((int) $resourceId).' AND r.entity IN ('.getEntity('resource').')';
			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
				return null;
			}
			$resource = $this->db->fetch_object($resql);
			$allowedStatuses = getDolGlobalInt('RESOURCE_ENABLE_UNKNOWN_AVAILABILITY') ? array(0, 1) : array(1);
			if (!$resource || !in_array((int) $resource->fk_statut, $allowedStatuses, true)) {
				return null;
			}
			$availableUnits = max(1, (int) $resource->available_units);
			$intrinsicCapacity = $this->getResourceMaximumCapacity($resource) * $availableUnits;
			if ($maximumCapacity === null || $maximumCapacity > $intrinsicCapacity) {
				$maximumCapacity = $intrinsicCapacity;
			}
		}
		if ($maximumCapacity === null) {
			$maximumCapacity = 1.0;
		}
		$assignments = $this->loadConfirmedAssignments($resourceType, array($resourceId), $this->db->idate($earliestStart), $this->db->idate($latestEnd), $locking);
		$cursor = (int) (ceil($earliestStart / 900) * 900);
		$durationSeconds = $durationMinutes * 60;
		while ($cursor + $durationSeconds <= $latestEnd) {
			$end = $cursor + $durationSeconds;
			$occupied = $this->getOccupiedCapacityFromAssignments($assignments, $resourceId, $this->db->idate($cursor), $this->db->idate($end));
			$occupiedUnits = $this->getOccupiedResourceUnitsFromAssignments($assignments, $resourceId, $this->db->idate($cursor), $this->db->idate($end));
			$matchesCalendar = $this->matchesOpeningRule($cursor, $end, $rules);
			if ($hasTimeMask && $matchesCalendar) {
				$dayKey = (new DateTimeImmutable('@'.$cursor))->setTimezone(new DateTimeZone($maskTimezone))->format('Y-m-d');
				if (!isset($maskRangesByDay[$dayKey])) {
					$maskRangesByDay[$dayKey] = $maskProvider->getRangesCoveringLocalDay($resourceType, $resourceId, $dayKey, $maskTimezone);
				}
				if ($maskProvider->hasDatabaseError()) {
					return null;
				}
				$matchesCalendar = $matchesCalendar && $this->matchesExpandedMaskRange($cursor, $end, $maskRangesByDay[$dayKey]);
			}
			$fitsUnits = $availableUnits === null || ($occupiedUnits + max(1, (int) $requiredUnits)) <= $availableUnits;
			if ($matchesCalendar && $fitsUnits && ($occupied + $capacity) <= $maximumCapacity) {
				return array('date_start' => $this->db->idate($cursor), 'date_end' => $this->db->idate($end));
			}
			$cursor += 900;
		}
		return null;
	}

	/**
	 * Check whether an interval is contained in an expanded reusable mask range.
	 *
	 * @param int                                             $start  Start timestamp
	 * @param int                                             $end    End timestamp
	 * @param array<int,array{start:int,end:int,slot_duration:int}> $ranges Expanded ranges
	 * @return bool
	 */
	private function matchesExpandedMaskRange($start, $end, array $ranges)
	{
		foreach ($ranges as $range) {
			$slotSeconds = max(1, (int) $range['slot_duration']) * 60;
			if ($start >= $range['start'] && $end <= $range['end'] && (($start - $range['start']) % $slotSeconds) === 0) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Load active availability rules for a resource in the current entity scope.
	 *
	 * @param int $resourceId Resource id
	 * @return array<int,array<string,mixed>>
	 */
	private function loadResourceTimeRules($resourceId)
	{
		$rules = array();
		$sql = 'SELECT slot_type, availability_status, date_start, date_end, weekday, time_start, time_end';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'resource_time_slot';
		$sql .= ' WHERE fk_resource = '.((int) $resourceId).' AND active = 1';
		$sql .= ' AND entity IN ('.getEntity('resource').')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->hasAvailabilityReadError = true;
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return $rules;
		}
		while ($resql && ($rule = $this->db->fetch_array($resql))) {
			$rules[] = $rule;
		}
		return $rules;
	}

	/**
	 * Check fixed dates against reusable masks and resource-specific exceptions.
	 *
	 * @param int $resourceId Resource id
	 * @param int $start      Start timestamp
	 * @param int $end        End timestamp
	 * @return bool
	 */
	private function matchesResourceSchedule($resourceId, $start, $end)
	{
		$rules = $this->loadResourceTimeRules($resourceId);
		if ($this->hasAvailabilityReadError || !$this->matchesOpeningRule($start, $end, $rules)) {
			return false;
		}
		$maskProvider = new ResourceTimeMaskProvider($this->db);
		$hasMask = $maskProvider->hasMask('dolresource', $resourceId);
		if ($maskProvider->hasDatabaseError()) {
			$this->hasAvailabilityReadError = true;
			return false;
		}
		if (!$hasMask) {
			return true;
		}
		$timezone = $maskProvider->getTimezone('dolresource', $resourceId, 'UTC');
		$dayKey = (new DateTimeImmutable('@'.$start))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d');
		$ranges = $maskProvider->getRangesCoveringLocalDay('dolresource', $resourceId, $dayKey, $timezone);
		if ($maskProvider->hasDatabaseError()) {
			$this->hasAvailabilityReadError = true;
			return false;
		}
		return $this->matchesExpandedMaskRange($start, $end, $ranges);
	}

	/**
	 * Check a requested interval against the resource schedule.
	 *
	 * @param string $resourceType Resource type
	 * @param int    $resourceId   Resource id
	 * @param string $dateStart    Database start date
	 * @param string $dateEnd      Database end date
	 * @return bool
	 */
	public function isAvailableInterval($resourceType, $resourceId, $dateStart, $dateEnd)
	{
		if ($resourceType !== 'dolresource') {
			return true;
		}
		$start = $this->db->jdate($dateStart);
		$end = $this->db->jdate($dateEnd);
		return $start > 0 && $end > $start && $this->matchesResourceSchedule($resourceId, $start, $end);
	}

	/**
	 * @param int                            $start Start timestamp
	 * @param int                            $end   End timestamp
	 * @param array<int,array<string,mixed>> $rules Opening rules
	 * @return bool
	 */
	private function matchesOpeningRule($start, $end, array $rules)
	{
		if (empty($rules)) {
			return true;
		}
		$hasAvailabilityRule = false;
		foreach ($rules as $rule) {
			$status = !empty($rule['availability_status']) ? $rule['availability_status'] : 'available';
			if ($status === 'available') {
				$hasAvailabilityRule = true;
				continue;
			}
			if ($rule['slot_type'] === 'absolute') {
				$ruleStart = $this->db->jdate($rule['date_start']);
				$ruleEnd = $this->db->jdate($rule['date_end']);
				if ($ruleStart > 0 && $ruleEnd > $ruleStart && $start < $ruleEnd && $end > $ruleStart) {
					return false;
				}
			} elseif ($rule['slot_type'] === 'weekly') {
				foreach ($this->getWeeklyRuleIntervals($rule, $start) as $interval) {
					if ($start < $interval['end'] && $end > $interval['start']) {
						return false;
					}
				}
			}
		}
		if (!$hasAvailabilityRule) {
			return true;
		}
		foreach ($rules as $rule) {
			if ((!empty($rule['availability_status']) ? $rule['availability_status'] : 'available') !== 'available') {
				continue;
			}
			if ($rule['slot_type'] === 'absolute') {
				$ruleStart = $this->db->jdate($rule['date_start']);
				$ruleEnd = $this->db->jdate($rule['date_end']);
				if ($ruleStart > 0 && $ruleEnd > $ruleStart && $start >= $ruleStart && $end <= $ruleEnd) {
					return true;
				}
			} elseif ($rule['slot_type'] === 'weekly') {
				foreach ($this->getWeeklyRuleIntervals($rule, $start) as $interval) {
					if ($start >= $interval['start'] && $end <= $interval['end']) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Expand the closest weekly rule occurrences in one coherent timezone.
	 *
	 * @param array<string,mixed> $rule               Weekly rule
	 * @param int                 $referenceTimestamp Requested start timestamp
	 * @return array<int,array{start:int,end:int}>
	 */
	private function getWeeklyRuleIntervals(array $rule, $referenceTimestamp)
	{
		$weekday = (int) $rule['weekday'];
		$timeStart = (int) $rule['time_start'];
		$timeEnd = (int) $rule['time_end'];
		if ($weekday < 1 || $weekday > 7 || $timeStart < 0 || $timeEnd < 0) {
			return array();
		}
		$timezone = new DateTimeZone(date_default_timezone_get());
		$referenceDay = (new DateTimeImmutable('@'.$referenceTimestamp))->setTimezone($timezone)->setTime(0, 0, 0);
		$daysBack = ((int) $referenceDay->format('N') - $weekday + 7) % 7;
		$closestAnchor = $referenceDay->modify('-'.$daysBack.' days');
		$intervals = array();
		for ($weekOffset = 0; $weekOffset <= 1; $weekOffset++) {
			$anchor = $closestAnchor->modify('+'.($weekOffset * 7).' days');
			$ruleStart = $this->buildLocalRuleBoundary($anchor, $timeStart);
			$endOffset = $timeEnd <= $timeStart ? $timeEnd + 86400 : $timeEnd;
			$ruleEnd = $this->buildLocalRuleBoundary($anchor, $endOffset);
			if ($ruleEnd > $ruleStart) {
				$intervals[] = array('start' => $ruleStart, 'end' => $ruleEnd);
			}
		}
		return $intervals;
	}

	/**
	 * Build a local wall-clock boundary while preserving DST transitions.
	 *
	 * @param DateTimeImmutable $anchor        Local midnight
	 * @param int               $secondsOffset Seconds after the anchor day
	 * @return int
	 */
	private function buildLocalRuleBoundary(DateTimeImmutable $anchor, $secondsOffset)
	{
		$dayOffset = (int) floor($secondsOffset / 86400);
		$seconds = $secondsOffset % 86400;
		$hour = (int) floor($seconds / 3600);
		$minute = (int) floor(($seconds % 3600) / 60);
		$second = $seconds % 60;
		return $anchor->modify('+'.$dayOffset.' days')->setTime($hour, $minute, $second)->getTimestamp();
	}

	/**
	 * Return occupied capacity for a half-open interval [start, end).
	 *
	 * @param string $resourceType Resource type
	 * @param int $resourceId Resource id
	 * @param string $dateStart Database date start
	 * @param string $dateEnd Database date end
	 * @param int $excludeRowId Assignment to ignore
	 * @param bool $locking Use a current locking read
	 * @param int  $excludeLegacyActionId Agenda action to ignore
	 * @return float
	 */
	public function getOccupiedCapacity($resourceType, $resourceId, $dateStart, $dateEnd, $excludeRowId = 0, $locking = false, $excludeLegacyActionId = 0)
	{
		$sql = 'SELECT rowid, capacity_used';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id = '.((int) $resourceId);
		$sql .= " AND resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= " AND reservation_status = 'confirmed'";
		$sql .= " AND (relation_kind = 'assignment' OR relation_kind IS NULL)";
		$sql .= " AND (date_end IS NULL OR date_end > '".$this->db->escape($dateStart)."')";
		$sql .= " AND (date_start IS NULL OR date_start < '".$this->db->escape($dateEnd)."')";
		if ($excludeRowId > 0) {
			$sql .= ' AND rowid <> '.((int) $excludeRowId);
		}
		$sql .= ' ORDER BY rowid';
		if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return PHP_FLOAT_MAX;
		}
		$occupied = 0.0;
		while ($assignment = $this->db->fetch_object($resql)) {
			$occupied += $assignment->capacity_used === null ? 1.0 : (float) $assignment->capacity_used;
		}
		$legacyConflicts = $this->loadLegacyActionConflicts($resourceType, array($resourceId), $dateStart, $dateEnd, $locking, $excludeLegacyActionId);
		return $legacyConflicts === false || !empty($legacyConflicts[$resourceId]) ? PHP_FLOAT_MAX : $occupied;
	}

	/**
	 * Return the number of overlapping homogeneous resource units in use.
	 *
	 * @param string $resourceType Resource type
	 * @param int    $resourceId   Resource id
	 * @param string $dateStart    Database date start
	 * @param string $dateEnd      Database date end
	 * @param bool   $locking      Use a current locking read
	 * @param int    $excludeLegacyActionId Agenda action to ignore
	 * @return int
	 */
	public function getOccupiedResourceUnits($resourceType, $resourceId, $dateStart, $dateEnd, $locking = false, $excludeLegacyActionId = 0)
	{
		$sql = 'SELECT rowid, resource_units_used';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id = '.((int) $resourceId);
		$sql .= " AND resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= " AND reservation_status = 'confirmed'";
		$sql .= " AND (relation_kind = 'assignment' OR relation_kind IS NULL)";
		$sql .= " AND (date_end IS NULL OR date_end > '".$this->db->escape($dateStart)."')";
		$sql .= " AND (date_start IS NULL OR date_start < '".$this->db->escape($dateEnd)."')";
		$sql .= ' ORDER BY rowid';
		if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return PHP_INT_MAX;
		}
		$occupied = 0;
		while ($assignment = $this->db->fetch_object($resql)) {
			$occupied += $assignment->resource_units_used === null ? 1 : max(1, (int) $assignment->resource_units_used);
		}
		$legacyConflicts = $this->loadLegacyActionConflicts($resourceType, array($resourceId), $dateStart, $dateEnd, $locking, $excludeLegacyActionId);
		return $legacyConflicts === false || !empty($legacyConflicts[$resourceId]) ? PHP_INT_MAX : $occupied;
	}

	/**
	 * Load confirmed assignments for many resources using one future-bounded query.
	 *
	 * @param string     $resourceType Resource type
	 * @param array<int> $resourceIds  Resource ids
	 * @param string     $dateStart    Earliest requested date
	 * @param string     $dateEnd      Latest requested date
	 * @param bool       $locking      Use a current locking read
	 * @return array<int,array<int,array{date_start:?string,date_end:?string,capacity:float,units:int}>>
	 */
	public function loadConfirmedAssignments($resourceType, array $resourceIds, $dateStart, $dateEnd, $locking = false)
	{
		$resourceIds = array_values(array_unique(array_filter(array_map('intval', $resourceIds))));
		$assignments = array();
		if (empty($resourceIds) || empty($dateStart) || empty($dateEnd) || $dateStart >= $dateEnd) {
			return $assignments;
		}
		$sqlResourceIds = $this->db->sanitize(implode(',', $resourceIds));
		$sql = 'SELECT rowid, resource_id, date_start, date_end, capacity_used, resource_units_used FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id IN ('.$sqlResourceIds.')';
		$sql .= " AND resource_type = '".$this->db->escape($resourceType)."'";
		$sql .= " AND reservation_status = 'confirmed'";
		$sql .= " AND (relation_kind = 'assignment' OR relation_kind IS NULL)";
		$sql .= " AND (date_end IS NULL OR date_end > '".$this->db->escape($dateStart)."')";
		$sql .= " AND (date_start IS NULL OR date_start < '".$this->db->escape($dateEnd)."')";
		$sql .= ' ORDER BY rowid';
		if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			foreach ($resourceIds as $resourceId) {
				$assignments[$resourceId][] = array(
					'date_start' => null,
					'date_end' => null,
					'capacity' => PHP_FLOAT_MAX,
					'units' => PHP_INT_MAX,
				);
			}
			return $assignments;
		}
		while ($resql && ($row = $this->db->fetch_object($resql))) {
			$assignments[(int) $row->resource_id][] = array(
				'date_start' => $row->date_start,
				'date_end' => $row->date_end,
				'capacity' => $row->capacity_used === null ? 1.0 : (float) $row->capacity_used,
				'units' => $row->resource_units_used === null ? 1 : max(1, (int) $row->resource_units_used),
			);
		}
		$legacyConflicts = $this->loadLegacyActionConflicts($resourceType, $resourceIds, $dateStart, $dateEnd, $locking);
		if ($legacyConflicts === false) {
			$legacyConflicts = array_fill_keys($resourceIds, true);
		}
		foreach ($legacyConflicts as $resourceId => $hasConflict) {
			if ($hasConflict) {
				$assignments[(int) $resourceId][] = array(
					'date_start' => null,
					'date_end' => null,
					'capacity' => PHP_FLOAT_MAX,
					'units' => PHP_INT_MAX,
				);
			}
		}
		return $assignments;
	}

	/**
	 * Load overlapping legacy Agenda links that predate the reservation ledger.
	 *
	 * @param string     $resourceType Resource implementation type
	 * @param array<int> $resourceIds  Resource ids
	 * @param string     $dateStart    Interval start
	 * @param string     $dateEnd      Interval end
	 * @param bool       $locking      Use a current locking read
	 * @param int        $excludeActionId Agenda action to ignore
	 * @return array<int,bool>|false
	 */
	private function loadLegacyActionConflicts($resourceType, array $resourceIds, $dateStart, $dateEnd, $locking, $excludeActionId = 0)
	{
		$conflicts = array();
		if ($resourceType !== 'dolresource' || empty($resourceIds) || !getDolGlobalString('RESOURCE_USED_IN_EVENT_CHECK')) {
			return $conflicts;
		}
		$sql = 'SELECT er.rowid, er.resource_id, er.element_id FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " WHERE er.element_type = 'action' AND er.resource_type = 'dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind = 'link')";
		$sql .= ' AND er.reservation_status IS NULL AND er.busy = 1';
		$sql .= ' AND er.resource_id IN ('.$this->db->sanitize(implode(',', array_map('intval', $resourceIds))).')';
		if ($excludeActionId > 0) {
			$sql .= ' AND er.element_id <> '.((int) $excludeActionId);
		}
		$sql .= ' ORDER BY er.rowid';
		if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return false;
		}
		$resourcesByAction = array();
		while ($link = $this->db->fetch_object($resql)) {
			$resourcesByAction[(int) $link->element_id][] = (int) $link->resource_id;
		}
		if (empty($resourcesByAction)) {
			return $conflicts;
		}

		$sql = 'SELECT id, datep, datep2, fulldayevent FROM '.MAIN_DB_PREFIX.'actioncomm';
		$sql .= ' WHERE id IN ('.implode(',', array_keys($resourcesByAction)).')';
		$sql .= ' AND entity IN ('.getEntity('actioncomm').')';
		$sql .= ' ORDER BY id';
		if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			// A locking read is required under MySQL REPEATABLE READ so a request that
			// waited for the resource lock cannot validate against an older action
			// snapshot. The database may resolve an action/resource lock inversion by
			// aborting one transaction; callers fail closed and retry that operation.
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return false;
		}
		while ($action = $this->db->fetch_object($resql)) {
			$actionStartTimestamp = $this->db->jdate($action->datep);
			$actionEndTimestamp = $this->db->jdate($action->datep2);
			if ($actionStartTimestamp <= 0) {
				continue;
			}
			if ($actionEndTimestamp <= 0) {
				if (!empty($action->fulldayevent)) {
					$startParts = dol_getdate($actionStartTimestamp);
					$actionStartTimestamp = dol_mktime(0, 0, 0, $startParts['mon'], $startParts['mday'], $startParts['year']);
					$actionEndTimestamp = dol_mktime(0, 0, 0, $startParts['mon'], $startParts['mday'] + 1, $startParts['year']);
				} else {
					$actionEndTimestamp = $actionStartTimestamp + 1;
				}
			}
			$actionStart = $this->db->idate($actionStartTimestamp);
			$actionEnd = $this->db->idate($actionEndTimestamp);
			if ($actionEnd <= $dateStart || $actionStart >= $dateEnd) {
				continue;
			}
			foreach ($resourcesByAction[(int) $action->id] as $resourceId) {
				$conflicts[$resourceId] = true;
			}
		}
		return $conflicts;
	}

	/**
	 * Sum overlapping homogeneous resource units from a loaded assignment index.
	 *
	 * @param array<int,array<int,array{date_start:?string,date_end:?string,capacity:float,units:int}>> $assignments Assignment index
	 * @param int    $resourceId Resource id
	 * @param string $dateStart  Requested start
	 * @param string $dateEnd    Requested end
	 * @return int
	 */
	public function getOccupiedResourceUnitsFromAssignments(array $assignments, $resourceId, $dateStart, $dateEnd)
	{
		$occupied = 0;
		foreach ($assignments[(int) $resourceId] ?? array() as $assignment) {
			if (($assignment['date_end'] === null || $assignment['date_end'] > $dateStart)
				&& ($assignment['date_start'] === null || $assignment['date_start'] < $dateEnd)) {
				$occupied += $assignment['units'];
			}
		}
		return $occupied;
	}

	/**
	 * Sum overlapping capacity from a previously loaded assignment index.
	 *
	 * @param array<int,array<int,array{date_start:?string,date_end:?string,capacity:float,units:int}>> $assignments Assignment index
	 * @param int    $resourceId Resource id
	 * @param string $dateStart  Requested start
	 * @param string $dateEnd    Requested end
	 * @return float
	 */
	public function getOccupiedCapacityFromAssignments(array $assignments, $resourceId, $dateStart, $dateEnd)
	{
		$occupied = 0.0;
		foreach ($assignments[(int) $resourceId] ?? array() as $assignment) {
			if (($assignment['date_end'] === null || $assignment['date_end'] > $dateStart)
				&& ($assignment['date_start'] === null || $assignment['date_start'] < $dateEnd)) {
				$occupied += $assignment['capacity'];
			}
		}
		return $occupied;
	}

	/**
	 * Check a resource capacity using the common assignment ledger.
	 *
	 * @param string $resourceType Resource type
	 * @param int $resourceId Resource id
	 * @param string $dateStart Database date start
	 * @param string $dateEnd Database date end
	 * @param float $requiredCapacity Requested capacity
	 * @param float|null $maximumCapacity Explicit capacity for virtual resources
	 * @param int $requiredUnits Number of homogeneous units required
	 * @param int|null $availableUnits Number of homogeneous units available
	 * @param bool $locking Use current locking reads for concurrent allocation
	 * @param int $excludeLegacyActionId Agenda action to ignore
	 * @return bool
	 */
	public function canReserve($resourceType, $resourceId, $dateStart, $dateEnd, $requiredCapacity = 1.0, $maximumCapacity = null, $requiredUnits = 1, $availableUnits = null, $locking = false, $excludeLegacyActionId = 0)
	{
		$requiredUnits = max(1, (int) $requiredUnits);
		if (empty($dateStart) || empty($dateEnd) || $dateStart >= $dateEnd || $requiredCapacity <= 0) {
			return false;
		}
		if ($resourceType === 'dolresource') {
			$sql = 'SELECT r.max_users, r.metric_value, r.available_units, r.fk_statut, ty.capacity_mode FROM '.MAIN_DB_PREFIX.'resource r';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
			$sql .= ' WHERE r.rowid = '.((int) $resourceId).' AND r.entity IN ('.getEntity('resource').')';
			if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
				$sql .= ' FOR UPDATE';
			}
			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
				return false;
			}
			$obj = $this->db->fetch_object($resql);
			$allowedStatuses = getDolGlobalInt('RESOURCE_ENABLE_UNKNOWN_AVAILABILITY') ? array(0, 1) : array(1);
			if (!$obj || !in_array((int) $obj->fk_statut, $allowedStatuses, true)) {
				return false;
			}
			if ($availableUnits === null) {
				$availableUnits = max(1, (int) $obj->available_units);
			}
			$intrinsicCapacity = $this->getResourceMaximumCapacity($obj) * $availableUnits;
			if ($maximumCapacity === null || $maximumCapacity > $intrinsicCapacity) {
				$maximumCapacity = $intrinsicCapacity;
			}
			$dateStartTimestamp = $this->db->jdate($dateStart);
			$dateEndTimestamp = $this->db->jdate($dateEnd);
			if (!$dateStartTimestamp || !$dateEndTimestamp || !$this->matchesResourceSchedule($resourceId, $dateStartTimestamp, $dateEndTimestamp)) {
				return false;
			}
		} elseif ($resourceType === 'bookcal_calendar') {
			$sql = 'SELECT status FROM '.MAIN_DB_PREFIX.'bookcal_calendar WHERE rowid = '.((int) $resourceId);
			$sql .= ' AND entity IN ('.getEntity('calendar', 0).')';
			if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
				$sql .= ' FOR UPDATE';
			}
			$resql = $this->db->query($sql);
			if (!$resql) {
				dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
				return false;
			}
			$calendar = $this->db->fetch_object($resql);
			if (!$calendar || (int) $calendar->status !== 1) {
				return false;
			}
		}
		if ($maximumCapacity === null) {
			$maximumCapacity = 1.0;
		}
		if ($maximumCapacity <= 0 || $requiredCapacity > $maximumCapacity) {
			return false;
		}
		if ($availableUnits !== null && ($this->getOccupiedResourceUnits($resourceType, $resourceId, $dateStart, $dateEnd, $locking, $excludeLegacyActionId) + $requiredUnits) > $availableUnits) {
			return false;
		}
		return ($this->getOccupiedCapacity($resourceType, $resourceId, $dateStart, $dateEnd, 0, $locking, $excludeLegacyActionId) + $requiredCapacity) <= $maximumCapacity;
	}

	/**
	 * Validate an exclusive physical-resource link owned by an Agenda action.
	 *
	 * @param int    $resourceId Resource id
	 * @param int    $actionId   Action id to exclude from legacy occupancy
	 * @param string $dateStart  Database interval start
	 * @param string $dateEnd    Database interval end
	 * @return bool
	 */
	public function canReserveExclusiveAction($resourceId, $actionId, $dateStart, $dateEnd)
	{
		$sql = 'SELECT r.max_users, r.metric_value, r.available_units, r.fk_statut, ty.capacity_mode';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'resource r';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code = r.fk_code_type_resource';
		$sql .= ' WHERE r.rowid = '.((int) $resourceId).' AND r.entity IN ('.getEntity('resource').')';
		if (!in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		$resource = $resql ? $this->db->fetch_object($resql) : null;
		if (!$resql || !$resource) {
			return false;
		}
		$availableUnits = max(1, (int) $resource->available_units);
		$maximumCapacity = $this->getResourceMaximumCapacity($resource) * $availableUnits;
		return $this->canReserve(
			'dolresource',
			(int) $resourceId,
			$dateStart,
			$dateEnd,
			$maximumCapacity,
			$maximumCapacity,
			$availableUnits,
			$availableUnits,
			true,
			(int) $actionId
		);
	}

	/**
	 * Create one assignment after rechecking capacity.
	 *
	 * @param array<string,mixed> $assignment Normalized assignment
	 * @param User $user Current user
	 * @return int Assignment id, -1 on conflict or database error
	 */
	public function createAssignment(array $assignment, User $user)
	{
		$requiredFields = array('element_type', 'element_id', 'resource_type', 'resource_id', 'date_start', 'date_end');
		foreach ($requiredFields as $requiredField) {
			if (!isset($assignment[$requiredField]) || $assignment[$requiredField] === '') {
				return -1;
			}
		}
		$resourceType = $assignment['resource_type'];
		$resourceId = (int) $assignment['resource_id'];
		$reservationStatus = !empty($assignment['reservation_status']) ? $assignment['reservation_status'] : self::STATUS_CONFIRMED;
		if ((int) $assignment['element_id'] <= 0 || $resourceId <= 0
			|| !in_array($resourceType, array('dolresource', 'bookcal_calendar'), true)
			|| !in_array($reservationStatus, array(self::STATUS_PROVISIONAL, self::STATUS_CONFIRMED), true)) {
			return -1;
		}
		$capacity = isset($assignment['capacity_used']) ? (float) $assignment['capacity_used'] : 1.0;
		$resourceUnits = max(1, (int) (isset($assignment['resource_units_used']) ? $assignment['resource_units_used'] : 1));
		$maximum = isset($assignment['maximum_capacity']) ? (float) $assignment['maximum_capacity'] : null;
		if (!$this->db->begin()) {
			return -1;
		}
		$lockSql = '';
		$lockSuffix = in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
		if ($resourceType === 'dolresource') {
			$lockSql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'resource WHERE rowid = '.((int) $resourceId);
			$lockSql .= ' AND entity IN ('.getEntity('resource').')'.$lockSuffix;
		} elseif ($resourceType === 'bookcal_calendar') {
			$lockSql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'bookcal_calendar WHERE rowid = '.((int) $resourceId);
			$lockSql .= ' AND status = 1 AND entity IN ('.getEntity('calendar', 0).')'.$lockSuffix;
		}
		if ($lockSql) {
			$lockResult = $this->db->query($lockSql);
			if (!$lockResult || !$this->db->num_rows($lockResult)) {
				$this->db->rollback();
				return -1;
			}
		}
		if ($resourceType === 'bookcal_calendar' && $assignment['element_type'] === 'action') {
			$sql = 'SELECT a.id FROM '.MAIN_DB_PREFIX.'actioncomm a';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bookcal_calendar bc ON bc.rowid = '.((int) $resourceId).' AND bc.entity = a.entity';
			$sql .= ' WHERE a.id = '.((int) $assignment['element_id']).' AND a.entity IN ('.getEntity('actioncomm').')';
			$resql = $this->db->query($sql);
			if (!$resql || !$this->db->num_rows($resql)) {
				$this->db->rollback();
				return -1;
			}
		}
		if ($resourceType === 'bookcal_calendar') {
			require_once DOL_DOCUMENT_ROOT.'/bookcal/class/bookcalavailabilityprovider.class.php';
			$dateStartTimestamp = $this->db->jdate($assignment['date_start']);
			$dateEndTimestamp = $this->db->jdate($assignment['date_end']);
			$excludeActionId = $assignment['element_type'] === 'action' ? (int) $assignment['element_id'] : 0;
			$provider = new BookCalAvailabilityProvider($this->db);
			if (!$dateStartTimestamp || !$dateEndTimestamp || !$provider->isAvailable($resourceId, $dateStartTimestamp, $dateEndTimestamp, $excludeActionId)) {
				$this->db->rollback();
				return -1;
			}
		}
		if (!$this->canReserve($resourceType, $resourceId, $assignment['date_start'], $assignment['date_end'], $capacity, $maximum, $resourceUnits, null, true)) {
			$this->db->rollback();
			return -1;
		}
		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'element_resources (';
		$sql .= 'element_id, element_type, resource_id, resource_type, busy, mandatory, position, relation_kind, resource_role,';
		$sql .= ' service_quantity, capacity_used, resource_units_used, cooldown_minutes_applied, date_start, date_end, reservation_status, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) $assignment['element_id']).", '".$this->db->escape($assignment['element_type'])."', ".((int) $resourceId).", '".$this->db->escape($resourceType)."', 1, ";
		$sql .= (!empty($assignment['mandatory']) ? 1 : 0).', '.((int) (!empty($assignment['position']) ? $assignment['position'] : 0));
		$sql .= ", 'assignment', '".$this->db->escape(!empty($assignment['resource_role']) ? $assignment['resource_role'] : 'capacity')."', ";
		$sql .= price2num(isset($assignment['service_quantity']) ? $assignment['service_quantity'] : 1, 'MS').', '.price2num($capacity, 'MS').', '.((int) $resourceUnits).', '.max(0, (int) (isset($assignment['cooldown_minutes_applied']) ? $assignment['cooldown_minutes_applied'] : 0)).', ';
		$sql .= "'".$this->db->escape($assignment['date_start'])."', '".$this->db->escape($assignment['date_end'])."', '";
		$sql .= $this->db->escape($reservationStatus)."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return -1;
		}
		$assignmentId = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'element_resources');
		if (!$this->db->commit()) {
			return -1;
		}
		return $assignmentId;
	}

	/**
	 * Remove assignments generated by a source element.
	 *
	 * @param string $elementType Source type
	 * @param int $elementId Source id
	 * @return int
	 */
	public function deleteAssignments($elementType, $elementId)
	{
		$sql = 'DELETE FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= " WHERE element_type = '".$this->db->escape($elementType)."' AND element_id = ".((int) $elementId);
		$sql .= " AND (relation_kind = 'assignment' OR reservation_status IS NOT NULL)";
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Preview reassignment of current and future reservations.
	 *
	 * @param int $resourceId Resource becoming unavailable
	 * @return array<int,array<string,mixed>>
	 */
	public function previewOutOfService($resourceId)
	{
		$this->hasAvailabilityReadError = false;
		$this->hasOperationReadError = false;
		$reservations = $this->fetchAffectedReservations($resourceId);
		if ($reservations === false) {
			return false;
		}
		$planned = array();
		foreach ($reservations as &$reservation) {
			$reservation['replacement'] = $this->findReplacement($reservation, $planned);
			if ($this->hasOperationReadError || $this->hasAvailabilityReadError) {
				unset($reservation);
				return false;
			}
			if (!empty($reservation['replacement']) && $reservation['reservation_status'] === 'confirmed') {
				$planned[] = array(
					'resource_id' => $reservation['replacement']['resource_id'],
					'capacity_used' => $reservation['replacement']['capacity_used'],
					'resource_units_used' => $reservation['replacement']['resource_units_used'],
					'date_start' => $reservation['replacement']['date_start'],
					'date_end' => $reservation['replacement']['date_end'],
				);
			}
		}
		unset($reservation);
		return $reservations;
	}

	/**
	 * Apply a previously previewed impact.
	 *
	 * @param int $resourceId Resource becoming unavailable
	 * @param array<int,array<string,mixed>> $impact Preview result
	 * @param User|null $actor User that marks the resource out of service
	 * @return int Number of processed reservations, -1 on error
	 */
	public function applyOutOfService($resourceId, array $impact, ?User $actor = null)
	{
		global $langs;
		$this->hasAvailabilityReadError = false;
		$this->hasOperationReadError = false;
		$langs->load('resource');
		if ($actor === null && isset($GLOBALS['user']) && $GLOBALS['user'] instanceof User) {
			$actor = $GLOBALS['user'];
		}
		if (!$this->db->begin()) {
			return -1;
		}
		if (!$this->lockResource((int) $resourceId)) {
			$this->db->rollback();
			return -1;
		}
		$lockedAssignmentIds = $this->lockAffectedAssignmentRows((int) $resourceId);
		if ($lockedAssignmentIds === false) {
			$this->db->rollback();
			return -1;
		}
		$reservations = $this->fetchAffectedReservations((int) $resourceId);
		if ($reservations === false || !$this->sameReservationIds($reservations, $lockedAssignmentIds)) {
			$this->db->rollback();
			return -1;
		}
		$lockedReplacementIds = $this->lockReplacementResources($reservations);
		if ($lockedReplacementIds === false) {
			$this->db->rollback();
			return -1;
		}
		$sql = 'SELECT ref FROM '.MAIN_DB_PREFIX.'resource WHERE rowid='.((int) $resourceId);
		$resql = $this->db->query($sql);
		$resource = $resql ? $this->db->fetch_object($resql) : null;
		if (!$resql || !$resource) {
			$this->db->rollback();
			return -1;
		}
		$resourceRef = $resource ? (string) $resource->ref : (string) $resourceId;
		$sql = 'UPDATE '.MAIN_DB_PREFIX.'resource SET fk_statut=2 WHERE rowid='.((int) $resourceId);
		$sql .= ' AND entity IN ('.getEntity('resource').')';
		if (!$this->db->query($sql)) {
			$this->db->rollback();
			return -1;
		}
		$planned = array();
		foreach ($reservations as $reservation) {
			$reservation = $this->refreshLockedReservationLedger($reservation, (int) $resourceId);
			if ($reservation === false) {
				$this->db->rollback();
				return -1;
			}
			$reservation['replacement'] = $this->findReplacement($reservation, $planned, true, $lockedReplacementIds);
			if ($this->hasOperationReadError || $this->hasAvailabilityReadError) {
				$this->db->rollback();
				return -1;
			}
			if (!empty($reservation['replacement'])) {
				$replacement = $reservation['replacement'];
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET';
				$sql .= ' resource_id = '.((int) $replacement['resource_id']).',';
				$sql .= ' position = '.((int) $replacement['position']).',';
				$sql .= ' users_per_service_unit = '.price2num($replacement['users_per_service_unit'], 'MS').',';
				$sql .= ' capacity_used = '.price2num($replacement['capacity_used'], 'MS').',';
				$sql .= ' resource_units_used = '.((int) $replacement['resource_units_used']).',';
				$sql .= ' cooldown_minutes_applied = '.((int) $replacement['cooldown_minutes_applied']).',';
				$sql .= ' load_volume_used = '.price2num($replacement['load_volume_used'], 'MS').',';
				$sql .= ' payload_weight_used = '.price2num($replacement['payload_weight_used'], 'MS').',';
				$sql .= " date_end = '".$this->db->escape($replacement['date_end'])."'";
				$sql .= ' WHERE rowid = '.((int) $reservation['rowid']);
				$sql .= ' AND resource_id = '.((int) $resourceId);
				$sql .= " AND resource_type = 'dolresource' AND relation_kind = 'assignment'";
			} else {
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources';
				$sql .= " SET reservation_status = 'unavailable'";
				$sql .= ' WHERE rowid = '.((int) $reservation['rowid']);
				$sql .= ' AND resource_id = '.((int) $resourceId);
				$sql .= " AND resource_type = 'dolresource' AND relation_kind = 'assignment'";
			}
			$resql = $this->db->query($sql);
			if (!$resql || $this->db->affected_rows($resql) !== 1) {
				$this->db->rollback();
				return -1;
			}
			if (!empty($reservation['replacement']) && $reservation['reservation_status'] === self::STATUS_CONFIRMED) {
				$planned[] = array(
					'resource_id' => $reservation['replacement']['resource_id'],
					'capacity_used' => $reservation['replacement']['capacity_used'],
					'resource_units_used' => $reservation['replacement']['resource_units_used'],
					'date_start' => $reservation['replacement']['date_start'],
					'date_end' => $reservation['replacement']['date_end'],
				);
			}
			if ($actor instanceof User && $this->createOutOfServiceAlert($resourceRef, $reservation, $actor, $langs) < 0) {
				$this->db->rollback();
				return -1;
			}
		}
		if (!$this->db->commit()) {
			return -1;
		}
		return count($reservations);
	}

	/**
	 * Reassign one reservation when its current assignment fails.
	 *
	 * @param int    $assignmentId Assignment id
	 * @return int<-2,1> 1 applied, -1 on error, -2 if assignment is not affected
	 */
	public function replaceFailedAssignment($assignmentId)
	{
		$this->hasAvailabilityReadError = false;
		$this->hasOperationReadError = false;
		if (!$this->db->begin()) {
			return -1;
		}
		$sql = 'SELECT resource_id FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE rowid='.((int) $assignmentId)." AND resource_type='dolresource' AND relation_kind='assignment'";
		$resql = $this->db->query($sql);
		$assignment = $resql ? $this->db->fetch_object($resql) : null;
		if (!$resql || !$assignment || !$this->lockResource((int) $assignment->resource_id)) {
			$this->db->rollback();
			return $resql && !$assignment ? -2 : -1;
		}
		$lockSuffix = in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
		$sql = 'SELECT rowid, resource_id FROM '.MAIN_DB_PREFIX.'element_resources WHERE rowid='.((int) $assignmentId).$lockSuffix;
		$lockedAssignment = $this->db->query($sql);
		$currentAssignment = $lockedAssignment ? $this->db->fetch_object($lockedAssignment) : null;
		if (!$lockedAssignment || !$currentAssignment) {
			$this->db->rollback();
			return -2;
		}
		if ((int) $currentAssignment->resource_id !== (int) $assignment->resource_id) {
			$this->db->rollback();
			return -1;
		}
		$lockedAssignmentIds = $this->lockAffectedAssignmentRows((int) $assignment->resource_id);
		if ($lockedAssignmentIds === false || !in_array((int) $assignmentId, $lockedAssignmentIds, true)) {
			$this->db->rollback();
			return $lockedAssignmentIds === false ? -1 : -2;
		}
		$reservations = $this->fetchAffectedReservations((int) $assignment->resource_id);
		if ($reservations === false || !$this->sameReservationIds($reservations, $lockedAssignmentIds)) {
			$this->db->rollback();
			return -1;
		}
		$affected = null;
		foreach ($reservations as $reservation) {
			if ((int) $reservation['rowid'] === (int) $assignmentId) {
				$affected = $reservation;
				break;
			}
		}
		if ($affected === null) {
			$this->db->rollback();
			return -2;
		}
		$affected = $this->refreshLockedReservationLedger($affected, (int) $assignment->resource_id);
		if ($affected === false) {
			$this->db->rollback();
			return -1;
		}
		$lockedReplacementIds = $this->lockReplacementResources(array($affected));
		if ($lockedReplacementIds === false) {
			$this->db->rollback();
			return -1;
		}
		$affected['replacement'] = $this->findReplacement($affected, array(), true, $lockedReplacementIds);
		if ($this->hasOperationReadError || $this->hasAvailabilityReadError) {
			$this->db->rollback();
			return -1;
		}
		if (!empty($affected['replacement'])) {
			$replacement = $affected['replacement'];
			$sql = 'UPDATE '.MAIN_DB_PREFIX.'element_resources SET resource_id='.((int) $replacement['resource_id']);
			$sql .= ', position='.((int) $replacement['position']);
			$sql .= ', users_per_service_unit='.price2num($replacement['users_per_service_unit'], 'MS');
			$sql .= ', capacity_used='.price2num($replacement['capacity_used'], 'MS');
			$sql .= ', resource_units_used='.((int) $replacement['resource_units_used']);
			$sql .= ', cooldown_minutes_applied='.((int) $replacement['cooldown_minutes_applied']);
			$sql .= ', load_volume_used='.price2num($replacement['load_volume_used'], 'MS');
			$sql .= ', payload_weight_used='.price2num($replacement['payload_weight_used'], 'MS');
			$sql .= ", date_end='".$this->db->escape($replacement['date_end'])."'";
			$sql .= ' WHERE rowid='.((int) $assignmentId).' AND resource_id='.((int) $assignment->resource_id);
		} else {
			$sql = 'UPDATE '.MAIN_DB_PREFIX."element_resources SET reservation_status='unavailable'";
			$sql .= ' WHERE rowid='.((int) $assignmentId).' AND resource_id='.((int) $assignment->resource_id);
		}
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) !== 1) {
			$this->db->rollback();
			return -1;
		}
		return $this->db->commit() ? 1 : -1;
	}

	/**
	 * Lock one resource row before reading or changing its assignments.
	 *
	 * @param int $resourceId Resource id
	 * @return bool
	 */
	private function lockResource($resourceId)
	{
		$lockSuffix = in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'resource';
		$sql .= ' WHERE rowid='.((int) $resourceId).' AND entity IN ('.getEntity('resource').')'.$lockSuffix;
		$resql = $this->db->query($sql);
		return $resql && $this->db->num_rows($resql) === 1;
	}

	/**
	 * Lock active assignment rows of a resource using a current read.
	 *
	 * @param int $resourceId Resource id
	 * @return array<int,int>|false Locked assignment ids, false on error
	 */
	private function lockAffectedAssignmentRows($resourceId)
	{
		$lockSuffix = in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
		$now = $this->db->idate(dol_now());
		$sql = 'SELECT er.rowid FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' WHERE er.resource_id='.((int) $resourceId);
		$sql .= " AND er.resource_type='dolresource' AND er.relation_kind='assignment'";
		$sql .= " AND (er.date_end IS NULL OR er.date_end >= '".$this->db->escape($now)."')";
		$sql .= ' AND (';
		$sql .= "(er.element_type='propaldet' AND er.reservation_status NOT IN ('canceled','unavailable')";
		$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'propaldet pd INNER JOIN '.MAIN_DB_PREFIX.'propal p ON p.rowid=pd.fk_propal';
		$sql .= ' WHERE pd.rowid=er.element_id AND p.entity IN ('.getEntity('propal').')))';
		$sql .= " OR (er.element_type='commandedet' AND er.reservation_status='confirmed'";
		$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'commandedet od INNER JOIN '.MAIN_DB_PREFIX.'commande o ON o.rowid=od.fk_commande';
		$sql .= ' WHERE od.rowid=er.element_id AND o.entity IN ('.getEntity('commande').')))';
		$sql .= " OR (er.element_type='contratdet' AND er.reservation_status='confirmed'";
		$sql .= ' AND EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'contratdet cd INNER JOIN '.MAIN_DB_PREFIX.'contrat c ON c.rowid=cd.fk_contrat';
		$sql .= ' WHERE cd.rowid=er.element_id AND c.entity IN ('.getEntity('contract').')))';
		$sql .= ') ORDER BY er.rowid'.$lockSuffix;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$ids = array();
		while ($row = $this->db->fetch_object($resql)) {
			$ids[] = (int) $row->rowid;
		}
		return $ids;
	}

	/**
	 * Lock all alternatives that may receive the supplied reservations.
	 *
	 * @param array<int,array<string,mixed>> $reservations Reservations being moved
	 * @return array<int,int>|false Locked resource ids, false on error
	 */
	private function lockReplacementResources(array $reservations)
	{
		$resourceIds = array();
		foreach ($reservations as $reservation) {
			$candidates = $this->loadReplacementCandidates($reservation);
			if ($candidates === false) {
				return false;
			}
			foreach ($candidates as $candidate) {
				$resourceIds[] = (int) $candidate->resource_id;
			}
		}
		$resourceIds = array_values(array_unique(array_filter($resourceIds)));
		if (empty($resourceIds)) {
			return array();
		}
		sort($resourceIds, SORT_NUMERIC);
		$lockSuffix = in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'resource';
		$sql .= ' WHERE rowid IN ('.$this->db->sanitize(implode(',', $resourceIds)).')';
		$sql .= ' AND entity IN ('.getEntity('resource').') ORDER BY rowid'.$lockSuffix;
		$resql = $this->db->query($sql);
		if (!$resql) {
			return false;
		}
		$lockedIds = array();
		while ($resource = $this->db->fetch_object($resql)) {
			$lockedIds[] = (int) $resource->rowid;
		}
		return $lockedIds === $resourceIds ? $lockedIds : false;
	}

	/**
	 * Verify that a current locking read and the enriched document query describe
	 * the exact same assignment set.
	 *
	 * @param array<int,array<string,mixed>> $reservations Enriched reservations
	 * @param array<int,int>                 $lockedIds    Current locked ids
	 * @return bool
	 */
	private function sameReservationIds(array $reservations, array $lockedIds)
	{
		$reservationIds = array_map(static function ($reservation) {
			return (int) $reservation['rowid'];
		}, $reservations);
		sort($reservationIds, SORT_NUMERIC);
		sort($lockedIds, SORT_NUMERIC);
		return $reservationIds === $lockedIds;
	}

	/**
	 * Refresh ledger fields with a current locking read after the resource lock.
	 * Document metadata remains available for matching and alert generation.
	 *
	 * @param array<string,mixed> $reservation Enriched reservation
	 * @param int                 $resourceId  Expected current resource
	 * @return array<string,mixed>|false
	 */
	private function refreshLockedReservationLedger(array $reservation, $resourceId)
	{
		$lockSuffix = in_array($this->db->type, array('sqlite', 'sqlite3'), true) ? '' : ' FOR UPDATE';
		$sql = 'SELECT er.* FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' WHERE er.rowid='.((int) $reservation['rowid']).' AND er.resource_id='.((int) $resourceId);
		$sql .= " AND er.resource_type='dolresource' AND er.relation_kind='assignment'".$lockSuffix;
		$resql = $this->db->query($sql);
		$current = $resql ? $this->db->fetch_array($resql) : false;
		if (!$resql || !$current) {
			return false;
		}
		foreach ($current as $key => $value) {
			if (is_string($key)) {
				$reservation[$key] = $value;
			}
		}
		return $reservation;
	}

	/**
	 * @param int $resourceId Resource id
	 * @return array<int,array<string,mixed>>|false
	 */
	private function fetchAffectedReservations($resourceId)
	{
		$supplierJoin = ' LEFT JOIN (SELECT fk_product, MIN(fk_soc) as fk_soc FROM '.MAIN_DB_PREFIX.'product_fournisseur_price';
		$supplierJoin .= ' WHERE entity IN ('.getEntity('productprice').') GROUP BY fk_product) rsp ON rsp.fk_product=prod.rowid';
		$supplierJoin .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe supplier ON supplier.rowid=rsp.fk_soc';
		$sql = "SELECT er.*, pd.fk_product, p.rowid as document_id, p.ref as document_ref, prod.ref as service_ref,";
		$sql .= " rsp.fk_soc as supplier_id, supplier.nom as supplier_name, 'propal' as document_type";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."propaldet pd ON pd.rowid=er.element_id AND er.element_type='propaldet'";
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'propal p ON p.rowid=pd.fk_propal';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product prod ON prod.rowid=pd.fk_product';
		$sql .= $supplierJoin; // Fragment only contains fixed table names and an entity filter. @phan-suppress-current-line SqlInjection
		$sql .= ' WHERE er.resource_id='.((int) $resourceId)." AND er.reservation_status NOT IN ('canceled','unavailable')";
		$sql .= " AND er.resource_type='dolresource' AND er.relation_kind='assignment'";
		$sql .= ' AND p.entity IN ('.getEntity('propal').')';
		$sql .= " AND (er.date_end IS NULL OR er.date_end >= '".$this->db->idate(dol_now())."')";
		$sql .= ' UNION ALL ';
		$sql .= "SELECT er.*, od.fk_product, o.rowid as document_id, o.ref as document_ref, prod.ref as service_ref,";
		$sql .= " rsp.fk_soc as supplier_id, supplier.nom as supplier_name, 'order' as document_type";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commandedet od ON od.rowid=er.element_id AND er.element_type='commandedet'";
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'commande o ON o.rowid=od.fk_commande';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product prod ON prod.rowid=od.fk_product';
		$sql .= $supplierJoin; // Fragment only contains fixed table names and an entity filter. @phan-suppress-current-line SqlInjection
		$sql .= ' WHERE er.resource_id='.((int) $resourceId)." AND er.reservation_status='confirmed'";
		$sql .= " AND er.resource_type='dolresource' AND er.relation_kind='assignment'";
		$sql .= ' AND o.entity IN ('.getEntity('commande').')';
		$sql .= " AND (er.date_end IS NULL OR er.date_end >= '".$this->db->idate(dol_now())."')";
		$sql .= ' UNION ALL ';
		$sql .= "SELECT er.*, cd.fk_product, c.rowid as document_id, c.ref as document_ref, prod.ref as service_ref,";
		$sql .= " rsp.fk_soc as supplier_id, supplier.nom as supplier_name, 'contract' as document_type";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."contratdet cd ON cd.rowid=er.element_id AND er.element_type='contratdet'";
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'contrat c ON c.rowid=cd.fk_contrat';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product prod ON prod.rowid=cd.fk_product';
		$sql .= $supplierJoin; // Fragment only contains fixed table names and an entity filter. @phan-suppress-current-line SqlInjection
		$sql .= ' WHERE er.resource_id='.((int) $resourceId)." AND er.reservation_status='confirmed'";
		$sql .= " AND er.resource_type='dolresource' AND er.relation_kind='assignment'";
		$sql .= ' AND c.entity IN ('.getEntity('contract').')';
		$sql .= " AND (er.date_end IS NULL OR er.date_end >= '".$this->db->idate(dol_now())."')";
		$sql .= ' ORDER BY date_start, rowid';
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return false;
		}
		$result = array();
		while ($row = $this->db->fetch_array($resql)) {
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * Create an agenda alert describing one reservation affected by an outage.
	 *
	 * @param string               $resourceRef Resource reference
	 * @param array<string,mixed>  $reservation Affected reservation
	 * @param User                 $user        Acting user
	 * @param Translate            $langs       Translation handler
	 * @return int Event id, negative on error
	 */
	private function createOutOfServiceAlert($resourceRef, array $reservation, User $user, Translate $langs)
	{
		require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
		$replacementRef = !empty($reservation['replacement']['ref']) ? (string) $reservation['replacement']['ref'] : '';
		$action = new ActionComm($this->db);
		$action->type_code = 'AC_OTH_AUTO';
		$action->code = 'AC_RESOURCE_OUT_OF_SERVICE';
		$action->label = $langs->trans('ResourceOutOfServiceAlert', $resourceRef, $reservation['document_ref']);
		$action->datep = dol_now();
		$action->datef = $action->datep;
		$action->percentage = -1;
		$action->socid = !empty($reservation['supplier_id']) ? (int) $reservation['supplier_id'] : 0;
		$action->authorid = $user->id;
		$action->userownerid = $user->id;
		$action->fk_element = (int) $reservation['document_id'];
		$action->elementid = (int) $reservation['document_id'];
		$action->elementtype = (string) $reservation['document_type'];
		$action->note_private = $this->formatLongTranslation(
			$langs,
			'ResourceOutOfServiceAlertDetail',
			array(
				$resourceRef,
				$reservation['service_ref'],
				$reservation['document_ref'],
				$reservation['date_start'],
				$reservation['date_end'],
				!empty($reservation['supplier_name']) ? $reservation['supplier_name'] : '-',
				$replacementRef !== '' ? $replacementRef : '-',
			)
		);
		return $action->create($user);
	}

	/**
	 * Format translations that need more parameters than Translate::trans supports.
	 *
	 * @param Translate           $langs      Translation handler
	 * @param string              $key        Translation key
	 * @param array<int,mixed>    $parameters Replacement parameters
	 * @return string
	 */
	private function formatLongTranslation(Translate $langs, $key, array $parameters)
	{
		$format = !empty($langs->tab_translate[$key]) ? $langs->tab_translate[$key] : $key;
		// Translation formats are loaded dynamically from trusted language files.
		// @phan-suppress-next-line PhanPluginPrintfVariableFormatString
		return vsprintf($format, $parameters);
	}

	/**
	 * @param array<string,mixed>              $reservation Reservation to replace
	 * @param array<int,array<string,mixed>>   $planned     Planned replacements
	 * @param bool                             $locking          Use a current locking read
	 * @param array<int,int>|null              $lockedResourceIds Exact resource lock snapshot
	 * @return array<string,mixed>|null
	 */
	private function findReplacement(array $reservation, array $planned, $locking = false, ?array $lockedResourceIds = null)
	{
		// A NULL group means an independent requirement, not an alternative set.
		// Older assignments do not store a source requirement id, so substituting
		// them from another NULL row could silently remove a separate obligation.
		if (empty($reservation['requirement_group'])) {
			return null;
		}
		$candidates = $this->loadReplacementCandidates($reservation, $locking);
		if ($candidates === false) {
			$this->hasOperationReadError = true;
			return null;
		}
		if ($locking && $lockedResourceIds !== null) {
			foreach ($candidates as $candidate) {
				if (!in_array((int) $candidate->resource_id, $lockedResourceIds, true)) {
					$this->hasOperationReadError = true;
					return null;
				}
			}
		}
		foreach ($candidates as $candidate) {
			$replacement = $this->buildReplacement($candidate, $reservation);
			if ($replacement === null) {
				continue;
			}
			if ($reservation['reservation_status'] !== self::STATUS_CONFIRMED
				|| $this->candidateHasCapacity($candidate, $replacement, $reservation, $planned)) {
				return $replacement;
			}
		}
		return null;
	}

	/**
	 * Load semantically equivalent alternatives for one assignment.
	 *
	 * @param array<string,mixed> $reservation Reservation to replace
	 * @param bool                $locking     Use a current locking read
	 * @return array<int,object>|false
	 */
	private function loadReplacementCandidates(array $reservation, $locking = false)
	{
		if (empty($reservation['requirement_group'])) {
			return array();
		}
		$sql = 'SELECT er.rowid as requirement_rowid, er.resource_id, er.position, er.users_per_service_unit, er.quantity_required, er.allow_split, er.required_location,';
		$sql .= ' er.capacity_metrics, r.ref, r.max_users, r.metric_value, r.available_units, r.max_payload_weight, r.cooldown_minutes,';
		$sql .= ' r.operational_location, ty.capacity_mode';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'resource r ON r.rowid=er.resource_id';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'c_type_resource ty ON ty.code=r.fk_code_type_resource';
		$sql .= " WHERE er.element_type IN ('product', 'service') AND er.resource_type='dolresource'";
		$sql .= " AND (er.relation_kind IS NULL OR er.relation_kind='requirement')";
		$sql .= ' AND er.element_id='.((int) $reservation['fk_product']);
		$sql .= ' AND er.resource_id <> '.((int) $reservation['resource_id']);
		$sql .= " AND er.resource_role = '".$this->db->escape(!empty($reservation['resource_role']) ? $reservation['resource_role'] : 'capacity')."'";
		$sql .= " AND er.requirement_group = '".$this->db->escape($reservation['requirement_group'])."'";
		$sql .= ' AND r.entity IN ('.getEntity('resource').')';
		$sql .= ' AND r.fk_statut=1';
		$sql .= $locking ? ' ORDER BY r.rowid' : ' ORDER BY er.position,er.rowid';
		if ($locking && !in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			$this->hasOperationReadError = true;
			return false;
		}
		$candidates = array();
		$seen = array();
		while ($candidate = $this->db->fetch_object($resql)) {
			if (!isset($seen[(int) $candidate->resource_id])) {
				$seen[(int) $candidate->resource_id] = true;
				$candidates[] = $candidate;
			}
		}
		usort($candidates, static function ($left, $right) {
			$positionComparison = (int) $left->position <=> (int) $right->position;
			return $positionComparison !== 0 ? $positionComparison : ((int) $left->requirement_rowid <=> (int) $right->requirement_rowid);
		});
		return $candidates;
	}

	/**
	 * Calculate the consumption that an equivalent candidate would receive.
	 *
	 * @param object              $candidate   Candidate requirement/resource row
	 * @param array<string,mixed> $reservation Existing assignment
	 * @return array<string,mixed>|null
	 */
	private function buildReplacement($candidate, array $reservation)
	{
		$capacityPerUnit = $this->getResourceMaximumCapacity($candidate);
		$availableUnits = max(1, (int) $candidate->available_units);
		$serviceQuantity = abs((float) $reservation['service_quantity']);
		if ($serviceQuantity <= 0) {
			$serviceQuantity = 1.0;
		}
		$requiredUnits = max(1, (int) ceil($serviceQuantity * max(0.0, (float) $candidate->quantity_required)));
		$loadVolume = max(0.0, (float) $reservation['load_volume_used']);
		$payloadWeight = max(0.0, (float) $reservation['payload_weight_used']);
		if ($candidate->capacity_mode === 'volume') {
			if ($capacityPerUnit <= 0) {
				return null;
			}
			$requiredUnits = max($requiredUnits, (int) ceil($loadVolume / $capacityPerUnit));
		}
		if ($payloadWeight > 0) {
			if ((float) $candidate->max_payload_weight <= 0) {
				return null;
			}
			$requiredUnits = max($requiredUnits, (int) ceil($payloadWeight / (float) $candidate->max_payload_weight));
		}
		if ($requiredUnits > $availableUnits || ($requiredUnits > 1 && empty($candidate->allow_split))) {
			return null;
		}
		if (!empty($candidate->required_location)
			&& strcasecmp(trim((string) $candidate->required_location), trim((string) $candidate->operational_location)) !== 0) {
			return null;
		}
		$capacityUsed = $candidate->capacity_mode === 'volume'
			? $capacityPerUnit * $requiredUnits
			: $serviceQuantity * (float) $candidate->users_per_service_unit;
		if ($capacityUsed <= 0) {
			$capacityUsed = max(1.0, (float) $reservation['capacity_used']);
		}
		if ($candidate->capacity_mode !== 'volume' && $capacityPerUnit > 0) {
			$requiredUnits = max($requiredUnits, (int) ceil($capacityUsed / $capacityPerUnit));
			if ($requiredUnits > $availableUnits || ($requiredUnits > 1 && empty($candidate->allow_split))) {
				return null;
			}
		}
		$dateStart = $reservation['date_start'];
		$dateEndTimestamp = $this->db->jdate($reservation['date_end']);
		if (empty($dateStart) || !$dateEndTimestamp) {
			return null;
		}
		$baseEndTimestamp = $dateEndTimestamp - (max(0, (int) $reservation['cooldown_minutes_applied']) * 60);
		$startTimestamp = $this->db->jdate($dateStart);
		if (!$startTimestamp || $baseEndTimestamp <= $startTimestamp) {
			return null;
		}
		$replacementCooldown = max(0, (int) $candidate->cooldown_minutes);
		$dateEnd = $this->db->idate($baseEndTimestamp + ($replacementCooldown * 60));
		return array(
			'resource_id' => (int) $candidate->resource_id,
			'ref' => $candidate->ref,
			'position' => (int) $candidate->position,
			'users_per_service_unit' => (float) $candidate->users_per_service_unit,
			'capacity_used' => $capacityUsed,
			'resource_units_used' => $requiredUnits,
			'cooldown_minutes_applied' => $replacementCooldown,
			'load_volume_used' => $loadVolume,
			'payload_weight_used' => $payloadWeight,
			'date_start' => $dateStart,
			'date_end' => $dateEnd,
		);
	}

	/**
	 * @param object                          $candidate   Candidate resource
	 * @param array<string,mixed>              $replacement Candidate consumption
	 * @param array<string,mixed>              $reservation Reservation data
	 * @param array<int,array<string,mixed>>   $planned     Planned replacements
	 * @return bool
	 */
	private function candidateHasCapacity($candidate, array $replacement, array $reservation, array $planned)
	{
		$availableUnits = max(1, (int) $candidate->available_units);
		$maximumCapacity = $this->getResourceMaximumCapacity($candidate) * $availableUnits;
		$required = (float) $replacement['capacity_used'];
		$requiredUnits = max(1, (int) $replacement['resource_units_used']);
		$dateStart = $replacement['date_start'];
		$dateEnd = $replacement['date_end'];
		if ($maximumCapacity <= 0 || $required > $maximumCapacity || $requiredUnits > $availableUnits
			|| empty($dateStart) || empty($dateEnd)) {
			return false;
		}
		if (!$this->isAvailableInterval('dolresource', (int) $candidate->resource_id, $dateStart, $dateEnd)) {
			return false;
		}
		$sql = 'SELECT rowid, capacity_used, resource_units_used FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id='.((int) $candidate->resource_id)." AND resource_type='dolresource'";
		$sql .= " AND reservation_status='confirmed' AND (relation_kind='assignment' OR relation_kind IS NULL)";
		$sql .= " AND (date_end IS NULL OR date_end > '".$this->db->escape($dateStart)."')";
		$sql .= " AND (date_start IS NULL OR date_start < '".$this->db->escape($dateEnd)."')";
		$sql .= ' ORDER BY rowid';
		if (!in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			$this->hasOperationReadError = true;
			return false;
		}
		$occupied = 0.0;
		$occupiedUnits = 0;
		while ($assignment = $this->db->fetch_object($resql)) {
			$occupied += $assignment->capacity_used === null ? 1.0 : (float) $assignment->capacity_used;
			$occupiedUnits += $assignment->resource_units_used === null ? 1 : max(1, (int) $assignment->resource_units_used);
		}
		$legacyConflicts = $this->loadLegacyActionConflicts('dolresource', array((int) $candidate->resource_id), $dateStart, $dateEnd, true);
		if ($legacyConflicts === false) {
			$this->hasOperationReadError = true;
			return false;
		}
		if (!empty($legacyConflicts[(int) $candidate->resource_id])) {
			return false;
		}
		$replacementInterval = array('date_start' => $dateStart, 'date_end' => $dateEnd);
		foreach ($planned as $item) {
			if ((int) $item['resource_id'] === (int) $candidate->resource_id && $this->overlaps($replacementInterval, $item)) {
				$occupied += (float) $item['capacity_used'];
				$occupiedUnits += max(1, (int) $item['resource_units_used']);
			}
		}
		return ($occupied + $required) <= $maximumCapacity && ($occupiedUnits + $requiredUnits) <= $availableUnits;
	}

	/**
	 * @param array<string,mixed> $left  First interval
	 * @param array<string,mixed> $right Second interval
	 * @return bool
	 */
	private function overlaps(array $left, array $right)
	{
		return (empty($left['date_end']) || empty($right['date_start']) || $right['date_start'] < $left['date_end'])
			&& (empty($right['date_end']) || empty($left['date_start']) || $right['date_end'] > $left['date_start']);
	}

	/**
	 * Check that reducing a resource pool keeps every confirmed future interval valid.
	 *
	 * The rows are read with a current lock so an API/import update follows the same
	 * concurrency guarantees as the interactive allocator.
	 *
	 * @param int   $resourceId       Resource id
	 * @param float $capacityPerUnit  New capacity of one homogeneous unit
	 * @param int        $availableUnits    New number of homogeneous units
	 * @param float|null $payloadPerUnit    New payload limit per homogeneous unit, null to skip payload validation
	 * @return bool
	 */
	public function canResizeResource($resourceId, $capacityPerUnit, $availableUnits, $payloadPerUnit = null)
	{
		$availableUnits = max(1, (int) $availableUnits);
		$maximumCapacity = max(0.0, (float) $capacityPerUnit) * $availableUnits;
		$payloadPerUnit = $payloadPerUnit === null ? null : max(0.0, (float) $payloadPerUnit);
		$now = $this->db->idate(dol_now());
		$sql = 'SELECT rowid, date_start, date_end, capacity_used, resource_units_used, load_volume_used, payload_weight_used';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources';
		$sql .= ' WHERE resource_id = '.((int) $resourceId)." AND resource_type = 'dolresource'";
		$sql .= " AND reservation_status = 'confirmed'";
		$sql .= " AND (relation_kind = 'assignment' OR relation_kind IS NULL)";
		$sql .= " AND (date_end IS NULL OR date_end > '".$this->db->escape($now)."')";
		$sql .= ' ORDER BY rowid';
		if (!in_array($this->db->type, array('sqlite', 'sqlite3'), true)) {
			$sql .= ' FOR UPDATE';
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog(__METHOD__.': '.$this->db->lasterror(), LOG_ERR);
			return false;
		}

		$events = array();
		while ($assignment = $this->db->fetch_object($resql)) {
			$start = empty($assignment->date_start) || $assignment->date_start < $now ? $now : $assignment->date_start;
			$end = $assignment->date_end;
			if (!empty($end) && $end <= $start) {
				continue;
			}
			$capacity = $assignment->capacity_used === null ? 1.0 : (float) $assignment->capacity_used;
			$units = $assignment->resource_units_used === null ? 1 : max(1, (int) $assignment->resource_units_used);
			$requiredCapacity = $assignment->load_volume_used !== null && (float) $assignment->load_volume_used > 0
				? (float) $assignment->load_volume_used
				: $capacity;
			if ($requiredCapacity > ((max(0.0, (float) $capacityPerUnit) * $units) + 0.000001)) {
				return false;
			}
			$requiredPayload = $assignment->payload_weight_used === null ? 0.0 : (float) $assignment->payload_weight_used;
			if ($payloadPerUnit !== null && $requiredPayload > (($payloadPerUnit * $units) + 0.000001)) {
				return false;
			}
			if (!isset($events[$start])) {
				$events[$start] = array('start_capacity' => 0.0, 'start_units' => 0, 'end_capacity' => 0.0, 'end_units' => 0);
			}
			$events[$start]['start_capacity'] += $capacity;
			$events[$start]['start_units'] += $units;
			if (!empty($end)) {
				if (!isset($events[$end])) {
					$events[$end] = array('start_capacity' => 0.0, 'start_units' => 0, 'end_capacity' => 0.0, 'end_units' => 0);
				}
				$events[$end]['end_capacity'] += $capacity;
				$events[$end]['end_units'] += $units;
			}
		}

		ksort($events);
		$occupiedCapacity = 0.0;
		$occupiedUnits = 0;
		foreach ($events as $event) {
			// Intervals are half-open: releases at T happen before allocations at T.
			$occupiedCapacity -= $event['end_capacity'];
			$occupiedUnits -= $event['end_units'];
			$occupiedCapacity += $event['start_capacity'];
			$occupiedUnits += $event['start_units'];
			if ($occupiedCapacity > ($maximumCapacity + 0.000001) || $occupiedUnits > $availableUnits) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param object $resource Resource capacity fields
	 * @return float
	 */
	public function getResourceMaximumCapacity($resource)
	{
		if ($resource->capacity_mode === 'users') {
			return (float) $resource->max_users;
		}
		if (in_array($resource->capacity_mode, array('custom', 'volume'), true)) {
			return (float) $resource->metric_value;
		}
		return 1.0;
	}
}
