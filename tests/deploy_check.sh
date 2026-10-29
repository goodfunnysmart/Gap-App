#!/bin/bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
HOME_DIR="$(mktemp -d)"
export HOME="$HOME_DIR"
export SITE_ROOT="$HOME_DIR/betterschools.com.au"
# Preserve a Directory Privacy block and an existing private config.
mkdir -p "$SITE_ROOT/gap-app" "$HOME_DIR/gap-app-private"
cat > "$SITE_ROOT/gap-app/.htaccess" <<'EOF'
# existing
# Section managed by cPanel: cp:ppd start
AuthUserFile "/home/example/.htpasswds/gap-app/passwd"
AuthType Basic
Require valid-user
# End section managed by cPanel: cp:ppd
EOF
printf '%s\n' '<?php return ["FEEDBACK_TO"=>"kept@example.com","FEEDBACK_FROM"=>"noreply@betterschools.com.au","GEMINI_API_KEY"=>"kept-secret","GEMINI_MODEL"=>"gemini-3.5-flash-lite"];' > "$HOME_DIR/gap-app-private/config.php"
before="$(cat "$HOME_DIR/gap-app-private/config.php")"
bash "$ROOT/deploy/cpanel-deploy.sh"
test -f "$SITE_ROOT/gap-app/index.html"
test -f "$SITE_ROOT/gap-app/identify.php"
test -f "$SITE_ROOT/gap-app/plan.php"
test -f "$SITE_ROOT/gap-app/wording.php"
test -f "$SITE_ROOT/gap-app/feedback.php"
test -f "$SITE_ROOT/gap-app/data/marble.json"
test -f "$SITE_ROOT/gap-app/assets/logo-classic.webp"
test -f "$SITE_ROOT/gap-app/assets/sample-task.png"
test -f "$SITE_ROOT/gap-app/private-path.php"
test -f "$HOME_DIR/gap-app-private/bootstrap.php"
test -f "$HOME_DIR/gap-app-private/gemini.php"
test -f "$HOME_DIR/gap-app-private/marble.php"
test -f "$HOME_DIR/gap-app-private/config.example.php"
test ! -f "$HOME_DIR/gap-app-private/config.php.bak"
test -d "$HOME_DIR/gap-app-private/throttle"
grep -q 'cp:ppd' "$SITE_ROOT/gap-app/.htaccess"
grep -q 'AuthUserFile' "$SITE_ROOT/gap-app/.htaccess"
grep -q 'Options -Indexes' "$SITE_ROOT/gap-app/.htaccess"
after="$(cat "$HOME_DIR/gap-app-private/config.php")"
test "$before" = "$after"
php -r '$p=require $argv[1]; exit($p===$argv[2]?0:1);' "$SITE_ROOT/gap-app/private-path.php" "$HOME_DIR/gap-app-private"
grep -q 'info@betterschools.com.au' "$HOME_DIR/gap-app-private/config.example.php"
grep -q 'gemini-3.5-flash-lite' "$HOME_DIR/gap-app-private/config.example.php"
grep -q 'kept@example.com' "$HOME_DIR/gap-app-private/config.php"
grep -q 'kept-secret' "$HOME_DIR/gap-app-private/config.php"
# The live config's secret must not be copied into the website.
if grep -R -q 'kept-secret' "$SITE_ROOT/gap-app"; then
  echo "secret leaked into the website" >&2
  exit 1
fi
bash "$ROOT/deploy/cpanel-deploy.sh"
grep -q 'AuthUserFile' "$SITE_ROOT/gap-app/.htaccess"
echo "deploy checks passed"
rm -rf "$HOME_DIR"
