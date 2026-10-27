# Transparent Edge Cache — WordPress Plugin

Plugin de caché y optimización WPO nativo para la plataforma [Transparent Edge](https://www.transparentedge.eu). Diseñado para aprovechar las capacidades exclusivas de Varnish Enterprise, i3 image optimizer, y la API de invalidación de Transparent Edge.

## ¿Por qué este plugin?

Los plugins de caché existentes (WP Rocket, W3 Total Cache) no soportan las funcionalidades exclusivas del stack de Transparent Edge:

| Funcionalidad | WP Rocket | W3TC | **TE Cache** |
|---|---|---|---|
| Surrogate-Keys | ❌ | ❌ | ✅ |
| Soft Purge | ❌ | ❌ | ✅ |
| Invalidación por tags | ❌ | ❌ | ✅ |
| Warm-up tras purge | ❌ | ❌ | ✅ |
| i3 image optimizer | ❌ | ❌ | ✅ |
| Speculation Rules coordinado con CDN | ❌ | ❌ | ✅ |
| Recomendación VCL de seguridad | ❌ | ❌ | ✅ |
| Degradación elegante si cae la CDN | ❌ | ❌ | ✅ |
| API TE nativa | ❌ | Buggy | ✅ |

Además, cubre el terreno WPO común (minify, combine, defer/delay JS, lazy load, Remove Unused CSS, self-host fonts) con el mismo nivel que WP Rocket, pero con la ventaja de coordinarse con el edge.

## Instalación

1. Descargar `transparent-edge-cache.zip`
2. WordPress Admin → Plugins → Añadir nuevo → Subir plugin
3. Activar
4. Ir a **TE Cache** en el menú lateral
5. Introducir credenciales API (Company ID, Client ID, Client Secret)

### Primer uso (Setup Wizard)

Si es la primera instalación, aparece un wizard que:
- Auto-detecta el tipo de site (blog, corporate, WooCommerce, membership)
- Detecta plugins activos (WPML, Elementor, Yoast, etc.)
- Aplica defaults óptimos para ese tipo de site
- Conecta con la API en un click

### Autenticación

OAuth2 client_credentials. Token cacheado en WP transient (1 hora). En multisite, credenciales heredables desde la configuración de red.

### Surrogate-Keys generados

| Key | Cuándo |
|---|---|
| `site-{blog_id}` | Siempre |
| `front-page` | Portada |
| `post-{id}` | Página/post individual |
| `type-{cpt}` | Archivo de post type |
| `author-{id}` | Página de autor |
| `term-{id}` | Taxonomía (categoría, tag, etc.) |
| `tax-{taxonomy}` | Archivo de taxonomía |
| `feed` | Feeds RSS |
| `sidebar-{id}` | Sidebar activa |
| `menu-{location}` | Menú por ubicación |
| `woo-product-{id}` | Producto WooCommerce |
| `woo-cat-{id}` | Categoría de producto |
| `woo-shop` | Página de tienda |
| `speculation-rules-{blog_id}` | JSON de Speculation Rules |
| `ucss-{blog_id}` | Used CSS (Remove Unused CSS) |

### Invalidación inteligente

Al publicar un post, el plugin calcula el conjunto mínimo de tags afectados y envía un solo `tag_invalidate`. Después, hace warm-up de las URLs purgadas (categorías, tags, home, etc.) para evitar MISSes.

## VCL Snippets

El plugin genera VCL para funcionalidades que requieren procesamiento en el edge:

### i3 Image Optimization
Generado en la pestaña i3. Usa `urlplus.get_extension()` para detectar imágenes y aplica `TCDN-i3-transform`.

### Query String Stripping
Generado en la pestaña Advanced. Usa el patrón óptimo de `urlplus`:
```vcl
urlplus.parse(req.url);
urlplus.query_delete_regex("^(utm_source|utm_medium|...)$");
urlplus.query_delete("fbclid");
set req.url = urlplus.write();
```

### Security Headers
Generado en la pestaña Security. El plugin **nunca despliega VCL automáticamente** — genera un snippet recomendado que el cliente aplica desde su panel, igual que i3 y Speculation Rules. El CSP arranca en modo Report-Only por seguridad.

## Optimización WPO

### Minify y Combine
Minificación de CSS/JS con caché en disco. El combine concatena ficheros locales del footer. Si el directorio de caché no es escribible, el plugin sirve los originales sin combinar (degradación elegante) en lugar de romper la página.

### Remove Unused CSS
Genera el "Used CSS" por plantilla (home, post, page, archive, etc.) de forma **asíncrona** vía WP Cron, sin bloquear al visitante. El CSS crítico se sirve inline y el resto se difiere. Se invalida por Surrogate-Key (`ucss-{blog_id}`) cuando cambian tema, plugins o contenido. Si la generación falla o el directorio no es escribible, sirve el CSS original sin modificar.

### Delay / Defer JS — consciente del árbol de dependencias
El Delay, Defer y Combine JS respetan el árbol de dependencias que WordPress declara entre scripts. Un script nunca se optimiza si otro script no optimizado depende de él (directa o transitivamente). Esto evita errores del tipo `Backbone is not defined` o `ChildViewContainer` (Marionette / Ninja Forms) sin necesidad de mantener listas de exclusión manuales. La protección se calcula leyendo `wp_scripts()->registered[$handle]->deps`.

### Lazy load
Imágenes e iframes con `loading="lazy"`. Las imágenes de fondo CSS (inline `background-image`) se difieren con IntersectionObserver; las dos primeras se mantienen activas para no penalizar el LCP. Opt-out por elemento con `data-no-lazy`.

## Speculation Rules

Genera reglas de Speculation Rules (prefetch/prerender) servidas en un endpoint REST cacheable en el edge (`/wp-json/te-cache/v1/speculation-rules`). Tres modos: Conservative (click), Balanced (hover), Aggressive (hover + prerender). Incluye exclusiones automáticas para WP core, WooCommerce, plugins de membresía, parámetros de HubSpot y multilingüe, además de detección de conflictos con otros plugins de prefetch. Inyección **inline** en el `<head>` (por defecto; las reglas viajan dentro del HTML ya cacheado, sin petición extra al endpoint), o por cabecera PHP / VCL. El modo Aggressive requiere confirmación explícita.

## Seguridad

El plugin detecta qué servicios de seguridad tiene contratados el cliente (WAF, Bot Mitigation, Perimetrical) vía `GET /v1/companies/services/` y usa esa información para habilitar o mostrar en modo upsell las funciones que los requieren.

| Función | Requiere | Toca VCL |
|---|---|---|
| Detección de servicios | — | No |
| Security Headers (recomendador) | — | Recomienda snippet |
| Bloqueo PHP en uploads | — | No (.htaccess) |
| Desactivar XML-RPC | — | No |
| Límite de intentos de login | — | No |

**Principio invariable:** el plugin recomienda VCL, nunca lo despliega. Toda automatización de seguridad activa (rate limit, antibot, respuesta a anomalías) queda fuera del plugin y se gestiona desde la plataforma con Perimetrical contratado.

## Cache de estáticos

| Servidor | Método |
|---|---|
| Apache/LiteSpeed | `.htaccess` automático con `mod_headers` y `mod_expires` |
| Nginx | Snippet copiable para `server {}` block |
| Ambos | Invalidación automática: subida de media → purge URL; actualización tema/plugin → BAN CSS/JS |

## WooCommerce

| Evento | Acción |
|---|---|
| Guardar producto | Purge producto + categorías + shop + warm-up |
| Cambio de stock | Purge producto + categorías |
| Orden completada/cancelada | Purge productos de la orden |
| Review de producto | Purge ficha |
| Ventas programadas | Purge shop + on-sale |
| Cart/checkout/account | Excluidos de caché (configurable) |

## Object Cache

Soporte para Redis, Memcached y APCu como backends de Object Cache de WordPress. El plugin auto-detecta backends disponibles, genera el `object-cache.php` drop-in, y ofrece flush desde el dashboard. Nunca escribe un drop-in de un backend no soportado (evita un fatal de sitio).

## Page Cache de origen (Origin Shield)

Caché de página **en origen** que sirve el HTML anónimo **antes de que arranque WordPress** (drop-in `advanced-cache.php`), de modo que un *miss* de CDN no regenera WordPress. Integrado con la CDN: **una sola purga invalida edge + origen** a la vez.

- **Backends:** `disk` (sin dependencias), `redis` o `memcached` (lookup en memoria, mejor bajo concurrencia). Seleccionable, con el trade-off explicado en el panel.
- **Seguro por diseño:** solo GET anónimos, particionado por dispositivo, nunca cachea páginas logadas, respuestas con `Set-Cookie`, 404/búsquedas, ni URLs con query real; allowlist de host y tope de tamaño por entrada para proteger orígenes frágiles.
- **Opt-in.** Gestiona `WP_CACHE` en `wp-config.php` con backup fuera del webroot, y respeta un `WP_CACHE` definido por el usuario.

## Resiliencia de caché en el edge

- **Perfil de portada propio:** la URL más pedida recibe su política (TTL fresco corto + `stale-while-revalidate` generoso + `stale-if-error`) desde el plugin, no desde una rama VCL a mano. Extensible por plantilla con el filtro `flavor_edge_cache_profile`.
- **Neutralización de `Vary`:** el documento anónimo nunca varía por `Cookie`/`User-Agent` (que fragmenta la caché en un objeto por cookie de analytics), re-afirmado justo antes de enviar cabeceras.
- **Cookie guard:** VCL propose-only que limpia cookies no esenciales antes del lookup + sonda de diagnóstico `Set-Cookie`/`Vary: Cookie`.
- **Serve-stale (grace/keep):** recomendador VCL que deriva `beresp.grace`/`beresp.keep` del `Cache-Control` que emite el plugin, para que el SWR se honre de verdad en el edge.

## Multisite

- Network activation: crea tablas y defaults en cada site
- Credenciales compartidas (opcionales) desde Network Admin
- Panel con overview de todos los sites (estado, conexión)
- Purge All Network desde admin bar

## Desarrollo

### Requisitos
- PHP 7.4+
- WordPress 5.5+
- Cuenta de Transparent Edge con API habilitada

### Text domain
`flavor-edge-cache`

### Namespace
`flavor_edge\`

### Hooks disponibles

**Filtros:**
- `flavor_edge_surrogate_keys` — Modificar Surrogate-Keys antes de enviar
- `flavor_edge_is_uncacheable` — Marcar requests como no-cacheables
- `flavor_edge_vary_headers` — Añadir headers Vary
- `flavor_edge_post_purge_tags` — Modificar tags de purge por post
- `flavor_edge_post_warmup_urls` — Modificar URLs de warm-up por post
- `flavor_edge_auto_prefetch_domains` — Modificar dominios auto-detectados para DNS prefetch
- `flavor_edge_max_warmup_urls` — Límite de URLs de warm-up (default: 20)
- `flavor_edge_speculation_rules` — Modificar el array completo de Speculation Rules
- `flavor_edge_speculation_eagerness` — Modificar el nivel de eagerness
- `flavor_edge_speculation_excluded_paths` — Añadir rutas excluidas de prefetch/prerender
- `flavor_edge_speculation_enabled` — Activar/desactivar la inyección de la cabecera

**Acciones:**
- `flavor_edge_after_purge_all` — Tras purge completo
- `flavor_edge_after_tag_purge` — Tras purge por tags
- `flavor_edge_after_url_purge` — Tras purge por URLs
- `flavor_edge_settings_saved` — Tras guardar settings
- `flavor_edge_speculation_invalidated` — Tras invalidar el JSON de Speculation Rules

### Contribuir
1. Fork del repositorio
2. Crear branch feature: `git checkout -b feature/mi-feature`
3. Commit: `git commit -m "Add: descripción"`
4. Push: `git push origin feature/mi-feature`
5. Pull Request

## Changelog

- **1.12.0** — NUEVO (opt-in): **variante por negociación de contenido** — una página puede guardarse y servirse bajo una segunda clave de caché cuando el edge pone una cabecera configurada, de modo que la misma URL devuelva una representación alternativa (p. ej. Markdown para agentes de IA) sin que HTML y alternativa choquen. Apagada por defecto; se indica el nombre de la cabecera en TE Cache → Avanzado (p. ej. `X-Accept-Markdown`). Requisito: el edge debe limpiar cualquier copia de esa cabecera que mande el cliente y ponerla él mismo. Serve, store e invalidación comparten la clave, así que una purga de URL limpia las dos representaciones. — Y NUEVO (opt-in): normalización de query strings. Una opción nueva sirve páginas cacheadas para peticiones con parámetros desconocidos, conservando en la clave solo una allowlist de parámetros que cambian la página. Con ella activa, `/pagina/?ref=x` o `/pagina/?zz=1` se sirven de caché en vez de forzar un render completo. Apagada por defecto; la allowlist trae los parámetros de WP core (`s`, `p`, `paged`, `cat`, `tag`, `author`…) y se amplía en el panel (TE Cache → Avanzado) y con el filtro `flavor_edge_pc_allowed_query_strings`. Actívala solo donde todos los parámetros que cambian la página estén listados — no en sitios con búsqueda facetada o filtros WooCommerce. Serve, store e invalidación usan la misma clave normalizada, así que una purga de URL cubre también la entrada normalizada.
- **1.11.0** — Mejoras de rendimiento y fiabilidad en invalidación, object cache y page cache de origen. La invalidación sigue siendo por clave y quirúrgica: solo se purga lo que realmente cambió. **Object cache Redis**: conexión persistente por worker PHP-FPM y lectura multi-clave en un solo round-trip (MGET / `wp_cache_get_multiple`), menos llamadas en el camino logado y de admin. **Media**: una imagen se purga solo cuando su fichero cambia de verdad (firma tamaño/mtime) — editar título, alt o leyenda ya no la purga; las miniaturas se evictan sin refetch y las purgas de URL en bloque tienen tope (filtro `flavor_edge_max_purge_urls`). **Publicación**: la invalidación se despacha con un timeout corto no bloqueante, así guardar un post responde al instante; nuevo filtro `flavor_edge_full_purge_on_plugin_change` para acotar o desactivar la purga completa al activar/desactivar un plugin. **Page cache**: flush completo O(1) (rename de directorio + limpieza diferida) en vez de borrado recursivo síncrono; el fichero de config se reescribe solo cuando cambia; las páginas guardadas conservan el HTML ya optimizado. **Warm-up**: cron de principio a fin, con watchdog que reanuda una ejecución interrumpida; la sonda de salud de origen se cachea brevemente. **Autoload**: mapa estático de clases en vez de búsquedas por clase en cada request (escaneo como fallback); Speculation Rules construidas una vez por request.
- **1.10.0** — Dos capacidades nuevas sobre la base estable 1.9.1, sin tocar el comportamiento existente. **WP-CLI**: `wp te-cache purge|warm|status|config` para operación y pipelines de despliegue — purga soft/hard por URL/tag/sitio, warm-up en background o bloqueante (`--block` para CI), y lectura de estado/config (las credenciales nunca se imprimen); envoltorios finos sobre las APIs ya endurecidas de purga/preload. **Redis sobre TLS**: el object cache y la page cache de origen pueden conectar a Redis por TLS (`rediss`) con verificación de certificado activada por defecto — para Redis gestionado/remoto con cifrado en tránsito (p. ej. ElastiCache). Se activa con `WP_REDIS_SCHEME='tls'` (o `WP_REDIS_TLS=true`); verificación ajustable con `WP_REDIS_TLS_OPTIONS` para CA interna o cert self-signed. Requiere phpredis >= 5.3. Las conexiones en claro no cambian; el drop-in se regenera en el upgrade para que el código nuevo llegue a instalaciones existentes.
- **1.9.1** — Pasada de endurecimiento tras **dos auditorías de código completas** + verificación de re-fixes, antes de desplegar en un sitio de alto tráfico. Sin features nuevas; solo correctness, seguridad y robustez. **Escritura ATÓMICA de todos los drop-ins** (`object-cache.php`, `advanced-cache.php`, `wp-config.php` y `config.php`) con temp+rename, preservando permisos y symlinks e invalidando opcache — cierra la ventana de WSOD al regenerar el drop-in en un upgrade bajo carga. **Privacidad**: páginas protegidas por contraseña (`wp-postpass_`) y vistas de comentaristas (`comment_author_`) ya no se cachean públicamente. **"Saltar wizard"** ya no borraba todos los ajustes (incluido el interruptor maestro). **Token de API** cachea su expiración (ya no re-autentica en cada purga/warm, que podía disparar el circuit breaker); `Retry-After` acepta formato fecha. **Surrogate-keys** de purga saneadas con `sanitize_key` para casar con las emitidas (menús/taxonomías mixed-case ya invalidan); respuestas REST no cachean errores y llevan key purgable; un 5xx ya no se clava en el edge. **Features WPO (opt-in)**: parser de Used CSS brace-aware (ya no aplana `@media`/`@keyframes`), critical CSS por término de sección, Combine respeta `media` y orden de dependencias, Google Fonts descarga en background (sin WSOD en primera visita). **Robustez**: transforms de HTML con guarda null, Memcached TTL>30d, detección de backend memoizada por request. **Preload**: una caída de la API ya no vacía la cola ni reporta "completado" en falso; lock cross-proceso; origen inalcanzable pasa a "stalled" en vez de reprogramarse sin fin; despublicar no calienta un 404; retirar una noticia siempre purga home + secciones. **Multisite/seguridad**: credenciales de red cifradas, limitador de login no spoofeable por defecto, caché de settings reseteada en `switch_to_blog`, etc. Ver `readme.txt` para el detalle completo.
- **1.9.0** — Endurecimiento de la capa de object cache y del warm-up tras una revisión completa del código (las áreas donde nos quedábamos cortos frente a un plugin de caché maduro en un sitio con redacción intensiva). **Object cache turnkey**: con un backend Redis/Memcached vivo, el drop-in se instala automáticamente (opt-out en TE Cache → Caché → Object cache); nunca pisa en silencio un `object-cache.php` de otro plugin — lo marca y ofrece "Reemplazar" con copia de seguridad previa. **Fix — nuestros arreglos del drop-in llegan a instalaciones existentes**: el drop-in es autocontenido y NO se actualizaba al actualizar el plugin; ahora lleva sello de versión y se regenera en el upgrade si es nuestro y está desfasado. **Fix — el panel ya no da por sano un object cache caído**: si el backend cae, el drop-in caía a un array por-request no persistente pero seguía contando hits; ahora la degradación se detecta con comprobación en vivo y se muestra honestamente ("No persistente" / "Bloqueado"). **Fix — APCu endurecido** al nivel de Redis/Memcached (salt por instalación + flush namespaced con APCUIterator). **Warm-up local y priorizado**: el preload arma la lista de URLs desde WordPress (home → secciones más activas → contenido reciente) sin pasar por el sitemap vía HTTP, evitando el hairpin origen→CDN→origen y calentando primero lo que importa; el sitemap-HTTP queda como fallback para front-ends headless. Filtros: `flavor_edge_preload_terms`, `flavor_edge_preload_recent_posts`, `flavor_edge_preload_urls`, `flavor_edge_preload_local_min`. **Controles de redacción (opt-in)**: límites opcionales de revisiones (`wp_revisions_to_keep`) e intervalo de autosave (`AUTOSAVE_INTERVAL`), en TE Cache → Caché → Carga de redacción; desactivados por defecto, nunca pisan un `AUTOSAVE_INTERVAL` ya definido en `wp-config.php`. **Fix — Redis securizado (con contraseña) reportado correctamente**: en una instalación con `requirepass` y `phpredis` instalado pero sin `WP_REDIS_PASSWORD`, el backend salía como un escueto "No disponible" sin pistas; ahora la detección reconoce el caso NOAUTH/WRONGPASS también en la rama de `phpredis` y te dice que añadas `WP_REDIS_PASSWORD` al `wp-config.php`, y un Redis configurado pero caído se reporta como "no responde" en vez de "extensión no instalada". Documentadas las constantes `WP_REDIS_*` (y las `FLAVOR_EDGE_PC_REDIS_*` para aislar la page cache de una instancia compartida). En desactivación el plugin retira sus propios drop-ins de object cache y page cache.
- **1.8.1** — Fix del preload de sitemap que devolvía "no URLs found": las peticiones HTTP del sitemap/preload fuerzan ahora HTTP/1.1 (`wp_remote_get` usa HTTP/1.0 por defecto, que un edge CDN puede descartar en IPv4 → timeout → sitemap vacío); aplicado a sitemap, sub-sitemaps, sonda de salud del origen y warm HTTP de fallback. Índices Yoast grandes (50+ sub-sitemaps) ya no se descargan enteros en una petición AJAX: para en cuanto hay URLs suficientes para el tope. Eliminado un guard SSRF (`gethostbyname` + IP privada) que descartaba todo el sitemap tras un CDN cuyo origen resuelve su propio hostname a IP privada. Un candidato se trata como sitemap solo si el cuerpo contiene `<sitemapindex>`/`<urlset>`, no ante cualquier 200. Timeout filtrable (`flavor_edge_preload_sitemap_timeout`).
- **1.8.0** — Warm-up con ritmo adaptativo (AIMD): el preload del sitemap auto-ajusta su throughput a lo que el origen aguanta en vez de ir a ritmo fijo — sube el tamaño de lote mientras el origen responde rápido y la page cache tiene hueco de disco, y lo baja a la mitad ante rate-limit de plataforma, origen lento o disco casi lleno. En un origen fuerte, calentar miles de URLs tarda una fracción; en uno débil se queda lento y seguro solo. Los guardarraíles no cambian: solo se mueve dentro de ellos. **Nuevo guardarraíl de disco**: como calentar una URL también escribe su entrada en la page cache, un calentado masivo es I/O de disco en el origen; el preload se pausa si el disco baja de un suelo de espacio libre (solo backend de disco; Redis/Memcached escriben en memoria y están exentos). Tope de URLs del sitemap y umbrales del pacer ahora filtrables (`flavor_edge_preload_max_urls`, `flavor_edge_preload_batch_max`, `flavor_edge_preload_up_tolerance`, `flavor_edge_preload_down_tolerance`, `flavor_edge_preload_disk_floor`, `flavor_edge_preload_disk_green`). **Fix detección de backend**: Redis y Memcached se marcan "disponible" solo tras un round-trip real al servidor (no por tener solo la extensión PHP); antes, una máquina con la librería cliente pero sin demonio mostraba un tick verde y, si se seleccionaba, la page cache no cacheaba nada.
- **1.7.0** — Warm-up origin-safe. El republicado de una URL ahora se recalienta con **refetch de plataforma** en vez de un GET al origen: una sola llamada recalienta la URL en **todos los nodos** (la invalidación va por fanout a toda la red) mientras los nodos refetcher **agregan** las peticiones al origen (el origen ve 1-2 en vez de una por nodo), y no necesita MidTier. El GET al origen queda como *fallback* automático si la API no está disponible (seleccionable en TE Cache → Caché → Método de warm-up). El **preload del sitemap** deja de hacer un GET por URL: cada lote se calienta con un refetch agregado y queda protegido para no tumbar un origen débil — sonda de salud del origen que lo pausa si va lento o da 5xx, ventana off-peak opcional y backoff exponencial (respetando `Retry-After`) ante rate-limit de la plataforma. Método "prefetch" avanzado disponible para orígenes fuertes (puebla sin invalidar, pero hace fan-out de ~1 petición por nodo). Nuevos filtros: `flavor_edge_preload_health_url`, `flavor_edge_preload_health_timeout`, `flavor_edge_preload_health_max_latency`, `flavor_edge_preload_wait_delay`. **Fix:** el warm-up normaliza a la URL canónica de la página (quita query string y fragmento, barra final) para no calentar una misma página como varios objetos de caché cuando WPML u otro plugin decora `home_url()`/`get_permalink()` en el guardado (p. ej. un `?translation_priority=…`).
- **1.6.0** — Origin Shield: page cache de origen (disk/Redis/Memcached, sirve antes de WordPress, una purga limpia edge+origen), perfil de caché de portada, neutralización de `Vary: Cookie`/`User-Agent`, speculation inline por defecto, cookie guard (strip VCL + sonda Set-Cookie/Vary) y recomendador VCL grace/keep. Drop-in de object cache Memcached. Endurecimiento: no cachear respuestas con `Set-Cookie`, backup de `wp-config` fuera del webroot, allowlist de host y tope de tamaño en la page cache. **Fix de fragmentación de caché:** `Vary: X-Device` y `Vary: Accept-Language` dejan de enviarse por defecto (no corresponden a variantes reales y partían la caché), ahora opt-in, el `Vary` se fusiona con el existente sin perder la neutralización de Cookie/User-Agent, y una migración one-time los apaga en instalaciones existentes con aviso. El selector de backend avisa si Redis/Memcached no está disponible (y retiene Activar), y el panel distingue "Activada" real de "Activada (no sirve)" cuando falta `WP_CACHE`. Barra de pestañas que envuelve limpia. Retirada la redacción de "paridad" con terceros.
- **1.5.1** — Fix: el combinado de CSS perdía los estilos añadidos con wp_add_inline_style(); ahora se incluyen en el bundle en orden de cascada. Hardening: allowlist de extensión en Google Fonts y sanitización del post type en Remove Unused CSS.
- **1.5.0** — Remove Unused CSS, módulo de seguridad (gating de servicios, recomendador de security headers, hardening local), y seguridad del árbol de dependencias JS (Delay/Defer/Combine respetan `deps` de WordPress).
- **1.4.0** — Comprobación de permisos de escritura en activación; combine con degradación elegante; fixes de CSS de pestañas.
- **1.3.1** — Fix de serialización de radios y arrays de checkboxes en el formulario de admin.
- **1.3.0** — Speculation Rules coordinado con la CDN.
- **1.2.0** — Cifrado de credenciales, protección SSRF/path-traversal, y purga soft por defecto (en lugar de BAN).
- **1.1.0** — Degradación elegante: circuit breaker y health check de la API.
- **1.0.0** — Release inicial.

## Licencia
Apache 2.0
