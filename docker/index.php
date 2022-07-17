<?php
define('VERSION', '15/7/2022');
/*PhpDoc:
title: script mettant en oeuvre le registre
name: index.php
doc: |
  Permet de consulter et de mettre à jour le registre.
  Le registre est exposé sur https://registre.georef.eu/
  Il devrait être transféré sur https://registre.data.developpement-durable.gouv.fr/
  La version de dév. est sur http://localhost/georef/registre/index.php
  La version de préprod est sur https://regpprod.georef.eu/
  Le code est publié sur https://github.com/benoitdavidfr/registre

  Ce script nécessite 2 variables d'envronnement:
    REGISTRE_DB_URI: URI de la base PgSql, ex.: 'pgsql://{login:pwd}@pgsqlserver/gis/public'
    REGISTRE_PUB_LOGIN_PW: login et passwd dans la base pour la connection anonyme, ex: 'docker:docker'

  Le registre expose une API http:
    - la méthode GET expose une ressource formatée en JSON ou en HTML
    - la méthode PUT crée ou remplace une ressource
    - la méthode DELETE supprime une ressource
    - la méthode POST retourne en JSON le contenu d'une ressource tel que stocké en base
  Les méthodes PUT et DELETE sont authentifiées avec le login/mdp de connexion dans PgSql qui doit être fourni
  dans la requête Http.

  La définition de l'API est dans api.yaml.

  Dans l'URI les caractères autorisés sont les caractères alpha, digit et safe de la RFC 1738 plus '/'

  Lorsque les tables n'existent pas, elles sont créées ce qui permet d'exécuter le script avec une base PgSql vide
  créée par docker-compose.

  création de la base: voir registre.sql
  Protocole de tests: voir test.php et tests.yaml

journal: |
  15/7/2022:
    - ajout d'un nom de schéma comme var. d'env. et utilisation au login dans la base
  4/7/2022:
    - adaptation à l'offre de base gérée Eco
  29/3/2022:
    - ajout de la création des tables lorsqu'elles n'existent pas
      - permer d'exécuter le script avec une base PgSql vide créée par docker-compose
    - simplification en gérant le format comme une fonction et en l'utilisant dans error()
  17/3/2022:
    - correction bug dans bulkLoad
    - la suppression d'une ressource inexistante retourne une erreur 404
    - ajout de contrôles dans bulkLoad
  16/3/2022:
    - utilisation des variables d'environnement sur localhost
    - ajout d'une constante VERSION avec la date de la version
    - gestion des erreurs dans le load
  14/3/2022:
    - ajout chargement en nombre (bulkLoad)
  13/3/2022:
    - utilisation des variables d'environnement sur regpprod et registre
  10-11/3/2022:
    - transformation de plusieurs variables globales en fonctions
    - ajout d'un logError pour loguer les erreurs autres que 404
    - ajout possibilité de loguer dans un fichier Yaml les requêtes PUT en entrée
    - ajout d'une erreur plus claire en PUT quand le body n'est pas du JSON
    - pour une ressource de type R, en GET et en POST génération des children même si jsonval est défini
    - restructuration pour simuler un fonctionnement avec variables d'environnement
  7/3/2022:
    - modification de l'algo. pour DELETE et PUT -> upsert
  6/3/2022:
    - utilisation de jsonval et htmlval
  5/3/2022:
    - le champ title est obligatoire dans l'opération PUT
    - restriction des caractères autorisés dans l'URI
    - suppression du format XML
  4/3/2022:
    - ajout possibilité d'insérer title, jsonval et htmlval nuls
    - ajout authentification
  3/3/2022:
    - 1ère version
include:
  - ../../phplib/sql.inc.php
*/
// les options utilisées par défaut pour json_encode()
define('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

require_once __DIR__.'/vendor/autoload.php';
require_once __DIR__.'/pgsql.inc.php';

use Symfony\Component\Yaml\Yaml;

if (!function_exists('array_is_list')) {
  function is_assoc_array(array $array): bool { return count(array_diff_key($array, array_keys(array_keys($array)))); }
  function array_is_list($list): bool { return is_array($list) && !is_assoc_array($list); }
}

function initDatabase(): void { // initalise la base de données lorsque la table registre n'existe pas 
  echo "initialisation de la base de données<br>\n";
  foreach ([
    "create type registretype as enum('E','R') -- 'E' pour un élément, 'R' pour un registre",
    // table stockant les ressources du registre de type registre et élément
    "create table registre(
      id varchar(255) not null primary key, -- PATH_INFO de l'URI, '' pour la racine
      parent varchar(255) not null references registre, -- clé du registre parent, '' pour la racine
      type registretype not null, -- 'E' pour un élément, 'R' pour un registre
      title varchar(255) not null, -- titre nécessaire pour naviguer dans le registre
      script varchar(255), -- references script, -- éventuelle clé du script à exécuter, réf. ajoutée ultérieurement
      jsonval json, -- si script null alors éventuel texte retourné en JSON, sinon représentation interne
      htmlval text -- éventuel texte retourné en HTML
    )",
    // table stockant les scripts du registre
    "create table script(
      id varchar(255) not null primary key, -- PATH_INFO de l'URI dans le registre
      parent varchar(255) not null references registre, -- clé du registre parent dans le registre
      title varchar(255) not null, -- titre nécessaire pour naviguer dans le registre
      jsonval text not null, -- script Php générant le texte retourné en JSON
      htmlval text not null -- script Php générant le texte retourné en HTML
    )",
    // ajout de la contrainte sur la colonne script de la table registre
    "alter table registre
      add constraint ref_script foreign key(script) references script(id)",
    // table de log des erreurs 
    "create table errorlog(
      date timestamp not null, -- la date de l'erreur
      httpCode int not null, -- le code d'erreur
      message text, -- le message d'erreur
      path_info varchar(255) not null, -- l'id de la ressource objet de la requête
      method varchar(255) not null, -- la méthode Http (PUT/DELETE)
      input text -- le message transmis en entrée du script
    )",
    // initialisation du registre par création de la racine
    "insert into registre(id,parent,type,title) values('','','R','Racine')",
  ] as $sql) {
    try {
      PgSql::query($sql);
    }
    catch (Exception $e) {
      die($e->getMessage());
    }
  }
  echo "Fin de l'initialisation de la base de données<br>\n";
}

