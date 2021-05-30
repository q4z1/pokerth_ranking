window._ = require('lodash');

/**
 * We'll load jQuery and the Bootstrap jQuery plugin which provides support
 * for JavaScript based Bootstrap features such as modals and tabs. This
 * code may be modified to fit the specific needs of your application.
 */

try {
    window.Popper = require('popper.js').default;

    window.$ = jQuery = require('jquery');

    require('bootstrap');
} catch (e) {}

/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

window.axios = require('axios');

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allows your team to easily build robust real-time web applications.
 */

// import Echo from 'laravel-echo';

// window.Pusher = require('pusher-js');

// window.Echo = new Echo({
//     broadcaster: 'pusher',
//     key: process.env.MIX_PUSHER_APP_KEY,
//     cluster: process.env.MIX_PUSHER_APP_CLUSTER,
//     forceTLS: true
// });

window.countries = [
    {svg: 'ad', title: 'Andorra', png: 'ad'},
    {svg: 'ae', title: 'United Arab Emirates', png: 'ae'},
    {svg: 'af', title: 'Afghanistan', png: 'af'},
    {svg: 'ag', title: 'Antigua and Barbuda', png: 'ag'},
    {svg: 'ai', title: 'Anguilla', png: 'ai'},
    {svg: 'al', title: 'Albania', png: 'al'},
    {svg: 'am', title: 'Armenia', png: 'am'},
    {svg: 'n/a', title: 'Netherlands Antilles', png: 'an'},
    {svg: 'ao', title: 'Angola', png: 'ao'},
    {svg: 'ar', title: 'Argentina', png: 'ar'},
    {svg: 'as', title: 'American Samoa', png: 'as'},
    {svg: 'at', title: 'Austria', png: 'at'},
    {svg: 'au', title: 'Australia', png: 'au'},
    {svg: 'aw', title: 'Aruba', png: 'aw'},
    {svg: 'ax', title: 'Åland Islands', png: 'ax'},
    {svg: 'az', title: 'Azerbaijan', png: 'az'},
    {svg: 'ba', title: 'Bosnia and Herzegovina', png: 'ba'},
    {svg: 'bb', title: 'Barbados', png: 'bb'},
    {svg: 'bd', title: 'Bangladesh', png: 'bd'},
    {svg: 'be', title: 'Belgium', png: 'be'},
    {svg: 'bf', title: 'Burkina Faso', png: 'bf'},
    {svg: 'bg', title: 'Bulgaria', png: 'bg'},
    {svg: 'bh', title: 'Bahrain', png: 'bh'},
    {svg: 'bi', title: 'Burundi', png: 'bi'},
    {svg: 'bj', title: 'Benin', png: 'bj'},
    {svg: 'bm', title: 'Bermuda', png: 'bm'},
    {svg: 'bn', title: 'Brunei Darussalam', png: 'bn'},
    {svg: 'bo', title: 'Bolivia (Plurinational State of)', png: 'bo'},
    {svg: 'br', title: 'Brazil', png: 'br'},
    {svg: 'bs', title: 'Bahamas', png: 'bs'},
    {svg: 'bt', title: 'Bhutan', png: 'bt'},
    {svg: 'bv', title: 'Bouvet Island', png: 'bv'},
    {svg: 'bw', title: 'Botswana', png: 'bw'},
    {svg: 'by', title: 'Belarus', png: 'by'},
    {svg: 'bz', title: 'Belize', png: 'bz'},
    {svg: 'ca', title: 'Canada', png: 'ca'},
    {svg: 'catalonia', title: 'Catalonia', png: 'catalonia'},
    {svg: 'cc', title: 'Cocos (Keeling) Islands', png: 'cc'},
    {svg: 'cd', title: 'Congo, Democratic Republic of the', png: 'cd'},
    {svg: 'cf', title: 'Central African Republic', png: 'cf'},
    {svg: 'cg', title: 'Congo', png: 'cg'},
    {svg: 'ch', title: 'Switzerland', png: 'ch'},
    {svg: 'ci', title: 'Côte d\'Ivoire', png: 'ci'},
    {svg: 'ck', title: 'Cook Islands', png: 'ck'},
    {svg: 'cl', title: 'Chile', png: 'cl'},
    {svg: 'cm', title: 'Cameroon', png: 'cm'},
    {svg: 'cn', title: 'China', png: 'cn'},
    {svg: 'co', title: 'Colombia', png: 'co'},
    {svg: 'cr', title: 'Costa Rica', png: 'cr'},
    {svg: 'cu', title: 'Cuba', png: 'cu'},
    {svg: 'cv', title: 'Cabo Verde', png: 'cv'},
    {svg: 'cx', title: 'Christmas Island', png: 'cx'},
    {svg: 'cy', title: 'Cyprus', png: 'cy'},
    {svg: 'cz', title: 'Czechia', png: 'cz'},
    {svg: 'de', title: 'Germany', png: 'de'},
    {svg: 'dj', title: 'Djibouti', png: 'dj'},
    {svg: 'dk', title: 'Denmark', png: 'dk'},
    {svg: 'dm', title: 'Dominica', png: 'dm'},
    {svg: 'do', title: 'Dominican Republic', png: 'do'},
    {svg: 'dz', title: 'Algeria', png: 'dz'},
    {svg: 'ec', title: 'Ecuador', png: 'ec'},
    {svg: 'ee', title: 'Estonia', png: 'ee'},
    {svg: 'eg', title: 'Egypt', png: 'eg'},
    {svg: 'eh', title: 'Western Sahara', png: 'eh'},
    {svg: 'gb-eng', title: 'England', png: 'england'},
    {svg: 'er', title: 'Eritrea', png: 'er'},
    {svg: 'es', title: 'Spain', png: 'es'},
    {svg: 'et', title: 'Ethiopia', png: 'et'},
    {svg: 'europeanunion', title: 'European Union', png: 'europeanunion'},
    // {svg: 'fam', title: '', png: 'fam'},
    {svg: 'fi', title: 'Finland', png: 'fi'},
    {svg: 'fj', title: 'Fiji', png: 'fj'},
    {svg: 'fk', title: 'Falkland Islands (Malvinas)', png: 'fk'},
    {svg: 'fm', title: 'Micronesia (Federated States of)', png: 'fm'},
    {svg: 'fo', title: 'Faroe Islands', png: 'fo'},
    {svg: 'fr', title: 'France', png: 'fr'},
    {svg: 'ga', title: 'Gabon', png: 'ga'},
    {svg: 'gb', title: 'United Kingdom of Great Britain and Northern Ireland', png: 'gb'},
    {svg: 'gd', title: 'Grenada', png: 'gd'},
    {svg: 'ge', title: 'Georgia', png: 'ge'},
    {svg: 'gf', title: 'French Guiana', png: 'gf'},
    {svg: 'gh', title: 'Ghana', png: 'gh'},
    {svg: 'gi', title: 'Gibraltar', png: 'gi'},
    {svg: 'gl', title: 'Greenland', png: 'gl'},
    {svg: 'gm', title: 'Gambia', png: 'gm'},
    {svg: 'gn', title: 'Guinea', png: 'gn'},
    {svg: 'gp', title: 'Guadeloupe', png: 'gp'},
    {svg: 'gq', title: 'Equatorial Guinea', png: 'gq'},
    {svg: 'gr', title: 'Greece', png: 'gr'},
    {svg: 'gs', title: 'South Georgia and the South Sandwich Islands', png: 'gs'},
    {svg: 'gt', title: 'Guatemala', png: 'gt'},
    {svg: 'gu', title: 'Guam', png: 'gu'},
    {svg: 'gw', title: 'Guinea-Bissau', png: 'gw'},
    {svg: 'gy', title: 'Guyana', png: 'gy'},
    {svg: 'hk', title: 'Hong Kong', png: 'hk'},
    {svg: 'hm', title: 'Heard Island and McDonald Islands', png: 'hm'},
    {svg: 'hn', title: 'Honduras', png: 'hn'},
    {svg: 'hr', title: 'Croatia', png: 'hr'},
    {svg: 'ht', title: 'Haiti', png: 'ht'},
    {svg: 'hu', title: 'Hungary', png: 'hu'},
    {svg: 'id', title: 'Indonesia', png: 'id'},
    {svg: 'ie', title: 'Ireland', png: 'ie'},
    {svg: 'il', title: 'Israel', png: 'il'},
    {svg: 'in', title: 'India', png: 'in'},
    {svg: 'io', title: 'British Indian Ocean Territory', png: 'io'},
    {svg: 'iq', title: 'Iraq', png: 'iq'},
    {svg: 'ir', title: 'Iran (Islamic Republic of)', png: 'ir'},
    {svg: 'is', title: 'Iceland', png: 'is'},
    {svg: 'it', title: 'Italy', png: 'it'},
    {svg: 'jm', title: 'Jamaica', png: 'jm'},
    {svg: 'jo', title: 'Jordan', png: 'jo'},
    {svg: 'jp', title: 'Japan', png: 'jp'},
    {svg: 'ke', title: 'Kenya', png: 'ke'},
    {svg: 'kg', title: 'Kyrgyzstan', png: 'kg'},
    {svg: 'kh', title: 'Cambodia', png: 'kh'},
    {svg: 'ki', title: 'Kiribati', png: 'ki'},
    {svg: 'km', title: 'Comoros', png: 'km'},
    {svg: 'kn', title: 'Saint Kitts and Nevis', png: 'kn'},
    {svg: 'kp', title: 'Korea (Democratic People\'s Republic of)', png: 'kp'},
    {svg: 'kr', title: 'Korea, Republic of', png: 'kr'},
    {svg: 'kw', title: 'Kuwait', png: 'kw'},
    {svg: 'ky', title: 'Cayman Islands', png: 'ky'},
    {svg: 'kz', title: 'Kazakhstan', png: 'kz'},
    {svg: 'la', title: 'Lao People\'s Democratic Republic', png: 'la'},
    {svg: 'lb', title: 'Lebanon', png: 'lb'},
    {svg: 'lc', title: 'Saint Lucia', png: 'lc'},
    {svg: 'li', title: 'Liechtenstein', png: 'li'},
    {svg: 'lk', title: 'Sri Lanka', png: 'lk'},
    {svg: 'lr', title: 'Liberia', png: 'lr'},
    {svg: 'ls', title: 'Lesotho', png: 'ls'},
    {svg: 'lt', title: 'Lithuania', png: 'lt'},
    {svg: 'lu', title: 'Luxembourg', png: 'lu'},
    {svg: 'lv', title: 'Latvia', png: 'lv'},
    {svg: 'ly', title: 'Libya', png: 'ly'},
    {svg: 'ma', title: 'Morocco', png: 'ma'},
    {svg: 'mc', title: 'Monaco', png: 'mc'},
    {svg: 'md', title: 'Moldova, Republic of', png: 'md'},
    {svg: 'me', title: 'Montenegro', png: 'me'},
    {svg: 'mg', title: 'Madagascar', png: 'mg'},
    {svg: 'mh', title: 'Marshall Islands', png: 'mh'},
    {svg: 'mk', title: 'North Macedonia', png: 'mk'},
    {svg: 'ml', title: 'Mali', png: 'ml'},
    {svg: 'mm', title: 'Myanmar', png: 'mm'},
    {svg: 'mn', title: 'Mongolia', png: 'mn'},
    {svg: 'mo', title: 'Macao', png: 'mo'},
    {svg: 'mp', title: 'Northern Mariana Islands', png: 'mp'},
    {svg: 'mq', title: 'Martinique', png: 'mq'},
    {svg: 'mr', title: 'Mauritania', png: 'mr'},
    {svg: 'ms', title: 'Montserrat', png: 'ms'},
    {svg: 'mt', title: 'Malta', png: 'mt'},
    {svg: 'mu', title: 'Mauritius', png: 'mu'},
    {svg: 'mv', title: 'Maldives', png: 'mv'},
    {svg: 'mw', title: 'Malawi', png: 'mw'},
    {svg: 'mx', title: 'Mexico', png: 'mx'},
    {svg: 'my', title: 'Malaysia', png: 'my'},
    {svg: 'mz', title: 'Mozambique', png: 'mz'},
    {svg: 'na', title: 'Namibia', png: 'na'},
    {svg: 'nc', title: 'New Caledonia', png: 'nc'},
    {svg: 'ne', title: 'Niger', png: 'ne'},
    {svg: 'nf', title: 'Norfolk Island', png: 'nf'},
    {svg: 'ng', title: 'Nigeria', png: 'ng'},
    {svg: 'ni', title: 'Nicaragua', png: 'ni'},
    {svg: 'nl', title: 'Netherlands', png: 'nl'},
    {svg: 'no', title: 'Norway', png: 'no'},
    {svg: 'np', title: 'Nepal', png: 'np'},
    {svg: 'nr', title: 'Nauru', png: 'nr'},
    {svg: 'nu', title: 'Niue', png: 'nu'},
    {svg: 'nz', title: 'New Zealand', png: 'nz'},
    {svg: 'om', title: 'Oman', png: 'om'},
    {svg: 'pa', title: 'Panama', png: 'pa'},
    {svg: 'pe', title: 'Peru', png: 'pe'},
    {svg: 'pf', title: 'French Polynesia', png: 'pf'},
    {svg: 'pg', title: 'Papua New Guinea', png: 'pg'},
    {svg: 'ph', title: 'Philippines', png: 'ph'},
    {svg: 'pk', title: 'Pakistan', png: 'pk'},
    {svg: 'pl', title: 'Poland', png: 'pl'},
    {svg: 'pm', title: 'Saint Pierre and Miquelon', png: 'pm'},
    {svg: 'pn', title: 'Pitcairn', png: 'pn'},
    {svg: 'pr', title: 'Puerto Rico', png: 'pr'},
    {svg: 'ps', title: 'Palestine, State of', png: 'ps'},
    {svg: 'pt', title: 'Portugal', png: 'pt'},
    {svg: 'pw', title: 'Palau', png: 'pw'},
    {svg: 'py', title: 'Paraguay', png: 'py'},
    {svg: 'qa', title: 'Qatar', png: 'qa'},
    {svg: 're', title: 'Réunion', png: 're'},
    {svg: 'ro', title: 'Romania', png: 'ro'},
    {svg: 'rs', title: 'Serbia', png: 'rs'},
    {svg: 'ru', title: 'Russian Federation', png: 'ru'},
    {svg: 'rw', title: 'Rwanda', png: 'rw'},
    {svg: 'sa', title: 'Saudi Arabia', png: 'sa'},
    {svg: 'sb', title: 'Solomon Islands', png: 'sb'},
    {svg: 'sc', title: 'Seychelles', png: 'sc'},
    {svg: 'gb-sct', title: 'Scotland', png: 'scotland'},
    {svg: 'sd', title: 'Sudan', png: 'sd'},
    {svg: 'se', title: 'Sweden', png: 'se'},
    {svg: 'sg', title: 'Singapore', png: 'sg'},
    {svg: 'sh', title: 'Saint Helena, Ascension and Tristan da Cunha', png: 'sh'},
    {svg: 'si', title: 'Slovenia', png: 'si'},
    {svg: 'sj', title: 'Svalbard and Jan Mayen', png: 'sj'},
    {svg: 'sk', title: 'Slovakia', png: 'sk'},
    {svg: 'sl', title: 'Sierra Leone', png: 'sl'},
    {svg: 'sm', title: 'San Marino', png: 'sm'},
    {svg: 'sn', title: 'Senegal', png: 'sn'},
    {svg: 'so', title: 'Somalia', png: 'so'},
    {svg: 'sr', title: 'Suriname', png: 'sr'},
    {svg: 'st', title: 'Sao Tome and Principe', png: 'st'},
    {svg: 'sv', title: 'El Salvador', png: 'sv'},
    {svg: 'sy', title: 'Syrian Arab Republic', png: 'sy'},
    {svg: 'sz', title: 'Eswatini', png: 'sz'},
    {svg: 'tc', title: 'Turks and Caicos Islands', png: 'tc'},
    {svg: 'td', title: 'Chad', png: 'td'},
    {svg: 'tf', title: 'French Southern Territories', png: 'tf'},
    {svg: 'tg', title: 'Togo', png: 'tg'},
    {svg: 'th', title: 'Thailand', png: 'th'},
    {svg: 'tj', title: 'Tajikistan', png: 'tj'},
    {svg: 'tk', title: 'Tokelau', png: 'tk'},
    {svg: 'tl', title: 'Timor-Leste', png: 'tl'},
    {svg: 'tm', title: 'Turkmenistan', png: 'tm'},
    {svg: 'tn', title: 'Tunisia', png: 'tn'},
    {svg: 'to', title: 'Tonga', png: 'to'},
    {svg: 'tr', title: 'Turkey', png: 'tr'},
    {svg: 'tt', title: 'Trinidad and Tobago', png: 'tt'},
    {svg: 'tv', title: 'Tuvalu', png: 'tv'},
    {svg: 'tw', title: 'Taiwan, Province of China', png: 'tw'},
    {svg: 'tz', title: 'Tanzania, United Republic of', png: 'tz'},
    {svg: 'ua', title: 'Ukraine', png: 'ua'},
    {svg: 'ug', title: 'Uganda', png: 'ug'},
    {svg: 'um', title: 'United States Minor Outlying Islands', png: 'um'},
    {svg: 'us', title: 'United States of America', png: 'us'},
    {svg: 'uy', title: 'Uruguay', png: 'uy'},
    {svg: 'uz', title: 'Uzbekistan', png: 'uz'},
    {svg: 'va', title: 'Holy See', png: 'va'},
    {svg: 'vc', title: 'Saint Vincent and the Grenadines', png: 'vc'},
    {svg: 've', title: 'Venezuela (Bolivarian Republic of)', png: 've'},
    {svg: 'vg', title: 'Virgin Islands (British)', png: 'vg'},
    {svg: 'vi', title: 'Virgin Islands (U.S.)', png: 'vi'},
    {svg: 'vn', title: 'Viet Nam', png: 'vn'},
    {svg: 'vu', title: 'Vanuatu', png: 'vu'},
    {svg: 'gb-wls', title: 'Wales', png: 'wales'},
    {svg: 'wf', title: 'Wallis and Futuna', png: 'wf'},
    {svg: 'ws', title: 'Samoa', png: 'ws'},
    {svg: 'ye', title: 'Yemen', png: 'ye'},
    {svg: 'yt', title: 'Mayotte', png: 'yt'},
    {svg: 'za', title: 'South Africa', png: 'za'},
    {svg: 'zm', title: 'Zambia', png: 'zm'},
    {svg: 'zw', title: 'Zimbabwe', png: 'zw'}
];