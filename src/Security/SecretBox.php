<?php
/**
 * Authenticated encryption for stored secrets.
 *
 * @package Stillpress
 */

namespace Stillpress\Security;

/**
 * Seals and opens secrets with libsodium `secretbox` (XSalsa20-Poly1305).
 *
 * Each secret gets its own key, derived with HKDF-SHA256 from the key material and a context string (site, deployer,
 * field). A sealed value therefore only opens in the slot it was written to: copying one field's ciphertext into
 * another field's option fails authentication instead of revealing the first field's value elsewhere.
 *
 * Sealed format: `sp1:` followed by base64 of nonce || ciphertext. The prefix versions the scheme.
 */
final class SecretBox {

	/** Format prefix. */
	const PREFIX = 'sp1:';

	/**
	 * Input key material.
	 *
	 * @var string
	 */
	private $key_material;

	/**
	 * Creates a box over the given key material.
	 *
	 * @param string $key_material High-entropy secret (at least 32 bytes), for example the wp-config salts.
	 * @throws \InvalidArgumentException When the key material is too short.
	 */
	public function __construct( string $key_material ) {
		if ( strlen( $key_material ) < 32 ) {
			throw new \InvalidArgumentException( 'Secret key material must be at least 32 bytes.' );
		}
		$this->key_material = $key_material;
	}

	/**
	 * Encrypts a value for one context.
	 *
	 * @param string $plaintext Secret value.
	 * @param string $context   Slot the value belongs to.
	 * @return string Sealed value.
	 */
	public function seal( string $plaintext, string $context ): string {
		$key    = $this->key( $context );
		$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		$sealed = self::PREFIX . base64_encode( $nonce . sodium_crypto_secretbox( $plaintext, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Binary ciphertext stored in an option.
		sodium_memzero( $key );
		return $sealed;
	}

	/**
	 * Decrypts a value sealed for the same context.
	 *
	 * @param string $sealed  Sealed value.
	 * @param string $context Slot the value belongs to.
	 * @return string|null The secret, or null when the value is malformed, tampered with, from another context,
	 *                     or sealed under different key material (rotated salts).
	 */
	public function open( string $sealed, string $context ): ?string {
		if ( 0 !== strpos( $sealed, self::PREFIX ) ) {
			return null;
		}
		$raw = base64_decode( substr( $sealed, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- See seal().
		if ( false === $raw || strlen( $raw ) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES ) {
			return null;
		}
		$key   = $this->key( $context );
		$plain = sodium_crypto_secretbox_open(
			substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ),
			$key
		);
		sodium_memzero( $key );
		return false === $plain ? null : $plain;
	}

	/**
	 * Derives the key for one context.
	 *
	 * @param string $context Slot.
	 * @return string
	 */
	private function key( string $context ): string {
		return hash_hkdf( 'sha256', $this->key_material, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, 'stillpress secrets v1|' . $context );
	}
}
