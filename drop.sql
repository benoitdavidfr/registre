# Suppression du contenu d'une base registre

# suppression de la contrainte sur la colonne script de la table registre
alter table registre drop constraint ref_script;
drop table script;
drop table registre;
drop table errorlog;
drop type registretype;
