<?php
/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/enablebanking/bankaccount_link.php
 * \ingroup enablebanking
 * \brief   Tab "Enable Banking" of a bank account: connection to the bank and synchronisation
 */

// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include str_replace("..", "", $_SERVER["CONTEXT_DOCUMENT_ROOT"])."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
$j = strlen($tmp2) - 1;  // @phan-suppress-current-line DolibarrForbiddenFunctionPlugin
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/bank.lib.php';
dol_include_once('/enablebanking/lib/enablebanking.lib.php');
dol_include_once('/enablebanking/class/enablebankingclient.class.php');
dol_include_once('/enablebanking/class/ebaccount.class.php');
dol_include_once('/enablebanking/class/enablebankingsync.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("banks", "companies", "enablebanking@enablebanking"));

$id = GETPOSTINT('id');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');

// Security check
if ($user->socid) {
	accessforbidden();
}
$result = restrictedArea($user, 'banque', $id, 'bank_account&bank_account', '', '', 'rowid');
if (!isModEnabled('enablebanking') || !$user->hasRight('enablebanking', 'read')) {
	accessforbidden();
}
$permissiontolink = $user->hasRight('enablebanking', 'link');
$permissiontosync = $user->hasRight('enablebanking', 'link') || $user->hasRight('enablebanking', 'match');

$object = new Account($db);
if ($object->fetch($id) <= 0) {
	accessforbidden('', 0, 0, 1);
}

$ebaccount = new EbAccount($db);
$haslink = ($ebaccount->fetchByBankAccount($object->id) > 0);
$client = new EnableBankingClient($db);

$hookmanager->initHooks(array('enablebankingbankaccountlink', 'globalcard'));

$selfurl = $_SERVER["PHP_SELF"].'?id='.$object->id;


/*
 * Actions
 */

$error = 0;

// Start the authorisation at the bank (new link or renewal of consent)
if ($action == 'startauth' && $permissiontolink) {
	$country = GETPOST('country', 'aZ09');
	$aspspname = GETPOST('aspsp_name', 'alphanohtml');
	$psutype = (GETPOST('psu_type', 'aZ09') == 'personal' ? 'personal' : 'business');
	if (!$haslink && $country === '' && $aspspname === '') {
		$action = '';
	} elseif ($haslink && $aspspname === '') {
		// Renewal: same bank
		$country = $ebaccount->aspsp_country;
		$aspspname = $ebaccount->aspsp_name;
		$psutype = $ebaccount->psu_type;
	}

	if ($action == 'startauth') {
		// Validity of consent: maximum allowed by the bank
		$maxvalidity = 180 * 86400;
		$aspsps = $client->getAspsps($country, $psutype);
		if ($aspsps === false) {
			setEventMessages($langs->trans($client->error), null, 'errors');
			$error++;
		} else {
			$found = false;
			foreach ($aspsps as $aspsp) {
				if (isset($aspsp['name']) && $aspsp['name'] === $aspspname) {
					$found = true;
					if (!empty($aspsp['maximum_consent_validity'])) {
						$maxvalidity = (int) $aspsp['maximum_consent_validity'];
					}
				}
			}
			if (!$found) {
				setEventMessages($langs->trans('EnableBankingErrorBankNotFound'), null, 'errors');
				$error++;
			}
		}

		if (!$error) {
			$state = bin2hex(random_bytes(16));
			if (!$haslink) {
				$ebaccount->entity = $conf->entity;
				$ebaccount->fk_bank_account = $object->id;
				$ebaccount->status = EbAccount::STATUS_PENDING;
			}
			$ebaccount->aspsp_name = $aspspname;
			$ebaccount->aspsp_country = $country;
			$ebaccount->psu_type = $psutype;
			$ebaccount->auth_state = $state;
			$result = $haslink ? $ebaccount->update($user) : $ebaccount->create($user);
			if ($result < 0) {
				setEventMessages($ebaccount->error, $ebaccount->errors, 'errors');
				$error++;
			}
		}

		if (!$error) {
			// Keep a margin of one hour under the maximum validity
			$validuntil = dol_now() + max(3600, $maxvalidity - 3600);
			$auth = $client->startAuthorization($aspspname, $country, $psutype, $state, $validuntil, enablebankingGetCallbackUrl(), substr($langs->defaultlang, 0, 2));
			if ($auth === false || empty($auth['url'])) {
				setEventMessages($langs->trans('EnableBankingErrorAuth').' '.$client->error, null, 'errors');
				$error++;
			} else {
				header("Location: ".$auth['url']);
				exit;
			}
		}
		$action = $haslink ? '' : 'displaybanks';
	}
}

// Synchronise now (the user is present, PSU headers are sent)
if ($action == 'sync' && $permissiontosync && $haslink) {
	$sync = new EnableBankingSync($db);
	$result = $sync->syncAccount($ebaccount, $user, enablebankingGetPsuHeaders());
	if ($result < 0) {
		setEventMessages($langs->trans('EnableBankingSyncKo').' '.$sync->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('EnableBankingNbImported', $result), null, 'mesgs');
		if ($sync->error) {
			setEventMessages($sync->error, null, 'warnings');
		}
	}
	header("Location: ".$selfurl);
	exit;
}

// Disconnect: close the session at Enable Banking, keep imported transactions
if ($action == 'confirm_disconnect' && $confirm == 'yes' && $permissiontolink && $haslink) {
	if (!empty($ebaccount->session_id) && !$client->deleteSession($ebaccount->session_id)) {
		// The session may already be expired at the bank: go on
		setEventMessages($client->error, null, 'warnings');
	}
	$ebaccount->status = EbAccount::STATUS_DISCONNECTED;
	$ebaccount->session_id = '';
	$ebaccount->auth_state = '';
	if ($ebaccount->update($user) < 0) {
		setEventMessages($ebaccount->error, $ebaccount->errors, 'errors');
	} else {
		setEventMessages($langs->trans('EnableBankingDisconnected'), null, 'mesgs');
	}
	header("Location: ".$selfurl);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = $langs->trans("FinancialAccount").' - '.$langs->trans("EnableBanking");
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-enablebanking page-bankaccount_link');

$head = bank_prepare_head($object);
print dol_get_fiche_head($head, 'enablebanking', $langs->trans("FinancialAccount"), -1, 'account');

$linkback = '<a href="'.DOL_URL_ROOT.'/compta/bank/list.php?restore_lastsearch_values=1">'.$langs->trans("BackToList").'</a>';
dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', '', '&id='.$object->id);

print '<div class="fichecenter"><div class="underbanner clearboth"></div>';

if ($action == 'disconnect') {
	print $form->formconfirm($selfurl, $langs->trans('EnableBankingDisconnect'), $langs->trans('EnableBankingConfirmDisconnect'), 'confirm_disconnect', '', 0, 1);
}

if (!$client->isConfigured()) {
	print '<div class="warning">'.$langs->trans('EnableBankingErrorNotConfigured');
	if ($user->admin) {
		print ' <a href="'.dol_buildpath('/enablebanking/admin/setup.php', 1).'">'.$langs->trans('Setup').'</a>';
	}
	print '</div>';
} elseif (!$haslink || in_array((int) $ebaccount->status, array(EbAccount::STATUS_PENDING, EbAccount::STATUS_DISCONNECTED)) || $action == 'displaybanks') {
	// Connection form
	if ($haslink && (int) $ebaccount->status === EbAccount::STATUS_DISCONNECTED) {
		print '<div class="opacitymedium">'.$langs->trans('EnableBankingAccountDisconnected').'</div><br>';
	}
	if (!$permissiontolink) {
		print '<div class="opacitymedium">'.$langs->trans('EnableBankingNotLinked').'</div>';
	} else {
		$country = GETPOST('country', 'aZ09') ? GETPOST('country', 'aZ09') : ($haslink && $ebaccount->aspsp_country ? $ebaccount->aspsp_country : $mysoc->country_code);
		$psutype = GETPOST('psu_type', 'aZ09') ? GETPOST('psu_type', 'aZ09') : 'business';

		print load_fiche_titre($langs->trans('EnableBankingConnectBank'), '', '');
		print '<span class="opacitymedium">'.$langs->trans('EnableBankingConnectHelp').'</span><br><br>';

		// Step 1: country and type of account
		print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
		print '<input type="hidden" name="id" value="'.$object->id.'">';
		print '<input type="hidden" name="action" value="displaybanks">';
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">'.$langs->trans('Country').'</td><td>'.$form->select_country($country, 'country', '', 0, 'minwidth200', 'code2').'</td></tr>';
		print '<tr><td>'.$langs->trans('EnableBankingPsuType').'</td><td>';
		print $form->selectarray('psu_type', array('business' => $langs->trans('EnableBankingPsuBusiness'), 'personal' => $langs->trans('EnableBankingPsuPersonal')), $psutype);
		print ' <input type="submit" class="button smallpaddingimp" value="'.$langs->trans('EnableBankingShowBanks').'">';
		print '</td></tr>';
		print '</table>';
		print '</form>';

		// Step 2: bank
		if ($action == 'displaybanks' && $country) {
			$aspsps = $client->getAspsps($country, $psutype);
			if ($aspsps === false) {
				print '<div class="error">'.dolPrintHTML($client->error).'</div>';
			} elseif (empty($aspsps)) {
				print '<div class="warning">'.$langs->trans('EnableBankingNoBank').'</div>';
			} else {
				$options = array();
				foreach ($aspsps as $aspsp) {
					if (!empty($aspsp['name'])) {
						$options[$aspsp['name']] = $aspsp['name'];
					}
				}
				asort($options);
				print '<br><form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="id" value="'.$object->id.'">';
				print '<input type="hidden" name="action" value="startauth">';
				print '<input type="hidden" name="country" value="'.dolPrintHTMLForAttribute($country).'">';
				print '<input type="hidden" name="psu_type" value="'.dolPrintHTMLForAttribute($psutype).'">';
				print '<table class="border centpercent tableforfield">';
				print '<tr><td class="titlefield">'.$langs->trans('EnableBankingBank').'</td><td>';
				print $form->selectarray('aspsp_name', $options, ($haslink ? $ebaccount->aspsp_name : ''), 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
				print ajax_combobox('aspsp_name');
				print ' <input type="submit" class="button" value="'.$langs->trans('EnableBankingConnect').'">';
				print '</td></tr>';
				print '</table>';
				print '</form>';
			}
		}
	}
} else {
	// Linked account
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('Status').'</td><td>'.$ebaccount->getLibStatut(4).'</td></tr>';
	print '<tr><td>'.$langs->trans('EnableBankingBank').'</td><td>'.dolPrintHTML($ebaccount->aspsp_name).' ('.dolPrintHTML($ebaccount->aspsp_country).')</td></tr>';
	print '<tr><td>'.$langs->trans('EnableBankingRemoteAccount').'</td><td>'.dolPrintHTML($ebaccount->account_name).' - '.dolPrintHTML($ebaccount->iban).' '.dolPrintHTML($ebaccount->currency).'</td></tr>';
	print '<tr><td>'.$langs->trans('EnableBankingConsentValidUntil').'</td><td>'.dol_print_date($ebaccount->valid_until, 'dayhour');
	if (!empty($ebaccount->valid_until) && $ebaccount->valid_until < dol_now() + 15 * 86400) {
		print ' '.img_warning($langs->trans('EnableBankingConsentExpiresSoon')).' <span class="warning">'.$langs->trans('EnableBankingConsentExpiresSoon').'</span>';
	}
	print '</td></tr>';
	print '<tr><td>'.$langs->trans('EnableBankingLastSync').'</td><td>'.dol_print_date($ebaccount->date_last_sync, 'dayhour').'</td></tr>';
	if (!empty($ebaccount->last_error)) {
		print '<tr><td>'.$langs->trans('Error').'</td><td><span class="error">'.dolPrintHTML($ebaccount->last_error).'</span></td></tr>';
	}
	if ($ebaccount->isConsentValid() && $action != 'disconnect') {
		$balances = $client->getBalances($ebaccount->account_uid, enablebankingGetPsuHeaders());
		print '<tr><td>'.$langs->trans('EnableBankingBankBalance').'</td><td>';
		if ($balances === false) {
			print '<span class="opacitymedium">'.dolPrintHTML($client->error).'</span>';
		} elseif (!empty($balances)) {
			$balance = $balances[0];
			print '<span class="amount">'.price((float) $balance['balance_amount']['amount'], 0, $langs, 1, -1, -1, $balance['balance_amount']['currency']).'</span>';
			print ' <span class="opacitymedium">('.dolPrintHTML($balance['balance_type'] ?? '').(!empty($balance['reference_date']) ? ' '.dolPrintHTML($balance['reference_date']) : '').')</span>';
		}
		print ' &nbsp; <span class="opacitymedium">'.$langs->trans('EnableBankingDolibarrBalance').':</span> '.price($object->solde(1), 0, $langs, 1, -1, -1, $object->currency_code);
		print '</td></tr>';
	}
	print '</table>';

	print '<div class="tabsAction">';
	if ($ebaccount->isConsentValid()) {
		print dolGetButtonAction('', $langs->trans('EnableBankingSyncNow'), 'default', $selfurl.'&action=sync&token='.newToken(), '', $permissiontosync);
	}
	print dolGetButtonAction('', $langs->trans('EnableBankingTransactions'), 'default', dol_buildpath('/enablebanking/transaction_list.php', 1).'?search_fk_bank_account='.$object->id, '', true);
	print dolGetButtonAction('', $langs->trans('EnableBankingRenewConsent'), 'default', $selfurl.'&action=startauth&token='.newToken(), '', $permissiontolink);
	print dolGetButtonAction('', $langs->trans('EnableBankingChangeBank'), 'default', $selfurl.'&action=displaybanks&country='.urlencode($ebaccount->aspsp_country), '', $permissiontolink);
	print dolGetButtonAction('', $langs->trans('EnableBankingDisconnect'), 'delete', $selfurl.'&action=disconnect&token='.newToken(), '', $permissiontolink);
	print '</div>';
}

print '</div>';

print dol_get_fiche_end();

llxFooter();
$db->close();
