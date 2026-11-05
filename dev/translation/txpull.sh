#!/bin/bash
# SPDX-License-Identifier: GPL-3.0-or-later
#------------------------------------------------------
# Pull language files from Transifex.
# Adapted from DoliMods (dev/translation/txpull.sh, Laurent Destailleur, GPL-3)
# for the layout of this module (langs/ in the root).
#------------------------------------------------------
# Usage: dev/translation/txpull.sh (all|xx_XX) [tx options]
#   all     every language in langs/ except en_US
#   xx_XX   one language (skipped if the local file is newer, -f overwrites)
#------------------------------------------------------

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" >/dev/null 2>&1 && pwd)"
cd "$DIR/../.." || exit 1

if [ -z "$1" ]; then
	echo "Pull translated files from Transifex into langs/."
	echo "Note: a language file is skipped if the local file is newer; -f overwrites it (not with 'all')."
	echo "Usage: ./dev/translation/txpull.sh (all|xx_XX) [-r dolibarr-peppol-peppyrus.peppol] [-f]"
	exit 0
fi

if [ ! -d ".tx" ]; then
	echo "Run this script from the root of the repository: ./dev/translation/txpull.sh"
	exit 1
fi

lang="$1"
shift
if [ "$lang" = "all" ]; then
	for dir in langs/*/; do
		l=$(basename "$dir")
		if [ "$l" != "en_US" ]; then
			echo "tx pull -l $l $*"
			tx pull -l "$l" "$@"
		fi
	done
else
	echo "tx pull -l $lang $*"
	tx pull -l "$lang" "$@"
fi
