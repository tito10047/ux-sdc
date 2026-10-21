#!/bin/bash
set -e
# 1. Create a dummy Symfony project
SCRIPT_DIR=$(cd "$(dirname "$0")" && pwd)
PROJECT_ROOT=$(cd "$SCRIPT_DIR/.." && pwd)

if [ -d "e2e-test" ]; then
  rm -rf e2e-test
fi

mkdir -p e2e-test
cd e2e-test

if [ ! -d "dummy-project" ]; then
    composer create-project symfony/skeleton dummy-project --no-interaction
fi
cd dummy-project

# 2. Install the bundle from this checkout, with Flex recipes enabled
composer config repositories.local "{\"type\": \"path\", \"url\": \"$PROJECT_ROOT\", \"canonical\": false, \"options\": {\"symlink\": true}}"
composer config extra.symfony.allow-contrib true
composer config minimum-stability dev
composer config prefer-stable true

# 3. Map App\Component\ to src_component/ in composer.json, editing the JSON with PHP
if [ -f "composer.json" ]; then
php -r '
$json = json_decode(file_get_contents("composer.json"), true);
$json["autoload"]["psr-4"]["App\\Component\\"] = "src_component/";
// json_decode() turns an empty require-dev object into an array; the Composer schema wants an object
if (isset($json["require-dev"]) && empty($json["require-dev"])) {
    $json["require-dev"] = new stdClass();
}
file_put_contents("composer.json", json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
'
fi

# 3b. Create the component directory. services.yaml is left alone: the bundle
#     registers the classes of component_namespace itself.
mkdir -p src_component

# 4. Install the dependencies
BRANCH_NAME=${1:-main}
composer require symfony/twig-bundle symfony/ux-twig-component webapp --no-interaction
composer require tito10047/ux-sdc:dev-$BRANCH_NAME --no-interaction

# 5. Copy the E2E fixtures (from tests_e2e/basic)
cp -r "$PROJECT_ROOT/tests_e2e/basic/"* .

# 6. Verify
echo "Running debug:container..."
JSON_OUTPUT=$(php bin/console debug:container --tag=twig.component --format=json)

# The test component must be registered as a Twig component service
if echo "$JSON_OUTPUT" | php -r '
$json = json_decode(file_get_contents("php://stdin"), true);
if (isset($json["definitions"]["App\\Component\\Component\\MyComponent"])) {
    exit(0);
}
exit(1);
'; then
    echo "SUCCESS: MyComponent found in container!"
else
    echo "FAILURE: MyComponent NOT found in container!"
    exit 1
fi
