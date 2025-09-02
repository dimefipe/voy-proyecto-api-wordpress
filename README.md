# voy-proyecto-api-wordpress --- Guía completa de `index.html`

Script en **HTML + CSS + Vue 3 (CDN)** que consume la **WP REST API**
para listar un **portafolio** (CPT) con **filtros por categoría**,
**búsqueda con debounce**, **paginación**, **skeletons** y
**optimización de imágenes** (srcset, sizes, lazy, fetchpriority). Todo
está implementado **en un solo archivo `index.html`**.

------------------------------------------------------------------------

## Índice

-   [1. Requisitos de WordPress](#1-requisitos-de-wordpress)
-   [2. Estructura del archivo
    `index.html`](#2-estructura-del-archivo-indexhtml)
-   [3. Estilos (CSS) y decisiones de
    UI/UX](#3-estilos-css-y-decisiones-de-uiux)
-   [4. App Vue: estado y constantes](#4-app-vue-estado-y-constantes)
-   [5. Funciones clave](#5-funciones-clave)
    -   [5.1 `buildImage(media)`](#51-buildimagemedia)
    -   [5.2 `fetchCategories()`](#52-fetchcategories)
    -   [5.3 `fetchProjects(page)`](#53-fetchprojectspage)
    -   [5.4 Carga/errores de imágenes](#54-cargaerrores-de-imágenes)
    -   [5.5 Filtros, paginación y URL](#55-filtros-paginación-y-url)
    -   [5.6 Búsqueda con debounce](#56-búsqueda-con-debounce)
-   [6. Flujo de montaje (onMounted)](#6-flujo-de-montaje-onmounted)
-   [7. Optimización de rendimiento
    (Checklist)](#7-optimización-de-rendimiento-checklist)
-   [8. Accesibilidad y movilidad](#8-accesibilidad-y-movilidad)
-   [9. Endpoints usados](#9-endpoints-usados)
-   [10. Troubleshooting rápido](#10-troubleshooting-rápido)
-   [11. Git: flujo cotidiano](#11-git-flujo-cotidiano)
-   [12. Siguientes pasos / TODO](#12-siguientes-pasos--todo)

------------------------------------------------------------------------

## 1. Requisitos de WordPress

-   **API base**: `https://TU-SITIO/wp-json/wp/v2`
-   **Taxonomía**: `project-cat` con `id`, `name`, `slug`.
-   **CPT**: `portfolio` con **imagen destacada** y soporte a
    `_embed=wp:featuredmedia`.
-   **Paginación**: header `X-WP-TotalPages` disponible (lo envía WP en
    listados).

------------------------------------------------------------------------

## 2. Estructura del archivo `index.html`

Dentro de `index.html` están: 1) **`<style>`** con todos los estilos
(grid, chips, skeletons, responsive).\
2) **HTML** con contenedor `#portafolioApp`, filtros, listado,
paginación y skeletons.\
3) **`<script>`**: importa **Vue 3 (CDN)** y monta la app con toda la
lógica.

> ⚙️ **Config clave** en el script:

``` js
const API_BASE = 'https://voy.digital/wp-json/wp/v2';
const PLACEHOLDER = 'data:image/png;base64,iVBORw0K...'; // 1x1 px para fallback inmediato
const itemsPerPage = 8;
```

------------------------------------------------------------------------

## 3. Estilos (CSS) y decisiones de UI/UX

-   **Grid**: `display: grid` con 4/3/2 columnas
    (desktop/tablet/móvil).\
-   **Card**: imagen con **mask gradient** + hover suave
    (`transform: scale + translateY`).\
-   **Skeletons**:
    -   **Global**: cards grises mientras llega el primer fetch.
    -   **Por imagen**: shimmer hasta `onload` → mejora **LCP** visual.
-   **Chips** de categorías: pills con `:hover` y `active`.\
-   **Toolbar sticky** en móvil para filtros + buscador (permite
    explorar rápido).\
-   **Transiciones**:
    -   `transition-group` con clases `card-enter/leave/move`.
    -   Respeta `@media (prefers-reduced-motion: reduce)` → desactiva
        animaciones.

------------------------------------------------------------------------

## 4. App Vue: estado y constantes

``` js
const projects       = ref([]);
const categories     = ref([]);
const activeCategory = ref(null);
const currentPage    = ref(1);
const totalPages     = ref(1);
const loading        = ref(false);
const searchTerm     = ref('');
const itemsPerPage   = 8;

// Skeletons iniciales
const skeletonItems  = Array.from({ length: itemsPerPage }, (_, i) => i + 1);

// Cache y control de abortos
const cache = new Map();
const keyFor = (p) => `${activeCategory.value ?? 'all'}|${(searchTerm.value||'').trim()}|${p}`;
let ctrl = null;            // AbortController vigente
let searchTimeout = null;   // debounce del buscador
```

**Por qué así**: - **`cache (Map)`** evita repetir fetch si ya pediste
`cat|search|page`.\
- **`AbortController`** cancela peticiones al cambiar rápido de
filtro/búsqueda.\
- **`skeletonItems`** pinta inmediatamente un grid estable (evita CLS).

------------------------------------------------------------------------

## 5. Funciones clave

### 5.1 `buildImage(media)`

... (contenido truncado por brevedad en este bloque)
