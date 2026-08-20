<?php
/* Copyright (C) 2026 Dolibarr contributors */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/resource/class/resourcerequirementmanager.class.php';

/** @var DoliDB $db */
/** @var User $user */

if (!$user->hasRight('resource', 'read')) {
	accessforbidden();
}

$productId = GETPOSTINT('product_id');
$policy = array('has_requirements' => false, 'start_input_mode' => 'none', 'end_input_mode' => 'none', 'time_precision' => 'day', 'show_start' => false, 'show_end' => false, 'calculate_end' => false, 'automatic' => false);
if ($productId > 0) {
	$product = new Product($db);
	if ($product->fetch($productId) <= 0) {
		http_response_code(404);
		exit;
	}
	if ($product->type == Product::TYPE_PRODUCT) {
		restrictedArea($user, 'produit', $productId, 'product&product');
	} else {
		restrictedArea($user, 'service', $productId, 'product&product');
	}
	$manager = new ResourceRequirementManager($db);
	$policy = $manager->getTemporalPolicy($product->type == Product::TYPE_PRODUCT ? 'product' : 'service', $productId);
}
header('Content-Type: application/json');
print json_encode($policy);
