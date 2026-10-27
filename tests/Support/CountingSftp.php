<?php
/**
 * An SFTP connection (phpseclib) that counts the requests it sends, for round-trip tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Support;

/**
 * Every SFTP request waits for a reply, so the number of requests sent is the number of network round trips (writes
 * of one file are the exception: phpseclib sends them back to back and collects the replies once).
 */
final class CountingSftp extends \phpseclib3\Net\SFTP {

	/** @var array<string,int> Request type => count. */
	public static $counts = array();

	/** @var array<int,string> Names of SFTP packet types. */
	const NAMES = array(
		3   => 'OPEN',
		4   => 'CLOSE',
		6   => 'WRITE',
		7   => 'LSTAT',
		11  => 'OPENDIR',
		12  => 'READDIR',
		13  => 'REMOVE',
		14  => 'MKDIR',
		15  => 'RMDIR',
		16  => 'REALPATH',
		17  => 'STAT',
		18  => 'RENAME',
		200 => 'EXTENDED',
	);

	/**
	 * Counts each SFTP packet: phpseclib hands them to the channel whole, a 4-byte length and then the type.
	 *
	 * @param int    $client_channel Channel.
	 * @param string $data           Packet.
	 */
	protected function send_channel_packet( $client_channel, $data ) {
		if ( self::CHANNEL === $client_channel && strlen( $data ) >= 5 ) {
			$type                  = ord( $data[4] );
			$name                  = self::NAMES[ $type ] ?? (string) $type;
			self::$counts[ $name ] = ( self::$counts[ $name ] ?? 0 ) + 1;
		}
		return parent::send_channel_packet( $client_channel, $data );
	}

	public static function reset_counts(): void {
		self::$counts = array();
	}

	/** Requests other than WRITE: each is one round trip. */
	public static function round_trips(): int {
		$total = 0;
		foreach ( self::$counts as $name => $count ) {
			if ( 'WRITE' !== $name ) {
				$total += $count;
			}
		}
		return $total;
	}
}
