<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * \file resource/class/resourcesupplyrequestmanager.class.php
 * \ingroup resource
 * \brief Link unknown resource availability to owner or supplier confirmations.
 */

/**
 * Manage the lifecycle of an availability request without creating supplier
 * orders. Future integrations can create the document and then attach its ids.
 */
class ResourceSupplyRequestManager
{
	public const STATUS_UNKNOWN = 'unknown';
	public const STATUS_REQUESTED = 'requested';
	public const STATUS_CONFIRMED = 'confirmed';
	public const STATUS_REJECTED = 'rejected';
	public const STATUS_CANCELED = 'canceled';

	/** @var DoliDB */
	private $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Record the data needed by a future owner request or supplier order.
	 *
	 * @param array<string,mixed> $request Normalized request data
	 * @param User                $user    Current user
	 * @return int Request id, negative on error
	 */
	public function create(array $request, User $user)
	{
		$assignmentId = (int) ($request['fk_element_resource'] ?? 0);
		$resourceId = (int) ($request['fk_resource'] ?? 0);
		$dateStart = (string) ($request['date_start'] ?? '');
		$dateEnd = (string) ($request['date_end'] ?? '');
		$quantity = (float) ($request['quantity_requested'] ?? 0);
		if ($assignmentId <= 0 || $resourceId <= 0 || $quantity <= 0 || $dateStart === '' || $dateEnd === '') {
			return -2;
		}
		$sql = 'INSERT INTO '.$this->db->prefix().'resource_supply_request (';
		$sql .= 'entity, fk_element_resource, fk_resource, request_type, fk_soc_supplier, fk_product_supplier,';
		$sql .= ' quantity_requested, date_start, date_end, timezone, request_status, date_creation, fk_user_create';
		$sql .= ') VALUES (';
		$sql .= ((int) ($request['entity'] ?? 1)).', '.$assignmentId.', '.$resourceId.', ';
		$sql .= "'".$this->db->escape((string) ($request['request_type'] ?? 'owner'))."', ";
		$sql .= (!empty($request['fk_soc_supplier']) ? ((int) $request['fk_soc_supplier']) : 'NULL').', ';
		$sql .= (!empty($request['fk_product_supplier']) ? ((int) $request['fk_product_supplier']) : 'NULL').', ';
		$sql .= price2num($quantity, 'MS').", '".$this->db->escape($dateStart)."', '".$this->db->escape($dateEnd)."', ";
		$sql .= "'".$this->db->escape((string) ($request['timezone'] ?? 'UTC'))."', '".self::STATUS_UNKNOWN."', '";
		$sql .= $this->db->idate(dol_now())."', ".((int) $user->id).')';
		if (!$this->db->query($sql)) {
			return -1;
		}
		return (int) $this->db->last_insert_id($this->db->prefix().'resource_supply_request');
	}

	/**
	 * Attach the supplier document created by a future procurement adapter.
	 *
	 * @param int  $requestId          Supply request id
	 * @param int  $supplierOrderId    Supplier order id
	 * @param int  $supplierOrderLineId Supplier order line id
	 * @param User $user               Current user
	 * @return int<-1,1>
	 */
	public function linkSupplierOrder($requestId, $supplierOrderId, $supplierOrderLineId, User $user)
	{
		if ($requestId <= 0 || $supplierOrderId <= 0 || $supplierOrderLineId <= 0) {
			return -1;
		}
		$sql = 'UPDATE '.$this->db->prefix().'resource_supply_request SET request_type=\'supplier\',';
		$sql .= ' fk_supplier_order='.((int) $supplierOrderId).', fk_supplier_order_line='.((int) $supplierOrderLineId).',';
		$sql .= " request_status='".self::STATUS_REQUESTED."', date_request='".$this->db->idate(dol_now())."',";
		$sql .= ' fk_user_modif='.((int) $user->id).' WHERE rowid='.((int) $requestId);
		return $this->db->query($sql) ? 1 : -1;
	}

	/**
	 * Confirm only an exact supplier response and atomically confirm assignment.
	 *
	 * @param int                  $requestId Supply request id
	 * @param array<string,mixed>  $response  Confirmed quantity and date range
	 * @param User                 $user      Current user
	 * @return int<-2,1> 1 confirmed, -1 database error, -2 mismatch
	 */
	public function confirm($requestId, array $response, User $user)
	{
		$sql = 'SELECT * FROM '.$this->db->prefix().'resource_supply_request WHERE rowid='.((int) $requestId).' FOR UPDATE';
		$this->db->begin();
		$request = $this->db->fetch_object($this->db->query($sql));
		$quantity = (float) ($response['quantity_confirmed'] ?? 0);
		$dateStart = (string) ($response['date_start'] ?? '');
		$dateEnd = (string) ($response['date_end'] ?? '');
		if (!$request || $quantity !== (float) $request->quantity_requested || $dateStart !== $request->date_start || $dateEnd !== $request->date_end) {
			$this->db->rollback();
			return -2;
		}
		$sql = 'UPDATE '.$this->db->prefix().'resource_supply_request SET request_status=\''.self::STATUS_CONFIRMED.'\',';
		$sql .= ' quantity_confirmed='.price2num($quantity, 'MS').", date_confirmation='".$this->db->idate(dol_now())."',";
		$sql .= ' fk_user_modif='.((int) $user->id).' WHERE rowid='.((int) $requestId);
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) !== 1) {
			$this->db->rollback();
			return $resql ? -2 : -1;
		}
		$sql = 'UPDATE '.$this->db->prefix().'element_resources SET reservation_status=\'confirmed\'';
		$sql .= ' WHERE rowid='.((int) $request->fk_element_resource)." AND reservation_status='awaiting_supply'";
		$resql = $this->db->query($sql);
		if (!$resql || $this->db->affected_rows($resql) !== 1) {
			$this->db->rollback();
			return $resql ? -2 : -1;
		}
		$this->db->commit();
		return 1;
	}

	/**
	 * Mirror supplier-order lifecycle without treating internal approval as a
	 * supplier confirmation.
	 *
	 * @param string $action          Dolibarr trigger action
	 * @param int    $supplierOrderId Supplier order id
	 * @param User   $user            Current user
	 * @return int<-1,1>
	 */
	public function handleSupplierOrderTrigger($action, $supplierOrderId, User $user)
	{
		$status = null;
		if (in_array($action, array('ORDER_SUPPLIER_VALIDATE', 'ORDER_SUPPLIER_APPROVE', 'ORDER_SUPPLIER_SUBMIT'), true)) {
			$status = self::STATUS_REQUESTED;
		} elseif ($action === 'ORDER_SUPPLIER_REFUSE') {
			$status = self::STATUS_REJECTED;
		} elseif ($action === 'ORDER_SUPPLIER_CANCEL') {
			$status = self::STATUS_CANCELED;
		}
		if ($status === null || $supplierOrderId <= 0) {
			return 0;
		}
		$sql = 'UPDATE '.$this->db->prefix().'resource_supply_request SET request_status=\''.$status.'\',';
		$sql .= " supplier_order_status='".$this->db->escape($action)."', fk_user_modif=".((int) $user->id);
		$sql .= ' WHERE fk_supplier_order='.((int) $supplierOrderId);
		if ($status === self::STATUS_REQUESTED) {
			$sql .= " AND request_status IN ('".self::STATUS_UNKNOWN."','".self::STATUS_REQUESTED."')";
		} else {
			$sql .= " AND request_status<>'".self::STATUS_CONFIRMED."'";
		}
		return $this->db->query($sql) ? 1 : -1;
	}
}
