<?php
/*PhpDoc:
name: pgsql.inc.php
title: pgsql.inc.php - définition de la classe PgSql facilitant l'utilisation de PostgreSql
classes:
doc: |
  La méthode statique PgSql::open(string $params) ouvre une connexion au serveur et à la base définis.
  La méthode statique PgSql::query(string $sql) exécute une requête SQL.

  Le script implémente comme test un navigateur dans les serveurs définis dans secret.inc.php
  Pour des raisons de sécurité ces fonctionnalités ne sont disponibles que sur le serveur localhost

  Chaque base définit un catalogue (au sens information_schema).
  Le schema information_schema contient notamment les tables:
    - schemata - liste des schema du catalogue, cad de la base
    - tables - liste des tables
    - columns - liste des colonnes avec notamment
      - la colonne data_type qui vaut 'USER-DEFINED' si le type est user-defined
        et dans ce cas la colonne udt_name donne le nom du type, par ex 'geometry'
    - key_column_usage - liste les clés primaires et index uniques avec semble t'il les colonnes qui y participent
  Le schema pg_catalog contient la table:
    - pg_indexes - index avec sa définition

journal: |
  4/3/2022:
    - modification de l'envoi de l'exception lors d'une erreur de connexion à la base
  15/2/2022:
    - ajout de la méthode PgSql::schema()
  20/1/2022:
    - correction fonctionnailté dépéciée en Php 8.1 dans PgSql::query()
  6/11/2021:
    - correction d'un bug lors de la connexion pgsql://{user}(:{passwd})?@{server}(:{port})?/{dbname}/{schema}
      avec password
  6/2/2021:
    - ajout à PgSql::query() de l'option 'jsonColumns' indiquant les colonnes à json_décoder
  29/1/2021:
    - chgt des URI
      serveur: pgsql://{user}(:{passwd})?@{server}(:{port})?  sans '/' à la fin
      base: pgsql://{user}(:{passwd})?@{server}(:{port})?/{dbname}
      schéma: pgsql://{user}(:{passwd})?@{server}(:{port})?/{dbname}/{schema}
    - ajout méthode PgSql::pg_version()
  16/1/2021:
    - ajout de champs à PgSql
    - ajout de la méthode PgSql::tableColumns()
    - suppression de la méthode PgSql::server()
    - dév. navigateur serveur / base / schéma / table / description / contenu
  11/8/2020:
    - ajout PgSql::$connection et PgSql::affected_rows()
  18/6/2020:
    - écriture d'une doc
includes:
  - secret.inc.php
*/
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

/*PhpDoc: classes
name: PgSql
title: class PgSql implements Iterator - classe facilitant l'utilisation de PostgreSql
methods:
doc: |
  Classe implémentant en statique les méthodes de connexion et de requete
  et générant un objet correspondant à un itérateur permettant d'accéder au résultat

  La méthode statique open() ouvre une connexion PgSql à un {user}@{server}/{dbname}(/{schema})?
  La méthode statique query() exécute une requête SQL et renvoie un itérateur sur les n-uplets sélectionnés
  dans le cas d'une requête de manipulation de données et true dans le cas d'une requête de définition de données.
*/
class PgSql implements Iterator {
  static $connection; // ressource de connexion retournée par pg_connect()
  static $server; // le nom du serveur
  static $database; // nom de la base
  static $schema; // nom du schema s'il a été défini dans open() ou null
  protected ?string $sql = null; // la requête conservée pour pouvoir faire plusieurs rewind
  protected PgSql\Result|false $result = false; // l'objet retourné par pg_query()
  protected array $options; // ['jsonColumns'=> {jsonColumns}]
  protected bool $first; // indique s'il s'agit du premier rewind
  protected int $id; // un no en séquence à partir de 1
  protected array|bool $ctuple = false; // le tuple courant ou false
  
