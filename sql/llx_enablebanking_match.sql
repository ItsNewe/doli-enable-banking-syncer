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


-- Candidates proposed to match an imported transaction
CREATE TABLE llx_enablebanking_match(
	rowid INTEGER AUTO_INCREMENT PRIMARY KEY,
	fk_transaction INTEGER NOT NULL,
	element_type VARCHAR(32) NOT NULL,			-- 'facture', 'invoice_supplier' or 'bankline'
	element_ids VARCHAR(255) NOT NULL,			-- comma separated ids (several invoices of the same third party)
	fk_soc INTEGER,
	score INTEGER DEFAULT 0 NOT NULL,
	label VARCHAR(255)
) ENGINE=innodb;
