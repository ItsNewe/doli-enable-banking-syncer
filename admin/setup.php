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
 * \file    htdocs/custom/enablebanking/admin/setup.php
 * \ingroup enablebanking
 * \brief   EnableBanking setup page.
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

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/cron/class/cronjob.class.php';
dol_include_once('/enablebanking/lib/enablebanking.lib.php');
dol_include_once('/enablebanking/class/enablebankingclient.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var HookManager $hookmanager
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array("admin", "banks", "cron", "enablebanking@enablebanking"));

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');

if (!$user->admin) {
	accessforbidden();
}

$hookmanager->initHooks(array('enablebankingsetup', 'globalsetup'));

// Schedules offered for the synchronisation, in seconds. Banks allow 4 unattended fetches per day and per account.
$scheduleoptions = array(
	0 => $langs->trans('Disabled'),
	21600 => $langs->trans('EnableBankingEveryNHours', 6),
	43200 => $langs->trans('EnableBankingEveryNHours', 12),
	86400 => $langs->trans('EnableBankingDaily'),
	604800 => $langs->trans('EnableBankingWeekly'),
);


/*
 * Actions
 */

if ($action == 'update') {
	$error = 0;
	$values = array(
		'ENABLEBANKING_APPLICATION_ID' => trim(GETPOST('ENABLEBANKING_APPLICATION_ID', 'alphanohtml')),
		'ENABLEBANKING_PAYMENT_MODE' => GETPOST('ENABLEBANKING_PAYMENT_MODE', 'aZ09'),
		'ENABLEBANKING_MATCH_DAYS' => (string) max(0, GETPOSTINT('ENABLEBANKING_MATCH_DAYS')),
		'ENABLEBANKING_INITIAL_DAYS' => (string) max(1, GETPOSTINT('ENABLEBANKING_INITIAL_DAYS')),
		'ENABLEBANKING_SURE_SCORE' => (string) max(0, GETPOSTINT('ENABLEBANKING_SURE_SCORE')),
		'ENABLEBANKING_STATEMENT_FORMAT' => GETPOST('ENABLEBANKING_STATEMENT_FORMAT', 'alphanohtml'),
	);
	if ($values['ENABLEBANKING_STATEMENT_FORMAT'] === '') {
		$values['ENABLEBANKING_STATEMENT_FORMAT'] = '%Y%m';
	}
	foreach ($values as $name => $value) {
		if (dolibarr_set_const($db, $name, $value, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	// The private key is never displayed back: keep the stored one when the field is left empty.
	// Name ends with _KEY so dolibarr_set_const() stores it encrypted.
	$privatekey = trim(GETPOST('ENABLEBANKING_PRIVATE_KEY', 'restricthtml'));
	// A .pem file sent with the form takes precedence over the pasted key
	$keyfile = isset($_FILES['ENABLEBANKING_PRIVATE_KEY_FILE']) ? $_FILES['ENABLEBANKING_PRIVATE_KEY_FILE'] : null;
	if (is_array($keyfile) && isset($keyfile['error']) && $keyfile['error'] != UPLOAD_ERR_NO_FILE) {
		if ($keyfile['error'] != UPLOAD_ERR_OK || !is_uploaded_file($keyfile['tmp_name']) || $keyfile['size'] > 16384) {
			setEventMessages($langs->trans('EnableBankingErrorKeyFile'), null, 'errors');
			$error++;
			$privatekey = '';
		} else {
			$privatekey = trim((string) file_get_contents($keyfile['tmp_name']));
		}
	}
	if ($privatekey !== '') {
		if (openssl_pkey_get_private($privatekey) === false) {
			setEventMessages($langs->trans('EnableBankingErrorBadPrivateKey'), null, 'errors');
			$error++;
		} elseif (dolibarr_set_const($db, 'ENABLEBANKING_PRIVATE_KEY', $privatekey, 'chaine', 0, '', $conf->entity) < 0) {
			$error++;
		}
	}

	if (!$error) {
		setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	} else {
		setEventMessages($langs->trans("Error"), null, 'errors');
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'updateschedule') {
	$cronjob = enablebankingGetCronJob($db);
	$interval = GETPOSTINT('sync_interval');
	if (!$cronjob) {
		setEventMessages($langs->trans('EnableBankingErrorNoCronJob'), null, 'errors');
	} elseif (!isset($scheduleoptions[$interval])) {
		setEventMessages($langs->trans('ErrorBadValueForParameter', $interval, 'sync_interval'), null, 'errors');
	} else {
		$cronjob->status = $interval ? Cronjob::STATUS_ENABLED : Cronjob::STATUS_DISABLED;
		if ($interval) {
			$time = explode(':', GETPOST('sync_time', 'alphanohtml').':');
			$hour = min(23, max(0, (int) $time[0]));
			$minute = min(59, max(0, (int) $time[1]));
			$cronjob->unitfrequency = (string) ($interval >= 604800 ? 604800 : ($interval >= 86400 ? 86400 : 3600));
			$cronjob->frequency = (int) ($interval / (int) $cronjob->unitfrequency);
			// Core reprogramming keeps this alignment: next runs are this date plus multiples of the frequency
			$cronjob->datestart = enablebankingNextRun($interval, $hour, $minute, GETPOSTINT('sync_weekday'), dol_now());
			$cronjob->datenextrun = $cronjob->datestart;
		}
		if ($cronjob->update($user) < 0) {
			setEventMessages($cronjob->error, $cronjob->errors, 'errors');
		} else {
			setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'removekey') {
	dolibarr_del_const($db, 'ENABLEBANKING_PRIVATE_KEY', $conf->entity);
	setEventMessages($langs->trans("SetupSaved"), null, 'mesgs');
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}

if ($action == 'testconnection') {
	$client = new EnableBankingClient($db);
	$application = $client->getApplication();
	if ($application === false) {
		setEventMessages($langs->trans('EnableBankingTestKo').' '.$langs->trans($client->error), null, 'errors');
	} else {
		$msg = $langs->trans('EnableBankingTestOk');
		if (!empty($application['name'])) {
			$msg .= ' '.dolPrintHTML($application['name']);
		}
		if (!empty($application['environment'])) {
			$msg .= ' ('.dolPrintHTML($application['environment']).')';
		}
		setEventMessages($msg, null, 'mesgs');
		if (!empty($application['redirect_urls']) && is_array($application['redirect_urls']) && !in_array(enablebankingGetCallbackUrl(), $application['redirect_urls'])) {
			setEventMessages($langs->trans('EnableBankingWarningCallbackNotRegistered', enablebankingGetCallbackUrl()), null, 'warnings');
		}
	}
	header("Location: ".$_SERVER["PHP_SELF"]);
	exit;
}


/*
 * View
 */

$form = new Form($db);

$title = "EnableBankingSetup";
llxHeader('', $langs->trans($title), '', '', 0, 0, '', '', '', 'mod-enablebanking page-admin_setup');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans("BackToModuleList"), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans("BackToModuleList").'</span></a>';
print load_fiche_titre($langs->trans($title), $linkback, 'title_setup');

$head = enablebankingAdminPrepareHead();
print dol_get_fiche_head($head, 'settings', $langs->trans($title), -1, 'bank_account');

print '<span class="opacitymedium">'.$langs->trans("EnableBankingSetupHelp").'</span><br><br>';

print '<div class="info">'.$langs->trans('EnableBankingCallbackUrl').': <strong>'.dolPrintHTML(enablebankingGetCallbackUrl()).'</strong>';
print '<br>'.$langs->trans('EnableBankingCallbackUrlHelp').'</div>';

print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$form->textwithpicto($langs->trans("EnableBankingApplicationId"), $langs->trans("EnableBankingApplicationIdHelp")).'</td>';
print '<td><input type="text" class="minwidth400" name="ENABLEBANKING_APPLICATION_ID" value="'.dolPrintHTMLForAttribute(getDolGlobalString('ENABLEBANKING_APPLICATION_ID')).'"></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired tdtop">'.$form->textwithpicto($langs->trans("EnableBankingPrivateKey"), $langs->trans("EnableBankingPrivateKeyHelp")).'</td><td>';
if (getDolGlobalString('ENABLEBANKING_PRIVATE_KEY')) {
	print img_picto('', 'tick', 'class="pictofixedwidth"').$langs->trans('EnableBankingPrivateKeyStored');
	print ' <a class="reposition" href="'.$_SERVER["PHP_SELF"].'?action=removekey&token='.newToken().'">'.img_delete().'</a><br>';
}
print $langs->trans('EnableBankingPrivateKeyFile').' <input type="file" name="ENABLEBANKING_PRIVATE_KEY_FILE" accept=".pem,.key,.txt">';
print '<br><span class="opacitymedium">'.$langs->trans('EnableBankingPrivateKeyOrPaste').'</span><br>';
print '<textarea name="ENABLEBANKING_PRIVATE_KEY" class="quatrevingtpercent" rows="5" autocomplete="off" placeholder="-----BEGIN PRIVATE KEY-----"></textarea></td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans("EnableBankingPaymentMode"), $langs->trans("EnableBankingPaymentModeHelp")).'</td><td>';
$form->select_types_paiements(getDolGlobalString('ENABLEBANKING_PAYMENT_MODE', 'VIR'), 'ENABLEBANKING_PAYMENT_MODE', '', 2, 0);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans("EnableBankingMatchDays"), $langs->trans("EnableBankingMatchDaysHelp")).'</td>';
print '<td><input type="number" min="0" class="width75" name="ENABLEBANKING_MATCH_DAYS" value="'.getDolGlobalInt('ENABLEBANKING_MATCH_DAYS', 5).'"></td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans("EnableBankingInitialDays"), $langs->trans("EnableBankingInitialDaysHelp")).'</td>';
print '<td><input type="number" min="1" class="width75" name="ENABLEBANKING_INITIAL_DAYS" value="'.getDolGlobalInt('ENABLEBANKING_INITIAL_DAYS', 90).'"></td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans("EnableBankingSureScore"), $langs->trans("EnableBankingSureScoreHelp")).'</td>';
print '<td><input type="number" min="0" class="width75" name="ENABLEBANKING_SURE_SCORE" value="'.getDolGlobalInt('ENABLEBANKING_SURE_SCORE', 65).'"></td></tr>';

print '<tr class="oddeven"><td>'.$form->textwithpicto($langs->trans("EnableBankingStatementFormat"), $langs->trans("EnableBankingStatementFormatHelp")).'</td>';
print '<td><input type="text" class="width100" name="ENABLEBANKING_STATEMENT_FORMAT" value="'.dolPrintHTMLForAttribute(getDolGlobalString('ENABLEBANKING_STATEMENT_FORMAT', '%Y%m')).'">';
print ' <span class="opacitymedium">'.$langs->trans('Example').': '.dolPrintHTML(enablebankingGetStatementNumber(dol_now())).'</span></td></tr>';

print '</table>';
print '</div>';

print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
print '</form>';

print '<br><div class="tabsAction">';
print dolGetButtonAction('', $langs->trans('EnableBankingTestConnection'), 'default', $_SERVER["PHP_SELF"].'?action=testconnection&token='.newToken(), '', getDolGlobalString('ENABLEBANKING_APPLICATION_ID') && getDolGlobalString('ENABLEBANKING_PRIVATE_KEY'));
print '</div>';

// Automatic synchronisation: frequency and time of the scheduled job of the module
print load_fiche_titre($langs->trans('EnableBankingSchedule'), '', '');
$cronjob = enablebankingGetCronJob($db);
if (!isModEnabled('cron')) {
	print '<div class="warning">'.$langs->trans('EnableBankingWarningCronDisabled').'</div>';
}
if (!$cronjob) {
	print '<div class="error">'.$langs->trans('EnableBankingErrorNoCronJob').'</div>';
} else {
	$interval = 0;
	if ((int) $cronjob->status === Cronjob::STATUS_ENABLED) {
		$interval = (int) $cronjob->frequency * (int) $cronjob->unitfrequency;
		if (!isset($scheduleoptions[$interval])) {
			// Frequency set in the scheduled job screen: keep it visible
			$scheduleoptions[$interval] = $langs->trans('EnableBankingCustomSchedule', $cronjob->frequency, $cronjob->unitfrequency);
		}
	}
	$start = $cronjob->datestart ? $cronjob->datestart : $cronjob->datenextrun;
	$weekdays = array();
	for ($d = 1; $d <= 7; $d++) {
		$weekdays[$d % 7] = $langs->trans('Day'.($d % 7));
	}

	print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="updateschedule">';
	print '<div class="div-table-responsive-no-min">';
	print '<table class="noborder centpercent">';
	print '<tr class="liste_titre"><td>'.$langs->trans("Parameter").'</td><td>'.$langs->trans("Value").'</td></tr>';
	print '<tr class="oddeven"><td class="titlefieldcreate">'.$form->textwithpicto($langs->trans("EnableBankingScheduleFrequency"), $langs->trans("EnableBankingScheduleFrequencyHelp")).'</td>';
	print '<td>'.$form->selectarray('sync_interval', $scheduleoptions, $interval, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
	print ' <span id="sync_time_span">'.$langs->trans('EnableBankingScheduleAt').' <input type="time" name="sync_time" value="'.($start ? dol_print_date($start, '%H:%M', 'tzuserrel') : '07:00').'"></span>';
	print ' <span id="sync_weekday_span">'.$langs->trans('EnableBankingScheduleOn').' '.$form->selectarray('sync_weekday', $weekdays, $start ? (int) dol_print_date($start, '%w', 'tzuserrel') : 1).'</span>';
	print '</td></tr>';
	if ((int) $cronjob->status === Cronjob::STATUS_ENABLED) {
		print '<tr class="oddeven"><td>'.$langs->trans('CronDtNextLaunch').'</td><td>'.dol_print_date($cronjob->datenextrun, 'dayhour', 'tzuserrel').'</td></tr>';
	}
	print '<tr class="oddeven"><td>'.$langs->trans('CronDtLastLaunch').'</td><td>'.($cronjob->datelastrun ? dol_print_date($cronjob->datelastrun, 'dayhour', 'tzuserrel') : $langs->trans('None'));
	if ($cronjob->datelastrun) {
		print ' '.($cronjob->lastresult ? img_error(dolPrintHTMLForAttribute($cronjob->lastoutput)) : img_picto(dolPrintHTMLForAttribute($cronjob->lastoutput), 'tick'));
	}
	print ' - '.$cronjob->getNomUrl(1).'</td></tr>';
	print '</table>';
	print '</div>';
	print '<span class="opacitymedium">'.$langs->trans('EnableBankingScheduleHelp').'</span>';
	print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans("Save").'"></div>';
	print '</form>';
	print '<script src="'.dol_buildpath('/enablebanking/js/setup.js', 1).'"></script>';
}

print dol_get_fiche_end();

llxFooter();
$db->close();
