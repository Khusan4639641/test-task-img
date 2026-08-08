#!/bin/sh

set -eu

psql -v ON_ERROR_STOP=1 \
    --username "$POSTGRES_USER" \
    --dbname "$POSTGRES_DB" \
    --set test_database="$POSTGRES_TEST_DB" <<-'SQL'
SELECT 'CREATE DATABASE ' || quote_ident(:'test_database')
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = :'test_database')\gexec
SQL
