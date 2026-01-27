### Benchmark Results

#### Branch: `main` (Optimized)
| Benchmark | Subject | Mo (Mean) | Mem Peak |
|-----------|---------|--------------|----------|
| `ComponentBenchmark` | `benchWarmupClassicDebug` | 783.422ms | 25.16 MB |
| `ComponentBenchmark` | `benchWarmupSdcDebug` | 773.735ms | 33.25 MB |
| `ComponentBenchmark` | `benchWarmupClassic` | 573.221ms | 23.13 MB |
| `ComponentBenchmark` | `benchWarmupSdc` | 596.464ms | 31.47 MB |
| `ComponentBenchmark` | `benchRenderClassic` | 26.523ms | 31.63 MB |
| `ComponentBenchmark` | `benchRenderSdc` | 27.191ms | 36.24 MB |
| `ComponentBenchmark` | `benchRenderSdcDev` | 68.752ms | 88.79 MB |

**Evaluation:**
- **Warmup (Cold Boot):** In the `dev` environment (Debug), the difference between Classic and SDC is minimal. In `prod` (Warmup Sdc), the overhead of container compilation for 500 components is around 23ms.
- **Memory:** The SDC approach has approximately 8MB higher memory peak during container build, which is expected due to the registration of metadata for 500 components.
- **Render (Runtime - Prod):** After optimization (removing `md5` and reducing event listener overhead), the difference in rendering 500 components in production is reduced to approximately **0.7ms** (~1.4µs per component), which is practically negligible.
- **Render (Runtime - Dev):** In `dev` mode, rendering 500 components takes about **68.8ms** (compared to ~27ms in `prod`). This overhead (~42ms for 500 components, or **84µs per component**) is caused by runtime autodiscovery and metadata resolution. This is a deliberate trade-off to provide a better developer experience without requiring cache clears during development.
- **Caching:** Implemented runtime caching in `DevComponentRenderListener` ensures that each component is analyzed only once during a request.

