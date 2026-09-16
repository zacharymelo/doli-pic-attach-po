<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    ajax/debug.php
 * \ingroup rfqimages
 * \brief   Plain-text diagnostics. Admin only, gated by RFQIMAGES_DEBUG_MODE.
 *
 * Modes (?mode=):
 *   overview  — module status, hooks, table, extrafield, settings (default)
 *   product   — files and choices of one product (?mode=product&id=12)
 *   proposal  — what would be sent for a price request (?mode=proposal&id=5)
 *   all       — overview + product/proposal when id given
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	http_response_code(500);
	exit;
}

if (!$user->admin) {
	http_response_code(403);
	print 'Admin only';
	exit;
}
if (!getDolGlobalInt('RFQIMAGES_DEBUG_MODE')) {
	http_response_code(403);
	print 'Debug mode not enabled. Go to RFQ Product Images > Setup and enable Debug Mode.';
	exit;
}

header('Content-Type: text/plain; charset=utf-8');

dol_include_once('/rfqimages/class/rfqimagesservice.class.php');
require_once DOL_DOCUMENT_ROOT.'/supplier_proposal/class/supplier_proposal.class.php';

$mode = GETPOST('mode', 'alpha') ?: 'overview';
$id = GETPOSTINT('id');
$run_all = ($mode === 'all');
$service = new RfqImagesService($db);

print "=== RFQIMAGES DEBUG DIAGNOSTICS ===\n";
print "Timestamp: ".dol_print_date(dol_now(), 'dayhourlog')."\n";
print "Dolibarr: ".DOL_VERSION."\n";
print "Mode: $mode\n";
print "Usage: ?mode=overview|product|proposal|all [&id=N]\n";
print str_repeat('=', 60)."\n\n";

if ($mode === 'overview' || $run_all) {
	print "--- MODULE ---\n";
	print "isModEnabled('rfqimages'): ".(isModEnabled('rfqimages') ? 'YES' : 'NO')."\n";
	print "Public URL root: ".RfqImagesService::publicRoot()."\n";

	print "\n--- HOOK CONTEXTS ---\n";
	foreach (array('formmail', 'supplier_proposalcard') as $ctx) {
		$mods = isset($conf->modules_parts['hooks'][$ctx]) ? (array) $conf->modules_parts['hooks'][$ctx] : array();
		$found = false;
		foreach ($mods as $m) {
			if (stripos($m, 'rfqimages') !== false) {
				$found = true;
			}
		}
		print "  $ctx: ".($found ? 'registered' : 'NOT registered (re-enable module)')."\n";
	}
	print "  substitutions: ".(in_array('/rfqimages/core/substitutions/', (array) $conf->modules_parts['substitutions']) ? 'registered' : 'NOT registered')."\n";

	print "\n--- TABLE ---\n";
	$resql = $db->query("SELECT COUNT(rowid) as cnt FROM ".MAIN_DB_PREFIX."rfqimages_file");
	if ($resql) {
		$obj = $db->fetch_object($resql);
		print "  llx_rfqimages_file: ".$obj->cnt." rows\n";
	} else {
		print "  llx_rfqimages_file: MISSING (".$db->lasterror().")\n";
	}

	print "\n--- EXTRAFIELD ---\n";
	$ef = new ExtraFields($db);
	$ef->fetch_name_optionals_label('product', true);
	print "  product.rfqimages_send: ".(!empty($ef->attributes['product']['type']['rfqimages_send']) ? $ef->attributes['product']['type']['rfqimages_send'] : 'MISSING')."\n";

	print "\n--- SETTINGS ---\n";
	$resql = $db->query("SELECT name, value FROM ".MAIN_DB_PREFIX."const WHERE name LIKE 'RFQIMAGES%' AND entity IN (0, ".((int) $conf->entity).") ORDER BY name");
	if ($resql) {
		while ($row = $db->fetch_object($resql)) {
			print "  ".$row->name." = ".$row->value."\n";
		}
	}
	print "  extensions parsed: ".implode(',', RfqImagesService::getExtensions())."\n\n";
}

if (($mode === 'product' || $run_all) && $id > 0) {
	print "--- PRODUCT id=$id ---\n";
	$product = new Product($db);
	if ($product->fetch($id) <= 0) {
		print "  fetch failed\n\n";
	} else {
		$product->fetch_optionals();
		print "  ref: ".$product->ref."\n";
		print "  flagged: ".(!empty($product->array_options['options_rfqimages_send']) ? 'YES' : 'NO')."\n";
		print "  dir: ".RfqImagesService::getProductDir($product)."\n";
		foreach ($service->listCandidateFiles($product) as $f) {
			$ecm = $service->fetchEcmFile($f['fullpath']);
			print "  - ".$f['name']." size=".$f['size']." selected=".$f['selected']." ecm=".($ecm ? $ecm->id : 'none')." share=".($ecm && $ecm->share ? 'yes' : 'no')."\n";
		}
		print "\n";
	}
}

if (($mode === 'proposal' || $run_all) && $id > 0) {
	print "--- PRICE REQUEST id=$id ---\n";
	$sp = new SupplierProposal($db);
	if ($sp->fetch($id) <= 0) {
		print "  fetch failed\n\n";
	} else {
		$files = $service->collectForProposal($sp);
		$total = 0;
		foreach ($files as $f) {
			$total += $f['size'];
			print "  - ".$f['productref']." / ".$f['name']." (".$f['size'].")\n";
		}
		$limit = (float) getDolGlobalString('RFQIMAGES_MAX_ATTACH_MB', '10');
		print "  total: $total bytes, limit: $limit MB → ".(($limit > 0 && $total > $limit * 1048576) ? 'LINKS' : 'ATTACH')."\n";
		print "  session links: ".(!empty($_SESSION['rfqimages_links-spro'.$id]) ? $_SESSION['rfqimages_links-spro'.$id] : '(none)')."\n\n";
	}
}

print "=== END DEBUG ===\n";
