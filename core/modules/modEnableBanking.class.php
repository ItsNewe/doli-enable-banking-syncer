<?php
/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * 	\defgroup   enablebanking     Module EnableBanking
 *  \brief      Open Banking synchronisation of bank accounts through Enable Banking.
 *
 *  \file       htdocs/custom/enablebanking/core/modules/modEnableBanking.class.php
 *  \ingroup    enablebanking
 *  \brief      Description and activation file for module EnableBanking
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';


/**
 *  Description and activation class for module EnableBanking
 */
class modEnableBanking extends DolibarrModules
{
	/**
	 * Constructor. Define names, constants, directories, boxes, permissions
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;

		// Range 500000-599999 is for private development.
		//TODO: Reserve an id at https://wiki.dolibarr.org/index.php/List_of_modules_id before distributing.
		$this->numero = 517450;
		$this->rights_class = 'enablebanking';
		$this->family = "financial";
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "EnableBankingDescription";
		$this->descriptionlong = "EnableBankingDescription";
		$this->editor_name = 'Newe';
		$this->editor_url = '';
		$this->version = '1.0.3';
		$this->const_name = 'MAIN_MODULE_'.dol_strtoupper($this->name);
		$this->picto = 'bank_account';

		$this->module_parts = array(
			'triggers' => 0,
			'hooks' => array(),
			'moduleforexternal' => 0,
		);

		$this->dirs = array();
		$this->config_page_url = array("setup.php@enablebanking");

		$this->hidden = false;
		$this->depends = array('modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array("enablebanking@enablebanking");

		$this->phpmin = array(7, 2);
		$this->need_dolibarr_version = array(20, -3);
		$this->need_javascript_ajax = 0;

		// key, type, value, desc, visible, entity, deleteonunactive
		$this->const = array(
			1 => array('ENABLEBANKING_MATCH_DAYS', 'chaine', '5', 'Tolerance in days to match an existing bank line', 0, 'current', 0),
			2 => array('ENABLEBANKING_INITIAL_DAYS', 'chaine', '90', 'Number of days of history fetched on first synchronisation', 0, 'current', 0),
			3 => array('ENABLEBANKING_STATEMENT_FORMAT', 'chaine', '%Y%m', 'Format (dol_print_date) of the statement number set when reconciling', 0, 'current', 0),
			4 => array('ENABLEBANKING_PAYMENT_MODE', 'chaine', 'VIR', 'Default payment mode code for created payments', 0, 'current', 0),
			5 => array('ENABLEBANKING_SURE_SCORE', 'chaine', '65', 'Minimum score of a single candidate to be preselected', 0, 'current', 0),
		);

		if (!isModEnabled("enablebanking")) {
			$conf->enablebanking = new stdClass();
			$conf->enablebanking->enabled = 0;
		}

		$this->tabs = array();
		$this->tabs[] = array('data' => 'bank:+enablebanking:EnableBanking:enablebanking@enablebanking:$user->hasRight("enablebanking", "read"):/enablebanking/bankaccount_link.php?id=__ID__');

		$this->dictionaries = array();
		$this->boxes = array(
			0 => array('file' => 'box_enablebanking_transactions.php@enablebanking', 'note' => '', 'enabledbydefaulton' => 'Home'),
		);

		// 4 background fetches per day and per account are allowed by banks, so run every 6 hours by default.
		// Frequency and time are changed from the setup page of the module.
		$this->cronjobs = array(
			0 => array(
				'label' => 'EnableBankingCronSync',
				'jobtype' => 'method',
				'class' => '/enablebanking/class/enablebankingsync.class.php',
				'objectname' => 'EnableBankingSync',
				'method' => 'doScheduledJob',
				'parameters' => '',
				'comment' => 'EnableBankingCronSyncComment',
				'frequency' => 6,
				'unitfrequency' => 3600,
				'status' => 1,
				'test' => 'isModEnabled("enablebanking")',
				'priority' => 50,
			),
		);

		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 1);
		$this->rights[$r][1] = 'Read bank transactions imported from Enable Banking';
		$this->rights[$r][4] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 2);
		$this->rights[$r][1] = 'Connect or disconnect a bank account with Enable Banking';
		$this->rights[$r][4] = 'link';
		$r++;
		$this->rights[$r][0] = $this->numero.sprintf("%02d", 3);
		$this->rights[$r][1] = 'Validate matches (create payments, link and reconcile bank lines)';
		$this->rights[$r][4] = 'match';
		$r++;

		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=bank,fk_leftmenu=bank',
			'type' => 'left',
			'titre' => 'EnableBankingTransactionsToProcess',
			'mainmenu' => 'bank',
			'leftmenu' => 'enablebanking',
			'url' => '/enablebanking/transaction_list.php?mainmenu=bank&leftmenu=bank',
			'langs' => 'enablebanking@enablebanking',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("enablebanking")',
			'perms' => '$user->hasRight("enablebanking", "read")',
			'target' => '',
			'user' => 0,
		);
	}

	/**
	 *  Function called when module is enabled.
	 *
	 *  @param      string  $options    Options when enabling module ('', 'noboxes')
	 *  @return     int<-1,1>          	1 if OK, <=0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/enablebanking/sql/');
		if ($result < 0) {
			return -1;
		}

		$this->remove($options);

		$sql = array();
		return $this->_init($sql, $options);
	}

	/**
	 *	Function called when module is disabled.
	 *
	 *	@param	string		$options	Options when enabling module ('', 'noboxes')
	 *	@return	int<-1,1>				1 if OK, <=0 if KO
	 */
	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