function format(): string { // retourne le format demandé 'html' ou 'json'
  $http_accept = explode(',', $_SERVER['HTTP_ACCEPT'] ?? '');
  $format = in_array('text/html', $http_accept) ? 'html' : 'json';
  if (($format=='html') && (substr($_SERVER['PATH_INFO'] ?? '', -5) == '.json'))
    $format = 'json';
  return $format;
}

// insère un enregistrement dans la table errorlog
function logError(int $httpCode, string $message): void {
  $path_info = $_SERVER['PATH_INFO'] ?? '';
  $path_info = str_replace("'", "''", $path_info);
  $inputAsTxt = str_replace("'","''", file_get_contents('php://input'));
  $message = str_replace("'", "''", $message);
  PgSql::query("insert into errorlog(date,httpCode,message,path_info,method,input) values "
    ."(now(), $httpCode, '$message', '$path_info', '$_SERVER[REQUEST_METHOD]', '$inputAsTxt')");
}

// Génère une erreur Http avec le code $httpCode et $message comme message d'erreur
function error(int $httpCode, string $message, bool $log=true): void {
  //throw new Exception($message);
  // Dict. utilisée par la fonction error()
  define('HTTP_ERROR_LABELS', [
    400 => 'Bad Request', // La syntaxe de la requête est erronée.
    403 => 'Forbidden', // Le serveur a compris la requête, mais refuse de l'exécuter
    404 => 'Not Found', // Ressource non trouvée. 
    500 => 'Internal Server Error', // Erreur interne du serveur. 
    501 => 'Not Implemented', // Fonctionnalité réclamée non supportée par le serveur.
  ]
  );
  header(sprintf('HTTP/1.1 %d %s', $httpCode, HTTP_ERROR_LABELS[$httpCode] ?? "unknown error"));
  if (($httpCode <> 404) && $log)
    logError($httpCode, $message);
  $inputAsTxt = file_get_contents('php://input');
  $input = json_decode($inputAsTxt, true);
  if (format() == 'json') {
    header('Content-type: application/json; charset="utf8"');
    die(json_encode(
      [
        'path_info'=> $_SERVER['PATH_INFO'] ?? '',
        'method'=> $_SERVER['REQUEST_METHOD'] ?? '',
        'input'=> $input ?? $inputAsTxt,
        'error'=> $message,
      ], JSON_OPTIONS));
  }
  else {
    header('Content-type: text/plain; charset="utf8"');
    die($message);
  }
}

