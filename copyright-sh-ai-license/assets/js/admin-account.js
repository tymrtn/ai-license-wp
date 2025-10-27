(function () {
	const config = window.CSHAccount || {};
	const container = document.querySelector('[data-csh-ai-account]');

	if (!container || !config.ajaxUrl || !config.nonce) {
		return;
	}

	const feedback = container.querySelector('[data-feedback]');
	const states = container.querySelectorAll('[data-state]');
	const emailField = container.querySelector('input[type="email"]');
	let pendingCheckTimer = null;

	function setStatus(status) {
		container.dataset.status = status;
		states.forEach((element) => {
			element.hidden = element.dataset.state !== status;
		});
	}

	function setEmail(value) {
		container.dataset.email = value || '';
		if (emailField) {
			emailField.value = value || '';
		}
	}

	function showMessage(message, type = 'info') {
		if (!feedback) {
			return;
		}
		feedback.textContent = message || '';
		feedback.dataset.type = type;
	}

	function request(action, payload = {}) {
		const body = new window.FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);
		Object.keys(payload).forEach((key) => {
			if (payload[key] !== undefined && payload[key] !== null) {
				body.append(key, payload[key]);
			}
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		})
			.then((response) =>
				response.json().catch(() => ({})).then((data) => ({
					ok: response.ok,
					status: response.status,
					data,
				}))
			)
			.catch((error) => ({
				ok: false,
				status: 500,
				data: { message: error.message },
			}));
	}

	function scheduleStatusCheck(delayMs) {
		if (pendingCheckTimer) {
			window.clearTimeout(pendingCheckTimer);
		}
		pendingCheckTimer = window.setTimeout(() => {
			handleCheckStatus();
		}, delayMs);
	}

	function handleConnect() {
		const emailValue = emailField ? emailField.value.trim() : '';
		if (!emailValue) {
			showMessage(config.strings.invalidEmail, 'error');
			return;
		}

		setEmail(emailValue);
		showMessage(config.strings.connecting);

		request('csh_ai_account_register', { email: emailValue }).then((result) => {
			if (!result.ok || !result.data?.success) {
				const message = result.data?.data?.message || config.strings.genericError;
				showMessage(message, 'error');
				return;
			}

			setStatus('pending');
			showMessage(config.strings.pendingNotice, 'info');
			scheduleStatusCheck(8000);
		});
	}

	function handleResend() {
		const emailValue = container.dataset.email || (emailField ? emailField.value.trim() : '');
		if (!emailValue) {
			showMessage(config.strings.invalidEmail, 'error');
			return;
		}
		showMessage(config.strings.resending);
		request('csh_ai_account_register', { email: emailValue }).then((result) => {
			if (!result.ok || !result.data?.success) {
				const message = result.data?.data?.message || config.strings.genericError;
				showMessage(message, 'error');
				return;
			}
			setStatus('pending');
			showMessage(config.strings.pendingNotice, 'info');
			scheduleStatusCheck(8000);
		});
	}

	function handleCheckStatus() {
		showMessage(config.strings.checking);
		request('csh_ai_account_status').then((result) => {
			if (!result.ok || !result.data?.success) {
				const message = result.data?.data?.message || config.strings.genericError;
				showMessage(message, 'error');
				return;
			}

			const data = result.data.data || {};
			if (data.verified) {
				setStatus('connected');
				showMessage(config.strings.connected, 'success');
			} else {
				showMessage(config.strings.pendingStill, 'info');
				scheduleStatusCheck(12000);
			}
		});
	}

	function handleDisconnect() {
		if (pendingCheckTimer) {
			window.clearTimeout(pendingCheckTimer);
			pendingCheckTimer = null;
		}

		showMessage(config.strings.disconnecting);
		request('csh_ai_account_disconnect').then((result) => {
			if (!result.ok || !result.data?.success) {
				const message = result.data?.data?.message || config.strings.genericError;
				showMessage(message, 'error');
				return;
			}
			setEmail('');
			setStatus('disconnected');
			showMessage(config.strings.disconnected, 'success');
		});
	}

	container.addEventListener('click', (event) => {
		const actionButton = event.target.closest('[data-action]');
		if (!actionButton) {
			return;
		}

		event.preventDefault();
		const action = actionButton.dataset.action;

		switch (action) {
			case 'connect':
				handleConnect();
				break;
			case 'resend':
				handleResend();
				break;
			case 'check':
				handleCheckStatus();
				break;
			case 'disconnect':
				handleDisconnect();
				break;
			default:
				break;
		}
	});

	// Initialise visibility based on initial state.
	setStatus(container.dataset.status || 'disconnected');
})();
