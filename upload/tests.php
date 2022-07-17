<?php
/*PhpDoc:
title: test.php - implémentation du protocole de tests
name: test.php
*/
require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/httprequest.inc.php';

use Symfony\Component\Yaml\Yaml;

// listes des registres utilisables
define('REGISTRES', [
  'dev' => [
    'title'=> "registre en local en conteneur",
    'url'=> 'http://registre',
    'loginpwd'=> 'registre:UpWLmsgVKx3CXw2',
  ],
  //'pprod' => 'https://regpprod.georef.eu', // URL de base en pré-prod
  //'prod' => 'https://registre.georef.eu', // URL de base en prod
  //'ovh' => 'http://shomgt3.ecolabdata.fr', // URL sur le test OVH
]
);

define('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);


if (!isset($_GET['registre'])) {
  echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tests</title></head><body>\n<h1>Tests</h1>\n";
  foreach (REGISTRES as $regId => $registre)
    echo "<a href='?registre=$regId'>Tests sur $registre[title]</a><br>\n";
  die();
}

echo "<!DOCTYPE HTML><html><head><meta charset='UTF-8'><title>tests@$_GET[registre]</title></head><body>\n",
    "<h1>Tests@$_GET[registre]</h1>\n";

$baseUrl = REGISTRES[$_GET['registre']]['url'];
$auth = REGISTRES[$_GET['registre']]['loginpwd'];

$allTests = Yaml::parseFile(__DIR__.'/upload.yaml')['tests'];
if (isset($_GET['test']))
  $allTests = [$_GET['chap'] => ['u'=> $allTests[$_GET['chap']][$_GET['test']]]];

foreach ($allTests as $chapId => $tests) {
  echo "<h2>$chapId</h2>\n";
  foreach ($tests as $notest => $test) {
    switch($test['method']) {
      case 'GET': {
        foreach (['','.json'] as $format) {
          $result = httpRequestGetHtml("$baseUrl$test[path]$format");
          echo "<h3><a href='?registre=$_GET[registre]&chap=$chapId&test=$notest'>$test[title] (format $format)</a></h3>\n";
          $hdict = $result['hdict'];
          if (isset($test['r']) && ($hdict['httpCode']<>$test['resultCode']))
            echo "<b>Attention code Http ($hdict[httpCode]) différent de celui prévu ($test[r])</b></p>\n";
          if (in_array($result['hdict']['Content-Type'], ['application/json','application/json; charset="utf8"'])) {
            // je peux décoder le body qui est encodé en JSON
            $result['body'] = json_decode($result['body'], true);
          }
          unset($result['headers']);
          if ($notest <> 'u')
            unset($result['hdict']);
          echo "<pre>",str_replace('<','&lt;',Yaml::dump($result, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)),"</pre>\n";
        }
        break;
      }
    
      case 'DELETE':
      case 'PUT': {
        $result = httpRequest($test['method'], "$baseUrl$test[path]", $auth, $test['content'] ?? []);
        $body = json_decode($result['body'], true);
        if ($body)
          $result['body'] = $body;
        echo "<h3><a href='?registre=$_GET[registre]&chap=$chapId&test=$notest'>$test[title]</a></h3>\n";
        $hdict = $result['hdict'];
        if (isset($test['r']) && ($hdict['httpCode']<>$test['r']))
          echo "<b>Attention code Http ($hdict[httpCode]) différent de celui prévu ($test[r])</b></p>\n";
        unset($result['headers']);
        if ($notest <> 'u')
          unset($result['hdict']);
        echo "<pre>",str_replace('<','&lt;',Yaml::dump($result, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)),"</pre>\n";
        break;
      }
    
      case 'POST': {
        if (!isset($test['content']))
          $result = httpRequest($test['method'], "$baseUrl$test[path]");
        else {
          $result = httpRequest($test['method'], "$baseUrl$test[path]", $auth, $test['content']);
        }
        echo "<h3><a href='?registre=$_GET[registre]&chap=$chapId&test=$notest'>$test[title]</a></h3>\n";
        //echo '<pre>print_r(body)='; print_r($result['body']); echo "</pre>\n"; //die();
        $body = json_decode($result['body'], true);
        if ($body)
          $result['body'] = $body;
        $hdict = $result['hdict'];
        if (isset($test['r']) && ($hdict['httpCode']<>$test['r']))
          echo "<b>Attention code Http ($hdict[httpCode]) différent de celui prévu ($test[r])</b></p>\n";
        unset($result['headers']);
        if ($notest <> 'u')
          unset($result['hdict']);
        if ($result['body']['htmlval'] ?? null)
          $result['body']['htmlval'] = preg_replace('!\r\n!', "\n", $result['body']['htmlval']);
        echo "<pre>",str_replace('<','&lt;',Yaml::dump($result, 99, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)),"</pre>\n";
        break;
      }
    }
  }
}