  static function open(string $uri): void {
    /*PhpDoc: methods
    name: open
    title: static function open(string $$uri) - ouvre une connexion PgSql
    doc: |
      Le motif de l'uri est: 'pgsql://{user}(:{password})?@{server}(:{port})?/{dbname}(/{schema})?'
      Si le schéma est fourni alors il est initialisé après l'ouverture de la base.
    */
    //echo "PgSql::open($connection_string)\n";
    if (!preg_match('!^pgsql://([^@:]+)(:[^@]+)?@([^:/]+)(:\d+)?/([^/]+)(/.*)?$!', $uri, $matches))
      throw new Exception("Erreur: dans PgSql::open() params \"$uri\" incorrect");
    $user = $matches[1];
    $passwd = $matches[2] ? substr($matches[2], 1) : null;
    $server = $matches[3];
    $port = $matches[4] ? substr($matches[4], 1) : '';
    $database = $matches[5];
    $schema = isset($matches[6]) ? substr($matches[6], 1) : null;
    //print_r($matches); die();
    $conn_string = "host=$server".($port ? " port=$port": '')
      ." dbname=$database user=$user".($passwd ? " password=$passwd": '');
    //echo "conn_string=$conn_string\n";

    self::$server = $server;
    self::$database = $database;
    self::$schema = $schema;
    if (!(self::$connection = @pg_connect($conn_string)))
      throw new Exception("Could not connect to \"pgsql://$user:***@$server".($port?":$port":'')."/\"");
    
    if ($schema) {
      //echo "query(SET search_path TO $schema)\n";
      self::query("SET search_path TO $schema");
    }
  }
  
  /*static function server(): string {
    if (!self::$server)
      throw new Exception("Erreur: dans PgSql::server() server non défini");
    return self::$server;
  }*/
  
  static function close(): void { pg_close(); }
  
  static function server(): string { return self::$server; }
  
  static function schema(): ?string { return self::$schema; }
  
  static function pg_version(): array { return pg_version(self::$connection); }
  
  static function tableColumns(string $table, ?string $schema=null): ?array { // liste des colonnes de la table
    /*PhpDoc: methods
    name: tableColumns
    title: "static function tableColumns(string $table, ?string $schema=null): ?array"
    doc: |
      Retourne la liste des colonnes d'une table structuré comme:
        [ [
            'ordinal_position'=> ordinal_position,
            'column_name'=> column_name,
            'data_type'=> data_type,
            'character_maximum_length'=> character_maximum_length,
            'udt_name'=> udt_name,
            'constraint_name'=> constraint_name,
        ] ]
      Les 5 premiers champs proviennent de la table INFORMATION_SCHEMA.columns et le dernier d'une jointure gauche
      avec INFORMATION_SCHEMA.key_column_usage
    */
    $base = self::$database;
    if (!$schema)
      $schema = self::$schema;
    $sql = "select c.ordinal_position, c.column_name, c.data_type, c.character_maximum_length, c.udt_name, 
            k.constraint_name
          -- select c.*
          from INFORMATION_SCHEMA.columns c
          left join INFORMATION_SCHEMA.key_column_usage k
            on k.table_catalog=c.table_catalog and k.table_schema=c.table_schema
              and k.table_name=c.table_name and k.column_name=c.column_name
          where c.table_catalog='$base' and c.table_schema='$schema' and c.table_name='$table'";
    $columns = [];
    foreach(PgSql::query($sql) as $tuple) {
      //print_r($tuple);
      $columns[$tuple['column_name']] = $tuple;
    }
    return $columns;
  }
  
  static function getTuples(string $sql): array { // renvoie le résultat d'une requête sous la forme d'un array
    /*PhpDoc: methods
    name: getTuples
    title: "static function getTuples(string $sql): array - renvoie le résultat d'une requête sous la forme d'un array"
    doc: |
      Plus adapté que query() quand on sait que le nombre de n-uplets retournés est faible
    */
    $tuples = [];
    foreach (self::query($sql) as $tuple)
      $tuples[] = $tuple;
    return $tuples;
  }

  static function query(string $sql, array $options=[]) {
    /*PhpDoc: methods
    name: query
    title: static function query(string $sql) - lance une requête et retourne éventuellement un itérateur
    doc: |
      Si la requête renvoit comme résultat un ensemble de n-uplets alors retourne un itérateur donnant accès
      à chacun d'eux sous la forme d'un array [{column_name}=> valeur] (pg_fetch_array() avec PGSQL_ASSOC).
      Sinon renvoit un objet PgSql ssi la requête est Ok
      Sinon en cas d'erreur génère une exception
    */
    //echo '$sql dans query(): '; print_r($sql);
    if (!($result = @pg_query(self::$connection, $sql)))
      throw new Exception('Query failed: '.pg_last_error(self::$connection));
    else
      return new PgSql($sql, $result, $options);
  }

