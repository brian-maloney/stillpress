/**
 * Minimal static file server for the built site: directory indexes, a 404 page, and no path traversal.
 *
 * Usage: node tests/e2e/static-server.mjs <root> <port>
 */
import { createServer } from 'node:http';
import { createReadStream, existsSync, statSync } from 'node:fs';
import { extname, join, normalize, resolve, sep } from 'node:path';

const root = resolve( process.argv[ 2 ] || 'tests/e2e/.cache/site' );
const port = Number( process.argv[ 3 ] || 8890 );
const types = {
	'.html': 'text/html; charset=utf-8',
	'.css': 'text/css',
	'.js': 'application/javascript',
	'.mjs': 'application/javascript',
	'.json': 'application/json',
	'.xml': 'application/xml',
	'.xsl': 'application/xslt+xml',
	'.txt': 'text/plain; charset=utf-8',
	'.svg': 'image/svg+xml',
	'.png': 'image/png',
	'.jpg': 'image/jpeg',
	'.jpeg': 'image/jpeg',
	'.gif': 'image/gif',
	'.webp': 'image/webp',
	'.avif': 'image/avif',
	'.ico': 'image/x-icon',
	'.woff': 'font/woff',
	'.woff2': 'font/woff2',
	'.ttf': 'font/ttf',
};

function resolveFile( urlPath ) {
	let path;
	try {
		path = decodeURIComponent( urlPath.split( '?' )[ 0 ] );
	} catch {
		return null;
	}
	const candidate = normalize( join( root, path ) );
	if ( candidate !== root && ! candidate.startsWith( root + sep ) ) {
		return null;
	}
	const options = [ candidate ];
	if ( path.endsWith( '/' ) || ! extname( path ) ) {
		// Like real static hosts, a directory maps only to index.html.
		for ( const index of [ 'index.html' ] ) {
			options.push( join( candidate, index ) );
		}
	}
	return options.find( ( file ) => existsSync( file ) && statSync( file ).isFile() ) || null;
}

createServer( ( req, res ) => {
	const file = resolveFile( req.url || '/' );
	if ( ! file ) {
		const notFound = join( root, '404.html' );
		res.writeHead( 404, { 'Content-Type': 'text/html; charset=utf-8' } );
		if ( existsSync( notFound ) ) {
			createReadStream( notFound ).pipe( res );
		} else {
			res.end( 'Not found' );
		}
		return;
	}
	res.writeHead( 200, { 'Content-Type': types[ extname( file ).toLowerCase() ] || 'application/octet-stream' } );
	createReadStream( file ).pipe( res );
} ).listen( port, () => {
	// eslint-disable-next-line no-console
	console.log( `Serving ${ root } on http://localhost:${ port }` );
} );
