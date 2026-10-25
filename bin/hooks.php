<?php
/**
 * Lists every action and filter the plugin fires, with its docblock.
 *
 * Usage:
 *   php bin/hooks.php           Print docs/HOOKS.md to stdout.
 *   php bin/hooks.php --check   Exit 1 if any hook lacks a documented docblock.
 *
 * Also used by tests/unit/HooksDocumentedTest.php.
 *
 * @package Stillpress
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Developer CLI script, not loaded by WordPress.

namespace Stillpress\Bin;

/**
 * Scans PHP files for do_action()/apply_filters() calls with literal hook names.
 *
 * @param string $dir Source directory.
 * @return array<int,array{name:string,type:string,file:string,line:int,doc:string,documented_in:string}>
 */
function find_hooks( string $dir ): array {
	$hooks    = array();
	$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		$tokens = token_get_all( (string) file_get_contents( $file->getPathname() ) );
		$doc    = '';
		$count  = count( $tokens );
		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( is_array( $t ) && T_DOC_COMMENT === $t[0] ) {
				$doc = $t[1];
				continue;
			}
			// A docblock belongs to the hook call in the same statement; statement boundaries end it.
			if ( ! is_array( $t ) && in_array( $t, array( ';', '{', '}' ), true ) ) {
				$doc = '';
				continue;
			}
			if ( ! is_array( $t ) || T_STRING !== $t[0] || ! in_array( $t[1], array( 'do_action', 'apply_filters', 'do_action_ref_array', 'apply_filters_ref_array' ), true ) ) {
				continue;
			}
			// Next meaningful token after "(" must be the hook name string.
			$j = $i + 1;
			while ( $j < $count && ( '(' === $tokens[ $j ] || ( is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) ) ) {
				++$j;
			}
			if ( ! is_array( $tokens[ $j ] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $j ][0] ) {
				continue;
			}
			$name = trim( $tokens[ $j ][1], '\'"' );
			if ( 0 !== strpos( $name, 'stillpress_' ) ) {
				$doc = '';
				continue;
				// Core hooks we re-fire (documented by WordPress).
			}
			$documented_in = '';
			if ( preg_match( '#This (?:action|filter) is documented in (\S+)#', $doc, $m ) ) {
				$documented_in = $m[1];
			}
			$hooks[] = array(
				'name'          => $name,
				'type'          => false !== strpos( $t[1], 'filter' ) ? 'filter' : 'action',
				'file'          => str_replace( dirname( $dir ) . '/', '', $file->getPathname() ),
				'line'          => (int) $t[2],
				'doc'           => '' === $documented_in ? $doc : '',
				'documented_in' => $documented_in,
			);
			$doc     = '';
		}//end for
	}//end foreach
	usort(
		$hooks,
		static function ( $a, $b ) {
			$by_name = strcmp( $a['name'], $b['name'] );
			return 0 !== $by_name ? $by_name : strcmp( $a['file'], $b['file'] );
		}
	);
	return $hooks;
}

/**
 * Problems with a hook's documentation, empty when fine.
 *
 * @param array<string,mixed> $hook Hook.
 * @return string[]
 */
function problems( array $hook ): array {
	if ( '' !== $hook['documented_in'] ) {
		return array();
	}
	$out = array();
	if ( '' === $hook['doc'] ) {
		$out[] = 'no docblock';
		return $out;
	}
	if ( ! preg_match( '/@since\s+\d/', $hook['doc'] ) ) {
		$out[] = 'no @since';
	}
	if ( '' === summary( $hook['doc'] ) ) {
		$out[] = 'no description';
	}
	return $out;
}

/**
 * The description lines of a docblock.
 *
 * @param string $doc Docblock.
 * @return string
 */
function summary( string $doc ): string {
	$lines = array();
	foreach ( preg_split( '/\R/', $doc ) as $line ) {
		$line = preg_replace( '#^\s*/?\*+/?\s?#', '', $line );
		if ( 0 === strpos( (string) $line, '@' ) ) {
			break;
		}
		$lines[] = rtrim( (string) $line );
	}
	return trim( implode( "\n", $lines ) );
}

/**
 * Tags of a docblock (@param lines and @return).
 *
 * @param string $doc Docblock.
 * @return string[]
 */
function params( string $doc ): array {
	preg_match_all( '/@param\s+(.+)/', $doc, $m );
	return array_map( 'trim', $m[1] );
}

/**
 * Renders docs/HOOKS.md.
 *
 * @param array<int,array<string,mixed>> $hooks Hooks.
 * @return string
 */
function markdown( array $hooks ): string {
	$out  = "# Actions and filters\n\n";
	$out .= "Every extension point the plugin offers. This file is generated from the source docblocks by\n";
	$out .= "`php bin/hooks.php > docs/HOOKS.md`; a unit test fails when a hook is missing its docblock.\n\n";
	$out .= "Deployers are registered with `stillpress_register_deployers`; see `src/Deploy/DeployerInterface.php` for the contract.\n\n";
	$out .= "## Actions you fire\n\n";
	$out .= "### `stillpress_record_dependency`\n\n";
	$out .= "Call `do_action( 'stillpress_record_dependency', \$type, \$key )` while a page renders to say it depends on something the\n";
	$out .= "recorder can't see, for example data your plugin reads with direct SQL. Types: `post`, `term`, `user` (IDs), `option`\n";
	$out .= "(name), `tq` (taxonomy name). It does nothing outside a static render.\n\n";
	$seen = array();
	foreach ( array(
		'action' => 'Actions',
		'filter' => 'Filters',
	) as $type => $title ) {
		$out .= "## {$title}\n\n";
		foreach ( $hooks as $hook ) {
			if ( $hook['type'] !== $type || '' === $hook['doc'] || isset( $seen[ $hook['name'] ] ) ) {
				continue;
			}
			$seen[ $hook['name'] ] = true;
			$out                  .= "### `{$hook['name']}`\n\n" . summary( $hook['doc'] ) . "\n\n";
			foreach ( params( $hook['doc'] ) as $param ) {
				$out .= '- `' . preg_replace( '/^(\S+)\s+(\$\S+)\s*/', '$1 $2` ', $param ) . "\n";
			}
			if ( preg_match( '/@since\s+(\S+)/', $hook['doc'], $m ) ) {
				$out .= "\nSince {$m[1]}. Fired in `{$hook['file']}`.\n\n";
			}
		}
	}
	return $out;
}

if ( isset( $argv ) && realpath( $argv[0] ) === __FILE__ ) {
	$hooks = find_hooks( dirname( __DIR__ ) . '/src' );
	if ( in_array( '--check', $argv, true ) ) {
		$bad = 0;
		foreach ( $hooks as $hook ) {
			$problems = problems( $hook );
			if ( $problems ) {
				++$bad;
				fwrite( STDERR, "{$hook['file']}:{$hook['line']} {$hook['name']}: " . implode( ', ', $problems ) . "\n" );
			}
		}
		exit( $bad ? 1 : 0 );
	}
	echo markdown( $hooks );
}
