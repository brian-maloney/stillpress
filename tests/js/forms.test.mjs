/**
 * Forms client logic (assets/js/forms.js), run with Node's built-in test runner: npm run test:js
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';

const require = createRequire( import.meta.url );
const { matchField, readAnswer } = require( '../../assets/js/forms.js' );

test( 'matches reported field names to the form\'s inputs', () => {
	const names = [ 'your-name', 'topics[]', 'input_2.3', 'input_5', 'wpforms[fields][1][first]', 'wpforms[fields][1][last]', 'wpforms[fields][2]' ];
	assert.equal( matchField( names, 'your-name' ), 'your-name' );
	assert.equal( matchField( names, 'topics' ), 'topics[]', 'checkbox lists' );
	assert.equal( matchField( names, 'input_2_3' ), 'input_2.3', 'PHP turned the dot into an underscore' );
	assert.equal( matchField( names, 'input_2' ), 'input_2.3', 'Gravity Forms field with sub-inputs' );
	assert.equal( matchField( names, 'wpforms[fields][1]' ), 'wpforms[fields][1][first]', 'WPForms name field' );
	assert.equal( matchField( names, 'wpforms[fields][2]' ), 'wpforms[fields][2]' );
	assert.equal( matchField( names, 'nothing' ), null );
} );

test( 'reads answers defensively', () => {
	assert.deepEqual( readAnswer( null, 'Failed' ), { ok: false, status: 'error', message: 'Failed', errors: {}, redirect: '' } );
	assert.deepEqual( readAnswer( '<html>', 'Failed' ).status, 'error' );
	assert.deepEqual(
		readAnswer( { ok: true, status: 'sent', message: 'Thanks', errors: [], redirect: 'https://example.org/thanks/' }, 'x' ),
		{ ok: true, status: 'sent', message: 'Thanks', errors: {}, redirect: 'https://example.org/thanks/' }
	);
	const invalid = readAnswer( { ok: false, status: 'invalid', message: '', errors: { email: 'Bad', n: 5 } }, 'Failed' );
	assert.equal( invalid.message, 'Failed', 'empty message falls back' );
	assert.deepEqual( invalid.errors, { email: 'Bad' }, 'only text errors' );
	assert.equal( readAnswer( { ok: true, status: 'sent', redirect: 'javascript:alert(1)' }, 'x' ).redirect, '' );
	assert.equal( readAnswer( { ok: 'yes', status: 'sent' }, 'x' ).ok, false, 'ok must be true, not truthy' );
} );
