<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    lib/rfqimages.lib.php
 * \ingroup rfqimages
 * \brief   Helpers for the rfqimages module
 */

/**
 * Create the product extrafield rfqimages_send if missing. Idempotent.
 *
 * @param  DoliDB $db Database handler
 * @return int        1 if present or created, -1 on failure
 */
function rfqimages_ensure_extrafields($db)
{
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

	$ef = new ExtraFields($db);
	$ef->fetch_name_optionals_label('product', true);
	if (!empty($ef->attributes['product']['type']['rfqimages_send'])) {
		return 1;
	}
	$result = $ef->addExtraField('rfqimages_send', 'RfqImagesSend', 'boolean', 900, '', 'product', 0, 0, '', '', 1, '', '1', 'RfqImagesSendHelp', '', '', 'rfqimages@rfqimages', 'isModEnabled("rfqimages")');
	return ($result > 0) ? 1 : -1;
}

/**
 * Admin setup tabs
 *
 * @return array<array{0:string,1:string,2:string}>
 */
function rfqimages_admin_prepare_head()
{
	global $langs, $conf;

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath('/rfqimages/admin/setup.php', 1);
	$head[$h][1] = $langs->trans('Settings');
	$head[$h][2] = 'settings';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'rfqimages@rfqimages');

	return $head;
}
