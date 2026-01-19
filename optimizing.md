# Analýza a optimalizácia výkonu UX\TwigComponentSdc

Tento dokument sumarizuje zistenia z benchmarkovania bundle-u a navrhuje konkrétne kroky na optimalizáciu jeho výkonu.

## 1. Identifikované úzke hrdlá (Bottlenecks)

### 1.1 AssetMapper::getAsset() v AssetResponseListener (Kritické)
- **Zistenie:** Volanie `$this->assetMapper->getAsset($asset['path'])` v rámci cyklu v `AssetResponseListener::onKernelResponse` je najväčšou brzdou.
- **Dopad:** Pri väčšom počte komponentov (napr. 500-1000 assetov) trvá mapovanie ciest približne 10-15 ms na request. To tvorí až 80% dodatočného času, ktorý bundle pridáva k renderovaniu.
- **Príčina:** `AssetMapper` vykonáva vyhľadávanie v súborovom systéme alebo interných mapách pri každom volaní, čo je nákladné.

### 1.2 Generovanie kľúčov v AssetRegistry
- **Zistenie:** Použitie `md5($path . $type . serialize($attributes))` na generovanie unikátneho kľúča pre každý asset v `AssetRegistry::addAsset`.
- **Dopad:** Hoci ide o mikro-optimalizáciu, pri tisíckach volaní (ak sú komponenty vnorené) pridáva `serialize` a `md5` zbytočný overhead.
- **Príčina:** Každé pridanie assetu vyžaduje serializáciu atribútov, aj keď sú väčšinou prázdne.

### 1.3 Pamäťová náročnosť AssetComponentCompilerPass
- **Zistenie:** Použitie `ReflectionClass` pre všetky registrované komponenty počas kompilácie kontajnera.
- **Dopad:** Zvyšuje pamäťovú náročnosť procesu `cache:warmup`.
- **Príčina:** Reflexia sa používa na auto-discovery assetov a čítanie atribútov pre každý jeden komponent.

### 1.4 Metadata Registry Lookup
- **Zistenie:** `SdcMetadataRegistry` pri každom komponente načítava metadáta z cache súboru.
- **Dopad:** Ak je pole metadát veľké, prístup k nim v každom `PreCreateForRenderEvent` pridáva mierny overhead.

## 2. Navrhované optimalizácie

### 2.1 Implementácia lokálnej cache pre AssetMapper
- **Návrh:** V rámci `AssetResponseListener` implementovať statickú alebo lokálnu cache (pole), ktorá si zapamätá výsledok `getAsset($path)`.
- **Cieľ:** Znížiť počet volaní `AssetMapperInterface` na unikátne cesty v rámci jedného requestu.

### 2.2 Optimalizácia AssetRegistry kľúčov
- **Návrh:** Ak sú `attributes` prázdne, použiť jednoduchšie spájanie reťazcov namiesto `serialize`.
- **Príklad:** `$key = $attributes ? md5($path.$type.serialize($attributes)) : $path.$type;`

### 2.3 Pred-výpočet verejných ciest v CompilerPass
- **Návrh:** Ak je to možné, pokúsiť sa namapovať assety cez `AssetMapper` už počas kompilácie kontajnera a uložiť do `SdcMetadataRegistry` už finálne verejné cesty.
- **Výhoda:** `AssetResponseListener` by nemusel počas requestu vôbec pristupovať k `AssetMapperInterface`.

### 2.4 Lazy Loading pre AssetMapper
- **Návrh:** Zabezpečiť, aby bol `AssetMapper` do listenera vstrekovaný len vtedy, ak sa skutočne renderujú nejaké assety (Proxy/Lazy service).

### 2.5 Optimalizácia auto-discovery
- **Návrh:** Umožniť vypnutie auto-discovery v produkčnom prostredí alebo ho nahradiť statickým mapovaním v konfigurácii, čím sa vyhneme `file_exists` a reflexii pri warmupe.
