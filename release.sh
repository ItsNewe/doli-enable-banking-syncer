#!/bin/sh
# Set the module version to vX.Y.Z and commit it. -g creates the git tag, -p also pushes the branch and the tag.
set -e
cd "$(dirname "$0")"
usage() { echo "Usage: $0 [-g] [-p] vX.Y.Z" >&2; exit 1; }
TAG=0; PUSH=0
while getopts gp opt; do
	case $opt in
		g) TAG=1 ;;
		p) TAG=1; PUSH=1 ;;
		*) usage ;;
	esac
done
shift $((OPTIND - 1))
echo "$1" | grep -Eq '^v[0-9]+\.[0-9]+\.[0-9]+$' || usage
VERSION=${1#v}
FILE=core/modules/modEnableBanking.class.php
git rev-parse -q --verify "refs/tags/$1" >/dev/null && { echo "Tag $1 already exists" >&2; exit 1; }
sed -i.bak "s/\(\$this->version = '\)[^']*'/\1$VERSION'/" "$FILE" && rm -f "$FILE.bak"
git diff --quiet -- "$FILE" && { echo "Version already $VERSION" >&2; exit 1; }
git commit -m "NEW: Version $VERSION" -- "$FILE"
[ "$TAG" = 1 ] && git tag "$1"
[ "$PUSH" = 1 ] && git push origin HEAD "$1"
echo "Version $VERSION committed"
