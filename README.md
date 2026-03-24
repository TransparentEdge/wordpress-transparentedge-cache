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
| API TE nativa | ❌ | Buggy | ✅ |

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
- `flavor_edge_preload_limit` — Límite de URLs de sitemap preload (default: 500)

**Acciones:**
- `flavor_edge_after_purge_all` — Tras purge completo
- `flavor_edge_after_tag_purge` — Tras purge por tags
- `flavor_edge_after_url_purge` — Tras purge por URLs
- `flavor_edge_settings_saved` — Tras guardar settings

### Contribuir
1. Fork del repositorio
2. Crear branch feature: `git checkout -b feature/mi-feature`
3. Commit: `git commit -m "Add: descripción"`
4. Push: `git push origin feature/mi-feature`
5. Pull Request

## Licencia
Apache 2.0
