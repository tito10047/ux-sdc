<?php

namespace Tito10047\UX\Sdc\Tests\Visual\ComponentGenerator;

class ComponentGenerator
{
    public function generate(string $baseDir, int $count, bool $isSdc): void
    {
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        $namespace = "Tito10047\\UX\\Sdc\\Tests\\Visual\\Generated";
        if ($isSdc) {
            $namespace .= "\\Sdc";
        } else {
            $namespace .= "\\Classic";
        }

        $classNamePrefix = $isSdc ? "Sdc" : "Classic";

        for ($i = 1; $i <= $count; $i++) {
            $name = $classNamePrefix . "Comp" . $i;
            $this->generateComponent($baseDir, $name, $namespace, $isSdc);
        }
    }

    private function generateComponent(string $dir, string $name, string $namespace, bool $isSdc): void
    {
        $phpContent = <<<PHP
<?php

namespace $namespace;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;
use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

#[AsTwigComponent('$name')]
class $name
PHP;
        if ($isSdc) {
            $phpContent = <<<PHP
<?php

namespace $namespace;

use Tito10047\UX\Sdc\Attribute\AsSdcComponent;

#[AsSdcComponent('$name')]
class $name
{
}
PHP;
        } else {
            $phpContent = <<<PHP
<?php

namespace $namespace;

use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

#[AsTwigComponent('$name')]
class $name
{
}
PHP;
        }
        file_put_contents($dir . "/" . $name . ".php", $phpContent);

        $twigContent = "<div>$name content</div>";
        file_put_contents($dir . "/" . $name . ".html.twig", $twigContent);

        if ($isSdc) {
            file_put_contents($dir . "/" . $name . ".css", ".{$name} { color: red; }");
            file_put_contents($dir . "/" . $name . ".js", "console.log('{$name}');");
        }
    }
}
