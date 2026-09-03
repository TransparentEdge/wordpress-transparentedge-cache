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

Genera reglas de Speculation Rules (prefetch/prerender) servidas en un endpoint REST cacheable en el edge (`/wp-json/te-cache/v1/speculation-rules`). Tres modos: Conservative (click), Balanced (hover), Aggressive (hover + prerender). Incluye exclusiones automáticas para WP core, WooCommerce, plugins de membresía, parámetros de HubSpot y multilingüe, además de detección de conflictos con otros plugins de prefetch. Inyección por PHP (origen) o VCL (edge). El modo Aggressive requiere confirmación explícita.

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

Soporte para Redis y APCu como backends de Object Cache de WordPress. El plugin auto-detecta backends disponibles, genera el `object-cache.php` drop-in, y ofrece flush desde el dashboard.

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

- **1.5.0** — Remove Unused CSS, módulo de seguridad (gating de servicios, recomendador de security headers, hardening local), y seguridad del árbol de dependencias JS (Delay/Defer/Combine respetan `deps` de WordPress).
- **1.4.0** — Comprobación de permisos de escritura en activación; combine con degradación elegante; fixes de CSS de pestañas.
- **1.3.1** — Fix de serialización de radios y arrays de checkboxes en el formulario de admin.
- **1.3.0** — Speculation Rules coordinado con la CDN.
- **1.2.0** — Cifrado de credenciales, protección SSRF/path-traversal, y purga soft por defecto (en lugar de BAN).
- **1.1.0** — Degradación elegante: circuit breaker y health check de la API.
- **1.0.0** — Release inicial.

## Licencia
Apache 2.0
