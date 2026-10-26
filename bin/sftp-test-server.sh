#!/bin/sh
# Starts or stops the test-only SFTP server (tests/sftp) on the wp-env Docker network, reachable as sftp.test.
set -eu
cd "$(dirname "$0")/.."
NAME=stillpress-sftp-test
case "${1:-start}" in
	start)
		NETWORK=$(docker network ls --format '{{.Name}}' | grep -E '^wp-env-stillpress-[0-9a-f]+_default$' | head -n 1)
		if [ -z "$NETWORK" ]; then
			echo "wp-env isn't running. Run npm run env:start first." >&2
			exit 1
		fi
		# Test-only key pairs, generated per checkout and never committed (tests/sftp/.keys is git-ignored).
		KEYS=tests/sftp/.keys
		if [ ! -f "$KEYS/deploy_key" ] || [ ! -f "$KEYS/deploy_key_encrypted" ]; then
			mkdir -p "$KEYS"
			rm -f "$KEYS"/deploy_key*
			ssh-keygen -q -t ed25519 -N '' -C stillpress-test-only -f "$KEYS/deploy_key"
			ssh-keygen -q -t ed25519 -N 'test-passphrase' -C stillpress-test-only-encrypted -f "$KEYS/deploy_key_encrypted"
		fi
		cat "$KEYS/deploy_key.pub" "$KEYS/deploy_key_encrypted.pub" > "$KEYS/authorized_keys"
		docker build -q -t "$NAME" tests/sftp >/dev/null
		docker rm -f "$NAME" >/dev/null 2>&1 || true
		docker run -d --rm --name "$NAME" --network "$NETWORK" --network-alias sftp.test "$NAME" >/dev/null
		echo "SFTP test server running as sftp.test on $NETWORK"
		;;
	stop)
		docker rm -f "$NAME" >/dev/null 2>&1 || true
		;;
	*)
		echo "Usage: $0 start|stop" >&2
		exit 2
		;;
esac
