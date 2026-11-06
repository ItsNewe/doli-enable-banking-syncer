/* Copyright (C) 2026	Newe	<lobo@arcafin.fr>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/* Setup of the synchronisation schedule: the time is for any schedule, the day of week only for a weekly one */
$(function () {
	function refreshSchedule() {
		var interval = parseInt($('#sync_interval').val(), 10);
		$('#sync_time_span').toggle(interval > 0);
		$('#sync_weekday_span').toggle(interval >= 604800);
	}
	$('#sync_interval').on('change', refreshSchedule);
	refreshSchedule();
});