// génère l'URI PgSql à partir des variables d'environnement
function dbUri(): string {
  if (!($host = getenv('DATABASE_HOST'))) // Hôte du serveur de base de données
    error(500, "Erreur, variable d'environnement DATABASE_HOST non définie", false);
  if (!($port = getenv('DATABASE_PORT'))) // Port du serveur de base de données
    error(500, "Erreur, variable d'environnement DATABASE_PORT non définie", false);
  if (!($dbname = getenv('DATABASE_NAME'))) // Nom de la base partagée par les services
    error(500, "Erreur, variable d'environnement DATABASE_NAME non définie", false);
  if (!($schema = getenv('DATABASE_SCHEMA_NAME'))) // Nom du schéma partagé par les services
    error(500, "Erreur, variable d'environnement DATABASE_SCHEMA_NAME non définie", false);
  if (!($user = getenv('DATABASE_USERNAME'))) // Utilisateur de connexion sur la base
    error(500, "Erreur, variable d'environnement DATABASE_USERNAME non définie", false);
  if (!($passwd = getenv('DATABASE_PASSWORD'))) // Mot de passe de connexion sur la base
    error(500, "Erreur, variable d'environnement DATABASE_PASSWORD non définie", false);
  return "pgsql://$user:$passwd@$host:$port/$dbname/$schema";
}

function loginPwd(): void { // récupère les login/pwd en paramètre ou envoie une erreur 401 ou 403
  define('HASH', '$2y$10$K/82xc3hZSd8xc.tqzzXJeK5zFH94Bns.qmIh/n/OwYj/Hd7JI3qa');
  // Si la requete ne comporte pas d'utilisateur, alors renvoie d'une demande d'authentification 401
  if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW'])) {
    header('WWW-Authenticate: Basic realm="Authentification pour mise a jour du registre"');
    error(401, "ce service nécessite une authentification pour cette opération");
  }
  if (!password_verify("$_SERVER[PHP_AUTH_USER]:$_SERVER[PHP_AUTH_PW]", HASH))
    error(403, "Login/mot de passe incorrect");
}

function execScript(string $scriptid, ?string $data): void { // exécute un script 
  if ($data)
    $data = json_decode($data, true);
  $fmtval = format().'val';
  $tuples = PgSql::getTuples("select $fmtval script from script where id='$scriptid'");
  if (!$tuples)
    throw new Exception("Erreur script $scriptid non trouvé");
  $script = $tuples[0]['script'];
  //echo "script=$script\n";
  eval($script);
}
  
function id(): string { // identifie la ressource concernée 
  static $id = null;
  if (!$id) {
    $id = $_SERVER['PATH_INFO'] ?? '';
    if (!preg_match('!^[-a-zA-Z0-9$_.+/]*$!', $id)) // les caractères alpha, digit et safe de la RFC 1738 plus '/'
      throw new Exception("Erreur, caractère interdit dans l'URI");
    if (substr($id, -5) == '.json') {
      $id = substr($id, 0, strlen($id)-5);
    }
    if ($id == '/') // la racine est identifiée par la chaine vide
      $id = '';
  }
  return $id;
}

function scheme(): string { return $_SERVER['REQUEST_SCHEME']; } // 'http' ou 'https'

