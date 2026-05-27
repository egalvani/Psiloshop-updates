(function () {
	function startPixgoPolling() {
		var poller = document.querySelector('.pixgo-payments-wc-poller');

		if (!poller || !window.PixgoPaymentsWC || !PixgoPaymentsWC.ajaxUrl) {
			return;
		}

		var orderId = poller.getAttribute('data-pixgo-order-id');
		var orderKey = poller.getAttribute('data-pixgo-order-key');
		var nonce = poller.getAttribute('data-pixgo-nonce');
		var statusText = document.querySelector('.pixgo-payments-wc-status-text');
		var approvedBox = document.querySelector('.pixgo-payments-wc-approved');
		var attempts = 0;
		var maxAttempts = 180;

		function showApprovedMessage() {
			if (statusText) {
				statusText.textContent = PixgoPaymentsWC.approvedText || 'Pagamento confirmado. Seu pedido foi aprovado.';
			}

			if (approvedBox) {
				approvedBox.hidden = false;

				var title = approvedBox.querySelector('h3');
				var paragraphs = approvedBox.querySelectorAll('p');

				if (title && PixgoPaymentsWC.approvedTitle) {
					title.textContent = PixgoPaymentsWC.approvedTitle;
				}

				if (paragraphs[1] && PixgoPaymentsWC.approvedText) {
					paragraphs[1].textContent = PixgoPaymentsWC.approvedText;
				}

				if (paragraphs[2] && PixgoPaymentsWC.trackingNotice) {
					paragraphs[2].textContent = PixgoPaymentsWC.trackingNotice;
				}

				approvedBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
			}
		}

		function poll() {
			attempts += 1;

			var body = new URLSearchParams();
			body.set('action', 'pixgo_payments_wc_poll_order');
			body.set('order_id', orderId);
			body.set('order_key', orderKey);
			body.set('nonce', nonce);

			fetch(PixgoPaymentsWC.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
				},
				body: body.toString()
			}).then(function (response) {
				return response.json();
			}).then(function (payload) {
				if (payload && payload.success && payload.data && payload.data.paid) {
					showApprovedMessage();
					window.setTimeout(function () {
						window.location.reload();
					}, 5000);
					return;
				}

				if (attempts < maxAttempts) {
					window.setTimeout(poll, attempts < 12 ? 5000 : 10000);
				}
			}).catch(function () {
				if (attempts < maxAttempts) {
					window.setTimeout(poll, 10000);
				}
			});
		}

		window.setTimeout(poll, 3000);
	}

	startPixgoPolling();

	function startPixgoAlertModal() {
		var modal = document.querySelector('[data-pixgo-fraud-alert]');
		var poller = document.querySelector('.pixgo-payments-wc-poller');

		if (!modal || !poller) {
			return;
		}

		var orderId = poller.getAttribute('data-pixgo-order-id') || 'current';
		var storageKey = 'pixgo_payments_wc_alert_seen_' + orderId;
		var dialog = modal.querySelector('.pixgo-payments-wc-modal-dialog');
		var closeButtons = modal.querySelectorAll('[data-pixgo-alert-close]');

		try {
			if (window.sessionStorage && sessionStorage.getItem(storageKey)) {
				return;
			}
		} catch (error) {}

		function openModal() {
			modal.hidden = false;
			document.documentElement.classList.add('pixgo-payments-wc-modal-open');

			if (dialog) {
				dialog.focus();
			}
		}

		function closeModal() {
			modal.hidden = true;
			document.documentElement.classList.remove('pixgo-payments-wc-modal-open');

			try {
				if (window.sessionStorage) {
					sessionStorage.setItem(storageKey, 'yes');
				}
			} catch (error) {}
		}

		closeButtons.forEach(function (button) {
			button.addEventListener('click', closeModal);
		});

		document.addEventListener('keydown', function (event) {
			if ('Escape' === event.key && !modal.hidden) {
				closeModal();
			}
		});

		window.setTimeout(openModal, 450);
	}

	startPixgoAlertModal();

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-pixgo-copy]');

		if (!button) {
			return;
		}

		var field = document.querySelector(button.getAttribute('data-pixgo-copy'));

		if (!field) {
			return;
		}

		var text = field.value || '';
		var original = button.textContent;

		function markCopied() {
			button.textContent = 'Codigo copiado';
			window.setTimeout(function () {
				button.textContent = original;
			}, 1800);
		}

		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(text).then(markCopied).catch(function () {
				field.select();
				document.execCommand('copy');
				markCopied();
			});
			return;
		}

		field.select();
		document.execCommand('copy');
		markCopied();
	});
}());
