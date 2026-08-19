<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file bookcal/class/bookcalavailabilityprovider.class.php
 * \ingroup bookcal
 * \brief Adapt BookCal opening ranges to the common resource reservation engine.
 */

require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcereservationmanager.class.php';

/**
 * BookCal availability provider.
 */
class BookCalAvailabilityProvider
{
	/** @var DoliDB */
	private $db;
	/** @var ResourceReservationManager */
	private $manager;

	/** @param DoliDB $db Database handler */
	public function __construct($db)
	{
		$this->db = $db;
		$this->manager = new ResourceReservationManager($db);
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
		$sql = 'SELECT DISTINCT p.rowid, p.ref, p.label, p.description, p.price, p.tva_tx';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'element_resources er';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product p ON p.rowid = er.element_id';
		$sql .= " WHERE er.element_type = 'product'";
		$sql .= " AND er.resource_type = 'bookcal_calendar'";
		$sql .= ' AND er.resource_id = '.((int) $calendarId);
		$sql .= " AND (er.relation_kind = 'requirement' OR er.relation_kind IS NULL)";
		$sql .= ' AND p.fk_product_type = 1 AND p.tosell = 1';
		$sql .= ' ORDER BY p.ref, p.label';
		$resql = $this->db->query($sql);
		while ($resql && ($service = $this->db->fetch_object($resql))) {
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
		$dayParts = dol_getdate($dayStart);
		$dayKey = sprintf('%04d-%02d-%02d', $dayParts['year'], $dayParts['mon'], $dayParts['mday']);
		$timezone = new DateTimeZone($this->getTimezone($calendarId));
		$localDayStart = (new DateTimeImmutable($dayKey.' 00:00:00', $timezone))->getTimestamp();
		$sql = 'SELECT ba.duration, ba.startHour, ba.endHour, ba.start, ba.end';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'bookcal_availabilities ba';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bookcal_calendar bc ON bc.rowid = ba.fk_bookcal_calendar';
		$sql .= ' WHERE ba.fk_bookcal_calendar = '.((int) $calendarId).' AND ba.status = 1 AND bc.status = 1';
		$resql = $this->db->query($sql);
		while ($resql && ($range = $this->db->fetch_object($resql))) {
			$rangeStart = substr((string) $range->start, 0, 10);
			$rangeEnd = substr((string) $range->end, 0, 10);
			if ($dayKey < $rangeStart || $dayKey > $rangeEnd || (int) $range->duration <= 0) {
				continue;
			}
			$startHour = max(0, min(24, (int) $range->startHour));
			$endHour = max(0, min(24, (int) $range->endHour));
			$cursor = $localDayStart + ($startHour * 3600);
			$limit = $localDayStart + ($endHour * 3600);
			if ($endHour <= $startHour) {
				$limit += 86400;
			}
			$duration = (int) $range->duration;
			while ($cursor + ($duration * 60) <= $limit) {
				$key = (new DateTimeImmutable('@'.$cursor))->setTimezone($timezone)->format('H:i');
				$available = $this->isAvailable($calendarId, $cursor, $cursor + ($duration * 60));
				$slots[$key] = $available ? $duration : -$duration;
				$cursor += $duration * 60;
			}
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
	 * @return bool
	 */
	public function isAvailable($calendarId, $dateStart, $dateEnd)
	{
		if ($dateStart <= 0 || $dateEnd <= $dateStart) {
			return false;
		}
		$insideOpeningRange = false;
		$sql = 'SELECT ba.duration, ba.startHour, ba.endHour, ba.start, ba.end';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'bookcal_availabilities ba';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'bookcal_calendar bc ON bc.rowid = ba.fk_bookcal_calendar';
		$sql .= ' WHERE ba.fk_bookcal_calendar = '.((int) $calendarId).' AND ba.status = 1 AND bc.status = 1';
		$resql = $this->db->query($sql);
		$timezone = new DateTimeZone($this->getTimezone($calendarId));
		$localDate = (new DateTimeImmutable('@'.$dateStart))->setTimezone($timezone);
		$dayKey = $localDate->format('Y-m-d');
		$dayStart = (new DateTimeImmutable($dayKey.' 00:00:00', $timezone))->getTimestamp();
		while ($resql && ($range = $this->db->fetch_object($resql))) {
			$rangeStart = substr((string) $range->start, 0, 10);
			$rangeEnd = substr((string) $range->end, 0, 10);
			$startHour = max(0, min(24, (int) $range->startHour));
			$endHour = max(0, min(24, (int) $range->endHour));
			$opening = $dayStart + ($startHour * 3600);
			$closing = $dayStart + ($endHour * 3600);
			if ($endHour <= $startHour) {
				$closing += 86400;
			}
			$duration = (int) round(($dateEnd - $dateStart) / 60);
			if ($dayKey >= $rangeStart && $dayKey <= $rangeEnd && $dateStart >= $opening && $dateEnd <= $closing && $duration === (int) $range->duration) {
				$insideOpeningRange = true;
				break;
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
		$sql = 'SELECT COUNT(id) as nb FROM '.MAIN_DB_PREFIX.'actioncomm';
		$sql .= ' WHERE fk_bookcal_calendar = '.((int) $calendarId);
		$sql .= " AND code = 'AC_RDV' AND status = 0";
		$sql .= " AND (datep2 IS NULL OR datep2 > '".$this->db->idate($dateStart)."')";
		$sql .= " AND datep < '".$this->db->idate($dateEnd)."'";
		$obj = $this->db->fetch_object($this->db->query($sql));
		return !$obj || (int) $obj->nb === 0;
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
		$date = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $dayKey.' '.$clockTime, $timezone);
		if (!($date instanceof DateTimeImmutable) || $date->format('H:i') !== $clockTime) {
			return 0;
		}
		return $date->getTimestamp();
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
		$sql = 'SELECT timezone FROM '.MAIN_DB_PREFIX.'bookcal_calendar WHERE rowid = '.((int) $calendarId);
		$obj = $this->db->fetch_object($this->db->query($sql));
		$timezone = $obj && !empty($obj->timezone) ? (string) $obj->timezone : 'UTC';
		try {
			new DateTimeZone($timezone);
		} catch (Exception $exception) {
			$timezone = 'UTC';
		}
		return $timezone;
	}
}
