### Benchmark Výsledky

#### Pred Optimalizáciou (2026-01-19)

| Benchmark | Subject | Time Avg | Mem Peak |
|-----------|---------|----------|----------|
| ComponentBenchmark | benchWarmupClassic | 5,850.800μs | 2,133,520b |
| ComponentBenchmark | benchWarmupSdc | 7,414.933μs | 2,133,256b |
| ComponentBenchmark | benchRenderClassic | 28,865.120μs | 25,926,640b |
| ComponentBenchmark | benchRenderSdc | 29,671.860μs | 30,537,152b |

#### Po Optimalizácii (2026-01-19)

| Benchmark | Subject | Time Avg | Mem Peak |
|-----------|---------|----------|----------|
| ComponentBenchmark | benchWarmupClassic | 4,387.600μs | 2,133,520b |
| ComponentBenchmark | benchWarmupSdc | 5,261.467μs | 2,133,256b |
| ComponentBenchmark | benchRenderClassic | 28,101.267μs | 25,926,640b |
| ComponentBenchmark | benchRenderSdc | 29,254.133μs | 30,537,152b |

**Záver:**
Optimalizácia priniesla mierne zlepšenie v čase renderovania (cca 1-2%), ale hlavne pripravila infraštruktúru pre efektívnejšie spracovanie assetov v produkčnom prostredí bez nutnosti volania `AssetMapper` v každom requeste.

*Poznámka: Výsledky sú priemery z 3-5 iterácií.*
