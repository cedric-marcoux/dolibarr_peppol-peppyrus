#!/bin/bash
#------------------------------------------------------
# Push language files to Transifex.
# Adapted from DoliMods (dev/translation/txpush.sh, Laurent Destailleur, GPL-3)
# for the layout of this module (langs/ in the root).
#------------------------------------------------------
# Usage: dev/translation/txpush.sh (source|xx_XX) [tx options]
#   source  push langs/en_US (the source) after editing it
#   xx_XX   push a local translation into Transifex (skipped if Transifex is newer, -f forces)
#------------------------------------------------------

project='dolibarr-peppol-peppyrus'

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR/../.." || exit 1

if [ -z "$1" ]; then
	echo "Push local files to Transifex."
	echo "Note: a translation (not the source) is skipped if the Transifex file is newer; -f overwrites the translation but not the memory."
	echo "Usage: ./dev/translation/txpush.sh (source|xx_XX) [-r $project.peppol] [-f] [--no-interactive]"
	exit 0
fi

if [ ! -d ".tx" ]; then
	echo "Run this script from the root of the repository: ./dev/translation/txpush.sh"
	exit 1
fi

lang="$1"
shift
if [ "$lang" = "source" ]; then
	echo "tx push -s $*"
	tx push -s "$@"
else
	for file in langs/"$lang"/*.lang; do
		[ -f "$file" ] || continue
		basefile=$(basename "$file" .lang)
		echo "tx push --skip -r $project.$basefile -t -l $lang $*"
		tx push --skip -r "$project.$basefile" -t -l "$lang" "$@"
	done
fi
