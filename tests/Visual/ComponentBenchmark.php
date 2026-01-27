<?php

namespace Tito10047\UX\TwigComponentSdc\Tests\Visual;

use Symfony\Component\Filesystem\Filesystem;
use Tito10047\UX\TwigComponentSdc\Tests\Visual\ComponentGenerator\ComponentGenerator;
use PhpBench\Benchmark\Metadata\Annotations\BeforeMethods;

/**
 * @BeforeMethods({"prepare"})
 */
class ComponentBenchmark
{
	private string $generatedDir;
	private Filesystem $fs;

	public function __construct()
	{
		$this->generatedDir = __DIR__ . '/Generated';
		$this->fs = new Filesystem();
	}

	/**
	 * Spustí sa len raz pred celým benchmarkom.
	 * Vygeneruje komponenty a statickú stress-test šablónu.
	 */
	public function prepare(): void
	{
		$generator = new ComponentGenerator();

		// Vymažeme staré generované súbory
		if (is_dir($this->generatedDir)) {
			$this->fs->remove($this->generatedDir);
		}

		// 1. Generovanie 500 komponentov
		$generator->generate($this->generatedDir . '/Classic', 500, false);
		$generator->generate($this->generatedDir . '/Sdc', 500, true);

		// 2. Vytvorenie stress-test šablóny pre Twig (aby sme nemerali Twig compilation)
		$this->createStressTestTemplate('classic', 500);
		$this->createStressTestTemplate('sdc', 500);
	}

	private function createStressTestTemplate(string $type, int $count): void
	{
		$content = '';
		$prefix = $type === 'sdc' ? 'SdcComp' : 'ClassicComp';
		for ($i = 1; $i <= $count; $i++) {
			$content .= "<twig:$prefix$i />\n";
		}

		$path = $this->generatedDir."/".ucfirst($type) . "/stress_test_$type.html.twig";
		file_put_contents($path, $content);
	}

	private function clearCache(string $env): void
	{
		$cacheDir = __DIR__ . '/../../var/cache/' . $env;
		if (is_dir($cacheDir)) {
			$this->fs->remove($cacheDir);
		}
		
		$benchmarkCacheDir = sys_get_temp_dir() . '/UX/TwigComponentSdc/benchmark/' . $env;
		if (is_dir($benchmarkCacheDir)) {
			$this->fs->remove($benchmarkCacheDir);
		}
	}

	// --- WARMUP BENCHMARKS (Cold Boot) DEBUG mode ---

	/**
	 * Meria čas kompilácie kontajnera pre Classic prístup.
	 * @Revs(1)
	 * @Iterations(5)
	 */
	public function benchWarmupClassicDebug(): void
	{
		$this->clearCache('classic');
		$kernel = new BenchmarkKernel('classic',"dev", true);
		$kernel->boot();
	}

	/**
	 * Meria čas kompilácie kontajnera pre tvoj SDC bundle (tu sa ukáže optimalizácia registra).
	 * @Revs(1)
	 * @Iterations(5)
 */
	public function benchWarmupSdcDebug(): void
	{
		$this->clearCache('sdc');
		$kernel = new BenchmarkKernel('sdc', "dev", true);
		$kernel->boot();
	}

	// --- WARMUP BENCHMARKS (Cold Boot) ---

	/**
	 * Meria čas kompilácie kontajnera pre Classic prístup.
	 * @Revs(1)
	 * @Iterations(5)
	 */
	public function benchWarmupClassic(): void
	{
		$this->clearCache('classic');
		$kernel = new BenchmarkKernel('classic',"prod", false);
		$kernel->boot();
	}

	/**
	 * Meria čas kompilácie kontajnera pre tvoj SDC bundle (tu sa ukáže optimalizácia registra).
	 * @Revs(1)
	 * @Iterations(5)
 */
	public function benchWarmupSdc(): void
	{
		$this->clearCache('sdc');
		$kernel = new BenchmarkKernel('sdc', "prod",false);
		$kernel->boot();
	}

	// --- RENDER BENCHMARKS (Hot Runtime) ---

	/**
	 * Meria rýchlosť renderu 500 komponentov na už "zahriatom" kontajneri.
	 * @Revs(10)
	 * @Iterations(5)
	 */
	public function benchRenderClassic(): void
	{
		$kernel = new BenchmarkKernel('classic',"prod", false);
		$kernel->boot(); // Prvý boot zahreje cache, ak nie je zahriata

		$twig = $kernel->getContainer()->get('twig');
		// Renderujeme z disku, nie cez createTemplate()
		$twig->render('stress_test_classic.html.twig');
	}

	/**
	 * Meria rýchlosť renderu pre SDC (tu sa ukáže tvoj nový Registry a AssetResponseListener).
	 * @Revs(10)
	 * @Iterations(5)
	 */
	public function benchRenderSdc(): void
	{
		$kernel = new BenchmarkKernel('sdc', "prod",false);
		$kernel->boot();

		$twig = $kernel->getContainer()->get('twig');
		// Renderujeme z disku
		$twig->render('stress_test_sdc.html.twig');
	}

	/**
	 * Meria rýchlosť renderu pre SDC v DEV prostredí (runtime autodiscovery).
	 * @Revs(10)
	 * @Iterations(5)
	 */
	public function benchRenderSdcDev(): void
	{
		$kernel = new BenchmarkKernel('sdc', "dev", true);
		$kernel->boot();

		$twig = $kernel->getContainer()->get('twig');
		$twig->render('stress_test_sdc.html.twig');
	}
}