/**
 * Stillpress form receiver as a Netlify Function, answering at /stillpress-form on the site.
 *
 * Put this file in your site's functions folder (netlify/functions/stillpress-form.mjs) in the repository Netlify
 * builds from, set STILLPRESS_FORMS_SECRET (and STILLPRESS_FORMS_CAPTCHA_SECRET with a captcha) as environment
 * variables, and set https://<your site>/stillpress-form as the receiver address in Stillpress. Netlify functions keep
 * no shared state, so the rate limit counts per instance.
 *
 * `wp stillpress forms receiver netlify` writes this file with the settings below filled in.
 *
 * @license GPL-2.0-or-later
 */
import { handle, memoryLimiter, settings } from './receiver-core.mjs'; // stillpress:import

// stillpress:config:start
const DEFAULTS = {
	allowedOrigin: '',
	forwardUrl: '',
	captcha: 'none',
	captchaSiteKey: '',
	rateLimit: 5,
	rateWindow: 600,
};
// stillpress:config:end

let localLimiter = null;

export default async ( request, context ) => {
	// eslint-disable-next-line no-undef -- Netlify's global.
	const env = 'undefined' !== typeof Netlify ? Netlify.env.toObject() : {};
	const config = settings( DEFAULTS, env );
	if ( ! localLimiter ) {
		localLimiter = memoryLimiter( config.rateLimit, config.rateWindow );
	}
	return handle( request, config, {
		fetch: ( url, options ) => fetch( url, options ),
		now: () => Math.floor( Date.now() / 1000 ),
		limiter: localLimiter,
		ip: ( context && context.ip ) || '',
	} );
};

export const config = { path: '/stillpress-form' };
