#!/bin/bash
set -e
rm -rf e2e-test
mkdir -p e2e-test
cd e2e-test

# 1. Vytvorenie dummy projektu
composer create-project symfony/skeleton dummy-project --no-interaction
cd dummy-project

# 2. Konfigurácia lokálneho repozitára a symfony/flex
composer config repositories.local '{"type": "path", "url": "../../", "canonical": false, "options": {"symlink": false}}'
composer config extra.symfony.allow-contrib true
composer config minimum-stability dev
composer config prefer-stable true

# 3. Úprava autoload v composer.json
# Použijeme php na bezpečnú úpravu JSONu
php -r '
$json = json_decode(file_get_contents("composer.json"), true);
$json["autoload"]["psr-4"]["App\\Component\\"] = "src_component/";
// Oprava require-dev ak je to pole (Composer schema vyžaduje objekt)
if (isset($json["require-dev"]) && empty($json["require-dev"])) {
    $json["require-dev"] = new stdClass();
}
file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'

# 3b. Pridanie src_component do services.yaml pre autodiscovery
# Bundle už registruje triedy, ale pre istotu v skeleton projekte 
# musíme zabezpečiť, aby App\ neprekrývalo náš namespace ak by bol v src/
# V tomto flow je to v src_component/, takze bundle by to mal zvladnut.

# 4. Inštalácia závislostí
composer require symfony/twig-bundle symfony/ux-twig-component --no-interaction --no-scripts
composer require tito10047/ux-twig-component-sdc:* --no-interaction --no-scripts

# 5. Kopírovanie E2E testovacích súborov (z tests_e2e/basic)
cp -r ../../tests_e2e/basic/* .

# 6. Overenie
echo "Running debug:container..."
php bin/console debug:container --tag=twig.component --format=json

# Voliteľne: Overenie existencie služby pre náš komponent
if php bin/console debug:container --tag=twig.component | grep -q "App\\Component\\Component\\MyComponent"; then
    echo "SUCCESS: MyComponent found in container!"
else
    echo "FAILURE: MyComponent NOT found in container!"
    exit 1
fi
