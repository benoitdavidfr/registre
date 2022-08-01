<?php
/*PhpDoc:
title: uploader.php - chargement en CLI d'un fichier de ressources dans un des registres
name: uploader.php
doc: |
  Le script lit un fichier Yaml d'instructions de chargement ou de suppressions dans un registre ;
  le fichier Yaml doit respecter le schéma upload.schema.yaml ;
  le script exécute les instructions sur le registre indiqué.

  La liste des registres utilisables est dans la constante REGISTRES.
  De plus un fichier secret.inc.php contient les logins/mots de passe par registre sous la forme
    return [ {regid} => {loginPasswd} ];
  où {regid} est l'identifiant du registre et {loginPasswd} est la concaténation du login et du passwd séparés par ':'
*/
define('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/httprequest.inc.php';

use Symfony\Component\Yaml\Yaml;
use Symfony\Component\Yaml\Exception\ParseException;
// listes des registres utilisables
define('REGISTRES', [
  'dev' => [
    'title'=> "registre en local en conteneur",
    'url'=> 'http://registre',
  ],
  'georef-pprod' => [
    'title'=> "registre sur georef en pré-prod",
    'url'=> 'https://regpprod.georef.eu',
  ],
  'georef' => [
    'title'=> "registre sur georef en prod",
    'url'=> 'https://registre.georef.eu',
  ],
  'ovh' => [
    'title'=> "registre sur OVH",
    'url'=> 'http://shomgt3.ecolabdata.fr',
  ],
  'pprod' => [
    'title'=> "registre sur data.ddgfr en pré-prod",
    'url'=> 'https://preprod.registre.data.developpement-durable.gouv.fr',
  ],
  'prod' => [
    'title'=> "registre sur data.ddgfr en prod",
    'url'=> 'https://registre.data.developpement-durable.gouv.fr',
  ],
]
);


// message d'usage si l'appel est incomplet ou incorrect
function usage(array $argv, string $list): void {
  echo "usage: php $argv[0] {registre} {fichier} {chapitre}\n";
  echo "où:\n";
  echo "  - {registre} est l'identifiant d'un registre\n";
  echo "  - {fichier} est un des fichiers téléchargeables\n";
  echo "  - {chapitre} est un des chapitres du fichier\n";
  echo "\n";
  switch ($list) {
    case 'registre': {
      echo "Liste des registres:\n";
      foreach (REGISTRES as $rid => $registre)
        echo "  - $rid - $registre[title]\n";
      die();
    }
    case 'yamlFile': {
      echo "Liste des fichiers yaml:\n";
      foreach (new DirectoryIterator('.') as $fileInfo) {
        if ($fileInfo->getExtension() == 'yaml') {
          $yamlFile = $fileInfo->getFilename();
          $yaml = Yaml::parseFile($yamlFile);
          if (($yaml['$schema'] ?? null) == 'upload')
            echo Yaml::dump(
              [[ $yamlFile=> [
                  'title'=> $yaml['title'],
                  'abstract'=> $yaml['abstract'],
              ]]], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        }
      }
      die();
    }
    case 'chapter': {
      $yaml = Yaml::parseFile($argv[2]);
      echo "Liste des chapitres du fichier $argv[2]:\n";
      foreach ($yaml['chapters'] as $name => $chapter) {
        echo Yaml::dump(
          [[ $name=> [
              'title'=> $chapter['title'],
              'abstract'=> $chapter['abstract'] ?? '',
          ]]], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
      }
      die();
    }
    default: die();
  } 
}

if ($argc <= 1) {
  usage($argv, 'registre');
}
elseif (!($registre = REGISTRES[$argv[1]] ?? null)) {
  echo "Erreur: registre $argv[1] inconnu\n";
  usage($argv, 'registre');
}
$regId = $argv[1];

if ($argc <= 2) {
  usage($argv, 'yamlFile');
}
elseif (!is_file($yamlFile = $argv[2])) {
  echo "Erreur: fichier $yamlFile inconnu\n";
  usage($argv, 'yamlFile');
}
try {
  Yaml::parseFile($yamlFile);
}
catch (ParseException $e) {
  echo "Erreur de lecture du fichier $yamlFile:\n";
  die($e->getMessage()."\n");
}

if ($argc <= 3) {
  usage($argv, 'chapter');
}
elseif (!in_array($chapter = $argv[3], array_keys(Yaml::parseFile($yamlFile)['chapters']))) {
  echo "Erreur: chapitre $chapter inconnu\n";
  usage($argv, 'chapter');
}


function analyzeResult(string $method, string $path, array $result): void {
  if ($result['headers'] == "http_response_header non défini") {
    echo "$result[headers]\n";
    echo "body="; print_r($result['body']);
    return;
  }
  $body = json_decode($result['body'], true);
  if ($body)
    $result['body'] = $body;
  $hdict = $result['hdict'] ?? [];
  if (in_array($hdict['httpCode'], [200, 404])) {
    echo $method,' ',$record['title'] ?? $path," -> ok ($hdict[httpCode])\n";
  }
  else {
    echo $method,' ',$record['title'] ?? $path," -> Erreur\n";
    unset($result['headers']);
    echo Yaml::dump($result, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK),"\n";
    die();
  }
}


$chapter = (Yaml::parseFile($yamlFile))['chapters'][$chapter];
$auth = (require(__DIR__.'/secret.inc.php'))[$regId];

foreach ($chapter['delete'] ?? [] as $path) {
  $result = httpRequest('DELETE', $registre['url'].$path, $auth);
  analyzeResult('DELETE', $path, $result);
}

foreach ($chapter['put'] ?? [] as $path => $record) {
  if ($path=='/stop') die("Fin sur un /stop\n");
  $result = httpRequest('PUT', $registre['url'].$path, $auth, $record);
  analyzeResult('PUT', $path, $result);
}
