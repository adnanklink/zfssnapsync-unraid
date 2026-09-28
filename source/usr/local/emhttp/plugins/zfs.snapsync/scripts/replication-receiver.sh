#!/bin/bash
# Delivered over an authenticated SSH connection; no receiver installation.
# Runtime records are RAM-only. A revoke fences even an as-yet unstarted run.
set -Eeuo pipefail
export LC_ALL=C
umask 077
fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $# -ge 4 ]] || fail 'Incomplete receiver ownership request.'
mode=$1 boot=$2 token=$3 pool_guid=$4
shift 4
[[ "$mode" == run || "$mode" == revoke ]] || fail 'Invalid receiver operation.'
[[ "$boot" =~ ^[a-f0-9-]{36}$ && "$token" =~ ^[a-f0-9]{48}$ && "$pool_guid" =~ ^[0-9]{1,20}$ ]] || fail 'Invalid receiver identity.'
actual_boot=$(cat /proc/sys/kernel/random/boot_id)
[[ "$(stat -f -c %T /dev/shm)" == tmpfs ]] || fail 'Receiver ownership requires RAM-backed /dev/shm.'
root="/dev/shm/zfs-snapsync-receiver-$UID"
secure_dir() {
  local path=$1
  [[ ! -L "$path" ]] || fail 'Receiver ownership path is a symbolic link.'
  if [[ ! -d "$path" ]]; then mkdir -m 0700 "$path" 2>/dev/null || [[ -d "$path" ]] || fail 'Cannot create receiver ownership storage.'; fi
  [[ "$(stat -c '%u:%a' "$path")" == "$UID:700" ]] || fail 'Receiver ownership storage has unsafe permissions.'
}
secure_dir "$root"
dir="$root/$token"
secure_dir "$dir"
for file in mutex live owner revoked result; do [[ ! -L "$dir/$file" ]] || fail 'Unsafe receiver ownership record.'; done
exec {mutex}>"$dir/mutex"
flock -x "$mutex"