function children(string $parentid) { // retourne la liste des enfants et la retour comme [['id'=>id, 'title'=>title]]
  $scheme = scheme();
  $children = [];
  foreach (['registre','script'] as $table) {
    foreach(PgSql::query("select * from $table where parent='$parentid' and id<>''") as $child) {
      $children[] = [
        'id'=> "$scheme://$_SERVER[HTTP_HOST]$_SERVER[SCRIPT_NAME]$child[id]",
        'title'=> $child['title'],
      ];
    }
  }
  return $children;
}

function logRecentUpdates(string $id, $input=null): void { // log les mises à jour récentes dans LOGFILE_NAME
  // si le fichier n'a pas été modifié depuis plus de 5' alors il est effacé
  // Cela permet d'avoir les dernières mises à jour tout en s'assurerant que le fichier reste de taille limitée
  // A court-cicuiter en production
  define('LOGFILE_NAME', __DIR__.'/recentupdatelog.yaml'); // nom du fichier
  if (is_file(LOGFILE_NAME) && ((time() - filemtime(LOGFILE_NAME)) > 5*60))
    unlink(LOGFILE_NAME);
  file_put_contents(LOGFILE_NAME,
    Yaml::dump(
      [['date'=> date(DATE_ATOM),
        'id'=> $id,
        'method'=> $_SERVER['REQUEST_METHOD'],
        'input'=> $input,
      ]], 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),
    FILE_APPEND);
}

function update(string $id, array $input): array { // met à jour la ressource id avec le contenu dans $input
  //echo json_encode($input),"\n"; die();
  foreach (['parent','type','title'] as $key)
    if (!isset($input[$key]))
      throw new Exception("Erreur le champ $key doit être défini");
  $parent = str_replace("'","''", $input['parent']);
  if (!in_array($input['type'], ['E','R','S']))
    throw new Exception("Erreur type='$input[type]' doit être dans ['E','R','S']");
  $title = str_replace("'","''", $input['title']);
  if ($input['jsonval'] ?? null) {
    if ($input['type']<>'S')
      $jsonval =  json_encode($input['jsonval'], JSON_OPTIONS);
    else
      $jsonval = $input['jsonval'];
    $jsonval =  "'".str_replace("'","''", $jsonval)."'";
  }
  else {
    $jsonval = 'null';
  }
  if ($input['htmlval'] ?? null) {
    $htmlval = "'".str_replace("'","''", $input['htmlval'])."'";
  }
  else {
    $htmlval = 'null';
  }
  if ($input['script'] ?? null) {
    $script = "'".str_replace("'","''", $input['script'])."'";
  }
  else {
    $script = 'null';
  }
  if ($input['type']=='S') {
    PgSql::query(
     "insert into script(id,parent,title,jsonval,htmlval)
      values('$id','$parent','$title',$jsonval,$htmlval)
      on conflict(id) do
        update set parent='$parent',title='$title',jsonval=$jsonval,htmlval=$htmlval"
    );
  }
  else
    PgSql::query(
     "insert into registre(id,parent,type,title,script,jsonval,htmlval)
      values('$id','$parent','$input[type]','$title',$script,$jsonval,$htmlval)
      on conflict(id) do
        update set parent='$parent',type='$input[type]',title='$title',script=$script,jsonval=$jsonval,htmlval=$htmlval"
    );
  return ['method'=>'PUT','id'=>$id,'input'=>$input,'return'=>'ok'];
}

