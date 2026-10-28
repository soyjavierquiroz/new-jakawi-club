#!/bin/sh
set -eu

app_password=$(cat /run/secrets/db_app_password)
case "$app_password" in
    ''|*[!a-f0-9]*) echo 'Invalid generated application password format' >&2; exit 1 ;;
esac

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" --set ON_ERROR_STOP=1 <<SQL
CREATE ROLE jakawi_club LOGIN PASSWORD '$app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;
ALTER DATABASE jakawi_club OWNER TO jakawi_club;
REVOKE ALL ON DATABASE jakawi_club FROM PUBLIC;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO jakawi_club;
SQL
unset app_password
