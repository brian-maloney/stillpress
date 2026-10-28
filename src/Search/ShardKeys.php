<?php
/**
 * Decides how the search index is split into files.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

/**
 * Groups terms into shards by prefix. Pure: no WordPress calls.
 *
 * Every term starts in the shard named by its first BASE_LENGTH characters (or the whole term when shorter). A shard
 * with more postings than the limit is split: terms exactly as long as its key stay, the rest move to shards one
 * character longer, recursively. A term belongs to the longest key that is a prefix of it, and only keys holding at
 * least one term exist, so the client never fetches a missing file (D20).
 */
final class ShardKeys {

	/** Characters in a top-level key. */
	const BASE_LENGTH = 2;

	/** Default most postings in one shard before it splits (about 20 KB of JSON). */
	const DEFAULT_LIMIT = 2000;

	/**
	 * Plans the shard keys for a set of terms.
	 *
	 * @param array<string,int> $postings Term => number of documents holding it.
	 * @param int               $limit    Most postings per shard before it splits.
	 * @return string[] Keys, sorted.
	 */
	public static function plan( array $postings, int $limit = self::DEFAULT_LIMIT ): array {
		$groups = array();
		foreach ( $postings as $term => $count ) {
			$term                    = (string) $term;
			$key                     = self::prefix( $term, self::BASE_LENGTH );
			$groups[ $key ][ $term ] = (int) $count;
		}

		$keys = array();
		foreach ( $groups as $key => $terms ) {
			self::split( (string) $key, $terms, max( 1, $limit ), $keys );
		}
		sort( $keys, SORT_STRING );
		return $keys;
	}

	/**
	 * Returns the shard key a term belongs to, or null when no shard can hold it.
	 *
	 * @param string   $term Normalized term.
	 * @param string[] $keys Keys from plan().
	 * @return string|null
	 */
	public static function key_for( string $term, array $keys ): ?string {
		$lookup = array_flip( $keys );
		for ( $length = self::length( $term ); $length > 0; $length-- ) {
			$prefix = self::prefix( $term, $length );
			if ( isset( $lookup[ $prefix ] ) ) {
				return $prefix;
			}
		}
		return null;
	}

	/**
	 * Returns the shard's file name: the hex of its UTF-8 bytes, so any script makes a safe path.
	 *
	 * @param string $key Shard key.
	 * @return string
	 */
	public static function file_name( string $key ): string {
		return bin2hex( $key ) . '.json';
	}

	/**
	 * Adds a group's keys to $keys, splitting it while it is over the limit.
	 *
	 * @param string            $key   Group key.
	 * @param array<string,int> $terms Term => postings, all starting with $key.
	 * @param int               $limit Most postings per shard.
	 * @param string[]          $keys  Keys found so far.
	 * @return void
	 */
	private static function split( string $key, array $terms, int $limit, array &$keys ): void {
		$length = self::length( $key );
		if ( array_sum( $terms ) <= $limit ) {
			$keys[] = $key;
			return;
		}

		$children = array();
		$own      = false;
		foreach ( $terms as $term => $count ) {
			$term = (string) $term;
			if ( self::length( $term ) === $length ) {
				$own = true;
				continue;
			}
			$children[ self::prefix( $term, $length + 1 ) ][ $term ] = $count;
		}
		if ( $own ) {
			$keys[] = $key;
		}
		foreach ( $children as $child => $child_terms ) {
			self::split( (string) $child, $child_terms, $limit, $keys );
		}
	}

	/**
	 * First characters of a string.
	 *
	 * @param string $text   UTF-8 text.
	 * @param int    $length Characters.
	 * @return string
	 */
	private static function prefix( string $text, int $length ): string {
		return implode( '', array_slice( (array) preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ), 0, $length ) );
	}

	/**
	 * Length in characters.
	 *
	 * @param string $text UTF-8 text.
	 * @return int
	 */
	private static function length( string $text ): int {
		return (int) preg_match_all( '/./us', $text );
	}
}
