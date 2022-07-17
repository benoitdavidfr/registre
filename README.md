## Registre simple
Prototype d'un registre simple pour répondre à certains besoins du MTECT.  

Ce registre expose des ressources dans les formats HTML et JSON ainsi qu'une API de gestion.
Provisoirement la racine des URI est https://registre.georef.eu/  
L'API de ce registre est définie dans un [document OAS 3.0](https://swagger.io/specification/) disponible
sur https://registre.georef.eu/api

### Consultation
Le registre peut être consulté en HTML et en JSON par une requête Http GET sur l'URI.

Exemples:

  - https://registre.georef.eu/ - racine du registre
  - https://registre.georef.eu/iso - registre de quelques stds ISO
  - https://registre.georef.eu/iso/639-1 - registre de quelques langues ISO 639-1
  - https://registre.georef.eu/iso/639-1/fr - langue française
  - https://registre.georef.eu/iso/639-1/en - langue anglaise

Le choix entre HTML et JSON est effectué en fonction du header Http Accept.
Si le format 'text/html' est présent dans ce header alors le format est HTML, sinon c'est JSON.
Lorsque le format HTML est choisi, ce choix peut être modifié en ajoutant un suffixe '.json' à l'URI.

Exemples:

  - https://registre.georef.eu/iso/639-1.json - contenu du registre en JSON
  - https://registre.georef.eu/iso/639-1/fr.json - contenu d'un élément en JSON

### Structuration des données
Le registre est composé de 3 types de ressources:

  - des *registres* qui contiennent d'autres ressources,
  - des *éléments* qui contiennent l'information élémentaire exposée en HTML et en JSON,
  - des *scripts* qui permettent de convertir une représentation interne associée à une ressource
    pour l'exposer au travers du GET soit en HTML soit en JSON.
    Ainsi, une ressource de type script correspond à un script Php de génération HTML et un autre de génération JSON.

Il y a 3 façons de définir le contenu d'un élément:

  - la plus simple est uniquement de lui associer un titre,
  - une façon plus complexe est de lui associer en plus une représentation JSON et une représentation HTML,
  - enfin, plus complexe, de lui associer une représentation interne JSON et des scripts Php générant
    à partir de la représentation interne les représentations externes en JSON et en HTML.

Ces ressources sont stockées dans 2 tables dans PgSql :

  - la table `registre` pour les registres et les éléments,
  - la table `script` pour les scripts.

### Mise à jour du registre
Les méthodes PUT et DELETE permettent de mettre à jour le registre ; elles nécessitent une authentification http "Basic".
Le login/mot de passe utilisé est géré dans la base Pgsql.

Un PUT doit transmettre un message JSON conforme au schema JSON suivant :

    $schema: http://json-schema.org/draft-07/schema#
    type: object
    required: [parent, type, title]
    properties:
      parent:
        description: chemin de l'URI parente, utilisé pour naviguer dans le registre
        type: string
      type:
        description: type de ressource
        type: string
        enum:
          - E # élément
          - R # registre
          - S # script
      title:
        description: titre de la ressource, utilisé pour naviguer dans le registre
        type: string
      script:
        description: |
          Pour un élément ou un registre, id des scripts à utiliser pour générer les sorties GET,
          doit être vide ou l'id d'un script dans le registre.
          Est ignoré pour un script.
        type: string
      jsonval:
        description: |
          Pour un élément ou un registre, valeur quelconque.
          Si chaine vide ou propriété absente alors la représentation externe sera définie à partir du titre.
          Si la propriété script n'est pas définie alors contient la représentation externe de la ressource.
          Si la propriété script est définie alors contient la représentation interne de la ressource
          qui sera fournie en entrée aux scripts.
          Pour un script contient le code Php générant la représentation externe JSON.
      htmlval:
        description: |
          Pour un élément ou un registre, texte HTML ou chaine vide.
          Si vide ou absente alors la représentation externe sera définie à partir du titre.
          Contient la représentation externe de la ressource en HTML si la propriété script n'est pas définie.
          Ignorée si la propriété script est définie.
          Pour un script contient le code Php générant la représentation externe HTML.
        type: string
  
#### Exemples:

    # /iso/639-1
    {"parent": "/iso", "type": "R", "title": "Langues définies dans ISO 639-1", "script": "", "jsonval": "", "htmlval": ""}

    # /iso/639-1/fr
    {"parent": "/iso/639-1", "type": "E", "title": "français", "script": "", "jsonval": "", "htmlval": ""}
    
    # /iso/639-1/en
    {"parent": "/iso/639-1",
     "type": "E",
     "title": "anglais",
     "script": "",
     "jsonval": {"fr":"anglais","en":"english"},
     "htmlval": "<b>anglais</b>"
    }
    
    # /scripts/echo.php
    {"parent": "/scripts",
     "type": "S",
     "title": "script simple de test",
     "script": "",
     "jsonval": "echo json_encode($data);",
     "htmlval": "echo json_encode($data);",
    }

    # /iso/639-1/es
    {"parent": "/iso/639-1",
     "type": "E",
     "title": "espagnol",
     "script": "/scripts/echo.php",
     "jsonval": {"fr":"espagnol","en":"spanish"}",
     "htmlval": ""
    }

En cas d'erreur, l'appel renvoie:

  - une erreur 404 si la ressource demandée n'existe pas,
  - une erreur 400 si les paramètres d'appel sont incorrects,
  - une erreur 401 si l'authentification est incorrecte.
  
### Récupération du contenu d'une ressource du registre

La méthode POST (de manière détournée de sa sémantique normale) permet de récupérer le contenu d'une ressource.
Son appel ne nécessite pas de contenu.
Elle renvoie un texte JSON dans un schéma similaire à celui ci-dessus avec la propriété id en plus.

Exemple:

    {
      "id": "/iso/639-1/fr",
      "parent": "/iso/639-1",
      "type": "E",
      "title": "français",
      "jsonval": "",
      "htmlval": ""
    }
    
Lorsque la ressource est un registre, la propriété est ajoutée `children` est ajoutée avec la liste
des ressources, chacune décrite par deux propriétés: `id` pour son URI et `title` pour son titre.

Exemple:

    {
        "id": "/iso/3166-1/alpha-2",
        "parent": "/iso/3166-1",
        "type": "R",
        "title": "codification des pays sur 2 caractères",
        "script": null,
        "jsonval": {
            "fr": "codification des pays sur 2 caractères",
            "en": "with 2 characters"
        },
        "htmlval": "codification des pays sur 2 caractères",
        "children": [
            {
                "href": "https://registre.georef.eu/iso/3166-1/alpha-2/GF",
                "title": "Guyane française"
            },
            {
                "href": "https://registre.georef.eu/iso/3166-1/alpha-2/FR",
                "title": "France"
            }
        ]
    }


### Scripts Php

Le script `docker/index.php` met en oeuvre le registre.  

Le script `upload/uploader.php` charge un ensemble de ressources défini dans un fichier conforme au schéma `upload.schema.yaml`
