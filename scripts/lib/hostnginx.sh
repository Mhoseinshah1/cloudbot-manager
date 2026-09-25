#!/usr/bin/env bash
#
# Host nginx vhost and TLS, for the public edge in front of the Docker stack.
#
#   Internet :443 -> host nginx -> 127.0.0.1:APP_PORT -> Docker nginx -> app
#
# This file touches exactly one site file and never anything else. It does not
# edit nginx.conf, does not remove other sites, and does not disable the
# default site: this may well be a host that already serves something.

if [ "${BASH_SOURCE[0]}" = "${0}" ]; then
    echo "hostnginx.sh is a library and must be sourced, not executed." >&2
    exit 1
fi

readonly HOST_SITE_NAME="cloudbot-manager"
readonly HOST_SITES_AVAILABLE="/etc/nginx/sites-available"
readonly HOST_SITES_ENABLED="/etc/nginx/sites-enabled"

host_nginx_site_file() { printf '%s/%s.conf' "${HOST_SITES_AVAILABLE}" "${HOST_SITE_NAME}"; }
host_nginx_link_file() { printf '%s/%s.conf' "${HOST_SITES_ENABLED}" "${HOST_SITE_NAME}"; }

install_host_nginx_package() {
    if have nginx; then
        ok "nginx is already installed."
        return 0
    fi

    step "Installing nginx"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq nginx
    ok "nginx installed."
}

# Writes the plain-HTTP vhost. TLS is added afterwards by certbot, which edits
# this same file — which is why the HTTP server block stays minimal and why
# certbot's own additions are never overwritten once present.
write_host_nginx_site() {
    local domain="$1" port="$2" site tmp
    site="$(host_nginx_site_file)"

    valid_domain "${domain}" || die "Refusing to write an nginx site for an invalid domain: ${domain}"
    valid_port "${port}" || die "Refusing to write an nginx site for an invalid port: ${port}"

    if [ -f "${site}" ] && grep -q 'managed by Certbot' "${site}"; then
        # Certbot has already rewritten this file with the TLS server block.
        # Regenerating it would drop the certificate configuration and take the
        # site off HTTPS on every re-run of the installer.
        ok "Existing TLS-enabled site file kept: ${site}"
        return 0
    fi

    mkdir -p "${HOST_SITES_AVAILABLE}" "${HOST_SITES_ENABLED}"

    tmp="$(mktemp)"
    cat > "${tmp}" <<NGINX
# CloudBot Manager — public edge.
#
# Written by install.sh. Only this file is managed; other sites on this host
# are left alone. Certbot adds the TLS server block and the redirect.
#
# The upstream is the Docker nginx published on loopback, which is why nothing
# here needs to know about PHP.

server {
    listen 80;
    listen [::]:80;

    server_name ${domain};

    # Kept so an HTTP-01 renewal can be served from the webroot if the operator
    # ever moves off the nginx authenticator.
    location /.well-known/acme-challenge/ {
        root /var/www/html;
    }

    location / {
        proxy_pass http://127.0.0.1:${port};
        proxy_http_version 1.1;

        proxy_set_header Host              \$host;
        proxy_set_header X-Real-IP         \$remote_addr;
        proxy_set_header X-Forwarded-For   \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header X-Forwarded-Host  \$host;

        # A provider call can make a request slow; a customer's webhook must
        # not be cut off halfway through being handled.
        proxy_connect_timeout 10s;
        proxy_send_timeout    120s;
        proxy_read_timeout    120s;

        # Telegram sends small JSON bodies; nothing here needs a large body.
        client_max_body_size 8m;
    }

    server_tokens off;

    access_log /var/log/nginx/${HOST_SITE_NAME}.access.log;
    error_log  /var/log/nginx/${HOST_SITE_NAME}.error.log warn;
}
NGINX

    install -m 0644 "${tmp}" "${site}"
    rm -f "${tmp}"

    ln -sfn "${site}" "$(host_nginx_link_file)"

    ok "Wrote ${site}"
}

