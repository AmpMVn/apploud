# GitLab Access Checker

Nastroj pro kontrolu pristupu v GitLab projektech. Vypise vsechny uzivatele a jejich pristupy ve skupine a jejich podskupinach.

## Instalace

Pro spusteni je potreba Docker. Instalace probiha nasledovne:

    # Build image
    docker compose build
    
    # Priprava prostredi
    docker compose run --rm app composer install
    docker compose run --rm app cp .env.dist .env
    
    # Upravit .env a nastavit GITLAB_BASE_URL (https://gitlab.com/api/v4)
    # Upravit .env a nastavit GITLAB_TOKEN (Personal Access Token z GitLabu)

## Spusteni kontroly pristupu

    docker compose run --rm app bin/console gitlab:access-report <group_id>

Kde `group_id` je ID skupiny v GitLabu, kterou chcete analyzovat. ID najdete v URL skupiny nebo v nastaveni skupiny.

Priklad vystupu:

    Nacitam data z GitLabu pro ID: 123

    Jan Novak (@novak)
    Skupiny:
      ├─ moje/skupina (Vlastnik)
      ├─ moje/skupina/podskupina (Spravce)
    Projekty:
      ├─ moje/skupina/projekt1 (Vyvojar)
      ├─ moje/skupina/projekt2 (Reporter)

    Celkem uzivatelu: 1

## Vyvoj

    # Spusteni testu
    docker compose run --rm app composer test

    # Kontrola kodu
    docker compose run --rm app composer check

    # Test GitLab API tokenu
    docker compose run --rm app composer test-token

## Reseni problemu

Pri problemu s pravy zapisu do slozky var:

    docker compose run --rm app chmod -R 777 var/