function bulkLoad(array $input): void { // chargement en nombre
  logRecentUpdates('/bulkLoad', $input);
  try {
    loginPwd();
    PgSql::open(dbUri());
  }
  catch (Exception $e) {
    error(500, $e->getMessage(), false); // l'erreur n'est pas loguée car la connexion à la base n'est pas possible
  }
  $results = [];
  $errors = false;
  foreach ($input as $no => $update) {
    if (!isset($update['path'])) {
      $results[$no] = "Erreur le champ path doit être défini";
      $errors = true;
    }
    elseif (!preg_match('!^[-a-zA-Z0-9$_.+/]*$!', $update['path'])) { // les car. alpha, digit et safe de la RFC 1738 plus '/'
      $results[$no] = "Erreur, caractère interdit dans le champ path";
      $errors = true;
    }
    else {
      try {
        update($update['path'], $update);
        $results[$no] = 'ok';
      }
      catch(Exception $e) {
        $results[$no] = $e->getMessage();
        $errors = true;
      }
    }
  }
  header('Content-type: application/json; charset="utf8"');
  if (!$errors) {
    die(json_encode(['bulkLoad'=> 'ok'], JSON_OPTIONS));
  }
  else {
    logError(400, "erreur dans le chargement");
    header('HTTP/1.1 400 Bad Request');
    die(json_encode(
      [
        'path_info'=> $_SERVER['PATH_INFO'] ?? null,
        'method'=> $_SERVER['REQUEST_METHOD'] ?? null,
        'errors'=> $results,
      ], JSON_OPTIONS));
  }
}

