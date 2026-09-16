<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    admin/setup.php
 * \ingroup rfqimages
 * \brief   rfqimages setup page
 */

$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/ajax.lib.php';
dol_include_once('/rfqimages/lib/rfqimages.lib.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'rfqimages@rfqimages'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

// Upgrades from 1.0.0 without re-enabling: these new settings default to on, create them so the toggles show it.
// Their toggles store 0 when switched off (instead of deleting), so this never switches them back on.
foreach (array('RFQIMAGES_ON_SUPPLIER_PROPOSAL', 'RFQIMAGES_ON_SUPPLIER_ORDER', 'RFQIMAGES_LINKS_SHOW_TITLE') as $constname) {
	if (!isset($conf->global->$constname)) {
		dolibarr_set_const($db, $constname, '1', 'chaine', 0, '', $conf->entity);
	}
}


/*
 * Actions
 */

if ($action == 'update_extensions') {
	$exts = array();
	foreach (explode(',', GETPOST('RFQIMAGES_EXTENSIONS', 'alphanohtml')) as $e) {
		$e = strtolower(trim(ltrim(trim($e), '.')));
		if ($e !== '' && preg_match('/^[a-z0-9]+$/', $e)) {
			$exts[] = $e;
		}
	}
	dolibarr_set_const($db, 'RFQIMAGES_EXTENSIONS', implode(',', array_unique($exts)), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'update_linkstitle') {
	// Blank deletes the setting, which means the translated default heading
	dolibarr_set_const($db, 'RFQIMAGES_LINKS_TITLE', trim(GETPOST('RFQIMAGES_LINKS_TITLE', 'alphanohtml')), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'update_maxsize') {
	$mb = price2num(GETPOST('RFQIMAGES_MAX_ATTACH_MB', 'alphanohtml'));
	dolibarr_set_const($db, 'RFQIMAGES_MAX_ATTACH_MB', (string) max(0, (float) $mb), 'chaine', 0, '', $conf->entity);
	setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

if ($action == 'repair_extrafield') {
	if (rfqimages_ensure_extrafields($db) > 0) {
		setEventMessages($langs->trans('RfqImagesExtrafieldOk'), null, 'mesgs');
	} else {
		setEventMessages($langs->trans('Error'), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}


/*
 * View
 */

llxHeader('', $langs->trans('RfqImagesSetup'), '', '', 0, 0, '', '', '', 'mod-rfqimages page-admin');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('RfqImagesSetup'), $linkback, 'title_setup');

$head = rfqimages_admin_prepare_head();
print dol_get_fiche_head($head, 'settings', $langs->trans('Module510410Name'), -1, 'image');

print '<span class="opacitymedium">'.$langs->trans('RfqImagesSetupIntro').'</span><br><br>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td><td>'.$langs->trans('Description').'</td></tr>';

// Documents
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesOnSupplierProposal').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_ON_SUPPLIER_PROPOSAL', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesOnSupplierProposalDesc').(isModEnabled('supplier_proposal') ? '' : ' <span class="warning">'.$langs->trans('RfqImagesModuleOff').'</span>').'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesOnSupplierOrder').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_ON_SUPPLIER_ORDER', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesOnSupplierOrderDesc').((isModEnabled('fournisseur') || isModEnabled('supplier_order')) ? '' : ' <span class="warning">'.$langs->trans('RfqImagesModuleOff').'</span>').'</td></tr>';

// Extensions
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesExtensions').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_extensions">';
print '<input type="text" class="minwidth200" name="RFQIMAGES_EXTENSIONS" value="'.dol_escape_htmltag(getDolGlobalString('RFQIMAGES_EXTENSIONS', 'jpg,jpeg,png,gif,webp')).'">';
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesExtensionsDesc').'</td></tr>';

// Max attachment size
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesMaxAttach').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_maxsize">';
print '<input type="number" step="0.01" min="0" name="RFQIMAGES_MAX_ATTACH_MB" value="'.dol_escape_htmltag(getDolGlobalString('RFQIMAGES_MAX_ATTACH_MB', '10')).'" style="width:80px;"> MB';
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesMaxAttachDesc').'</td></tr>';

// Links heading
print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesLinksShowTitle').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_LINKS_SHOW_TITLE', array(), null, 0, 0, 0, 2, 0, 1).'</td>';
print '<td class="opacitymedium">'.$langs->trans('RfqImagesLinksShowTitleDesc').'</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('RfqImagesLinksTitleSetting').'</td><td>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="margin:0;">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update_linkstitle">';
print '<input type="text" class="minwidth300" maxlength="255" name="RFQIMAGES_LINKS_TITLE" value="'.dol_escape_htmltag(getDolGlobalString('RFQIMAGES_LINKS_TITLE')).'" placeholder="'.dol_escape_htmltag($langs->transnoentities('RfqImagesLinksTitle')).'">';
print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('Save').'">';
print '</form>';
print '</td><td class="opacitymedium">'.$langs->trans('RfqImagesLinksTitleSettingDesc').'</td></tr>';

// Debug mode (always last)
print '<tr class="oddeven"><td>'.$langs->trans('DebugMode').'</td>';
print '<td>'.ajax_constantonoff('RFQIMAGES_DEBUG_MODE').'</td>';
print '<td class="opacitymedium">'.$langs->trans('DebugModeDesc').'</td></tr>';

print '</table>';
print '</div>';

print '<br>';
print '<div class="info">'.$langs->trans('RfqImagesTemplateHelp').'</div>';

print '<div class="tabsAction">';
print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=repair_extrafield&token='.newToken().'">'.$langs->trans('RfqImagesRepairExtrafield').'</a>';
print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
