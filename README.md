# voy-proyecto-api-wordpress — Guía completa de `index.html`

Script en **HTML + CSS + Vue 3 (CDN)** que consume la **WP REST API** para listar un **portafolio** (CPT) con **filtros por categoría**, **búsqueda con debounce**, **paginación**, **skeletons** y **optimización de imágenes** (srcset, sizes, lazy, fetchpriority). Todo está implementado **en un solo archivo `index.html`**.

---

## Índice
- [1. Requisitos de WordPress](#1-requisitos-de-wordpress)
- [2. Estructura del archivo `index.html`](#2-estructura-del-archivo-indexhtml)
- [3. Estilos (CSS) y decisiones de UI/UX](#3-estilos-css-y-decisiones-de-uiux)
- [4. App Vue: estado y constantes](#4-app-vue-estado-y-constantes)
- [5. Funciones clave](#5-funciones-clave)
  - [5.1 `buildImage(media)`](#51-buildimagemedia)
  - [5.2 `fetchCategories()`](#52-fetchcategories)
  - [5.3 `fetchProjects(page)`](#53-fetchprojectspage)
  - [5.4 Carga/errores de imágenes](#54-cargaerrores-de-imágenes)
  - [5.5 Filtros, paginación y URL](#55-filtros-paginación-y-url)
  - [5.6 Búsqueda con debounce](#56-búsqueda-con-debounce)
- [6. Flujo de montaje (onMounted)](#6-flujo-de-montaje-onmounted)
- [7. Optimización de rendimiento (Checklist)](#7-optimización-de-rendimiento-checklist)
- [8. Accesibilidad y movilidad](#8-accesibilidad-y-movilidad)
- [9. Endpoints usados](#9-endpoints-usados)
- [10. Troubleshooting rápido](#10-troubleshooting-rápido)
- [11. Git: flujo cotidiano](#11-git-flujo-cotidiano)
- [12. Siguientes pasos / TODO](#12-siguientes-pasos--todo)

---

## 1. Requisitos de WordPress
- **API base**: `https://TU-SITIO/wp-json/wp/v2`
- **Taxonomía**: `project-cat` con `id`, `name`, `slug`.
- **CPT**: `portfolio` con **imagen destacada** y soporte a `_embed=wp:featuredmedia`.
- **Paginación**: header `X-WP-TotalPages` disponible (lo envía WP en listados).

---

## 2. Estructura del archivo `index.html`
Dentro de `index.html` están:
1) **`<style>`** con todos los estilos (grid, chips, skeletons, responsive).  
2) **HTML** con contenedor `#portafolioApp`, filtros, listado, paginación y skeletons.  
3) **`<script>`**: importa **Vue 3 (CDN)** y monta la app con toda la lógica.

> ⚙️ **Config clave** en el script:
```js
const API_BASE = 'https://voy.digital/wp-json/wp/v2';
const PLACEHOLDER = 'data:image/png;base64,iVBORw0K...'; // 1x1 px para fallback inmediato
const itemsPerPage = 8;
```

---

## 3. Estilos (CSS) y decisiones de UI/UX
- **Grid**: `display: grid` con 4/3/2 columnas (desktop/tablet/móvil).  
- **Card**: imagen con **mask gradient** + hover suave (`transform: scale + translateY`).  
- **Skeletons**:
  - **Global**: cards grises mientras llega el primer fetch.
  - **Por imagen**: shimmer hasta `onload` → mejora **LCP** visual.
- **Chips** de categorías: pills con `:hover` y `active`.  
- **Toolbar sticky** en móvil para filtros + buscador (permite explorar rápido).  
- **Transiciones**:
  - `transition-group` con clases `card-enter/leave/move`.
  - Respeta `@media (prefers-reduced-motion: reduce)` → desactiva animaciones.

---

## 4. App Vue: estado y constantes
```js
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

**Por qué así**:
- **`cache (Map)`** evita repetir fetch si ya pediste `cat|search|page`.  
- **`AbortController`** cancela peticiones al cambiar rápido de filtro/búsqueda.  
- **`skeletonItems`** pinta inmediatamente un grid estable (evita CLS).

---

## 5. Funciones clave

### 5.1 `buildImage(media)`
Construye `src` y `srcset` priorizando tamaños eficientes.
```js
const buildImage = (media) => {
  if (!media) return null;
  const sizes = media?.media_details?.sizes || {};
  const order = ['medium_large','large','medium','full','thumbnail'];
  let src = media?.source_url || '';
  for (const k of order) if (sizes[k]?.source_url) { src = sizes[k].source_url; break; }
  const srcset = Object.values(sizes)
    .map(s => (s?.source_url && s?.width) ? `${s.source_url} ${s.width}w` : '')
    .filter(Boolean).join(', ');
  return { src, srcset };
};
```
- **Motivo**: elegir el **tamaño justo** y generar **`srcset`** para densidades y breakpoints.

---

### 5.2 `fetchCategories()`
```js
const fetchCategories = async () => {
  const url = new URL(`${API_BASE}/project-cat`);
  url.searchParams.set('hide_empty','true');
  url.searchParams.set('_fields','id,name,slug'); // payload mínimo
  const res = await fetch(url, { cache:'no-store' });
  categories.value = await res.json();
};
```
- **Menos datos** via `_fields` → menor tiempo de red.

---

### 5.3 `fetchProjects(page)`
Pieza central con **cache**, **abort**, **paginación via headers**, **enriquecimiento de imagen** y **mapeo de categorías**.
```js
const fetchProjects = async (page=1) => {
  const k = keyFor(page);
  if (cache.has(k)) { const {items,total}=cache.get(k); projects.value=items; totalPages.value=total; return; }

  if (ctrl) ctrl.abort();                  // cancela request anterior
  ctrl = new AbortController();
  loading.value = true;

  try{
    const url = new URL(`${API_BASE}/portfolio`);
    url.searchParams.set('_embed','wp:featuredmedia');
    url.searchParams.set('per_page', itemsPerPage);
    url.searchParams.set('page', page);

    const cat = activeCategory.value;
    const q   = (searchTerm.value || '').trim();
    if (cat) url.searchParams.set('project-cat', cat);
    if (q)   url.searchParams.set('search', q);

    const res  = await fetch(url, { cache:'no-store', signal: ctrl.signal });
    const data = await res.json();

    totalPages.value = parseInt(res.headers.get('X-WP-TotalPages')) || 1;

    const items = (Array.isArray(data)?data:[]).map(post=>{
      const media = post?._embedded?.['wp:featuredmedia']?.[0] || null;
      const img   = buildImage(media);

      const catObjs = (post['project-cat']||[])
        .map(id=>categories.value.find(c=>c.id===id)).filter(Boolean);

      const noMedia = !img;
      return {
        ...post,
        image:  noMedia ? PLACEHOLDER : img.src,
        srcset: noMedia ? '' : img.srcset,
        title:  post.title,
        project_cat_links: catObjs,
        _imgLoaded: noMedia ? true : false // si no hay img, quita skeleton
      };
    });

    projects.value = items;
    cache.set(k, { items, total: totalPages.value });
  }catch(e){
    if (e.name!=='AbortError'){ console.error('Error proyectos:', e); projects.value=[]; totalPages.value=1; }
  }finally{ loading.value=false; }
};
```

---

### 5.4 Carga/errores de imágenes
```js
const onImgLoad = (item, ev) => { item._imgLoaded = true; };
const onImgError = (item, ev) => {
  const img = ev?.target;
  if (img) { img.src = PLACEHOLDER; img.removeAttribute('srcset'); }
  item._imgLoaded = true;
};
```
- **Skeleton por imagen** desaparece **al `load` o `error`** → no hay “huecos” visuales.
- **Placeholder base64** previene parpadeos.

---

### 5.5 Filtros, paginación y URL
```js
const updateURL = () => {
  const sel = categories.value.find(c=>c.id===activeCategory.value);
  const slug = sel ? sel.slug : null;
  const p = new URLSearchParams();
  if (currentPage.value>1) p.set('page', currentPage.value);
  if (slug) p.set('cat', slug);
  const q = (searchTerm.value||'').trim(); if (q) p.set('search', q);
  history.replaceState(null,'',`?${p.toString()}`);
};

const loadFromURL = () => {
  const p = new URLSearchParams(window.location.search);
  if (p.get('page')) currentPage.value = parseInt(p.get('page'),10);
  const slug = p.get('cat');
  if (slug && categories.value.length){
    const m = categories.value.find(c=>c.slug===slug);
    if (m) activeCategory.value = m.id;
  }
  if (p.get('search')) searchTerm.value = p.get('search');
};

const changeCategory = (categoryId) => {
  activeCategory.value = categoryId;
  if (searchTerm.value) searchTerm.value = ''; // UX: resetea búsqueda
  currentPage.value = 1;
  updateURL();
  fetchProjects(currentPage.value);
};

const changePage = (page) => {
  if (page>=1 && page<=totalPages.value){
    currentPage.value = page; updateURL(); fetchProjects(page);
    document.getElementById('portafolioApp')?.scrollIntoView({behavior:'smooth'});
  }
};
```
- **Deep-linking**: `?cat=slug&page=2&search=texto` → compartir estados.  
- **UX**: limpiar búsqueda al cambiar de categoría.

---

### 5.6 Búsqueda con debounce
```js
const debouncedSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    currentPage.value = 1;
    updateURL();
    fetchProjects(1);
  }, 650);
};
```
- **650ms** balancea reactividad sin “spamear” la API.

---

## 6. Flujo de montaje (onMounted)
```js
onMounted(async () => {
  await fetchCategories();      // 1) taxonomías
  loadFromURL();                // 2) hidrata estado desde querystring
  await fetchProjects(currentPage.value); // 3) primer listado
});
```
- Carga **ítems mínimos primero** (categorías) para poder **resolver `cat` por `slug`** de la URL.

---

## 7. Optimización de rendimiento (Checklist)
- [x] **Skeleton global** y **por imagen** → pinta rápido y estable.  
- [x] **`srcset` + `sizes`** → imágenes correctas por viewport/densidad.  
- [x] **`loading="lazy"` + `decoding="async"`** → reduce main-thread.  
- [x] **`fetchpriority="high"`** en **las 4 primeras** imágenes para mejorar LCP:
  ```html
  :fetchpriority="i < 4 ? 'high' : 'auto'"
  ```
- [x] **Cache (Map)** por `cat|search|page`.  
- [x] **AbortController** entre interacciones rápidas.  
- [x] **Debounce 650ms** en buscador.  
- [x] **`_fields`** en categorías (payload mínimo).  
- [x] **`cache:'no-store'`** donde conviene frescura.  
- [x] **URL stateful** con `history.replaceState` (sin recargas).  
- [x] **`prefers-reduced-motion`** respeta accesibilidad.  

---

## 8. Accesibilidad y movilidad
- **Toolbar sticky** en móvil (filtros + search) con máscara para integrarse visualmente.  
- **Tamaños de tipografía y pills** ajustados por media queries.  
- **Reduced motion** → desactiva transiciones si el usuario lo prefiere.  

> Pendiente: roles/labels ARIA en paginador y chips para lector de pantalla (ver TODO).

---

## 9. Endpoints usados
**Categorías**
```
GET /wp-json/wp/v2/project-cat?hide_empty=true&_fields=id,name,slug
```

**Proyectos**
```
GET /wp-json/wp/v2/portfolio?_embed=wp:featuredmedia&per_page=8&page={n}&project-cat={id}&search={q}
```

**Estructura que se espera por ítem**
```json
{
  "id": 123,
  "title": { "rendered": "Proyecto" },
  "link": "https://tusitio.com/proyecto/proyecto",
  "_embedded": {
    "wp:featuredmedia": [
      {
        "source_url": "…/full.jpg",
        "media_details": {
          "sizes": {
            "medium_large": {"source_url":"…","width":768},
            "large": {"source_url":"…","width":1024},
            "medium": {"source_url":"…","width":300}
          }
        }
      }
    ]
  },
  "project-cat": [3,7]
}
```

---

## 10. Troubleshooting rápido
- **No carga nada** → revisa `API_BASE` y CORS.  
- **Sin imágenes** → falta `_embed=wp:featuredmedia` o no hay featured image.  
- **Paginación rara** → inspecciona `X-WP-TotalPages` en headers.  
- **Grid lento** → confirma miniaturas generadas en WP (regenerar thumbnails).  
- **Se corta la carga al cambiar filtros rápido** → es el **AbortController** (normal).  

---

## 11. Git: flujo cotidiano
```bash
# Añadir cambios
git add .

# Mensajes orientados a performance
git commit -m "perf: cache+abort en fetch, srcset/sizes, skeleton por imagen"

# Subir a remoto
git push origin main

# Si el remoto tenía commits previos (README inicial, etc.)
git pull --rebase origin main
git push origin main
```

**.gitignore recomendado**
```gitignore
# versiones locales
final.html
final2.html

# SO / IDE
.DS_Store
Thumbs.db
.vscode/
.idea/

# Builds (si agregas tooling)
node_modules/
dist/
build/
```

---

## 12. Siguientes pasos / TODO
- [ ] **Prefetch** de la **siguiente página** (anticipar navegación).  
- [ ] **Infinite scroll** opcional con `IntersectionObserver` (mantener paginador accesible).  
- [ ] **SSR ligero** en plantilla WP para primer paint de títulos.  
- [ ] **ARIA y foco** en paginador y chips; atajos de teclado.  
- [ ] **Cache de taxonomías** en `localStorage` con TTL (ej. 24h).  

---
