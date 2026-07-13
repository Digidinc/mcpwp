(function () {
	'use strict';
	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-mcpwp-copy]');
		if (!button) return;
		var target = document.querySelector(button.getAttribute('data-mcpwp-copy'));
		if (target && navigator.clipboard) navigator.clipboard.writeText(target.textContent);
	});
}());
