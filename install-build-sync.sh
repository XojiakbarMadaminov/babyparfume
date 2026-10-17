#!/bin/sh
set -eu

if [ "${1:-}" != '--production' ]; then
    printf '%s\n' 'Faqat production uchun: sh install-build-sync.sh --production [public_html_path]' >&2
    exit 1
fi
shift

project_dir=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
public_dir=${1:-"$(dirname "$project_dir")/public_html"}

cd "$project_dir"

if [ ! -f public/build/manifest.json ]; then
    printf '%s\n' 'Xatolik: public/build/manifest.json topilmadi.' >&2
    exit 1
fi

if [ ! -d "$public_dir" ]; then
    printf 'Xatolik: public papka topilmadi: %s\n' "$public_dir" >&2
    exit 1
fi

public_dir=$(CDPATH= cd -- "$public_dir" && pwd)
hooks_dir=$(git rev-parse --git-path hooks)

for hook in post-merge post-rewrite; do
    if [ -e "$hooks_dir/$hook" ] && ! grep -q '^# babyparfume-build-sync$' "$hooks_dir/$hook"; then
        printf 'Xatolik: mavjud hook almashtirilmadi: %s\n' "$hooks_dir/$hook" >&2
        exit 1
    fi
done

mkdir -p "$hooks_dir"
git config --local deploy.publicPath "$public_dir"
git config --local deploy.buildSyncEnabled true

for hook in post-merge post-rewrite; do
    cat > "$hooks_dir/$hook" <<'HOOK'
#!/bin/sh
# babyparfume-build-sync
set -eu

if [ "$(git config --local --get deploy.buildSyncEnabled || true)" != 'true' ]; then
    exit 0
fi

project_dir=$(git rev-parse --show-toplevel)
public_dir=$(git config --local --get deploy.publicPath)

if [ ! -f "$project_dir/public/build/manifest.json" ]; then
    printf '%s\n' 'Xatolik: build nusxalanmadi, public/build/manifest.json topilmadi.' >&2
    exit 1
fi

mkdir -p "$public_dir/build"
cp -a "$project_dir/public/build/." "$public_dir/build/"
printf 'Build yangilandi: %s/build\n' "$public_dir"
HOOK
    chmod +x "$hooks_dir/$hook"
done

sh "$hooks_dir/post-merge"
printf '%s\n' 'Git pull uchun build nusxalash yoqildi.'
