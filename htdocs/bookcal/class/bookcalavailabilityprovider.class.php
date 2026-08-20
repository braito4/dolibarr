<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file bookcal/class/bookcalavailabilityprovider.class.php
 * \ingroup bookcal
 * \brief Adapt BookCal opening ranges to the common resource reservation engine.
 */

require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcereservationmanager.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcetimemaskprovider.class.php';

/**
 * BookCal availability provider.
 */
class BookCalAvailabilityProvider
{
	/** @var DoliDB */
	private $db;
	/** @var ResourceReservationManager */
	private $manager;
	/** @var ResourceTimeMaskProvider */
	private $maskProvider;
	/** @var array<int,object|false> */
	private $calendarCache = array();
	/** @var array<int,?bool> */
	private $timeMaskCache = array();
	/** @var bool */
	private $hasDatabaseError = false;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
		$this->manager = new ResourceReservationManager($db);
		$this->maskProvider = new ResourceTimeMaskProvider($db);
	}

	/**
	 * Return sellable services explicitly linked to a BookCal calendar.
	 *
	 * @param int $calendarId BookCal calendar id
	 * @return array<int,array<string,mixed>> Services indexed by product id
	 */
	public function getServices($calendarId)
	{
		$services = array();
		if ($this->hasDatabaseError || !$this->isCalendarOpen($calendarId)) {
			return $services;
		}
		$sql = 'SELECT DISTINCT p.rowid, p.ref, p.label, p.description, p.price, p.tva_tx';
		$sql .= ' FROM '.$this->db->prefix().'element_resources er';
		$sql .= ' INNER JOIN '.$this->db->prefix().'product p ON p.rowid = er.element_id';
		$sql .= ' INNER JOIN '.$this->db->prefix().'bookcal_calendar bc ON bc.rowid = er.resource_id';
		$sql .= " WHERE er.element_type IN ('product', 'service')";
		$sql .= " AND er.resource_type = 'bookcal_calendar'";
		$sql .= ' AND er.resource_id = '.((int) $calendarId);
		$sql .= ' AND bc.status = 1';
		$sql .= ' AND bc.entity IN ('.getEntity('calendar', 0).')';
		$sql .= ' AND p.entity IN ('.getEntity('product').')';
		$sql .= " AND (er.relation_kind = 'requirement' OR er.relation_kind IS NULL)";
		$sql .= ' AND p.fk_product_type = 1 AND p.tosell = 1';
		$sql .= ' ORDER BY p.ref, p.label';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return $services;
		}
		while ($service = $this->db->fetch_object($resql)) {
			$services[(int) $service->rowid] = array(
				'id' => (int) $service->rowid,
				'ref' => (string) $service->ref,
				'label' => (string) $service->label,
				'description' => (string) $service->description,
				'price' => (float) $service->price,
				'tva_tx' => (float) $service->tva_tx,
			);
		}
		return $services;
	}

	/**
	 * Check that a service can be bought for this calendar.
	 *
	 * @param int $calendarId BookCal calendar id
	 * @param int $serviceId  Service product id
	 * @return bool
	 */
	public function isServiceLinked($calendarId, $serviceId)
	{
		$services = $this->getServices($calendarId);
		return isset($services[(int) $serviceId]);
	}

	/**
	 * Return the slots for one local calendar day.
	 *
	 * @param int $calendarId BookCal calendar id
	 * @param int $dayStart Day timestamp
	 * @return array<string,int> Duration by HH:MM, negative when occupied
	 */
	public function getSlots($calendarId, $dayStart)
	{
		$slots = array();
		$openingRanges = array();
		if ($this->hasDatabaseError || !$this->isCalendarOpen($calendarId)) {
			return $slots;
		}
		$dayParts = dol_getdate($dayStart);
		$dayKey = sprintf('%04d-%02d-%02d', $dayParts['year'], $dayParts['mon'], $dayParts['mday']);
		$timezone = new DateTimeZone($this->getTimezone($calendarId));
		$targetDay = new DateTimeImmutable($dayKey.' 00:00:00', $timezone);
		$windowStart = $targetDay->getTimestamp();
		$windowEnd = $targetDay->modify('+1 day')->getTimestamp();
		$hasTimeMask = $this->getTimeMaskState($calendarId);
		if ($hasTimeMask === null) {
			return $slots;
		}
		if ($hasTimeMask) {
			$openingRanges = $this->maskProvider->getRangesCoveringLocalDay('bookcal_calendar', $calendarId, $dayKey, $timezone->getName());
			if ($this->maskProvider->hasDatabaseError()) {
				$this->registerDatabaseError(__METHOD__);
				return $slots;
			}
		} else {
			$sql = 'SELECT ba.duration, ba.startHour, ba.endHour, ba.start, ba.end';
			$sql .= ' FROM '.$this->db->prefix().'bookcal_availabilities ba';
			$sql .= ' INNER JOIN '.$this->db->prefix().'bookcal_calendar bc ON bc.rowid = ba.fk_bookcal_calendar';
			$sql .= ' WHERE ba.fk_bookcal_calendar = '.((int) $calendarId).' AND ba.status = 1 AND bc.status = 1';
			$sql .= ' AND bc.entity IN ('.getEntity('calendar', 0).')';
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->registerDatabaseError(__METHOD__);
				return $slots;
			}
			while ($range = $this->db->fetch_object($resql)) {
				foreach ($this->getLegacyRangesCoveringLocalDay($range, $dayKey, $timezone) as $openingRange) {
					$openingRanges[] = $openingRange;
				}
			}
		}
		$occupancyEnd = $windowEnd;
		foreach ($openingRanges as $openingRange) {
			$occupancyEnd = max($occupancyEnd, (int) $openingRange['end']);
		}
		$occupiedIntervals = $this->loadOccupiedIntervals($calendarId, $windowStart, $occupancyEnd);
		if ($occupiedIntervals === false) {
			return array();
		}
		foreach ($openingRanges as $openingRange) {
			$this->appendRangeSlots(
				$slots,
				$openingRange['start'],
				$openingRange['end'],
				$openingRange['slot_duration'],
				$timezone,
				$occupiedIntervals,
				$windowStart,
				$windowEnd
			);
		}
		ksort($slots);
		return $slots;
	}

	/**
	 * Recheck a BookCal slot on the server before insertion.
	 *
	 * @param int $calendarId BookCal calendar id
	 * @param int $dateStart Start timestamp
	 * @param int $dateEnd End timestamp
	 * @param int $excludeActionId Action id to ignore when revalidating an existing booking
	 * @return bool
	 */
	public function isAvailable($calendarId, $dateStart, $dateEnd, $excludeActionId = 0)
	{
		if ($this->hasDatabaseError || $dateStart <= dol_now() || $dateEnd <= $dateStart || !$this->isCalendarOpen($calendarId)) {
			return false;
		}
		$insideOpeningRange = false;
		$timezone = new DateTimeZone($this->getTimezone($calendarId));
		$localDate = (new DateTimeImmutable('@'.$dateStart))->setTimezone($timezone);
		$dayKey = $localDate->format('Y-m-d');
		if ($this->getUnambiguousLocalTimestamp($dayKey, $localDate->format('H:i'), $timezone) !== (int) $dateStart) {
			return false;
		}
		$hasTimeMask = $this->getTimeMaskState($calendarId);
		if ($hasTimeMask === null) {
			return false;
		}
		if ($hasTimeMask) {
			$ranges = $this->maskProvider->getRangesCoveringLocalDay('bookcal_calendar', $calendarId, $dayKey, $timezone->getName());
			if ($this->maskProvider->hasDatabaseError()) {
				$this->registerDatabaseError(__METHOD__);
				return false;
			}
			foreach ($ranges as $range) {
				if ($this->matchesGeneratedSlot($dateStart, $dateEnd, $range['start'], $range['end'], $range['slot_duration'])) {
					$insideOpeningRange = true;
					break;
				}
			}
		} else {
			$sql = 'SELECT ba.duration, ba.startHour, ba.endHour, ba.start, ba.end';
			$sql .= ' FROM '.$this->db->prefix().'bookcal_availabilities ba';
			$sql .= ' INNER JOIN '.$this->db->prefix().'bookcal_calendar bc ON bc.rowid = ba.fk_bookcal_calendar';
			$sql .= ' WHERE ba.fk_bookcal_calendar = '.((int) $calendarId).' AND ba.status = 1 AND bc.status = 1';
			$sql .= ' AND bc.entity IN ('.getEntity('calendar', 0).')';
			$resql = $this->db->query($sql);
			if (!$resql) {
				$this->registerDatabaseError(__METHOD__);
				return false;
			}
			while ($range = $this->db->fetch_object($resql)) {
				foreach ($this->getLegacyRangesCoveringLocalDay($range, $dayKey, $timezone) as $openingRange) {
					if ($this->matchesGeneratedSlot($dateStart, $dateEnd, $openingRange['start'], $openingRange['end'], $openingRange['slot_duration'])) {
						$insideOpeningRange = true;
						break 2;
					}
				}
			}
		}
		if (!$insideOpeningRange) {
			return false;
		}
		// New bookings use the common ledger.
		if (!$this->manager->canReserve('bookcal_calendar', $calendarId, $this->db->idate($dateStart), $this->db->idate($dateEnd), 1, 1)) {
			return false;
		}
		// Keep compatibility with bookings created before the common ledger.
		$sql = 'SELECT id, datep, datep2, fulldayevent FROM '.$this->db->prefix().'actioncomm';
		$sql .= ' WHERE fk_bookcal_calendar = '.((int) $calendarId);
		$sql .= " AND code = 'AC_RDV'";
		$sql .= " AND ((datep2 IS NOT NULL AND datep2 > '".$this->db->idate($dateStart)."' AND datep < '".$this->db->idate($dateEnd)."')";
		$sql .= ' OR datep2 IS NULL)';
		$sql .= ' AND entity = '.((int) $this->getCalendarEntity($calendarId));
		if ($excludeActionId > 0) {
			$sql .= ' AND id <> '.((int) $excludeActionId);
		}
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return false;
		}
		while ($action = $this->db->fetch_object($resql)) {
			$interval = $this->getLegacyActionInterval($action, $calendarId);
			if ($interval && $interval['end'] > $dateStart && $interval['start'] < $dateEnd) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Convert a calendar-local day and clock time to an absolute timestamp.
	 *
	 * @param int    $calendarId Calendar id
	 * @param int    $dayStart   Selected day timestamp used only for its date
	 * @param string $clockTime  Local time formatted as HH:MM
	 * @return int
	 */
	public function getLocalTimestamp($calendarId, $dayStart, $clockTime)
	{
		$dayParts = dol_getdate($dayStart);
		$dayKey = sprintf('%04d-%02d-%02d', $dayParts['year'], $dayParts['mon'], $dayParts['mday']);
		$timezone = new DateTimeZone($this->getTimezone($calendarId));
		return $this->getUnambiguousLocalTimestamp($dayKey, $clockTime, $timezone);
	}

	/**
	 * Convert an unambiguous local value to its absolute timestamp.
	 *
	 * @param string       $dayKey   Local day formatted as Y-m-d
	 * @param string       $clockTime Local time formatted as H:i
	 * @param DateTimeZone $timezone Calendar timezone
	 * @return int Zero when the local value is invalid or repeated
	 */
	private function getUnambiguousLocalTimestamp($dayKey, $clockTime, DateTimeZone $timezone)
	{
		$localValue = $dayKey.' '.$clockTime;
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $localValue, $timezone);
		if (!($date instanceof DateTimeImmutable) || $date->format('Y-m-d H:i') !== $localValue) {
			return 0;
		}
		$localAsUtc = gmmktime(
			(int) substr($clockTime, 0, 2),
			(int) substr($clockTime, 3, 2),
			0,
			(int) substr($dayKey, 5, 2),
			(int) substr($dayKey, 8, 2),
			(int) substr($dayKey, 0, 4)
		);
		$matches = array();
		$transitions = $timezone->getTransitions($date->getTimestamp() - 10800, $date->getTimestamp() + 10800);
		foreach ($transitions as $transition) {
			$candidate = $localAsUtc - (int) $transition['offset'];
			$candidateValue = (new DateTimeImmutable('@'.$candidate))->setTimezone($timezone)->format('Y-m-d H:i');
			if ($candidateValue === $localValue) {
				$matches[$candidate] = true;
			}
		}
		return count($matches) === 1 ? (int) array_key_first($matches) : 0;
	}

	/**
	 * Format an absolute timestamp in the resource timezone.
	 *
	 * @param int    $calendarId Calendar id
	 * @param int    $timestamp  Absolute timestamp
	 * @param string $format     DateTime format
	 * @return string
	 */
	public function formatLocalTimestamp($calendarId, $timestamp, $format = 'Y-m-d H:i')
	{
		$timezone = new DateTimeZone($this->getTimezone($calendarId));
		return (new DateTimeImmutable('@'.$timestamp))->setTimezone($timezone)->format($format);
	}

	/**
	 * Return the IANA timezone configured on the booked resource.
	 *
	 * @param int $calendarId Calendar id
	 * @return string
	 */
	public function getTimezone($calendarId)
	{
		$calendar = $this->getCalendar($calendarId);
		$timezone = $calendar && !empty($calendar->timezone) ? (string) $calendar->timezone : date_default_timezone_get();
		try {
			$timezone = (new DateTimeZone($timezone))->getName();
		} catch (Exception $exception) {
			$timezone = date_default_timezone_get();
		}
		return $this->maskProvider->getTimezone('bookcal_calendar', $calendarId, $timezone);
	}

	/**
	 * Check whether a reusable mask is assigned to the calendar.
	 *
	 * @param int $calendarId Calendar id
	 * @return bool
	 */
	public function hasTimeMask($calendarId)
	{
		return $this->getTimeMaskState($calendarId) === true;
	}

	/**
	 * Return whether this request encountered a database read error.
	 *
	 * @return bool
	 */
	public function hasDatabaseError()
	{
		return $this->hasDatabaseError;
	}

	/**
	 * Check whether a masked calendar has at least one opening on a local day.
	 *
	 * @param int $calendarId Calendar id
	 * @param int $dayStart   Timestamp used for its calendar date
	 * @return bool
	 */
	public function hasMaskedOpeningOnDay($calendarId, $dayStart)
	{
		if (!$this->isCalendarOpen($calendarId) || !$this->hasTimeMask($calendarId)) {
			return false;
		}
		$dayParts = dol_getdate($dayStart);
		$dayKey = sprintf('%04d-%02d-%02d', $dayParts['year'], $dayParts['mon'], $dayParts['mday']);
		$timezone = $this->getTimezone($calendarId);
		$ranges = $this->maskProvider->getRangesCoveringLocalDay('bookcal_calendar', $calendarId, $dayKey, $timezone);
		if ($this->maskProvider->hasDatabaseError()) {
			$this->registerDatabaseError(__METHOD__);
			return false;
		}
		return !empty($ranges);
	}

	/**
	 * Return whether a calendar accepts public bookings.
	 *
	 * @param int $calendarId Calendar id
	 * @return bool
	 */
	private function isCalendarOpen($calendarId)
	{
		$calendar = $this->getCalendar($calendarId);
		return $calendar && (int) $calendar->status === 1;
	}

	/**
	 * Load an entity-scoped calendar once per request.
	 *
	 * @param int $calendarId Calendar id
	 * @return object|false Calendar row, or false when inaccessible/not found
	 */
	private function getCalendar($calendarId)
	{
		$calendarId = (int) $calendarId;
		if (array_key_exists($calendarId, $this->calendarCache)) {
			return $this->calendarCache[$calendarId];
		}
		$sql = 'SELECT rowid, entity, status, timezone FROM '.$this->db->prefix().'bookcal_calendar';
		$sql .= ' WHERE rowid = '.$calendarId;
		$sql .= ' AND entity IN ('.getEntity('calendar', 0).')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			$this->calendarCache[$calendarId] = false;
			return false;
		}
		$calendar = $this->db->fetch_object($resql);
		$this->calendarCache[$calendarId] = $calendar ?: false;
		return $this->calendarCache[$calendarId];
	}

	/**
	 * Return the owning entity of an accessible calendar.
	 *
	 * @param int $calendarId Calendar id
	 * @return int Entity id, or zero when unavailable
	 */
	private function getCalendarEntity($calendarId)
	{
		$calendar = $this->getCalendar($calendarId);
		return $calendar ? (int) $calendar->entity : 0;
	}

	/**
	 * Check whether the calendar has an active mask without treating SQL errors as no mask.
	 *
	 * @param int $calendarId Calendar id
	 * @return ?bool True when assigned, false when absent, null on SQL error
	 */
	private function getTimeMaskState($calendarId)
	{
		$calendarId = (int) $calendarId;
		if (array_key_exists($calendarId, $this->timeMaskCache)) {
			return $this->timeMaskCache[$calendarId];
		}
		if ($this->getCalendarEntity($calendarId) <= 0) {
			$this->timeMaskCache[$calendarId] = false;
			return false;
		}
		$hasTimeMask = $this->maskProvider->hasMask('bookcal_calendar', $calendarId);
		if ($this->maskProvider->hasDatabaseError()) {
			$this->registerDatabaseError(__METHOD__);
			$this->timeMaskCache[$calendarId] = null;
			return null;
		}
		$this->timeMaskCache[$calendarId] = $hasTimeMask;
		return $this->timeMaskCache[$calendarId];
	}

	/**
	 * Check that an interval is exactly one slot generated from an opening range.
	 *
	 * @param int $dateStart Start timestamp
	 * @param int $dateEnd   End timestamp
	 * @param int $opening   Opening timestamp
	 * @param int $closing   Closing timestamp
	 * @param int $duration  Slot duration in minutes
	 * @return bool
	 */
	private function matchesGeneratedSlot($dateStart, $dateEnd, $opening, $closing, $duration)
	{
		$durationInSeconds = ((int) $duration) * 60;
		return $durationInSeconds > 0
			&& $dateStart >= $opening
			&& $dateEnd <= $closing
			&& ($dateEnd - $dateStart) === $durationInSeconds
			&& (($dateStart - $opening) % $durationInSeconds) === 0;
	}

	/**
	 * Build legacy opening ranges that overlap one local day, including overnight carry-over.
	 *
	 * @param object       $range    Legacy availability row
	 * @param string       $dayKey   Local target day formatted as Y-m-d
	 * @param DateTimeZone $timezone Calendar timezone
	 * @return array<int,array{start:int,end:int,slot_duration:int}>
	 */
	private function getLegacyRangesCoveringLocalDay($range, $dayKey, DateTimeZone $timezone)
	{
		$duration = (int) $range->duration;
		if ($duration <= 0) {
			return array();
		}
		$targetDay = DateTimeImmutable::createFromFormat('!Y-m-d', $dayKey, $timezone);
		if (!($targetDay instanceof DateTimeImmutable) || $targetDay->format('Y-m-d') !== $dayKey) {
			return array();
		}
		$rangeStart = substr((string) $range->start, 0, 10);
		$rangeEnd = substr((string) $range->end, 0, 10);
		$startHour = max(0, min(24, (int) $range->startHour));
		$endHour = max(0, min(24, (int) $range->endHour));
		$anchorDays = array($targetDay);
		if ($endHour <= $startHour) {
			$anchorDays[] = $targetDay->modify('-1 day');
		}
		$ranges = array();
		foreach ($anchorDays as $anchorDay) {
			$anchorKey = $anchorDay->format('Y-m-d');
			if ($anchorKey < $rangeStart || $anchorKey > $rangeEnd) {
				continue;
			}
			$opening = $anchorDay->setTime($startHour, 0);
			$closing = $anchorDay->setTime($endHour, 0);
			if ($endHour <= $startHour) {
				$closing = $closing->modify('+1 day');
			}
			$ranges[] = array(
				'start' => $opening->getTimestamp(),
				'end' => $closing->getTimestamp(),
				'slot_duration' => $duration,
			);
		}
		return $ranges;
	}

	/**
	 * Record a database error so the provider stays fail-closed for this request.
	 *
	 * @param string $method Method reporting the error
	 * @return void
	 */
	private function registerDatabaseError($method)
	{
		$this->hasDatabaseError = true;
		dol_syslog($method.': '.$this->db->lasterror(), LOG_ERR);
	}

	/**
	 * Load normalized and legacy busy intervals once for a displayed day.
	 *
	 * @param int $calendarId Calendar id
	 * @param int $windowStart Earliest generated slot timestamp
	 * @param int $windowEnd Latest generated slot end timestamp
	 * @return array<int,array{start:int,end:int}>|false
	 */
	private function loadOccupiedIntervals($calendarId, $windowStart, $windowEnd)
	{
		$intervals = array();
		$dateStart = $this->db->idate($windowStart);
		$dateEnd = $this->db->idate($windowEnd);
		$sql = 'SELECT er.date_start, er.date_end FROM '.$this->db->prefix().'element_resources er';
		$sql .= ' INNER JOIN '.$this->db->prefix().'bookcal_calendar bc ON bc.rowid = er.resource_id';
		$sql .= " WHERE er.resource_type = 'bookcal_calendar' AND er.resource_id = ".((int) $calendarId);
		$sql .= " AND er.reservation_status = 'confirmed'";
		$sql .= " AND (er.relation_kind = 'assignment' OR er.relation_kind IS NULL)";
		$sql .= " AND (er.date_end IS NULL OR er.date_end > '".$this->db->escape($dateStart)."')";
		$sql .= " AND (er.date_start IS NULL OR er.date_start < '".$this->db->escape($dateEnd)."')";
		$sql .= ' AND bc.status = 1 AND bc.entity IN ('.getEntity('calendar', 0).')';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return false;
		}
		while ($assignment = $this->db->fetch_object($resql)) {
			$intervals[] = array(
				'start' => empty($assignment->date_start) ? $windowStart : (int) $this->db->jdate($assignment->date_start),
				'end' => empty($assignment->date_end) ? $windowEnd : (int) $this->db->jdate($assignment->date_end),
			);
		}

		$sql = 'SELECT a.datep, a.datep2, a.fulldayevent FROM '.$this->db->prefix().'actioncomm a';
		$sql .= ' WHERE a.fk_bookcal_calendar = '.((int) $calendarId);
		$sql .= " AND a.code = 'AC_RDV'";
		$sql .= ' AND a.entity = '.((int) $this->getCalendarEntity($calendarId));
		$sql .= " AND ((a.datep2 IS NOT NULL AND a.datep2 > '".$this->db->escape($dateStart)."' AND a.datep < '".$this->db->escape($dateEnd)."')";
		$sql .= ' OR a.datep2 IS NULL)';
		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->registerDatabaseError(__METHOD__);
			return false;
		}
		while ($action = $this->db->fetch_object($resql)) {
			$interval = $this->getLegacyActionInterval($action, $calendarId);
			if ($interval) {
				$intervals[] = $interval;
			}
		}
		return $intervals;
	}

	/**
	 * Normalize a legacy BookCal ActionComm interval.
	 *
	 * @param object $action     ActionComm database row
	 * @param int    $calendarId Calendar id used to resolve the local timezone
	 * @return array{start:int,end:int}|null
	 */
	private function getLegacyActionInterval($action, $calendarId)
	{
		$start = (int) $this->db->jdate($action->datep);
		$end = (int) $this->db->jdate($action->datep2);
		if ($start <= 0) {
			return null;
		}
		if ($end <= 0) {
			if (!empty($action->fulldayevent)) {
				$timezone = new DateTimeZone($this->getTimezone($calendarId));
				$localDay = (new DateTimeImmutable('@'.$start))->setTimezone($timezone)->setTime(0, 0, 0);
				$start = $localDay->getTimestamp();
				$end = $localDay->modify('+1 day')->getTimestamp();
			} else {
				$end = $start + 1;
			}
		}
		return $end > $start ? array('start' => $start, 'end' => $end) : null;
	}

	/**
	 * Expand one opening range into virtual slots.
	 *
	 * @param array<string,int> $slots      Generated slots
	 * @param int               $opening    Absolute opening timestamp
	 * @param int               $closing    Absolute closing timestamp
	 * @param int               $duration   Slot duration in minutes
	 * @param DateTimeZone      $timezone   Calendar timezone
	 * @param array<int,array{start:int,end:int}> $occupiedIntervals Busy intervals loaded for the day
	 * @param int|null          $windowStart Optional inclusive timestamp for slot starts
	 * @param int|null          $windowEnd   Optional exclusive timestamp for slot starts
	 * @return void
	 */
	private function appendRangeSlots(array &$slots, $opening, $closing, $duration, DateTimeZone $timezone, array $occupiedIntervals, $windowStart = null, $windowEnd = null)
	{
		if ($duration <= 0) {
			return;
		}
		$durationInSeconds = $duration * 60;
		$cursor = $opening;
		if ($windowStart !== null && $cursor < $windowStart) {
			$cursor += ((int) ceil(($windowStart - $cursor) / $durationInSeconds)) * $durationInSeconds;
		}
		while ($cursor + $durationInSeconds <= $closing && ($windowEnd === null || $cursor < $windowEnd)) {
			$localDate = (new DateTimeImmutable('@'.$cursor))->setTimezone($timezone);
			$key = $localDate->format('H:i');
			if ($this->getUnambiguousLocalTimestamp($localDate->format('Y-m-d'), $key, $timezone) !== $cursor) {
				$cursor += $durationInSeconds;
				continue;
			}
			$slotEnd = $cursor + $durationInSeconds;
			$available = $cursor > dol_now();
			foreach ($occupiedIntervals as $occupiedInterval) {
				if ($occupiedInterval['end'] > $cursor && $occupiedInterval['start'] < $slotEnd) {
					$available = false;
					break;
				}
			}
			$slots[$key] = $available ? $duration : -$duration;
			$cursor += $durationInSeconds;
		}
	}
}
