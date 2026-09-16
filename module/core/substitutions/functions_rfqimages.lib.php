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
 * \brief   Substitution key __RFQIMAGES_LINKS__ for price request and purchase order emails
 */

/**
 * __RFQIMAGES_LINKS__ is resolved by ActionsRfqImages::doActions() when the email is sent, because
 * creating the links makes the files public. Here the key is only:
 * - described, when listing keys for email templates (no object)
 * - kept as is in an open price request / purchase order email form, so it survives until send
 * - emptied anywhere else
 *
 * @param  array<string,string>     $substitutionarray Substitution array (modified)
 * @param  Translate                $outputlangs       Output language
 * @param  CommonObject|null        $object            Object
 * @param  array<string,mixed>|null $parameters        Parameters
 * @return void
 */
function rfqimages_completesubstitutionarray(&$substitutionarray, $outputlangs, $object = null, $parameters = null)
{
	dol_include_once('/rfqimages/class/actions_rfqimages.class.php');
	dol_include_once('/rfqimages/class/rfqimagesservice.class.php');

	$key = RfqImagesService::LINKS_KEY;
	$substitutionarray[$key] = '';

	if (!is_object($object)) {
		$mode = (is_array($parameters) && isset($parameters['mode'])) ? $parameters['mode'] : '';
		if (in_array($mode, array('formemail', 'formemailwithlines'), true) && is_object($outputlangs)) {
			$outputlangs->load('rfqimages@rfqimages');
			$substitutionarray[$key] = $outputlangs->trans('RfqImagesLinksKeyDesc');
		}
		return;
	}

	$prefix = empty($object->element) ? '' : ActionsRfqImages::prefixForElement($object->element);
	if ($prefix !== '' && ActionsRfqImages::isEnabledFor($prefix) && GETPOST('action', 'aZ09') !== 'send') {
		$substitutionarray[$key] = $key;
	}
}
