/**
 * SF Lead Form — vanilla-JS multi-step state machine.
 *
 * Zero dependencies. Renders 6 input steps + a thank-you screen into
 * #sf-lead-form-root, then POSTs the collected data to the WP REST endpoint,
 * which forwards it to HubSpot CRM server-side.
 *
 * Reorder steps by editing the STEPS array below. To match the live
 * screenshots (Contact collected LAST), move the `contact` entry to the end.
 */
(function () {
	'use strict';

	/* ------------------------------------------------------------------ *
	 * Step configuration (data-driven)
	 * ------------------------------------------------------------------ */
	var STEPS = [
		{
			id: 'enquiry_type',
			type: 'choice',
			key: 'enquiry_type',
			title: 'What would you like to enquire about?',
			autoAdvance: true,
			options: [
				{ value: 'Ready-Made Supplements (White Label)', label: 'Ready-Made Supplements (White Label)' },
				{ value: 'Bespoke Supplement (Private Label)', label: 'Bespoke Supplement (Private Label)' }
			]
		},
		{
			id: 'product_type',
			type: 'choice',
			key: 'product_type',
			title: 'Product Type',
			autoAdvance: true,
			options: [
				{ value: 'Capsules', label: 'Capsules' },
				{ value: 'Powders', label: 'Powders' },
				{ value: 'Gummies', label: 'Gummies' },
				{ value: 'Softgels', label: 'Softgels' },
				{ value: 'Duocaps', label: 'Duocaps' },
				{ value: 'Licaps', label: 'Licaps' },
				{ value: 'Beadlets', label: 'Beadlets' }
			]
		},
		{
			id: 'unit_quantity',
			type: 'choice',
			key: 'unit_quantity',
			title: 'How Many Units Do You want?',
			subtitle: 'A unit is defined as a single finished product in a pot, tub, pouch or carton.',
			autoAdvance: true,
			options: [
				{ value: '200', label: '200 Units' },
				{ value: '500', label: '500 Units' },
				{ value: '750', label: '750 Units' },
				{ value: '1000', label: '1000 Units' },
				{ value: '2000', label: '2000 Units' },
				{ value: '5000', label: '5000 Units' },
				{ value: '10000+', label: '10000+ Units' }
			]
		},
		{
			id: 'manufacturing_budget',
			type: 'choice',
			key: 'manufacturing_budget',
			title: 'What is your total manufacturing budget?',
			autoAdvance: true,
			options: [
				{ value: '500-2000', label: '£500 – £2,000' },
				{ value: '2000-5000', label: '£2,000 – £5,000' },
				{ value: '5000-10000', label: '£5,000 – £10,000' },
				{ value: '10000-20000', label: '£10,000 – £20,000' },
				{ value: '20000-30000', label: '£20,000 – £30,000' },
				{ value: '30000-50000', label: '£30,000 – £50,000' },
				{ value: '50000-100000', label: '£50,000 – £100,000' },
				{ value: '100000+', label: '£100,000+' }
			]
		},
		{
			id: 'manufacturing_experience',
			type: 'choice',
			key: 'manufacturing_experience',
			title: 'Have you manufactured supplements before?',
			autoAdvance: false,
			cta: 'CONTINUE',
			options: [
				{ value: 'first_product', label: 'This will be our first product to market' },
				{ value: 'existing_products', label: 'We currently have supplement products on the market' }
			]
		},
		{
			id: 'journey_stage',
			type: 'choice',
			key: 'journey_stage',
			title: 'Where are you in your journey?',
			autoAdvance: true,
			options: [
				{ value: 'Exploring an idea', label: 'Exploring an idea' },
				{ value: 'Actively researching ingredients & costs', label: 'Actively researching ingredients & costs' },
				{ value: 'Formulation & business plan ready', label: 'Formulation & business plan ready' }
			]
		},
		{
			id: 'contact',
			type: 'contact',
			title: 'Contact Information',
			cta: 'CONTINUE'
		}
	];

	var COUNTRIES = [
		{ iso: 'GB', code: '+44', flag: '🇬🇧' },
		{ iso: 'IE', code: '+353', flag: '🇮🇪' },
		{ iso: 'US', code: '+1', flag: '🇺🇸' },
		{ iso: 'AU', code: '+61', flag: '🇦🇺' },
		{ iso: 'NZ', code: '+64', flag: '🇳🇿' },
		{ iso: 'DE', code: '+49', flag: '🇩🇪' },
		{ iso: 'FR', code: '+33', flag: '🇫🇷' },
		{ iso: 'ES', code: '+34', flag: '🇪🇸' },
		{ iso: 'IT', code: '+39', flag: '🇮🇹' },
		{ iso: 'NL', code: '+31', flag: '🇳🇱' },
		{ iso: 'AE', code: '+971', flag: '🇦🇪' },
		{ iso: 'IN', code: '+91', flag: '🇮🇳' },
		{ iso: 'ZA', code: '+27', flag: '🇿🇦' }
	];

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	/* ------------------------------------------------------------------ *
	 * State
	 * ------------------------------------------------------------------ */
	var state = {
		currentStep: 1,
		submitting: false,
		data: {
			firstname: '',
			lastname: '',
			email: '',
			phone: '',
			company_name: '',
			product_brief: '',
			enquiry_type: '',
			product_type: '',
			manufacturing_experience: '',
			unit_quantity: '',
			manufacturing_budget: '',
			journey_stage: '',
			_phoneCode: '+44',
			_phoneNumber: ''
		}
	};

	var root = null;

	/* ------------------------------------------------------------------ *
	 * Tiny DOM helper
	 * ------------------------------------------------------------------ */
	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				var v = attrs[k];
				if (v == null) {
					return;
				}
				if (k === 'class') {
					node.className = v;
				} else if (k === 'text') {
					node.textContent = v;
				} else if (k === 'html') {
					node.innerHTML = v;
				} else if (k.indexOf('on') === 0 && typeof v === 'function') {
					node.addEventListener(k.slice(2).toLowerCase(), v);
				} else {
					node.setAttribute(k, v);
				}
			});
		}
		(children || []).forEach(function (c) {
			if (c == null) {
				return;
			}
			node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	function labelFor(key, value) {
		var step = STEPS.filter(function (s) { return s.key === key; })[0];
		if (!step || !step.options) {
			return value;
		}
		var opt = step.options.filter(function (o) { return o.value === value; })[0];
		return opt ? opt.label : value;
	}

	/* ------------------------------------------------------------------ *
	 * Rendering
	 * ------------------------------------------------------------------ */
	function render() {
		if (!root) {
			return;
		}
		root.innerHTML = '';
		var step = STEPS[state.currentStep - 1];

		var card = el('div', { class: 'sf-lf__card' });
		card.appendChild(el('h2', { class: 'sf-lf__title', text: step.title }));
		if (step.subtitle) {
			card.appendChild(el('p', { class: 'sf-lf__subtitle', text: step.subtitle }));
		}

		if (step.type === 'contact') {
			card.appendChild(buildContactStep(step));
		} else {
			card.appendChild(buildChoiceStep(step));
		}

		card.appendChild(buildFooter());
		root.appendChild(card);
		updateProgress();
	}

	function buildChoiceStep(step) {
		var wrap = el('div', { class: 'sf-lf__options', role: 'radiogroup', 'aria-label': step.title });
		step.options.forEach(function (opt) {
			var selected = state.data[step.key] === opt.value;
			var btn = el('button', {
				type: 'button',
				class: 'sf-lf__option' + (selected ? ' is-selected' : ''),
				role: 'radio',
				'aria-checked': selected ? 'true' : 'false',
				onclick: function () { selectOption(step, opt.value, btn); }
			}, [
				el('span', { class: 'sf-lf__radio', 'aria-hidden': 'true' }),
				el('span', { class: 'sf-lf__option-label', text: opt.label })
			]);
			wrap.appendChild(btn);
		});

		var container = el('div', {}, [wrap]);

		if (!step.autoAdvance) {
			var cta = el('button', {
				type: 'button',
				class: 'sf-lf__btn',
				onclick: function () {
					if (!state.data[step.key]) {
						showInlineError(container, 'Please choose an option to continue.');
						return;
					}
					next();
				}
			}, [step.cta || 'CONTINUE']);
			container.appendChild(cta);
		}
		return container;
	}

	function buildContactStep(step) {
		var d = state.data;
		var wrap = el('div', {});

		var row = el('div', { class: 'sf-lf__row' }, [
			field('firstname', textInput('firstname', 'First name', d.firstname)),
			field('lastname', textInput('lastname', 'Last name', d.lastname))
		]);
		wrap.appendChild(row);

		wrap.appendChild(field('email', textInput('email', 'What is your email address?', d.email, 'email')));
		wrap.appendChild(field('phone', buildPhone()));
		wrap.appendChild(field('company_name', textInput('company_name', 'Company Name', d.company_name)));

		var brief = el('textarea', {
			class: 'sf-lf__textarea',
			id: 'sf-lf-product_brief',
			rows: '4',
			placeholder: 'Product Brief – More details will help us to create a better quote and connect you with the right team member',
			oninput: function (e) { d.product_brief = e.target.value; }
		});
		brief.value = d.product_brief;
		wrap.appendChild(field('product_brief', brief));

		var cta = el('button', {
			type: 'button',
			class: 'sf-lf__btn',
			onclick: onContactContinue
		}, [step.cta || 'CONTINUE']);
		wrap.appendChild(cta);

		return wrap;
	}

	function field(key, control) {
		var holder = el('div', { class: 'sf-lf__field', 'data-field': key }, [control]);
		return holder;
	}

	function textInput(key, placeholder, value, type) {
		var input = el('input', {
			class: 'sf-lf__input',
			id: 'sf-lf-' + key,
			type: type || 'text',
			placeholder: placeholder,
			'aria-label': placeholder,
			oninput: function (e) { state.data[key] = e.target.value; }
		});
		input.value = value || '';
		return input;
	}

	function buildPhone() {
		var d = state.data;
		var select = el('select', {
			class: 'sf-lf__phone-code',
			'aria-label': 'Country dialing code',
			onchange: function (e) { d._phoneCode = e.target.value; }
		});
		COUNTRIES.forEach(function (c) {
			var o = el('option', { value: c.code, text: c.flag + ' ' + c.code });
			if (c.code === d._phoneCode) {
				o.setAttribute('selected', 'selected');
			}
			select.appendChild(o);
		});

		var number = el('input', {
			class: 'sf-lf__phone-number',
			id: 'sf-lf-phone',
			type: 'tel',
			placeholder: 'What is your phone number?',
			'aria-label': 'Phone number',
			oninput: function (e) { d._phoneNumber = e.target.value; }
		});
		number.value = d._phoneNumber || '';

		return el('div', { class: 'sf-lf__phone' }, [select, number]);
	}

	function buildFooter() {
		var footer = el('div', { class: 'sf-lf__footer' });
		var progress = el('div', { class: 'sf-lf__progress', 'aria-hidden': 'true' }, [
			el('div', { class: 'sf-lf__progress-fill', id: 'sf-lf-progress-fill' })
		]);
		footer.appendChild(progress);

		if (state.currentStep > 1) {
			footer.appendChild(el('button', {
				type: 'button',
				class: 'sf-lf__back',
				onclick: prev
			}, ['← Back']));
		}
		return footer;
	}

	function updateProgress() {
		var fill = document.getElementById('sf-lf-progress-fill');
		if (fill) {
			var pct = Math.round((state.currentStep / STEPS.length) * 100);
			fill.style.width = pct + '%';
		}
	}

	/* ------------------------------------------------------------------ *
	 * Navigation + selection
	 * ------------------------------------------------------------------ */
	function selectOption(step, value, btnEl) {
		state.data[step.key] = value;

		var group = btnEl.parentNode;
		Array.prototype.forEach.call(group.children, function (child) {
			var on = child === btnEl;
			child.classList.toggle('is-selected', on);
			child.setAttribute('aria-checked', on ? 'true' : 'false');
		});

		if (step.autoAdvance) {
			window.setTimeout(next, 180);
		}
	}

	function onContactContinue() {
		var errors = validateContact();
		clearFieldErrors();
		if (Object.keys(errors).length) {
			showFieldErrors(errors);
			return;
		}
		next();
	}

	function next() {
		if (state.currentStep >= STEPS.length) {
			submit();
			return;
		}
		state.currentStep += 1;
		render();
	}

	function prev() {
		if (state.currentStep > 1) {
			state.currentStep -= 1;
			render();
		}
	}

	/* ------------------------------------------------------------------ *
	 * Validation (client-side, mirrored on the server)
	 * ------------------------------------------------------------------ */
	function validateContact() {
		var d = state.data;
		var e = {};
		if (!d.firstname || d.firstname.trim().length < 2) {
			e.firstname = 'Please enter your first name.';
		}
		if (!d.lastname || d.lastname.trim().length < 2) {
			e.lastname = 'Please enter your last name.';
		}
		if (!d.email || !EMAIL_RE.test(d.email.trim())) {
			e.email = 'Please enter a valid email address.';
		}
		var digits = (d._phoneNumber || '').replace(/\D/g, '');
		if (digits.length < 7) {
			e.phone = 'Please enter a valid phone number.';
		}
		if (!d.company_name || !d.company_name.trim()) {
			e.company_name = 'Please enter your company name.';
		}
		return e;
	}

	function clearFieldErrors() {
		if (!root) { return; }
		Array.prototype.forEach.call(root.querySelectorAll('.sf-lf__error-text'), function (n) { n.remove(); });
		Array.prototype.forEach.call(root.querySelectorAll('.is-invalid'), function (n) { n.classList.remove('is-invalid'); });
	}

	function showFieldErrors(errors) {
		Object.keys(errors).forEach(function (key) {
			var holder = root.querySelector('[data-field="' + key + '"]');
			if (!holder) { return; }
			var control = holder.querySelector('.sf-lf__input, .sf-lf__textarea, .sf-lf__phone');
			if (control) { control.classList.add('is-invalid'); }
			holder.appendChild(el('p', { class: 'sf-lf__error-text', text: errors[key] }));
		});
		var first = root.querySelector('.is-invalid');
		if (first && first.focus) { first.focus(); }
	}

	function showInlineError(container, message) {
		var existing = container.querySelector('.sf-lf__error');
		if (existing) { existing.remove(); }
		container.insertBefore(el('div', { class: 'sf-lf__error', text: message }), container.firstChild);
	}

	/* ------------------------------------------------------------------ *
	 * Submission
	 * ------------------------------------------------------------------ */
	function buildPayload() {
		var d = state.data;
		var national = (d._phoneNumber || '').replace(/\D/g, '').replace(/^0+/, '');
		d.phone = (d._phoneCode || '+44') + national;

		var hp = document.getElementById('sf-lf-hp');
		return {
			firstname: d.firstname.trim(),
			lastname: d.lastname.trim(),
			email: d.email.trim(),
			phone: d.phone,
			company_name: d.company_name.trim(),
			product_brief: d.product_brief.trim(),
			enquiry_type: d.enquiry_type,
			product_type: d.product_type,
			manufacturing_experience: d.manufacturing_experience,
			unit_quantity: d.unit_quantity,
			manufacturing_budget: d.manufacturing_budget,
			journey_stage: d.journey_stage,
			company_website: hp ? hp.value : ''
		};
	}

	function renderSubmitting() {
		root.innerHTML = '';
		var card = el('div', { class: 'sf-lf__card' }, [
			el('div', { class: 'sf-lf__thankyou' }, [
				el('div', { class: 'sf-lf__check' }, [el('span', { class: 'sf-lf__spinner' })]),
				el('h2', { class: 'sf-lf__title', text: 'Sending…' }),
				el('p', { class: 'sf-lf__subtitle', text: 'Submitting your enquiry, one moment.' })
			])
		]);
		root.appendChild(card);
	}

	function submit() {
		if (state.submitting) { return; }
		if (!window.sfLeadForm || !window.sfLeadForm.restUrl) {
			renderError('Form is not configured correctly. Please contact us directly.');
			return;
		}
		state.submitting = true;
		renderSubmitting();

		var payload = buildPayload();

		fetch(window.sfLeadForm.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.sfLeadForm.nonce || ''
			},
			body: JSON.stringify(payload)
		})
			.then(function (res) {
				return res.json().catch(function () { return { success: false }; });
			})
			.then(function (json) {
				state.submitting = false;
				if (json && json.success) {
					showThankYou();
				} else {
					renderError((json && json.error) ? json.error : 'Something went wrong. Please try again.');
				}
			})
			.catch(function () {
				state.submitting = false;
				renderError('We could not reach the server. Please check your connection and try again.');
			});
	}

	function renderError(message) {
		root.innerHTML = '';
		var card = el('div', { class: 'sf-lf__card' }, [
			el('div', { class: 'sf-lf__error', text: message }),
			el('button', { type: 'button', class: 'sf-lf__btn', onclick: submit }, ['Try Again']),
			el('button', { type: 'button', class: 'sf-lf__back', onclick: function () { state.currentStep = STEPS.length; render(); } }, ['← Back to form'])
		]);
		root.appendChild(card);
	}

	function showThankYou() {
		var d = state.data;
		root.innerHTML = '';

		var summary = el('div', { class: 'sf-lf__summary' }, [
			el('dl', {}, [
				el('dt', { text: 'Enquiry' }), el('dd', { text: labelFor('enquiry_type', d.enquiry_type) }),
				el('dt', { text: 'Product type' }), el('dd', { text: labelFor('product_type', d.product_type) }),
				el('dt', { text: 'Quantity' }), el('dd', { text: labelFor('unit_quantity', d.unit_quantity) }),
				el('dt', { text: 'Budget' }), el('dd', { text: labelFor('manufacturing_budget', d.manufacturing_budget) }),
				el('dt', { text: 'Stage' }), el('dd', { text: labelFor('journey_stage', d.journey_stage) })
			])
		]);

		var card = el('div', { class: 'sf-lf__card' }, [
			el('div', { class: 'sf-lf__thankyou' }, [
				el('div', { class: 'sf-lf__check', 'aria-hidden': 'true' }, ['✓']),
				el('h2', { class: 'sf-lf__title', text: 'Thank you!' }),
				el('p', { class: 'sf-lf__subtitle', text: "We've received your enquiry and a member of our team will be in touch shortly." }),
				summary
			])
		]);
		root.appendChild(card);
	}

	/* ------------------------------------------------------------------ *
	 * Init
	 * ------------------------------------------------------------------ */
	function init() {
		root = document.getElementById('sf-lead-form-root');
		if (!root) { return; }
		render();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
