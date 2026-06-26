#!/usr/bin/env bash
# =============================================================================
#  gereadmin — gestion des comptes ADMIN d'EduConnect (créer / modifier / supprimer)
#  Lancer sur le VPS :  ./scripts/gereadmin.sh
#  Protégé par mot de passe (défaut "root", override : GEREADMIN_PASSWORD=...).
# =============================================================================
set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BACKEND="$ROOT/backend"
PASSWORD="${GEREADMIN_PASSWORD:-root}"

cd "$BACKEND" 2>/dev/null || { echo "✗ Dossier backend introuvable : $BACKEND"; exit 1; }
command -v php >/dev/null || { echo "✗ php introuvable"; exit 1; }

# --- Tinker helper : exécute du PHP (variables passées par l'environnement) ---
run_php() { php artisan tinker --execute="$1" 2>&1 | grep -vE '^\s*$|Psy Shell|INFO'; }

# --- Authentification ---
authenticate() {
  local tries=0 input
  while [ "$tries" -lt 3 ]; do
    read -rs -p "🔒 Mot de passe : " input; echo
    [ "$input" = "$PASSWORD" ] && return 0
    tries=$((tries + 1)); echo "   Incorrect ($tries/3)."
  done
  echo "Trop de tentatives — abandon."; exit 1
}

# --- Choix du scope ---
ask_scope() {
  local s
  echo "  Scope : 1) Général (tout)  2) Primaire & Maternel  3) Secondaire & Technique" >&2
  read -rp "  Choix [1] : " s
  case "$s" in
    2) echo "primary_maternal" ;;
    3) echo "secondary_technical" ;;
    *) echo "global" ;;
  esac
}

list_admins() {
  echo "── Comptes admin ───────────────────────────────────────"
  run_php '
$admins = App\Models\User::where("role","admin")->orderBy("id")->get();
echo str_pad("ID",5).str_pad("SCOPE",22).str_pad("EMAIL",32)."NOM\n";
foreach($admins as $a){
  printf("%-5d%-22s%-32s%s%s\n",$a->id,($a->admin_scope ?? "global"),$a->email,$a->name,$a->is_active?"":" [inactif]");
}
echo "Total : ".$admins->count()." admin(s)\n";
'
}

create_admin() {
  echo "── Créer un admin ──────────────────────────────────────"
  local name email pass scope
  read -rp "  Nom complet : " name
  read -rp "  Email       : " email
  read -rsp "  Mot de passe: " pass; echo
  [ -z "$name" ] || [ -z "$email" ] || [ -z "$pass" ] && { echo "✗ Nom, email et mot de passe requis."; return; }
  scope="$(ask_scope)"
  A_NAME="$name" A_EMAIL="$email" A_PASS="$pass" A_SCOPE="$scope" run_php '
$email = getenv("A_EMAIL");
if (App\Models\User::where("email",$email)->exists()) { echo "✗ Email déjà utilisé.\n"; return; }
$u = App\Models\User::create([
  "name" => getenv("A_NAME"),
  "email" => $email,
  "password" => getenv("A_PASS"),
  "role" => "admin",
  "admin_scope" => getenv("A_SCOPE"),
  "is_active" => true,
]);
echo "✓ Admin créé : ".$u->email."  (scope ".$u->admin_scope.", id ".$u->id.")\n";
'
}

modify_admin() {
  echo "── Modifier un admin (laisser vide = inchangé) ─────────"
  local email name newemail pass scope
  read -rp "  Email du compte à modifier : " email
  [ -z "$email" ] && { echo "✗ Email requis."; return; }
  read -rp "  Nouveau nom         : " name
  read -rp "  Nouvel email        : " newemail
  read -rsp "  Nouveau mot de passe: " pass; echo
  read -rp "  Changer le scope ? (o/N) : " chg
  scope=""; [ "$chg" = "o" ] || [ "$chg" = "O" ] && scope="$(ask_scope)"
  A_EMAIL="$email" A_NAME="$name" A_NEWEMAIL="$newemail" A_PASS="$pass" A_SCOPE="$scope" run_php '
$u = App\Models\User::where("email",getenv("A_EMAIL"))->where("role","admin")->first();
if(!$u){ echo "✗ Admin introuvable.\n"; return; }
if(getenv("A_NAME")!=="") $u->name = getenv("A_NAME");
if(getenv("A_NEWEMAIL")!=="") $u->email = getenv("A_NEWEMAIL");
if(getenv("A_PASS")!=="") $u->password = getenv("A_PASS");
if(getenv("A_SCOPE")!=="") $u->admin_scope = getenv("A_SCOPE");
$u->save();
echo "✓ Admin mis à jour : ".$u->email."\n";
'
}

delete_admin() {
  echo "── Supprimer un admin ──────────────────────────────────"
  local email confirm
  read -rp "  Email du compte à supprimer : " email
  [ -z "$email" ] && { echo "✗ Email requis."; return; }
  read -rp "  Confirmer la suppression de \"$email\" ? (oui/non) : " confirm
  [ "$confirm" != "oui" ] && { echo "Annulé."; return; }
  A_EMAIL="$email" run_php '
$u = App\Models\User::where("email",getenv("A_EMAIL"))->where("role","admin")->first();
if(!$u){ echo "✗ Admin introuvable.\n"; return; }
$isGlobal = ($u->admin_scope===null || $u->admin_scope==="global");
$globals = App\Models\User::where("role","admin")->where(function($q){ $q->whereNull("admin_scope")->orWhere("admin_scope","global"); })->count();
if($isGlobal && $globals<=1){ echo "✗ Refusé : dernier administrateur général, on ne peut pas le supprimer.\n"; return; }
$u->delete();
echo "✓ Admin supprimé : ".getenv("A_EMAIL")."\n";
'
}

# --- Boucle principale ---
authenticate
echo ""
echo "╔══════════════════════════════════════════╗"
echo "║   EduConnect — Gestion des comptes ADMIN  ║"
echo "╚══════════════════════════════════════════╝"
while true; do
  echo ""
  echo "  1) Lister     2) Créer     3) Modifier     4) Supprimer     0) Quitter"
  read -rp "  Option : " opt
  case "$opt" in
    1) list_admins ;;
    2) create_admin ;;
    3) modify_admin ;;
    4) delete_admin ;;
    0) echo "À bientôt."; exit 0 ;;
    *) echo "  Option invalide." ;;
  esac
done
