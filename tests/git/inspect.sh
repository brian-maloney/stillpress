#!/bin/sh
# Test-only helper. Query: action=create|refs|tree|show|log&repo=<name>[&ref=<branch>][&path=<file>]
# Names are restricted to safe characters before they reach git.
printf 'Content-Type: text/plain\r\n\r\n'
param() { printf '%s' "$QUERY_STRING" | tr '&' '\n' | sed -n "s/^$1=//p" | head -n 1; }
action=$(param action); repo=$(param repo); ref=$(param ref); path=$(param path)
case "$repo" in *[!a-z0-9-]*|'') echo "bad repo"; exit 0;; esac
case "$ref" in *[!A-Za-z0-9._/-]*) echo "bad ref"; exit 0;; esac
case "$path" in *[!A-Za-z0-9._/-]*|*..*) echo "bad path"; exit 0;; esac
dir=/srv/git/$repo.git
case "$action" in
	create) git init -q --bare "$dir" && echo created ;;
	seed)
		# A repository whose gh-pages branch has a commit made elsewhere, for the ownership tests.
		export HOME=/tmp
		git init -q --bare "$dir" && work=$(mktemp -d) && git clone -q "$dir" "$work" 2>/dev/null \
			&& cd "$work" && git checkout -q -b gh-pages && echo "made elsewhere" > README.md && git add README.md \
			&& git commit -q -m "Not made by Stillpress" && git push -q origin gh-pages 2>/dev/null && cd / && rm -rf "$work" \
			&& echo seeded ;;
	refs) git --git-dir="$dir" for-each-ref --format='%(refname) %(objectname)' ;;
	tree) git --git-dir="$dir" ls-tree -r --name-only "refs/heads/$ref" ;;
	show) git --git-dir="$dir" show "refs/heads/$ref:$path" ;;
	log) git --git-dir="$dir" log --format='%H %P|%an <%ae>|%s' "refs/heads/$ref" ;;
	fsck) git --git-dir="$dir" fsck --full 2>&1; echo "exit=$?" ;;
	*) echo "bad action" ;;
esac