try {
  switch ($_SERVER['REQUEST_METHOD']) { // en fonction de la méthode Http de la requête 
    case 'GET': { // lecture d'une ressource 
      // Ouverture de la base Pgsql en lecture en fonction du serveur
      try {
        PgSql::open(dbUri());
      }
      catch (Exception $e) {
        error(500, $e->getMessage(), false); // l'erreur n'est pas loguée car la connexion à la base n'est pas possible
      }
      $id = id();
      try {
        $tuples = PgSql::getTuples("select * from registre where id='$id'");
      }
      catch (Exception $e) { // la table registre n'existe pas <=> la base n'a pas été initialisée
        initDatabase();
        $tuples = PgSql::getTuples("select * from registre where id='$id'");
      }
      if (!$tuples) { // cas où la ressource n'existe pas dans registre
        $tuples = PgSql::getTuples("select * from script where id='$id'");
        if (!$tuples) { // cas où la ressource n'existe ni dans registre ni dans script
          error(404, "Erreur, la ressource $id n'a pas été trouvée");
        }
        else { // la ressource est un script
          $tuple = $tuples[0];
          if (format() == 'json') {
            header('Content-type: application/json; charset="utf8"');
            die(json_encode($tuple, JSON_OPTIONS));
          }
          else { // (format == 'html')
            $htmlHeader = "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>registre</title></head><body>\n";
            echo $htmlHeader,
              "<pre>",str_replace('<','&lt;',Yaml::dump($tuple, 2, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)),"</pre>\n";
            die();
          }
        }
      }
      // cas où la ressource est un registre ou un élément
      $tuple = $tuples[0];
      if (format() == 'json') { // format json
        header('Content-type: application/json; charset="utf8"');
        if (isset($tuple['script']) && $tuple['script']) { // cas où un script est défini
          die(execScript($tuple['script'], $tuple['jsonval']));
        }
        elseif ($tuple['jsonval']) { // cas où jsonval est défini
          if ($tuple['type']=='E')
            die($tuple['jsonval']);
          else // pour un type 'R', j'ajoute au jsonval les children
            die(json_encode(
                 json_decode($tuple['jsonval'], true)
                 + ['children'=> children($id)]
              , JSON_OPTIONS));
        }
        elseif ($tuple['type']=='E') { // affichage d'un élément
          die(json_encode($tuple['title'], JSON_OPTIONS));
        }
        else { // type == 'R'
          die(
            json_encode([
                'id'=> $id,
                'title'=> $tuple['title'],
                'children'=> children($id),
              ], JSON_OPTIONS));
        }
      }
      else { // (format == 'html')
        $htmlHeader = "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>registre</title></head><body>\n";
        if (isset($tuple['script']) && $tuple['script']) {
          die(execScript($tuple['script'], $tuple['jsonval']));
        }
        elseif ($tuple['htmlval']) { // le champ htmlval existe
          if ($tuple['type']=='E')
            die($tuple['htmlval']);
          else {
            echo "$tuple[htmlval]<ul>\n";
            foreach(children($id) as $child) {
              echo "<li><a href=\"$child[id]\">$child[title]</a></li>\n";
            }
            echo "</ul>\n";
            die();
          }
        }
        elseif ($tuple['type']=='E') { // affichage d'un élément basique 
          die("$htmlHeader$tuple[title]\n");
        }
        else { // type == 'R'
          echo "$htmlHeader<h2>$tuple[title]</h2><ul>\n";
          foreach (['registre','script'] as $table) {
            foreach(PgSql::query("select * from $table where parent='$id'") as $child) {
              //echo "<pre>"; print_r($child); echo "</pre>\n";
              if ($child['id'])
                echo "<li><a href=\"$_SERVER[SCRIPT_NAME]$child[id]\">$child[title]</a></li>\n";
            }
          }
          echo "</ul>\n";
          die();
        }
      }
    }
  
    case 'DELETE': { // suppression d'une ressource 
      // Ouverture de la base Pgsql en fonction du serveur avec le login/pwd fourni ou erreur
      try {
        PgSql::open(dbUri());
      }
      catch (Exception $e) {
        error(500, $e->getMessage(), false);
      }
      loginPwd();
      $id = id();
      logRecentUpdates($id);
      $affected_rows = 0;
      foreach(['registre','script'] as $table) {
        $result = PgSql::query("delete from $table where id='$id'");
        $affected_rows += $result->affected_rows();
      }
      header('Content-type: application/json; charset="utf8"');
      if ($affected_rows == 0)
        error(404, "La ressource n'a pas été trouvée");
      else
        die(json_encode(['method'=>'DELETE','id'=>$id,'return'=>'ok'], JSON_OPTIONS));
    }
    
    case 'PUT': { // insertion ou mise à jour d'une ressource 
      // Ouverture de la base Pgsql en fonction du serveur avec le login/pwd fourni ou erreur
      try {
        PgSql::open(dbUri());
      }
      catch (Exception $e) {
        error(500, $e->getMessage(), false);
      }
      loginPwd();
      $id = id();
      $inputAsTxt = file_get_contents('php://input');
      $input = json_decode($inputAsTxt, true);
      logRecentUpdates($id, $input ?? $inputAsTxt);
      if (!$input) {
        error(400, "Erreur de décodage JSON du message");
      }
      $ok = update($id, $input);
      header('Content-type: application/json; charset="utf8"');
      die(json_encode($ok, JSON_OPTIONS));
    }
  
    case 'POST': { // 2 opérations différentes 
      $path_info = $_SERVER['PATH_INFO'] ?? '';
      $inputAsTxt = file_get_contents('php://input');
      $input = json_decode($inputAsTxt, true);
      if (($path_info=='/bulkLoad') && $input && array_is_list($input)) { // chargement en nombre
        bulkLoad($input);
      }
      else { // méthode pervertie pour renvoyer le contenu de la base
        // Ouverture de la base Pgsql en lecture en fonction du serveur
        try {
          PgSql::open(dbUri());
        }
        catch (Exception $e) {
          error(500, $e->getMessage(), false);
        }
        $id = id();
        $tuples = PgSql::getTuples("select id,parent,type,title,script,jsonval,htmlval from registre where id='$id'")
                + PgSql::getTuples("select id,parent,'S' as type,title,jsonval,htmlval from script where id='$id'");
        if (!$tuples) {
          error(404, "Erreur, la ressource $id n'existe pas");
        }
        else {
          $tuple = $tuples[0];
          if (($tuple['type']<>'S') && $tuple['jsonval'])
            $tuple['jsonval'] = json_decode($tuple['jsonval'], true, 512, JSON_THROW_ON_ERROR);
          if ($tuple['type'] == 'R')
            $tuple += ['children'=> children($id)];
          header('Content-type: application/json; charset="utf8"');
          die(json_encode($tuple, JSON_OPTIONS));
        }
      }
    }
  
    default: { // méthode non prévue 
      header('HTTP/1.1 400 Bad Request');
      header('Content-type: application/json; charset="utf8"');
      die(json_encode(['error'=> "Erreur: methode $_SERVER[REQUEST_METHOD] non traitée"], JSON_OPTIONS));
    }
  }
}
catch (Exception $e) {
  error(400, $e->getMessage());
}
