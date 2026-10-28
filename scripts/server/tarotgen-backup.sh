#!/usr/bin/env bash
#
# Nightly online backup of the TarotGen.io SQLite database (AUDIT.md §1.1).
#
# Installed on the droplet as /usr/local/sbin/tarotgen-backup and run by
# /etc/cron.d/tarotgen (scripts/server/tarotgen.cron). Runs as root.
#
#   1. `.backup` the live DB — online-safe under WAL, so no downtime — into a
#      staging file beside it, as www-data (see AGENTS.md §7: files created in
#      the DB's directory must belong to the web-server user).
#   2. Refuse the copy unless `PRAGMA integrity_check` says ok.
#   3. Move it into the root-only backup directory, gzip it, keep the newest
#      $KEEP. Backups never live in the web root (AUDIT.md §1.0).
#
# Local rotation only: this survives a bad migration or corruption, not the
# loss of the droplet. Logs to syslog under the tag `tarotgen-backup`
# (`journalctl -t tarotgen-backup`).
set -euo pipefail

DATA_DIR="${TAROT_DATA_DIR:-/var/lib/tarotgen}"
DB="$DATA_DIR/db/tarotdb.db"
DEST="${TAROT_BACKUP_DIR:-/var/backups/tarotgen/nightly}"
KEEP="${TAROT_BACKUP_KEEP:-14}"
STAMP="$(date -u +%Y%m%d-%H%M%S)"
STAGING="$DATA_DIR/db/.backup-$STAMP.db"
TARGET="$DEST/tarotdb-$STAMP.db"

log() { echo "$*"; logger -t tarotgen-backup -- "$*"; }
fail() { log "FAILED: $*"; rm -f "$STAGING"; exit 1; }

[ -f "$DB" ] || fail "no database at $DB"
install -d -m 700 -o root -g root "$DEST"

runuser -u www-data -- sqlite3 "$DB" ".backup '$STAGING'" || fail "sqlite3 .backup"
# The copy inherits the live DB's WAL flag; switch it to a plain rollback-journal
# file so it is self-contained (opening it never spawns -wal/-shm side files)
# and restores with nothing more than gunzip.
runuser -u www-data -- sqlite3 "$STAGING" 'PRAGMA journal_mode=DELETE;' >/dev/null || fail "journal_mode"

check="$(sqlite3 -readonly "$STAGING" 'PRAGMA integrity_check;')" || fail "integrity_check could not run"
[ "$check" = "ok" ] || fail "integrity_check: $check"
readings="$(sqlite3 -readonly "$STAGING" 'SELECT count(*) FROM readings;')"

mv "$STAGING" "$TARGET"
chown root:root "$TARGET"
chmod 600 "$TARGET"
gzip -9 "$TARGET"

# Newest first; drop everything past the newest $KEEP.
mapfile -t old < <(ls -1t "$DEST"/tarotdb-*.db.gz 2>/dev/null | tail -n +"$((KEEP + 1))")
if [ "${#old[@]}" -gt 0 ]; then
    rm -f -- "${old[@]}"
fi

log "ok: $TARGET.gz ($(stat -c %s "$TARGET.gz") bytes, $readings readings); kept $(ls -1 "$DEST"/tarotdb-*.db.gz | wc -l), pruned ${#old[@]}"