identity() {
  local pid=$1 stat rest
  [[ "$pid" =~ ^[1-9][0-9]*$ ]] || return 1
  stat=$(cat "/proc/$pid/stat" 2>/dev/null) || return 1
  rest=${stat##*) }
  local -a fields
  read -r -a fields <<< "$rest"
  [[ ${#fields[@]} -ge 20 && "${fields[2]}" =~ ^[0-9]+$ && "${fields[19]}" =~ ^[0-9]+$ ]] || return 1
  printf '%s %s %s\n' "${fields[0]}" "${fields[2]}" "${fields[19]}"
}

if [[ "$mode" == revoke ]]; then
  [[ $# == 0 ]] || fail 'Unexpected revocation arguments.'
  # Persist the fence while holding the same mutex as startup, before looking
  # for a process. A delayed SSH session cannot slip behind a stopped response.
  if [[ ! -f "$dir/revoked" ]]; then
    read -r uptime _ < /proc/uptime
    printf '%s\n' "${uptime%%.*}" > "$dir/revoked"
  fi
  if [[ ! -f "$dir/owner" ]]; then printf '%s\n' stopped; exit 0; fi
  read -r owner_boot pid start owner_pool < "$dir/owner"
  [[ "$owner_boot" == "$boot" && "$owner_pool" == "$pool_guid" && "$pid" =~ ^[1-9][0-9]*$ && "$start" =~ ^[0-9]+$ ]] || fail 'Receiver ownership record does not match this attempt.'
  if [[ "$actual_boot" != "$boot" ]]; then printf '%s\n' stopped; exit 0; fi
  leader=$(identity "$pid") || leader=''
  if [[ -n "$leader" ]]; then
    read -r state group current_start <<< "$leader"
    if [[ "$group" != "$pid" || "$current_start" != "$start" ]]; then printf '%s\n' ambiguous; exit 0; fi
  fi
  read -r revoked < "$dir/revoked"
  [[ "$revoked" =~ ^[0-9]+$ ]] || fail 'Invalid receiver revocation record.'
  read -r uptime _ < /proc/uptime
  signal=TERM
  (( ${uptime%%.*} - revoked < 2 )) || signal=KILL
  found=0
  for path in /proc/[0-9]*/stat; do
    member=${path#/proc/}; member=${member%/stat}
    before=$(identity "$member") || continue
    read -r state group current_start <<< "$before"
    [[ "$group" == "$pid" && "$state" != Z ]] || continue
    found=1
    # Never signal a PID that disappeared and was reused during enumeration.
    after=$(identity "$member") || continue
    [[ "$before" == "$after" ]] || continue
    kill -s "$signal" "$member" 2>/dev/null || true
  done
  if (( found )); then printf '%s\n' running; exit 0; fi
  exec {live}>"$dir/live"
  if flock -n -x "$live"; then printf '%s\n' stopped; else printf '%s\n' ambiguous; fi
  exit 0
fi

# A run must already be in its own session (setsid bash -c ... over SSH).
# The ownership record is durable before any command can mutate the receiver.
[[ "$actual_boot" == "$boot" ]] || fail 'Receiver rebooted after inspection.'
[[ ! -e "$dir/revoked" && ! -e "$dir/owner" ]] || fail 'Receiver attempt was revoked or already started.'
own=$(identity "$$") || fail 'Cannot inspect receiver worker identity.'
read -r _ group start <<< "$own"
[[ "$group" == "$$" ]] || fail 'Receiver worker requires an independent process group.'
[[ $# == 4 ]] || fail 'Incomplete receiver mutation capture.'
action=$1 dataset=$2 expected=$3 readonly=$4
[[ "$action" == receive || "$action" == readonly ]] || fail 'Unsupported receiver mutation.'
[[ "$dataset" =~ ^[A-Za-z0-9][A-Za-z0-9_.:+-]*(/[A-Za-z0-9_.:+-]+)+$ ]] || fail 'Invalid receiver dataset.'
[[ "$expected" =~ ^(absent:)?[0-9]{1,20}$ && ( "$readonly" == on || "$readonly" == off ) ]] || fail 'Invalid captured receiver policy.'
[[ "$action" != readonly || "$expected" != absent:* ]] || fail 'Readonly requires an existing receiver.'
exec {live}>"$dir/live"
flock -n -x "$live" || fail 'Receiver attempt already owns a worker.'
printf '%s %s %s %s\n' "$boot" "$$" "$start" "$pool_guid" > "$dir/owner"
exec {mutex}>&-

# Match the local plugin namespace when the receiver also runs SnapSync.
# Incompatible permissions fail closed rather than creating a second namespace.
ops=/tmp/zfs-snapsync-ops
[[ ! -L "$ops" && ! -L "$ops/dataset-locks" ]] || fail 'Unsafe receiver lock directory.'
mkdir -p "$ops/dataset-locks"
gate_permissions() {
  local path=$1 mode=$2
  chmod "$mode" "$path" 2>/dev/null || true
  if (( UID == 0 )); then
    chown nobody "$path" 2>/dev/null || true
    chgrp users "$path" 2>/dev/null || true
  fi
}
gate_permissions "$ops" 0775
gate_permissions "$ops/dataset-locks" 0775
[[ ! -L "$ops/auto-cleanup.lock" ]] || fail 'Unsafe receiver cleanup gate.'
exec {auto}>"$ops/auto-cleanup.lock"
gate_permissions "$ops/auto-cleanup.lock" 0660
flock -n -s "$auto" || fail 'Receiver cleanup owns the datasets.'
locks=()
while IFS= read -r ancestor; do
  key=$(printf '%s' "$ancestor" | sha256sum); key=${key%% *}
  path="$ops/dataset-locks/$key.lock"
  [[ ! -L "$path" ]] || fail 'Unsafe receiver dataset gate.'
  exec {fd}>"$path"
  gate_permissions "$path" 0660
  flock -n -x "$fd" || fail 'Another operation owns the receiver dataset.'
  locks+=("$fd")
done < <(ancestor=$dataset; while :; do printf '%s\n' "$ancestor"; [[ "$ancestor" == */* ]] || break; ancestor=${ancestor%/*}; done | sort -u)

[[ "$(zpool get -H -p -o value guid "${dataset%%/*}")" == "$pool_guid" ]] || fail 'Receiver pool identity changed.'
if [[ "$expected" == absent:* ]]; then
  parent=${dataset%/*}
  [[ "$(zfs get -H -p -o value guid -- "$parent")" == "${expected#absent:}" ]] || fail 'Receiver parent identity changed.'
  inventory=$(zfs list -H -o name -r -d 1 -- "$parent") || fail 'Receiver parent inventory is unavailable.'
  parent_seen=0
  while IFS= read -r name; do
    [[ "$name" != "$dataset" ]] || fail 'New receiver path is now occupied.'
    [[ "$name" != "$parent" ]] || parent_seen=1
  done <<< "$inventory"
  (( parent_seen )) || fail 'Receiver parent inventory is incomplete.'
else
  [[ "$(zfs get -H -p -o value guid -- "$dataset")" == "$expected" ]] || fail 'Receiver dataset identity changed.'
fi
[[ ! -e "$dir/revoked" ]] || fail 'Receiver attempt was revoked.'
# There is deliberately no general command execution, rollback, or forced receive.
# Locks and live ownership are inherited by the mutation and pipeline children.
if [[ "$action" == readonly ]]; then
  zfs set "readonly=$readonly" "$dataset"
else
  zfs receive -s -u -o "readonly=$readonly" -- "$dataset"
fi
printf '%s\n' success > "$dir/result"
