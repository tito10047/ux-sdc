<?php

namespace Tito10047\UX\TwigComponentSdc\Cache;

use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\HttpKernel\CacheWarmer\CacheWarmerInterface;

final class AssetMapperCacheWarmer implements CacheWarmerInterface
{
    public function __construct(
        private AssetMapperInterface $assetMapper,
        private array $logicalPaths = []
    ) {
    }

    public function isOptional(): bool
    {
        return true;
    }

    public function warmUp(string $cacheDir, ?string $buildDir = null): array
    {
        $map = [];
        foreach ($this->logicalPaths as $logicalPath) {
            $asset = $this->assetMapper->getAsset($logicalPath);
            if ($asset) {
                $map[$logicalPath] = $asset->publicPath;
            }
        }

        $content = '<?php return ' . var_export($map, true) . ';';
        file_put_contents($cacheDir . '/twig_component_sdc_assets.php', $content);

        return [];
    }
}
