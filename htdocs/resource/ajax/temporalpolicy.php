<?php
/* Copyright (C) 2026 Dolibarr contributors */

if (!defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', 1);
if (!defined('NOREQUIREMENU')) define('NOREQUIREMENU', 1);
if (!defined('NOREQUIREHTML')) define('NOREQUIREHTML', 1);
if (!defined('NOREQUIREAJAX')) define('NOREQUIREAJAX', 1);
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcereservationmanager.class.php';

$productId = GETPOSTINT('product_id');
$policy = array('has_requirements' => false, 'start_input_mode' => 'none', 'end_input_mode' => 'none', 'time_precision' => 'day', 'show_start' => false, 'show_end' => false, 'calculate_end' => false, 'automatic' => false);
if ($productId > 0) {
	$manager = new ResourceReservationManager($db);
	$policy = $manager->getTemporalPolicy('product', $productId);
}
header('Content-Type: application/json');
print json_encode($policy);