  function __construct(string $sql, $result, array $options) {
    $this->sql = $sql;
    $this->result = $result;
    $this->options = $options;
    $this->first = true;
  }
  
  function rewind(): void {
    if ($this->first) // la première fois ne pas faire de pg_query qui a déjà été fait
      $this->first = false;
    elseif (!($this->result = @pg_query($this->sql)))
      throw new Exception('Query failed: '.pg_last_error());
    $this->id = 0;
    $this->next();
  }
  
  function next(): void {
    $this->ctuple = pg_fetch_array($this->result, null, PGSQL_ASSOC);
    $this->id++;
  }
  
  function valid(): bool { return $this->ctuple <> false; }
  
  function current(): array {
    if (isset($this->options['jsonColumns'])) {
      foreach ($this->options['jsonColumns'] as $jsonColumn)
        $this->ctuple[$jsonColumn] = json_decode($this->ctuple[$jsonColumn], true);
    }
    return $this->ctuple;
  }
  
  function key(): int { return $this->id; }

  function affected_rows(): int { return pg_affected_rows($this->result); }
};



if ((__FILE__ <> realpath($_SERVER['DOCUMENT_ROOT'].$_SERVER['SCRIPT_NAME'])) && (($argv[0] ?? '') <> basename(__FILE__))) return;
echo "<!DOCTYPE HTML><html>\n<head><meta charset='UTF-8'><title>pgsql.inc.php</title></head><body><pre>\n";

$server = 'docker:docker@pgsqlserver';

