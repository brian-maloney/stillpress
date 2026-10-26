#!/bin/sh
# Starts or stops the test-only Git HTTPS server (tests/git) on the wp-env Docker network, reachable as git.test.
set -eu
cd "$(dirname "$0")/.."
NAME=stillpress-git-test
case "${1:-start}" in
	start)
		NETWORK=$(docker network ls --format '{{.Name}}' | grep -E '^wp-env-stillpress-[0-9a-f]+_default$' | head -n 1)
		if [ -z "$NETWORK" ]; then
			echo "wp-env isn't running. Run npm run env:start first." >&2
			exit 1
		fi
		docker build -q -t "$NAME" tests/git >/dev/null
		docker rm -f "$NAME" >/dev/null 2>&1 || true
		docker run -d --rm --name "$NAME" --network "$NETWORK" --network-alias git.test "$NAME" >/dev/null
		mkdir -p tests/git/.keys
		docker cp "$NAME":/etc/git-test/cert.pem tests/git/.keys/cert.pem >/dev/null
		echo "Git test server running as git.test on $NETWORK"
		;;
	stop)
		docker rm -f "$NAME" >/dev/null 2>&1 || true
		;;
	*)
		echo "Usage: $0 start|stop" >&2
		exit 2
		;;
esac
