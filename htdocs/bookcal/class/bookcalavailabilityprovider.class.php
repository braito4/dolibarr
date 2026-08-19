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
		$localDayStart = dol_mktime(0, 0, 0, $dayParts['mon'], $dayParts['mday'], $dayParts['year'], 'tzuserrel');
		$dayKey = sprintf('%04d-%02d-%02d', $dayParts['year'], $dayParts['mon'], $dayParts['mday']);
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
				$key = dol_print_date($cursor, '%H:%M', 'tzuserrel');
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
		$dateParts = dol_getdate($dateStart);
		$dayStart = dol_mktime(0, 0, 0, $dateParts['mon'], $dateParts['mday'], $dateParts['year'], 'tzuserrel');
		$dayKey = sprintf('%04d-%02d-%02d', $dateParts['year'], $dateParts['mon'], $dateParts['mday']);
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
}
