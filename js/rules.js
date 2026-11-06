/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/* Rule form: show only the fields used by the chosen action */
$(function () {
	function refreshAction() {
		var action = $('#ruleaction').val();
		$('tr.ruleaction').hide();
		$('tr.ruleaction_' + action).show();
	}
	$('#ruleaction').on('change', refreshAction);
	refreshAction();
});
