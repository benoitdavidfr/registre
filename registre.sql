-- Utilisation d'un schemé registre et d'un utilisateur registre
CREATE SCHEMA IF NOT EXISTS registre;
CREATE user registre PASSWORD 'registre';
GRANT ALL PRIVILEGES ON SCHEMA registre TO registre;

REVOKE ALL PRIVILEGES ON SCHEMA registre FROM registre;
drop user registre;
drop schema registre;


-- Suppression du contenu d'une base registre
-- suppression de la contrainte sur la colonne script de la table registre
alter table registre drop constraint ref_script;
drop table script;
drop table registre;
drop table errorlog;
drop type registretype;
