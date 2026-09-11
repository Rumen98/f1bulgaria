#!/usr/bin/env bash
# Linux регресия на deploy preflight и възстановяване след частичен pull.
# sudo bash scripts/deploy-selftest.sh — само временни репозитории в /tmp.
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
    echo "Тестът изисква root за временните root/www-data собственици." >&2
    exit 1
fi
id www-data >/dev/null

source_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
test_root="$(mktemp -d /tmp/padok-deploy-test.XXXXXX)"
[[ "$test_root" == /tmp/padok-deploy-test.* ]] || exit 1
trap 'rm -rf -- "$test_root"' EXIT
chmod 755 "$test_root"
export TEST_GIT="$(command -v git)"
export TEST_ORIGIN="$test_root/origin"
export TEST_CHECKOUT="$test_root/checkout"
export TEST_HOME="$test_root/home"
mkdir -p "$test_root/bin" "$TEST_HOME"

git init --quiet --initial-branch=main "$TEST_ORIGIN"
git -C "$TEST_ORIGIN" config user.name 'Deploy regression'
git -C "$TEST_ORIGIN" config user.email 'deploy-test@example.invalid'
mkdir -p "$TEST_ORIGIN/resources/js/game" "$TEST_ORIGIN/scripts/game"
printf '.env\n' > "$TEST_ORIGIN/.gitignore"
printf 'old\n' > "$TEST_ORIGIN/resources/js/game/car.js"
printf 'old\n' > "$TEST_ORIGIN/scripts/game/selftest.mjs"
git -C "$TEST_ORIGIN" add .
git -C "$TEST_ORIGIN" commit --quiet -m 'Initial fixture'
git clone --quiet "$TEST_ORIGIN" "$TEST_CHECKOUT"
git -C "$TEST_CHECKOUT" config user.name 'Deploy regression'
git -C "$TEST_CHECKOUT" config user.email 'deploy-test@example.invalid'
chown -R www-data:www-data "$TEST_CHECKOUT"

mkdir -p "$TEST_ORIGIN/resources/js/Components/Game"
printf 'new\n' > "$TEST_ORIGIN/resources/js/game/car.js"
printf 'hero\n' > "$TEST_ORIGIN/resources/js/Components/Game/GameLobbyHero.vue"
printf 'test\n' > "$TEST_ORIGIN/scripts/game/car-model-selftest.mjs"
git -C "$TEST_ORIGIN" add .
git -C "$TEST_ORIGIN" commit --quiet -m 'Incoming game update'
expected_commit="$(git -C "$TEST_ORIGIN" rev-parse HEAD)"
chown -R www-data:www-data "$TEST_ORIGIN"

# .git е правилна, но вложените директории не позволяват unlink/create.
chown root:root "$TEST_CHECKOUT/resources/js/game" "$TEST_CHECKOUT/scripts/game"
[ "$(stat -c %U "$TEST_CHECKOUT/.git")" = www-data ]
if runuser -u www-data -- "$TEST_GIT" \
    -C "$TEST_CHECKOUT" pull --ff-only > "$test_root/failed-pull.log" 2>&1; then
    echo 'Очаквахме отказ заради собствеността преди поправката.' >&2
    exit 1
fi
if ! grep -q 'Permission denied' "$test_root/failed-pull.log"; then
    cat "$test_root/failed-pull.log" >&2
    exit 1
fi
[ -f "$TEST_CHECKOUT/resources/js/Components/Game/GameLobbyHero.vue" ]

# Истинска локална промяна и външен symlink, които трябва да се запазят.
printf 'server note\n' > "$TEST_CHECKOUT/server-note.txt"
printf 'private\n' > "$TEST_CHECKOUT/.env"
chmod 600 "$TEST_CHECKOUT/.env"
printf 'external\n' > "$test_root/external-file"
ln -s "$test_root/external-file" "$TEST_CHECKOUT/external-link"

# Пускаме реалния deploy.sh с временен APP_DIR. Изолираме системния git
# config/HOME и спираме преди composer, npm, artisan и рестартите.
sed "s|^APP_DIR=.*|APP_DIR=\"$TEST_CHECKOUT\"|" "$source_dir/deploy.sh" > "$test_root/deploy.sh"
cat > "$test_root/bin/git" <<'SH'
#!/usr/bin/env bash
if [ "${1:-}" = config ] && [ "${2:-}" = --system ]; then exit 0; fi
exec "$TEST_GIT" "$@"
SH
cat > "$test_root/bin/getent" <<'SH'
#!/usr/bin/env bash
printf 'www-data:x:%s:%s::%s:/usr/sbin/nologin\n' "$(id -u www-data)" "$(id -g www-data)" "$TEST_HOME"
SH
cat > "$test_root/bin/sudo" <<'SH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$1 $2 $3" == '-H -u www-data' ]] || exit 90
shift 3
case "$1" in
    git)
        shift
        # Еднократното възстановяване пази и истински локални файлове.
        runuser -u www-data -- "$TEST_GIT" stash push --include-untracked -m recovery
        exec runuser -u www-data -- "$TEST_GIT" "$@"
        ;;
    composer) exit 73 ;;
    *) exit 91 ;;
esac
SH
chmod +x "$test_root/bin/"*

result=0
PATH="$test_root/bin:$PATH" bash "$test_root/deploy.sh" > "$test_root/deploy.log" 2>&1 || result=$?
if [ "$result" -ne 73 ]; then
    cat "$test_root/deploy.log" >&2
    exit 1
fi
[ "$(runuser -u www-data -- "$TEST_GIT" -C "$TEST_CHECKOUT" rev-parse HEAD)" = "$expected_commit" ]
[ "$(stat -c %U:%G "$TEST_CHECKOUT/resources/js/game")" = www-data:www-data ]
[ "$(stat -c %U:%G "$TEST_CHECKOUT/scripts/game")" = www-data:www-data ]
[ "$(stat -c %U "$test_root/external-file")" = root ]
[ "$(stat -c %a "$TEST_CHECKOUT/.env")" = 600 ]
runuser -u www-data -- "$TEST_GIT" -C "$TEST_CHECKOUT" cat-file -e 'stash@{0}^3:server-note.txt'
runuser -u www-data -- "$TEST_GIT" -C "$TEST_CHECKOUT" cat-file -e 'stash@{0}^3:resources/js/Components/Game/GameLobbyHero.vue'
echo 'СЕЛФТЕСТ ОК: вложена собственост, частичен pull, запазени локални файлове и външен symlink.'
