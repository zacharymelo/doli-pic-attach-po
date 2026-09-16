<?php
/* Copyright (C) 2026 Digital Properties Works
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file    core/substitutions/functions_rfqimages.lib.php
 * \ingroup rfqimages
 * \brief   Substitution key __RFQIMAGES_LINKS__ for email templates
 */

/**
 * Add __RFQIMAGES_LINKS__. Empty unless share links were prepared for this price request's or purchase order's email form.
 *
 * @param  array<string,string> $substitutionarray Substitution array (modified)
 * @param  Translate            $outputlangs       Output language
 * @param  CommonObject|null    $object            Object
 * @param  mixed                $parameters        Parameters
 * @return void
 */
function rfqimages_completesubstitutionarray(&$substitutionarray, $outputlangs, $object = null, $parameters = null)
{
	$substitutionarray['__RFQIMAGES_LINKS__'] = '';

	if (!is_object($object) || empty($object->id) || empty($object->element)) {
		return;
	}
	dol_include_once('/rfqimages/class/actions_rfqimages.class.php');
	$prefix = ActionsRfqImages::prefixForElement($object->element);
	if ($prefix === '') {
		return;
	}
	$key = ActionsRfqImages::sessionKey($prefix.$object->id);
	if (empty($_SESSION[$key])) {
		return;
	}
	$data = json_decode($_SESSION[$key], true);
	if (empty($data['links'])) {
		return;
	}

	dol_include_once('/rfqimages/class/rfqimagesservice.class.php');
	$message = GETPOSTISSET('message') ? GETPOST('message', 'restricthtml') : '';
	$html = $message !== '' ? dol_textishtml($message) : (bool) getDolGlobalInt('FCKEDITOR_ENABLE_MAIL');
	$substitutionarray['__RFQIMAGES_LINKS__'] = RfqImagesService::buildLinksBlock($data['links'], $html);
}
