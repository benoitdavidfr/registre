<?php
/*PhpDoc:
title: httprequest.inc.php - définit la fonction httpRequest()
name: httprequest.inc.php
*/

// les options par défaut pour json_encode()
//define('JSON_OPTIONS', JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);

// construit le résultat d'une requête http sous la forme d'un dict. contenant:
// - si possible le champ hdict correspondant aux headers restructurés en dict. avec en plus les champs httpCode
//   pour le code Http et status pour le dernier status ; si les hdict n'a pas pu être construit alors il est omis
// - headers correspondant aux en-têtes Http d'origine
// - body correspondant au corps du message
function addHttpCode(string $body, ?array $headers): array {
  // Correction de certaines clés de header Http
  static $headerKeys = [
    'content-type'=> 'Content-Type',
    'date'=> 'Date',
    'server'=> 'Server',
    'vary'=> 'Vary',
    'via'=> 'Via',
  ];
  if (!$headers) {
    return [
      'headers'=> "http_response_header non défini",
      'body'=> $body,
    ];
  }
  else { // $http_response_header défini
    $hdict = ['httpCode'=> null, 'status'=> null];
    for($i=0; isset($headers[$i]); $i++) {
      if (preg_match('!^HTTP/1\.. (\d\d\d)!', $headers[$i], $matches)) {
        if (!in_array($matches[1],[301,302])) {
          $hdict['httpCode'] = (int)$matches[1];
          $hdict['status'] = $headers[$i];
        }
      }
      else {
        $pos = strpos($headers[$i], ': ');
        $key = substr($headers[$i], 0, $pos);
        $key = $headerKeys[$key] ?? $key; // correction de la clé si elle est erronée
        $hdict[$key] = substr($headers[$i], $pos+2);
      }
    }
    if ($hdict['status'])
      return [
        'hdict'=> $hdict,
        'headers'=> $headers,
        'body'=> $body,
      ];
    else
      return [
        'headers'=> $headers,
        'body'=> $body,
      ];
  }
}

// Effectue une requête http GET en demandant en retour un format 'text/html'
// et retourne un dict. [('hdict'=>hdict,)? 'headers'=>headers, 'body'=>body]
function httpRequestGetHtml(string $url): array {
  $httpOptions = [
    'method'=> 'GET',
    'ignore_errors'=> true,
    'timeout'=> 30,
    'header'=> "Accept-language: en\r\n"
              ."Accept: text/html\r\n"
  ];
  $context = stream_context_create(['http'=> $httpOptions]);
  $body = @file_get_contents($url, false, $context);
  return addHttpCode($body, $http_response_header ?? null);
}

// Effectue une requête http et retourne un dict. [('hdict'=>hdict,)? 'headers'=>headers, 'body'=>body]
function httpRequest(string $method, string $url, string $auth='', array|string $content=[]): array {
  if ($auth)
    $auth = base64_encode($auth);
  $httpOptions = [
    'method'=> $method,
    'ignore_errors'=> true,
    'timeout'=> 30,
    'header'=> "Accept-language: en\r\n"
              .($auth ? "Authorization: Basic $auth\r\n" : '')
              .($content ? "Content-type: application/json\r\n" : '')
  ]
  + ($content && is_array($content) ? ['content'=> json_encode($content, JSON_OPTIONS)] : [])
  + ($content && is_string($content) ? ['content'=> $content] : []);
  $context = stream_context_create(['http'=> $httpOptions]);
  $body = @file_get_contents($url, false, $context);
  return addHttpCode($body, $http_response_header ?? null);
}
