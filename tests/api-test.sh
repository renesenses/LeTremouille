#!/usr/bin/env bash
# Test de l'API en local : php -S, envoi de courriel neutralisé (TREMOUILLE_MAIL_DRYRUN=1).
# Usage : tests/api-test.sh [chemin/vers/index.php]   (défaut : api/index.php)
set -u
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
INDEX="${1:-$ROOT/api/index.php}"
PORT="${PORT:-8791}"
BASE="http://127.0.0.1:$PORT"
fail=0

RESEND_API_KEY=re_test_factice TREMOUILLE_MAIL_DRYRUN=1 \
  php -S "127.0.0.1:$PORT" "$INDEX" >/dev/null 2>&1 &
PID=$!
trap 'kill $PID 2>/dev/null' EXIT
for _ in $(seq 1 30); do curl -s -o /dev/null "$BASE/" && break; sleep 0.2; done

DATE="$(date -v+7d +%F 2>/dev/null || date -d '+7 days' +%F)"
LONG="$(head -c 5000 /dev/zero | tr '\0' 'a')"

# expect <nom> <code attendu> <endpoint> [options curl...]
expect() {
  local name="$1" want="$2" ep="$3"; shift 3
  local got
  got="$(curl -s -o /dev/null -w '%{http_code}' -X POST "$BASE$ep" "$@")"
  if [ "$got" = "$want" ]; then echo "ok   $name ($got)"; else echo "ECHEC $name : attendu $want, obtenu $got"; fail=1; fi
}
resa() { # champs de base d'une réservation légitime, puis surcharges name=...
  local n=Alice g=2 t=20:00 p='06 12 34 56 78'
  for kv in "$@"; do case "$kv" in n=*) n="${kv#n=}";; g=*) g="${kv#g=}";; t=*) t="${kv#t=}";; p=*) p="${kv#p=}";; esac; done
  printf -- '--form-string\nname=%s\n--form-string\nemail=a@example.org\n--form-string\nphone=%s\n--form-string\nguests=%s\n--form-string\ndate=%s\n--form-string\ntime=%s\n--form-string\nmessage=Allergie noix\n' "$n" "$p" "$g" "$DATE" "$t"
}
run() { local name="$1" want="$2" ep="$3"; shift 3; local a=(); while IFS= read -r l; do a+=("$l"); done < <(resa "$@"); expect "$name" "$want" "$ep" "${a[@]}"; }

run "réservation légitime (FormData)"          200 /reservation
run "réservation 7+ personnes"                 200 /reservation g=7+
run "couverts hors bornes (50)"                422 /reservation g=50
run "couverts négatifs"                        422 /reservation g=-3
run "créneau hors liste"                       422 /reservation t=03:00
run "nom trop long"                            422 /reservation "n=$(head -c 300 /dev/zero | tr '\0' 'a')"
expect "retour chariot dans le nom"            422 /reservation --form-string "name=$(printf 'Alice\r\nBcc: x@example.org')" --form-string email=a@example.org --form-string phone=0612345678 --form-string guests=2 --form-string "date=$DATE" --form-string time=20:00
run "téléphone absurde"                        422 /reservation "p=<script>"
expect "date passée"                           422 /reservation --form-string name=A --form-string email=a@example.org --form-string phone=0612345678 --form-string guests=2 --form-string date=2020-01-01 --form-string time=20:00
expect "date impossible (31 février)"         422 /reservation --form-string name=A --form-string email=a@example.org --form-string phone=0612345678 --form-string guests=2 --form-string date=2027-02-31 --form-string time=20:00
expect "date trop lointaine"                   422 /reservation --form-string name=A --form-string email=a@example.org --form-string phone=0612345678 --form-string guests=2 --form-string date=2099-01-01 --form-string time=20:00
expect "message trop long"                     422 /reservation --form-string name=A --form-string email=a@example.org --form-string phone=0612345678 --form-string guests=2 --form-string "date=$DATE" --form-string time=20:00 --form-string "message=$LONG"
expect "champ en tableau (name[])"             400 /reservation --form-string 'name[]=x' --form-string email=a@example.org
expect "devis légitime"                        200 /contact --form-string name=Bob --form-string email=b@example.org --form-string phone=0612345678 --form-string prestation=Privatisation --form-string "date=$DATE" --form-string guests=30 --form-string message=Bonjour
expect "devis sans date ni convives"           200 /contact --form-string name=Bob --form-string email=b@example.org --form-string phone=0612345678 --form-string prestation=Autre --form-string message=Bonjour
expect "devis prestation hors liste"           422 /contact --form-string name=Bob --form-string email=b@example.org --form-string phone=0612345678 --form-string prestation=Piratage --form-string message=Bonjour
expect "devis retour chariot dans le nom"      422 /contact --form-string "name=$(printf 'Bob\nBcc: x@example.org')" --form-string email=b@example.org --form-string phone=0612345678 --form-string prestation=Autre --form-string message=Bonjour
expect "JSON légitime"                         200 /reservation -H 'Content-Type: application/json' -d "{\"name\":\"Alice\",\"email\":\"a@example.org\",\"phone\":\"0612345678\",\"guests\":\"2\",\"date\":\"$DATE\",\"time\":\"20:00\"}"
expect "JSON invalide"                         400 /reservation -H 'Content-Type: application/json' -d '{oups'
expect "JSON avec couverts en entier"          200 /reservation -H 'Content-Type: application/json' -d "{\"name\":\"Alice\",\"email\":\"a@example.org\",\"phone\":\"0612345678\",\"guests\":2,\"date\":\"$DATE\",\"time\":\"20:00\"}"
head -c 100000 /dev/zero | tr '\0' 'a' > "${TMPDIR:-/tmp}/tremouille-gros.json.$$"
expect "corps trop gros (JSON, 100 Ko)"        413 /reservation -H 'Content-Type: application/json' --data-binary "@${TMPDIR:-/tmp}/tremouille-gros.json.$$"
expect "corps trop gros (FormData, 100 Ko)"    413 /reservation --form-string "message=$(head -c 100000 /dev/zero | tr '\0' 'a')" --form-string name=A
rm -f "${TMPDIR:-/tmp}/tremouille-gros.json.$$"

[ "$fail" = 0 ] && echo "TOUT PASSE" || echo "DES TESTS ECHOUENT"
exit "$fail"
