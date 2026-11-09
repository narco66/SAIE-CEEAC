#!/bin/sh
# Rôles PostgreSQL à moindre privilège (SEC-04, TECH-02), exécuté une seule fois à la création du volume :
#   saie      propriétaire du schéma, utilisé uniquement par le service « migrate » ;
#   saie_app  utilisé par l'API, les workers et le planificateur : lecture et écriture des données, aucun droit sur le schéma,
#             ni modification ni suppression du journal d'audit (retirées par la migration revoke_audit_write_from_app_role).
set -eu
psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
  -v owner_password="$(cat /run/secrets/db_owner_password)" -v app_password="$SAIE_APP_PASSWORD" <<'SQL'
CREATE ROLE saie LOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB PASSWORD :'owner_password';
CREATE ROLE saie_app LOGIN NOSUPERUSER NOCREATEROLE NOCREATEDB PASSWORD :'app_password';
ALTER DATABASE saie_ceeac OWNER TO saie;
ALTER SCHEMA public OWNER TO saie;
REVOKE ALL ON DATABASE saie_ceeac FROM PUBLIC;
GRANT CONNECT ON DATABASE saie_ceeac TO saie, saie_app;
GRANT USAGE ON SCHEMA public TO saie_app;
ALTER DEFAULT PRIVILEGES FOR ROLE saie IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO saie_app;
ALTER DEFAULT PRIVILEGES FOR ROLE saie IN SCHEMA public GRANT USAGE, SELECT ON SEQUENCES TO saie_app;
SQL

# Sauvegarde de base (service « backup ») : connexion de réplication du superutilisateur d'administration seulement.
echo "host replication saie_admin all scram-sha-256" >> "$PGDATA/pg_hba.conf"