# Validates before reloading, and refuses to reload a broken configuration.
# A failed `nginx -t` leaves the running server exactly as it was.
reload_host_nginx() {
    step "Validating host nginx configuration"

    if ! nginx -t 2>&1 | sed 's/^/  /'; then
        err "nginx -t failed. The running configuration has been left untouched."
        err "Fix $(host_nginx_site_file) — or remove it and $(host_nginx_link_file) — then run: nginx -t && systemctl reload nginx"
        return 1
    fi

    if systemctl is-active --quiet nginx; then
        systemctl reload nginx
        ok "Host nginx reloaded."
    else
        systemctl enable --now nginx
        ok "Host nginx started."
    fi
}

# ---------------------------------------------------------------------------
# TLS
# ---------------------------------------------------------------------------

install_certbot() {
    if have certbot; then
        ok "certbot is already installed."
        return 0
    fi

    step "Installing certbot"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq certbot python3-certbot-nginx
    ok "certbot installed."
}

# DNS sanity, before asking a certificate authority for anything.
#
# Let's Encrypt rate-limits failed authorizations. Checking first turns "you
# are now rate limited for an hour" into a sentence explaining what to fix.
check_dns_resolves() {
    local domain="$1" resolved="" public=""

    if have dig; then
        resolved="$(dig +short +time=5 +tries=2 A "${domain}" | grep -E '^[0-9.]+$' | head -n 1 || true)"
    elif have host; then
        resolved="$(host -W 5 -t A "${domain}" 2>/dev/null | awk '/has address/ {print $4; exit}' || true)"
    elif have getent; then
        resolved="$(getent ahostsv4 "${domain}" | awk '{print $1; exit}' || true)"
    fi

    if [ -z "${resolved}" ]; then
        err "${domain} does not resolve to an IPv4 address from this host."
        err "Point an A record at this server and wait for it to propagate before requesting a certificate."
        return 1
    fi

    ok "${domain} resolves to ${resolved}."

    public="$(curl --silent --max-time 10 https://api.ipify.org 2>/dev/null || true)"
    if [ -n "${public}" ] && valid_ipv4 "${public}" && [ "${resolved}" != "${public}" ]; then
        # Not fatal: the host may legitimately sit behind a proxy or NAT.
        warn "${domain} resolves to ${resolved}, but this host's public address looks like ${public}."
        warn "If those should match, fix DNS before continuing or the certificate request will fail."
        return 2
    fi

    return 0
}

# Requests a certificate for exactly this domain, and nothing else on the host.
obtain_certificate() {
    local domain="$1" email="$2"

    valid_domain "${domain}" || die "Refusing to request a certificate for an invalid domain."
    valid_email "${email}" || die "Refusing to request a certificate with an invalid contact address."

    if certbot certificates 2>/dev/null | grep -qE "Domains:.*\b${domain}\b"; then
        ok "A certificate for ${domain} already exists; not requesting another."
        return 0
    fi

    step "Requesting a TLS certificate for ${domain}"

    # --cert-name scopes everything to this application's certificate, so a
    # re-run cannot fold somebody else's domain into ours.
    if ! certbot --nginx \
            --non-interactive \
            --agree-tos \
            --email "${email}" \
            --domains "${domain}" \
            --cert-name "${domain}" \
            --redirect \
            --keep-until-expiring; then
        err "certbot could not obtain a certificate for ${domain}."
        err "Common causes: DNS not pointing here yet, port 80 blocked, or a rate limit from a previous attempt."
        return 1
    fi

    ok "Certificate issued for ${domain}."
}

# Renewal is what makes the certificate real three months from now, so it is
# verified rather than assumed.
verify_renewal() {
    step "Checking certificate renewal"

    if systemctl list-timers --all 2>/dev/null | grep -q 'certbot'; then
        ok "certbot renewal timer is registered."
    elif [ -f /etc/cron.d/certbot ]; then
        ok "certbot renewal cron entry is present."
    else
        warn "No certbot renewal timer or cron entry found. The certificate will expire unless renewal is scheduled."
    fi

    if certbot renew --dry-run 2>&1 | tail -n 5 | sed 's/^/  /'; then
        ok "Renewal dry run succeeded."
    else
        warn "Renewal dry run failed. Fix this before the certificate expires."
    fi
}
