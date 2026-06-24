/**
 * SF Lead Form — vanilla-JS multi-step state machine.
 *
 * Zero dependencies. Renders the configured steps + a thank-you screen into
 * #sf-lead-form-root, then POSTs the collected data to the WP REST endpoint,
 * which forwards it to HubSpot CRM server-side.
 *
 * Two modes (chosen by the shortcode via window.sfLeadForm.mode):
 *   - "standard"    : the original flow — contact details collected LAST.
 *   - "progressive" : email + name collected FIRST (with consent), then each
 *                     gate is saved to HubSpot as the visitor advances, so
 *                     abandoned forms are still captured. See postPartial().
 */
(function () {
	'use strict';

	var CFG = window.sfLeadForm || {};
	var MODE = ('progressive' === CFG.mode) ? 'progressive' : 'standard';

	/* ------------------------------------------------------------------ *
	 * Step configuration (data-driven). Gate objects are shared between
	 * the two mode orderings.
	 * ------------------------------------------------------------------ */
	var GATE_ENQUIRY = {
		id: 'enquiry_type',
		type: 'choice',
		key: 'enquiry_type',
		title: 'What would you like to enquire about?',
		autoAdvance: true,
		options: [
			{ value: 'Ready-Made Supplements (White Label)', label: 'Ready-Made Supplements (White Label)' },
			{ value: 'Bespoke Supplement (Private Label)', label: 'Bespoke Supplement (Private Label)' }
		]
	};
	var GATE_PRODUCT = {
		id: 'product_type',
		type: 'choice',
		key: 'product_type',
		title: 'Product Type',
		autoAdvance: true,
		options: [
			{ value: 'Capsules', label: 'Capsules' },
			{ value: 'Powders', label: 'Powders' },
			{ value: 'Gummies', label: 'Gummies' },
			{ value: 'Softgels', label: 'Softgels' }
		]
	};
	var GATE_UNITS = {
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
	};
	var GATE_BUDGET = {
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
	};
	var GATE_EXPERIENCE = {
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
	};
	var GATE_JOURNEY = {
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
	};

	// Standard: contact LAST (full contact step).
	var STEPS_STANDARD = [
		GATE_ENQUIRY, GATE_PRODUCT, GATE_UNITS, GATE_BUDGET, GATE_EXPERIENCE, GATE_JOURNEY,
		{ id: 'contact', type: 'contact', title: 'Contact Information', cta: 'CONTINUE' }
	];

	// Progressive: email + name FIRST (with consent), gates next, remaining
	// contact details LAST. Each gate is saved via postPartial().
	var STEPS_PROGRESSIVE = [
		{
			id: 'details',
			type: 'contact',
			title: "Let's get started",
			subtitle: 'Pop in your details so we can send your quote — then a few quick questions.',
			fields: ['firstname', 'lastname', 'email', 'phone'],
			consent: true,
			cta: 'CONTINUE'
		},
		GATE_ENQUIRY, GATE_PRODUCT, GATE_UNITS, GATE_BUDGET, GATE_EXPERIENCE, GATE_JOURNEY,
		{
			id: 'finish',
			type: 'contact',
			title: 'Almost done',
			subtitle: "A couple of final details and we'll get your quote moving.",
			fields: ['company_name', 'product_brief'],
			cta: 'GET MY QUOTE'
		}
	];

	var STEPS = ('progressive' === MODE) ? STEPS_PROGRESSIVE : STEPS_STANDARD;

	var ALL_CONTACT_FIELDS = ['firstname', 'lastname', 'email', 'phone', 'company_name', 'product_brief'];

	// Consent wording is overridable from the server (filterable) so it can be
	// updated without a code change. This default is a placeholder pending sign-off.
	var CONSENT_TEXT = CFG.consentText ||
		'I agree to Supplement Factory storing these details and contacting me about my enquiry, in line with the Privacy Policy.';

	// Full country list. GB is pinned first (default); the rest are alphabetical.
	// `code` is the dialing code prepended to the number; North-American-Numbering-Plan
	// countries all use '+1' (callers type the full 10-digit number incl. area code).
	var COUNTRIES = [
		{ iso: 'GB', code: '+44', flag: '🇬🇧', name: 'United Kingdom' },
		{ iso: 'AF', code: '+93', flag: '🇦🇫', name: 'Afghanistan' },
		{ iso: 'AL', code: '+355', flag: '🇦🇱', name: 'Albania' },
		{ iso: 'DZ', code: '+213', flag: '🇩🇿', name: 'Algeria' },
		{ iso: 'AD', code: '+376', flag: '🇦🇩', name: 'Andorra' },
		{ iso: 'AO', code: '+244', flag: '🇦🇴', name: 'Angola' },
		{ iso: 'AG', code: '+1', flag: '🇦🇬', name: 'Antigua and Barbuda' },
		{ iso: 'AR', code: '+54', flag: '🇦🇷', name: 'Argentina' },
		{ iso: 'AM', code: '+374', flag: '🇦🇲', name: 'Armenia' },
		{ iso: 'AW', code: '+297', flag: '🇦🇼', name: 'Aruba' },
		{ iso: 'AU', code: '+61', flag: '🇦🇺', name: 'Australia' },
		{ iso: 'AT', code: '+43', flag: '🇦🇹', name: 'Austria' },
		{ iso: 'AZ', code: '+994', flag: '🇦🇿', name: 'Azerbaijan' },
		{ iso: 'BS', code: '+1', flag: '🇧🇸', name: 'Bahamas' },
		{ iso: 'BH', code: '+973', flag: '🇧🇭', name: 'Bahrain' },
		{ iso: 'BD', code: '+880', flag: '🇧🇩', name: 'Bangladesh' },
		{ iso: 'BB', code: '+1', flag: '🇧🇧', name: 'Barbados' },
		{ iso: 'BY', code: '+375', flag: '🇧🇾', name: 'Belarus' },
		{ iso: 'BE', code: '+32', flag: '🇧🇪', name: 'Belgium' },
		{ iso: 'BZ', code: '+501', flag: '🇧🇿', name: 'Belize' },
		{ iso: 'BJ', code: '+229', flag: '🇧🇯', name: 'Benin' },
		{ iso: 'BT', code: '+975', flag: '🇧🇹', name: 'Bhutan' },
		{ iso: 'BO', code: '+591', flag: '🇧🇴', name: 'Bolivia' },
		{ iso: 'BA', code: '+387', flag: '🇧🇦', name: 'Bosnia and Herzegovina' },
		{ iso: 'BW', code: '+267', flag: '🇧🇼', name: 'Botswana' },
		{ iso: 'BR', code: '+55', flag: '🇧🇷', name: 'Brazil' },
		{ iso: 'BN', code: '+673', flag: '🇧🇳', name: 'Brunei' },
		{ iso: 'BG', code: '+359', flag: '🇧🇬', name: 'Bulgaria' },
		{ iso: 'BF', code: '+226', flag: '🇧🇫', name: 'Burkina Faso' },
		{ iso: 'BI', code: '+257', flag: '🇧🇮', name: 'Burundi' },
		{ iso: 'CV', code: '+238', flag: '🇨🇻', name: 'Cabo Verde' },
		{ iso: 'KH', code: '+855', flag: '🇰🇭', name: 'Cambodia' },
		{ iso: 'CM', code: '+237', flag: '🇨🇲', name: 'Cameroon' },
		{ iso: 'CA', code: '+1', flag: '🇨🇦', name: 'Canada' },
		{ iso: 'CF', code: '+236', flag: '🇨🇫', name: 'Central African Republic' },
		{ iso: 'TD', code: '+235', flag: '🇹🇩', name: 'Chad' },
		{ iso: 'CL', code: '+56', flag: '🇨🇱', name: 'Chile' },
		{ iso: 'CN', code: '+86', flag: '🇨🇳', name: 'China' },
		{ iso: 'CO', code: '+57', flag: '🇨🇴', name: 'Colombia' },
		{ iso: 'KM', code: '+269', flag: '🇰🇲', name: 'Comoros' },
		{ iso: 'CG', code: '+242', flag: '🇨🇬', name: 'Congo (Republic)' },
		{ iso: 'CD', code: '+243', flag: '🇨🇩', name: 'Congo (DRC)' },
		{ iso: 'CR', code: '+506', flag: '🇨🇷', name: 'Costa Rica' },
		{ iso: 'CI', code: '+225', flag: '🇨🇮', name: "Côte d'Ivoire" },
		{ iso: 'HR', code: '+385', flag: '🇭🇷', name: 'Croatia' },
		{ iso: 'CU', code: '+53', flag: '🇨🇺', name: 'Cuba' },
		{ iso: 'CY', code: '+357', flag: '🇨🇾', name: 'Cyprus' },
		{ iso: 'CZ', code: '+420', flag: '🇨🇿', name: 'Czechia' },
		{ iso: 'DK', code: '+45', flag: '🇩🇰', name: 'Denmark' },
		{ iso: 'DJ', code: '+253', flag: '🇩🇯', name: 'Djibouti' },
		{ iso: 'DM', code: '+1', flag: '🇩🇲', name: 'Dominica' },
		{ iso: 'DO', code: '+1', flag: '🇩🇴', name: 'Dominican Republic' },
		{ iso: 'EC', code: '+593', flag: '🇪🇨', name: 'Ecuador' },
		{ iso: 'EG', code: '+20', flag: '🇪🇬', name: 'Egypt' },
		{ iso: 'SV', code: '+503', flag: '🇸🇻', name: 'El Salvador' },
		{ iso: 'GQ', code: '+240', flag: '🇬🇶', name: 'Equatorial Guinea' },
		{ iso: 'ER', code: '+291', flag: '🇪🇷', name: 'Eritrea' },
		{ iso: 'EE', code: '+372', flag: '🇪🇪', name: 'Estonia' },
		{ iso: 'SZ', code: '+268', flag: '🇸🇿', name: 'Eswatini' },
		{ iso: 'ET', code: '+251', flag: '🇪🇹', name: 'Ethiopia' },
		{ iso: 'FJ', code: '+679', flag: '🇫🇯', name: 'Fiji' },
		{ iso: 'FI', code: '+358', flag: '🇫🇮', name: 'Finland' },
		{ iso: 'FR', code: '+33', flag: '🇫🇷', name: 'France' },
		{ iso: 'GA', code: '+241', flag: '🇬🇦', name: 'Gabon' },
		{ iso: 'GM', code: '+220', flag: '🇬🇲', name: 'Gambia' },
		{ iso: 'GE', code: '+995', flag: '🇬🇪', name: 'Georgia' },
		{ iso: 'DE', code: '+49', flag: '🇩🇪', name: 'Germany' },
		{ iso: 'GH', code: '+233', flag: '🇬🇭', name: 'Ghana' },
		{ iso: 'GR', code: '+30', flag: '🇬🇷', name: 'Greece' },
		{ iso: 'GD', code: '+1', flag: '🇬🇩', name: 'Grenada' },
		{ iso: 'GT', code: '+502', flag: '🇬🇹', name: 'Guatemala' },
		{ iso: 'GN', code: '+224', flag: '🇬🇳', name: 'Guinea' },
		{ iso: 'GW', code: '+245', flag: '🇬🇼', name: 'Guinea-Bissau' },
		{ iso: 'GY', code: '+592', flag: '🇬🇾', name: 'Guyana' },
		{ iso: 'HT', code: '+509', flag: '🇭🇹', name: 'Haiti' },
		{ iso: 'HN', code: '+504', flag: '🇭🇳', name: 'Honduras' },
		{ iso: 'HK', code: '+852', flag: '🇭🇰', name: 'Hong Kong' },
		{ iso: 'HU', code: '+36', flag: '🇭🇺', name: 'Hungary' },
		{ iso: 'IS', code: '+354', flag: '🇮🇸', name: 'Iceland' },
		{ iso: 'IN', code: '+91', flag: '🇮🇳', name: 'India' },
		{ iso: 'ID', code: '+62', flag: '🇮🇩', name: 'Indonesia' },
		{ iso: 'IR', code: '+98', flag: '🇮🇷', name: 'Iran' },
		{ iso: 'IQ', code: '+964', flag: '🇮🇶', name: 'Iraq' },
		{ iso: 'IE', code: '+353', flag: '🇮🇪', name: 'Ireland' },
		{ iso: 'IL', code: '+972', flag: '🇮🇱', name: 'Israel' },
		{ iso: 'IT', code: '+39', flag: '🇮🇹', name: 'Italy' },
		{ iso: 'JM', code: '+1', flag: '🇯🇲', name: 'Jamaica' },
		{ iso: 'JP', code: '+81', flag: '🇯🇵', name: 'Japan' },
		{ iso: 'JO', code: '+962', flag: '🇯🇴', name: 'Jordan' },
		{ iso: 'KZ', code: '+7', flag: '🇰🇿', name: 'Kazakhstan' },
		{ iso: 'KE', code: '+254', flag: '🇰🇪', name: 'Kenya' },
		{ iso: 'KI', code: '+686', flag: '🇰🇮', name: 'Kiribati' },
		{ iso: 'XK', code: '+383', flag: '🇽🇰', name: 'Kosovo' },
		{ iso: 'KW', code: '+965', flag: '🇰🇼', name: 'Kuwait' },
		{ iso: 'KG', code: '+996', flag: '🇰🇬', name: 'Kyrgyzstan' },
		{ iso: 'LA', code: '+856', flag: '🇱🇦', name: 'Laos' },
		{ iso: 'LV', code: '+371', flag: '🇱🇻', name: 'Latvia' },
		{ iso: 'LB', code: '+961', flag: '🇱🇧', name: 'Lebanon' },
		{ iso: 'LS', code: '+266', flag: '🇱🇸', name: 'Lesotho' },
		{ iso: 'LR', code: '+231', flag: '🇱🇷', name: 'Liberia' },
		{ iso: 'LY', code: '+218', flag: '🇱🇾', name: 'Libya' },
		{ iso: 'LI', code: '+423', flag: '🇱🇮', name: 'Liechtenstein' },
		{ iso: 'LT', code: '+370', flag: '🇱🇹', name: 'Lithuania' },
		{ iso: 'LU', code: '+352', flag: '🇱🇺', name: 'Luxembourg' },
		{ iso: 'MO', code: '+853', flag: '🇲🇴', name: 'Macau' },
		{ iso: 'MG', code: '+261', flag: '🇲🇬', name: 'Madagascar' },
		{ iso: 'MW', code: '+265', flag: '🇲🇼', name: 'Malawi' },
		{ iso: 'MY', code: '+60', flag: '🇲🇾', name: 'Malaysia' },
		{ iso: 'MV', code: '+960', flag: '🇲🇻', name: 'Maldives' },
		{ iso: 'ML', code: '+223', flag: '🇲🇱', name: 'Mali' },
		{ iso: 'MT', code: '+356', flag: '🇲🇹', name: 'Malta' },
		{ iso: 'MH', code: '+692', flag: '🇲🇭', name: 'Marshall Islands' },
		{ iso: 'MR', code: '+222', flag: '🇲🇷', name: 'Mauritania' },
		{ iso: 'MU', code: '+230', flag: '🇲🇺', name: 'Mauritius' },
		{ iso: 'MX', code: '+52', flag: '🇲🇽', name: 'Mexico' },
		{ iso: 'FM', code: '+691', flag: '🇫🇲', name: 'Micronesia' },
		{ iso: 'MD', code: '+373', flag: '🇲🇩', name: 'Moldova' },
		{ iso: 'MC', code: '+377', flag: '🇲🇨', name: 'Monaco' },
		{ iso: 'MN', code: '+976', flag: '🇲🇳', name: 'Mongolia' },
		{ iso: 'ME', code: '+382', flag: '🇲🇪', name: 'Montenegro' },
		{ iso: 'MA', code: '+212', flag: '🇲🇦', name: 'Morocco' },
		{ iso: 'MZ', code: '+258', flag: '🇲🇿', name: 'Mozambique' },
		{ iso: 'MM', code: '+95', flag: '🇲🇲', name: 'Myanmar' },
		{ iso: 'NA', code: '+264', flag: '🇳🇦', name: 'Namibia' },
		{ iso: 'NR', code: '+674', flag: '🇳🇷', name: 'Nauru' },
		{ iso: 'NP', code: '+977', flag: '🇳🇵', name: 'Nepal' },
		{ iso: 'NL', code: '+31', flag: '🇳🇱', name: 'Netherlands' },
		{ iso: 'NZ', code: '+64', flag: '🇳🇿', name: 'New Zealand' },
		{ iso: 'NI', code: '+505', flag: '🇳🇮', name: 'Nicaragua' },
		{ iso: 'NE', code: '+227', flag: '🇳🇪', name: 'Niger' },
		{ iso: 'NG', code: '+234', flag: '🇳🇬', name: 'Nigeria' },
		{ iso: 'KP', code: '+850', flag: '🇰🇵', name: 'North Korea' },
		{ iso: 'MK', code: '+389', flag: '🇲🇰', name: 'North Macedonia' },
		{ iso: 'NO', code: '+47', flag: '🇳🇴', name: 'Norway' },
		{ iso: 'OM', code: '+968', flag: '🇴🇲', name: 'Oman' },
		{ iso: 'PK', code: '+92', flag: '🇵🇰', name: 'Pakistan' },
		{ iso: 'PW', code: '+680', flag: '🇵🇼', name: 'Palau' },
		{ iso: 'PS', code: '+970', flag: '🇵🇸', name: 'Palestine' },
		{ iso: 'PA', code: '+507', flag: '🇵🇦', name: 'Panama' },
		{ iso: 'PG', code: '+675', flag: '🇵🇬', name: 'Papua New Guinea' },
		{ iso: 'PY', code: '+595', flag: '🇵🇾', name: 'Paraguay' },
		{ iso: 'PE', code: '+51', flag: '🇵🇪', name: 'Peru' },
		{ iso: 'PH', code: '+63', flag: '🇵🇭', name: 'Philippines' },
		{ iso: 'PL', code: '+48', flag: '🇵🇱', name: 'Poland' },
		{ iso: 'PT', code: '+351', flag: '🇵🇹', name: 'Portugal' },
		{ iso: 'QA', code: '+974', flag: '🇶🇦', name: 'Qatar' },
		{ iso: 'RO', code: '+40', flag: '🇷🇴', name: 'Romania' },
		{ iso: 'RU', code: '+7', flag: '🇷🇺', name: 'Russia' },
		{ iso: 'RW', code: '+250', flag: '🇷🇼', name: 'Rwanda' },
		{ iso: 'KN', code: '+1', flag: '🇰🇳', name: 'Saint Kitts and Nevis' },
		{ iso: 'LC', code: '+1', flag: '🇱🇨', name: 'Saint Lucia' },
		{ iso: 'VC', code: '+1', flag: '🇻🇨', name: 'Saint Vincent and the Grenadines' },
		{ iso: 'WS', code: '+685', flag: '🇼🇸', name: 'Samoa' },
		{ iso: 'SM', code: '+378', flag: '🇸🇲', name: 'San Marino' },
		{ iso: 'ST', code: '+239', flag: '🇸🇹', name: 'Sao Tome and Principe' },
		{ iso: 'SA', code: '+966', flag: '🇸🇦', name: 'Saudi Arabia' },
		{ iso: 'SN', code: '+221', flag: '🇸🇳', name: 'Senegal' },
		{ iso: 'RS', code: '+381', flag: '🇷🇸', name: 'Serbia' },
		{ iso: 'SC', code: '+248', flag: '🇸🇨', name: 'Seychelles' },
		{ iso: 'SL', code: '+232', flag: '🇸🇱', name: 'Sierra Leone' },
		{ iso: 'SG', code: '+65', flag: '🇸🇬', name: 'Singapore' },
		{ iso: 'SK', code: '+421', flag: '🇸🇰', name: 'Slovakia' },
		{ iso: 'SI', code: '+386', flag: '🇸🇮', name: 'Slovenia' },
		{ iso: 'SB', code: '+677', flag: '🇸🇧', name: 'Solomon Islands' },
		{ iso: 'SO', code: '+252', flag: '🇸🇴', name: 'Somalia' },
		{ iso: 'ZA', code: '+27', flag: '🇿🇦', name: 'South Africa' },
		{ iso: 'KR', code: '+82', flag: '🇰🇷', name: 'South Korea' },
		{ iso: 'SS', code: '+211', flag: '🇸🇸', name: 'South Sudan' },
		{ iso: 'ES', code: '+34', flag: '🇪🇸', name: 'Spain' },
		{ iso: 'LK', code: '+94', flag: '🇱🇰', name: 'Sri Lanka' },
		{ iso: 'SD', code: '+249', flag: '🇸🇩', name: 'Sudan' },
		{ iso: 'SR', code: '+597', flag: '🇸🇷', name: 'Suriname' },
		{ iso: 'SE', code: '+46', flag: '🇸🇪', name: 'Sweden' },
		{ iso: 'CH', code: '+41', flag: '🇨🇭', name: 'Switzerland' },
		{ iso: 'SY', code: '+963', flag: '🇸🇾', name: 'Syria' },
		{ iso: 'TW', code: '+886', flag: '🇹🇼', name: 'Taiwan' },
		{ iso: 'TJ', code: '+992', flag: '🇹🇯', name: 'Tajikistan' },
		{ iso: 'TZ', code: '+255', flag: '🇹🇿', name: 'Tanzania' },
		{ iso: 'TH', code: '+66', flag: '🇹🇭', name: 'Thailand' },
		{ iso: 'TL', code: '+670', flag: '🇹🇱', name: 'Timor-Leste' },
		{ iso: 'TG', code: '+228', flag: '🇹🇬', name: 'Togo' },
		{ iso: 'TO', code: '+676', flag: '🇹🇴', name: 'Tonga' },
		{ iso: 'TT', code: '+1', flag: '🇹🇹', name: 'Trinidad and Tobago' },
		{ iso: 'TN', code: '+216', flag: '🇹🇳', name: 'Tunisia' },
		{ iso: 'TR', code: '+90', flag: '🇹🇷', name: 'Turkey' },
		{ iso: 'TM', code: '+993', flag: '🇹🇲', name: 'Turkmenistan' },
		{ iso: 'TV', code: '+688', flag: '🇹🇻', name: 'Tuvalu' },
		{ iso: 'UG', code: '+256', flag: '🇺🇬', name: 'Uganda' },
		{ iso: 'UA', code: '+380', flag: '🇺🇦', name: 'Ukraine' },
		{ iso: 'AE', code: '+971', flag: '🇦🇪', name: 'United Arab Emirates' },
		{ iso: 'US', code: '+1', flag: '🇺🇸', name: 'United States' },
		{ iso: 'UY', code: '+598', flag: '🇺🇾', name: 'Uruguay' },
		{ iso: 'UZ', code: '+998', flag: '🇺🇿', name: 'Uzbekistan' },
		{ iso: 'VU', code: '+678', flag: '🇻🇺', name: 'Vanuatu' },
		{ iso: 'VA', code: '+379', flag: '🇻🇦', name: 'Vatican City' },
		{ iso: 'VE', code: '+58', flag: '🇻🇪', name: 'Venezuela' },
		{ iso: 'VN', code: '+84', flag: '🇻🇳', name: 'Vietnam' },
		{ iso: 'YE', code: '+967', flag: '🇾🇪', name: 'Yemen' },
		{ iso: 'ZM', code: '+260', flag: '🇿🇲', name: 'Zambia' },
		{ iso: 'ZW', code: '+263', flag: '🇿🇼', name: 'Zimbabwe' }
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
			_consent: false,
			_phoneIso: 'GB',
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
	 * Analytics — gate-level tracking (Microsoft Clarity + optional GA4).
	 * Fire-and-forget: a tracking error must never break the form. No PII is
	 * ever sent (contact fields are not tagged).
	 * ------------------------------------------------------------------ */
	var tracked = {};

	function track(eventName, tags) {
		try {
			if (typeof window.clarity === 'function') {
				window.clarity('event', eventName);
				if (tags) {
					Object.keys(tags).forEach(function (k) {
						window.clarity('set', k, String(tags[k]));
					});
				}
			}
			if (typeof window.gtag === 'function') {
				window.gtag('event', eventName, tags || {});
			} else if (window.dataLayer && typeof window.dataLayer.push === 'function') {
				var payload = { event: eventName };
				if (tags) {
					Object.keys(tags).forEach(function (k) { payload[k] = tags[k]; });
				}
				window.dataLayer.push(payload);
			}
		} catch (e) { /* analytics must never break the form */ }
	}

	function tag(key, value) {
		try {
			if (typeof window.clarity === 'function') {
				window.clarity('set', key, String(value));
			}
		} catch (e) { /* no-op */ }
	}

	function trackStepReached(step, index) {
		var name = 'sf_g' + index + '_' + step.id;
		if (tracked[name]) {
			return;
		}
		tracked[name] = true;
		track(name, { sf_step_reached: index + '_' + step.id });
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

		if (!tracked.__started) {
			tracked.__started = true;
			track('sf_form_started');
		}
		trackStepReached(step, state.currentStep);

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
		var fields = step.fields || ALL_CONTACT_FIELDS;

		function has(name) { return fields.indexOf(name) !== -1; }

		if (has('firstname') || has('lastname')) {
			var rowKids = [];
			if (has('firstname')) { rowKids.push(field('firstname', textInput('firstname', 'First name', d.firstname))); }
			if (has('lastname')) { rowKids.push(field('lastname', textInput('lastname', 'Last name', d.lastname))); }
			wrap.appendChild(el('div', { class: 'sf-lf__row' }, rowKids));
		}

		if (has('email')) {
			wrap.appendChild(field('email', textInput('email', 'What is your email address?', d.email, 'email')));
		}
		if (has('phone')) {
			wrap.appendChild(field('phone', buildPhone()));
		}
		if (has('company_name')) {
			wrap.appendChild(field('company_name', textInput('company_name', 'Company Name', d.company_name)));
		}
		if (has('product_brief')) {
			var brief = el('textarea', {
				class: 'sf-lf__textarea',
				id: 'sf-lf-product_brief',
				rows: '4',
				placeholder: 'Product Brief – More details will help us to create a better quote and connect you with the right team member',
				oninput: function (e) { d.product_brief = e.target.value; }
			});
			brief.value = d.product_brief;
			wrap.appendChild(field('product_brief', brief));
		}

		if (step.consent) {
			wrap.appendChild(buildConsent());
		}

		var cta = el('button', {
			type: 'button',
			class: 'sf-lf__btn',
			onclick: onContactContinue
		}, [step.cta || 'CONTINUE']);
		wrap.appendChild(cta);

		return wrap;
	}

	function buildConsent() {
		var d = state.data;
		var cb = el('input', {
			type: 'checkbox',
			class: 'sf-lf__consent-check',
			id: 'sf-lf-consent',
			onchange: function (e) { d._consent = !!e.target.checked; }
		});
		cb.checked = !!d._consent;
		var label = el('label', { class: 'sf-lf__consent', 'for': 'sf-lf-consent' }, [
			cb,
			el('span', { class: 'sf-lf__consent-text', text: CONSENT_TEXT })
		]);
		return el('div', { class: 'sf-lf__field', 'data-field': '_consent' }, [label]);
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

	function countryByIso(iso) {
		return COUNTRIES.filter(function (c) { return c.iso === iso; })[0];
	}

	function buildPhone() {
		var d = state.data;
		var select = el('select', {
			class: 'sf-lf__phone-code',
			'aria-label': 'Country dialing code',
			onchange: function (e) {
				d._phoneIso = e.target.value;
				var c = countryByIso(d._phoneIso);
				d._phoneCode = c ? c.code : '+44';
			}
		});
		COUNTRIES.forEach(function (c) {
			var o = el('option', { value: c.iso, text: c.flag + ' ' + c.code + '  ' + c.name });
			if (c.iso === d._phoneIso) {
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
		tag('sf_' + step.key, value);
		maybePostPartial();

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
		var step = STEPS[state.currentStep - 1];
		var errors = validateContact(step);
		clearFieldErrors();
		if (Object.keys(errors).length) {
			showFieldErrors(errors);
			return;
		}
		if (state.currentStep < STEPS.length) {
			maybePostPartial();
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
	 * Validation (client-side, mirrored on the server). Only validates the
	 * fields present on the given contact step.
	 * ------------------------------------------------------------------ */
	function validateContact(step) {
		var d = state.data;
		var e = {};
		var fields = (step && step.fields) || ALL_CONTACT_FIELDS;
		function has(name) { return fields.indexOf(name) !== -1; }

		if (has('firstname') && (!d.firstname || d.firstname.trim().length < 2)) {
			e.firstname = 'Please enter your first name.';
		}
		if (has('lastname') && (!d.lastname || d.lastname.trim().length < 2)) {
			e.lastname = 'Please enter your last name.';
		}
		if (has('email') && (!d.email || !EMAIL_RE.test(d.email.trim()))) {
			e.email = 'Please enter a valid email address.';
		}
		if (has('phone')) {
			var digits = (d._phoneNumber || '').replace(/\D/g, '');
			if (digits.length < 7) {
				e.phone = 'Please enter a valid phone number.';
			}
		}
		if (has('company_name') && (!d.company_name || !d.company_name.trim())) {
			e.company_name = 'Please enter your company name.';
		}
		if (step && step.consent && !d._consent) {
			e._consent = 'Please tick the box to continue.';
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
	 * Progressive capture — POST the data known so far to /partial, so an
	 * abandoned form is still saved. Only active in progressive mode, and
	 * only once we have a valid email + consent (never store PII otherwise).
	 * ------------------------------------------------------------------ */
	var partialInFlight = false;

	function fullPhone() {
		var d = state.data;
		if (!d._phoneNumber) { return ''; }
		var national = (d._phoneNumber || '').replace(/\D/g, '').replace(/^0+/, '');
		return (d._phoneCode || '+44') + national;
	}

	function maybePostPartial() {
		if ('progressive' !== MODE) { return; }
		if (!CFG.partialUrl) { return; }
		var d = state.data;
		if (!d.email || !EMAIL_RE.test(d.email.trim())) { return; }
		if (!d._consent) { return; }
		postPartial();
	}

	function postPartial() {
		if (partialInFlight) { return; }
		var d = state.data;
		var step = STEPS[state.currentStep - 1];
		var hp = document.getElementById('sf-lf-hp');
		var payload = {
			firstname: (d.firstname || '').trim(),
			lastname: (d.lastname || '').trim(),
			email: (d.email || '').trim(),
			phone: fullPhone(),
			company_name: (d.company_name || '').trim(),
			product_brief: (d.product_brief || '').trim(),
			enquiry_type: d.enquiry_type,
			product_type: d.product_type,
			manufacturing_experience: d.manufacturing_experience,
			unit_quantity: d.unit_quantity,
			manufacturing_budget: d.manufacturing_budget,
			journey_stage: d.journey_stage,
			consent: d._consent ? 'yes' : '',
			form_progress: step ? (state.currentStep + '_' + step.id) : '',
			company_website: hp ? hp.value : ''
		};
		partialInFlight = true;
		try {
			fetch(CFG.partialUrl, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': CFG.nonce || ''
				},
				body: JSON.stringify(payload)
			}).then(function () { partialInFlight = false; })
				.catch(function () { partialInFlight = false; });
		} catch (e) {
			partialInFlight = false;
		}
	}

	/* ------------------------------------------------------------------ *
	 * Submission
	 * ------------------------------------------------------------------ */
	function readCookie(name) {
		var parts = ('; ' + document.cookie).split('; ' + name + '=');
		return 2 === parts.length ? (parts.pop().split(';').shift() || '') : '';
	}

	function buildPayload() {
		var d = state.data;
		d.phone = fullPhone();

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
			consent: d._consent ? 'yes' : '',
			company_website: hp ? hp.value : '',
			// HubSpot form-submission context (optional). Used server-side to mirror
			// the lead to a HubSpot form so it triggers CRM workflows/automations.
			hutk: readCookie('hubspotutk'),
			page_uri: window.location.href,
			page_name: document.title
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
		if (!CFG || !CFG.restUrl) {
			renderError('Form is not configured correctly. Please contact us directly.');
			return;
		}
		state.submitting = true;
		renderSubmitting();
		track('sf_form_submit_attempt');

		var payload = buildPayload();

		fetch(CFG.restUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': CFG.nonce || ''
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
		track('sf_form_error');
		root.innerHTML = '';
		var card = el('div', { class: 'sf-lf__card' }, [
			el('div', { class: 'sf-lf__error', text: message }),
			el('button', { type: 'button', class: 'sf-lf__btn', onclick: submit }, ['Try Again']),
			el('button', { type: 'button', class: 'sf-lf__back', onclick: function () { state.currentStep = STEPS.length; render(); } }, ['← Back to form'])
		]);
		root.appendChild(card);
	}

	function showThankYou() {
		track('sf_form_submitted');
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
