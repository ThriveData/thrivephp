#!/bin/sh

PHP=84

set -e


echo "===[ BOOTSTRAP ]=================================================="
pkg bootstrap -y



echo "===[ PKG INSTALL ]=================================================="
pkg install -y \
bat \
bash \
curl \
doas \
fd-find \
freetds \
git \
gmake \
jq \
lazygit \
neovim \
nginx \
opensmtpd \
php${PHP}-composer \
php${PHP}-ctype \
php${PHP}-curl \
php${PHP}-dom \
php${PHP}-fileinfo \
php${PHP}-filter \
php${PHP}-gd \
php${PHP}-iconv \
php${PHP}-intl \
php${PHP}-ldap \
php${PHP}-mbstring \
php${PHP}-pcntl \
php${PHP}-pdo_dblib \
php${PHP}-pdo_sqlite \
php${PHP}-pgsql \
php${PHP}-phar \
php${PHP}-session \
php${PHP}-simplexml \
php${PHP}-xml \
php${PHP}-xmlreader \
php${PHP}-xmlwriter \
php${PHP}-zip \
php${PHP}-zlib \
pv \
pwgen \
py311-certbot-dns-cloudflare \
rsync \
zsh


ln -sf /usr/local/bin/nvim /usr/local/bin/vim



echo "===[ USERS ]=================================================="

printf password | pw user show thrive >/dev/null || pw user add thrive -m -h 0



echo "===[ DOAS ]=================================================="

cat <<- 'EOF' > /usr/local/etc/doas.conf
permit nopass :wheel
EOF



echo "===[ SSH ]=================================================="

if [ ! -f /home/thrive/.ssh/id_ed25519 ]; then
	doas -u thrive -- ssh-keygen -t ed25519 -f /home/thrive/.ssh/id_ed25519 -N ''
	#ssh-copy-id -i /home/thrive/.ssh/id_ed25519.pub -o StrictHostKeyChecking=accept-new thrive@thrive.local
fi



echo "===[ GIT CLONE ]=================================================="

#if [ ! -d $HOME/secrets ]; then
#	doas -u pz git clone thrive@thrive.lan:secrets $HOME/secrets
#fi



echo "===[ SMTPD ]=================================================="

touch /etc/ssl/cert.pem

service smtpd enabled || service smtpd enable

cat <<- 'END' > /etc/mail/mailer.conf
sendmail	/usr/local/sbin/smtpctl
send-mail	/usr/local/sbin/smtpctl
mailq		/usr/local/sbin/smtpctl
makemap		/usr/local/sbin/smtpctl
newaliases	/usr/local/sbin/smtpctl
END

cat <<- 'END' > /usr/local/etc/mail/smtpd.conf
listen on localhost

table aliases file:/etc/mail/aliases
table secrets file:/usr/local/etc/mail/secrets

action "local" mbox alias <aliases>
action "relay" relay host smtp+tls://auth@{{HOST}}:{{PORT}} auth <secrets>

match for local action "local"
match from local for any action "relay"
END

cat <<- 'END' > /usr/local/etc/mail/secrets 
auth {{USER}}:{{PWD}}
END

if ! $(service smtpd status > /dev/null); then
	service smtpd start
fi



echo "===[ PHP ]=================================================="

cp /usr/local/etc/php.ini-production /usr/local/etc/php.ini

sed -i -r \
	-e 's/^short_open_tag = .*$/short_open_tag = on/' \
	-e 's/^memory_limit = .*$/memory_limit = 512M/' \
	/usr/local/etc/php.ini
        
service php_fpm enabled || service php_fpm enable

if ! $(service php_fpm status > /dev/null); then
	service php_fpm start
fi



echo "===[ SSL ]=================================================="

if [ ! -f /home/thrive/thrive/nginx/cert.crt ]; then
	openssl req -nodes -x509 -sha256 -newkey rsa:4096 \
	/nginx/cert.key \
	/nginx/cert.crt \
	-days 356 \
	-subj "/C=US/O=Thrive/OU=IT/CN=thrive@thrive.local"
	#-addext "subjectAltName = DNS:localhost,DNS:example.org"
fi



echo "===[ NGINX ]=================================================="

sed -e '121s/.*/    include sites\/*.conf;/' /usr/local/etc/nginx/nginx.conf-dist > /usr/local/etc/nginx/nginx.conf

mkdir -p /usr/local/etc/nginx/sites

service nginx enabled || service nginx enable

if ! $(service nginx status > /dev/null); then
	service nginx start
fi



echo "===[ POSTGRES ]=================================================="

doas bash <<- 'EOF'
	cd /home/pz/sunnyside/postgres/pg_cron && gmake install
	cd /home/pz/sunnyside/postgres/pg_curl && gmake install
EOF

service postgresql enabled || service postgresql enable

if [ ! -d /var/db/postgres/data17 ]; then
	service postgresql initdb
fi

if ! $(service postgresql status > /dev/null); then
	service postgresql start
fi

dbexist=$(psql postgres postgres -qtA -c "select (count(oid) > 0)::integer from pg_database where datname='thrive'")
if [ $dbexist -eq 0 ]; then
	psql -U postgres -c 'CREATE DATABASE thrive'
fi

psql -U postgres -d thrive -c 'ALTER DATABASE pz SET search_path TO "$user", thrive, public'
