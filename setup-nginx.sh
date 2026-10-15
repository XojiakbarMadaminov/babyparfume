#!/usr/bin/env bash
set -e

# 1. Remove broken symlinks in /etc/nginx/sites-enabled
for link in /etc/nginx/sites-enabled/*; do
    if [ -L "$link" ] && [ ! -e "$link" ]; then
        echo "Removing broken symlink: $link"
        rm -f "$link"
    fi
done

# 2. Copy baby.lc config to sites-available
cp -f /home/xojiakbar/PhpstormProjects/babyparfume/baby.lc.conf /etc/nginx/sites-available/baby.lc

# 3. Enable site
ln -sf /etc/nginx/sites-available/baby.lc /etc/nginx/sites-enabled/baby.lc

# 4. Add baby.lc to /etc/hosts if not already there
if ! grep -q -E '\bbaby\.lc\b' /etc/hosts; then
    echo "127.0.0.1 baby.lc" >> /etc/hosts
    echo "Added 127.0.0.1 baby.lc to /etc/hosts"
else
    echo "baby.lc already present in /etc/hosts"
fi

# 5. Test nginx configuration
nginx -t

# 6. Restart nginx service
systemctl restart nginx

echo "Successfully configured baby.lc on Nginx!"
