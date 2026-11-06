-- Copyright (C) 2026	Newe	<lobo@arcafin.fr>
--
-- This program is free software: you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation, either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program.  If not, see https://www.gnu.org/licenses/.


-- Rule proposing a match for the imported transactions meeting its conditions
CREATE TABLE llx_enablebanking_rule(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	entity INTEGER DEFAULT 1 NOT NULL,
	label VARCHAR(255) NOT NULL,
	active SMALLINT DEFAULT 1 NOT NULL,
	fk_bank_account INTEGER,					-- condition: bank account (NULL = all)
	direction VARCHAR(3),						-- condition: 'in' for credits, 'out' for debits, NULL for both
	counterparty VARCHAR(255),					-- condition: counterparty name contains
	iban VARCHAR(64),							-- condition: counterparty IBAN
	remittance VARCHAR(255),					-- condition: label contains all these words
	amount_min DOUBLE(24,8),					-- condition: absolute amount
	amount_max DOUBLE(24,8),
	action VARCHAR(16) NOT NULL,				-- 'thirdparty' (invoices of fk_soc), 'bankline' or 'tax'
	fk_soc INTEGER,
	fk_bank_categ INTEGER,
	payment_code VARCHAR(16),
	tax_type VARCHAR(16),						-- 'vat' or id of llx_c_chargesociales
	line_label VARCHAR(255),
	auto_validate SMALLINT DEFAULT 0 NOT NULL,	-- 1 = validated without review by the synchronisation
	date_creation DATETIME NOT NULL,
	tms TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat INTEGER,
	fk_user_modif INTEGER
) ENGINE=innodb;
