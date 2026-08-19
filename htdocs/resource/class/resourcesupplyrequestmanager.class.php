<?php
/* Copyright (C) 2026 Dolibarr contributors */

/**
 * Compatibility bridge for the optional recursos-beta-br4ito companion module.
 *
 * New integrations should use RecursosBetaBr4itoSupplyRequestManager directly.
 */

require_once DOL_DOCUMENT_ROOT.'/custom/recursos-beta-br4ito/class/recursosbetabr4itosupplyrequestmanager.class.php';

/**
 * @deprecated Use RecursosBetaBr4itoSupplyRequestManager from recursos-beta-br4ito.
 */
class ResourceSupplyRequestManager extends RecursosBetaBr4itoSupplyRequestManager
{
}