if (0) { // Utilisation de affected_rows()
  PgSql::open("pgsql://$server/gis/public");
  
  print_r(PgSql::query("drop table if exists test_pgsql"));
  print_r(PgSql::query("create table test_pgsql(key serial primary key, field varchar(256))"));
  print_r(PgSql::query("insert into test_pgsql(field) values ('une première valeur')"));
  print_r(PgSql::query("insert into test_pgsql(field) values ('une seconde valeur')"));
  $query = PgSql::query("delete from test_pgsql where field='une seconde valeur'");
  print_r($query);
  echo "affected_rows()=",$query->affected_rows(),"\n";
  $query = PgSql::query("drop table test_pgsql");
  print_r($query);
  echo "affected_rows()=",$query->affected_rows(),"\n";
}
elseif (0) { // Test de l'erreur de connexion
  PgSql::open('pgsql://xx::xx@pgsqlserver/gis/public');
}
// Navigation dans serveur / base=catalogue / schéma / table / description / contenu (uniquement sur localhost)
elseif ($_SERVER['HTTP_HOST'] == 'localhost') {
  if (!($base = $_GET['base'] ?? null)) { // choix d'une des bases=catalogues du serveur
    PgSql::open("pgsql://$server/postgres");
    echo Yaml::dump(["pgsql://$server" => ['pg_version'=> PgSql::pg_version()]], 3, 2);
    echo "  Bases:\n";
    $sql = "select * from pg_database";
    foreach (PgSql::query($sql) as $tuple) {
      echo "    - <a href='?base=$tuple[datname]&amp;server=",urlencode($server),"'>$tuple[datname]</a>\n";
      //print_r($tuple);
    }
    die();
  }
  elseif (!($schema = $_GET['schema'] ?? null)) { // choix d'un des schémas de la base
    echo "Schémas de la base pgsql://$server/$base:\n";
    PgSql::open("pgsql://$server/$base");
    $sql = "select schema_name from information_schema.schemata";
    $url = "base=$base&amp;server=".urlencode($server);
    foreach (PgSql::query($sql) as $tuple) {
      echo "  - <a href='?schema=$tuple[schema_name]&amp;$url'>$tuple[schema_name]</a>\n";
    }
    die();
  }
  elseif (!($table = $_GET['table'] ?? null)) { // choix d'une des tables du schema
    echo "Tables de pgsql:$server/$base/$schema:\n";
    PgSql::open("pgsql://$server/$base/$schema");
    $sql = "select table_name from information_schema.tables
        where table_catalog='$base' and table_schema='$schema'";
    //$sql = "select * from INFORMATION_SCHEMA.TABLES";
    $url = "schema=$schema&amp;base=$base&amp;server=".urlencode($server);
    foreach (PgSql::query($sql) as $tuple) {
      echo "  - <a href='?table=$tuple[table_name]&amp;$url'>$tuple[table_name]</a>\n";
      //print_r($tuple);
    }
    die();
  }
  elseif (null === ($offset = $_GET['offset'] ?? null)) { // Description de la table
    echo "Table pgsql://$server/$base/$schema/$table:\n";
    echo "  - <a href='?offset=0&amp;limit=20&amp;table=$table&amp;schema=$schema&amp;base=$base",
      "&amp;server=".urlencode($server),"'>Affichage du contenu de la table</a>.\n";
    echo "  - Description de la table:\n";
    PgSql::open("pgsql://$server/$base/$schema");
    $sql = "select c.ordinal_position, c.column_name, c.data_type, c.character_maximum_length, k.constraint_name
          from information_schema.columns c
          left join information_schema.key_column_usage k
            on k.table_catalog=c.table_catalog and k.table_schema=c.table_schema
              and k.table_name=c.table_name and k.column_name=c.column_name
          where c.table_catalog='$base' and c.table_schema='$schema' and c.table_name='$table'";
    foreach (PgSql::query($sql) as $tuple) {
      echo "    $tuple[ordinal_position]:\n";
      echo "      id: $tuple[column_name]\n";
      if ($tuple['constraint_name'])
        echo "      constraint: $tuple[constraint_name]\n";
      if ($tuple['data_type']=='character')
        echo "      data_type: $tuple[data_type]($tuple[character_maximum_length])\n";
      else
        echo "      data_type: $tuple[data_type]\n";
      if (0)
        print_r($tuple);
    }
    die();
  }
  else { // affichage du contenu de la table à partir de offset
    $limit = (int)($_GET['limit'] ?? 20);
    PgSql::open("pgsql://$server/$base/$schema");
    //print_r(PgSql::tableColumns($table));
    $columns = [];
    foreach (PgSql::tableColumns($table) as $cname => $column) {
      if ($column['udt_name']=='geometry')
        $columns[] = "ST_AsGeoJSON($cname) $cname";
      else
        $columns[] = $cname;
    }
    $sql = $_GET['sql'] ?? "select ".implode(', ', $columns)."\nfrom $table";
    if (substr($sql, 0, 7) <> 'select ')
      throw new Exception("Requête \"$sql\" interdite");
    $url = "table=$table&amp;schema=$schema&amp;base=$base&amp;server=".urlencode($server);
    echo "</pre>",
      "<h2>pgsql://$server/$base/$schema/$table</h2>\n",
      
      "<form><table border=1><tr>",
      "<input type='hidden' name='offset' value='0'>",
      "<input type='hidden' name='limit' value='$limit'>",
      "<td><textarea name='sql' rows='5' cols='130'>$sql</textarea></td>",
      "<input type='hidden' name='table' value='$table'>",
      "<input type='hidden' name='schema' value='$schema'>",
      "<input type='hidden' name='base' value='$base'>",
      "<input type='hidden' name='server' value='$server'>",
      "<td><input type=submit value='go'></td>",
      "</tr></table></form>\n",

      "<a href='?$url'>^</a> ",
      ((($offset-$limit) >= 0) ? "<a href='?offset=".($offset-$limit)."&amp;$url'>&lt;</a>" : ''),
      " offset=$offset ",
      "<a href='?offset=".($offset+$limit)."&amp;$url'>&gt;</a>",
      "<table border=1>\n";
    echo "</pre><table border=1>\n";
    $no = 0;
    //echo "sql=$sql\n";
    foreach (PgSql::query("$sql\nlimit $limit offset $offset") as $tuple) {
      if (!$no++)
        echo '<th>', implode('</th><th>', array_keys($tuple)),"</th>\n";
      echo '<tr><td>', implode('</td><td>', $tuple),"</td></tr>\n";
    }
    echo "</table>\n";
    die();
  }
  
  die();
}
