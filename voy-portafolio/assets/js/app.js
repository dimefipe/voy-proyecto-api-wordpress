(() => {
  function mountOne(entry) {
    const el = document.getElementById(entry.containerId);
    const stateEl = document.getElementById(entry.stateId);
    if (!el || !stateEl) return;

    let boot = {};
    try { boot = JSON.parse(stateEl.textContent || "{}"); } catch (e) {}

    const { config, initial, apiBase, placeholder } = boot;
    const { createApp, ref, onMounted, computed } = window.Vue;

    createApp({
      template: `
        <div>
          <div class="portafolio__filtros">
            <div v-if="CONFIG.ENABLE_SEARCH" class="portafolio__buscador--box">
              <input class="portafolio__buscador" type="text"
                     v-model="searchTerm" placeholder="Buscar proyectos..."
                     @input="debouncedSearch">
            </div>
            <div v-if="CONFIG.ENABLE_FILTERS" class="portafolio__filtros--box">
              <button :class="{ active: activeCategory === null }" @click="changeCategory(null)">Todo</button>
              <button v-for="cat in categories" :key="cat.id"
                      :class="{ active: activeCategory === cat.id }"
                      @click="changeCategory(cat.id)">{{ cat.name }}</button>
            </div>
          </div>

          <div class="portafolio__listado">
            <div class="portafolio__proyectos-wrapper">

              <div class="portafolio__proyectos" v-if="loading">
                <div class="portafolio__proyecto skeleton" v-for="n in skeletonItems" :key="'sk-'+n">
                  <a href="javascript:void(0)">
                    <div class="portafolio__img"><div class="skeleton-box skeleton-img"></div></div>
                    <div class="portafolio__titulo"><div class="skeleton-box skeleton-line skeleton-title"></div></div>
                  </a>
                  <div class="portafolio__categorias">
                    <span class="portafolio__categoria skeleton-box skeleton-pill"></span>
                    <span class="portafolio__categoria skeleton-box skeleton-pill"></span>
                    <span class="portafolio__categoria skeleton-box skeleton-pill short"></span>
                  </div>
                </div>
              </div>

              <div v-else>
                <transition-group
                  name="card"
                  tag="div"
                  class="portafolio__proyectos"
                  appear
                  :key="listKey"
                  :css="!instantSwap"
                >
                  <div v-for="(item,i) in projects" :key="item.id" class="portafolio__proyecto">
                    <a :href="item.link" target="_blank" rel="noopener">
                      <div class="portafolio__img">
                        <img :src="item.image"
                             :srcset="item.srcset && item.srcset.length ? item.srcset : undefined"
                             sizes="(max-width:767px) 50vw, (max-width:1024px) 33vw, 25vw"
                             :alt="item.title.rendered"
                             :class="{'is-loaded': item._imgLoaded}"
                             :fetchpriority="i < 4 ? 'high' : 'auto'"
                             @load="onImgLoad(item, $event)"
                             @error="onImgError(item, $event)"
                             loading="lazy" decoding="async" />
                        <div v-if="!item._imgLoaded" class="img-skeleton">
                          <div class="skeleton-box"></div>
                        </div>
                      </div>
                      <div class="portafolio__titulo"><h3>{{ item.title.rendered }}</h3></div>
                    </a>
                    <div class="portafolio__categorias">
                      <span v-for="cat in item.project_cat_links" :key="cat.id"
                            class="portafolio__categoria"
                            @click="CONFIG.ENABLE_FILTERS && changeCategory(cat.id)">
                        {{ cat.name }}
                      </span>
                    </div>
                  </div>
                </transition-group>

                <div v-if="!projects.length" class="no-results"><p>Sin resultados</p></div>
              </div>

            </div>

            <div v-if="CONFIG.ENABLE_PAGINATOR && totalPages > 1" class="portafolio__paginador">
              <button @click="changePage(currentPage - 1)" :disabled="currentPage === 1">Anterior</button>
              <button v-for="page in totalPages" :key="page"
                      @click="changePage(page)" :class="{ current: currentPage === page }">{{ page }}</button>
              <button @click="changePage(currentPage + 1)" :disabled="currentPage === totalPages">Siguiente</button>
            </div>
          </div>
        </div>
      `,
      setup() {
        const CONFIG = {
          ENABLE_SEARCH:    !!config.ENABLE_SEARCH,
          ENABLE_FILTERS:   !!config.ENABLE_FILTERS,
          ENABLE_PAGINATOR: !!config.ENABLE_PAGINATOR,
          ITEMS_PER_PAGE:   parseInt(config.ITEMS_PER_PAGE, 10) || 8
        };
        const API_BASE    = apiBase;
        const PLACEHOLDER = placeholder;

        // STATE (inicial desde SSR)
        const projects       = ref(initial.projects || []);
        const categories     = ref(initial.categories || []);
        const activeCategory = ref(initial.active_category ?? null);
        const currentPage    = ref(initial.current_page || 1);
        const totalPages     = ref(initial.total_pages || 1);
        const loading        = ref(false);
        const searchTerm     = ref(initial.search || '');

        // anti-flicker en cache
        const instantSwap = ref(false);

        const ITEMS_PER_PAGE = ref(CONFIG.ITEMS_PER_PAGE);
        const skeletonItems = computed(() =>
          Array.from({ length: ITEMS_PER_PAGE.value }, (_, i) => i + 1)
        );

        const listKey = computed(() => {
          const c = activeCategory.value ?? 'all';
          const q = CONFIG.ENABLE_SEARCH ? (searchTerm.value || '').trim() : '';
          return `p:${currentPage.value}|c:${c}|q:${q}`;
        });

        // CACHE
        let searchTimeout = null;
        const cache = new Map();
        const keyFor = (p) => {
          const ck = (activeCategory.value != null ? activeCategory.value : 'all');
          const qk = (CONFIG.ENABLE_SEARCH ? (searchTerm.value || '').trim() : '');
          return `${ck}|${qk}|${p}|${ITEMS_PER_PAGE.value}`;
        };
        let ctrl = null;

        // HELPERS
        const onImgLoad = (item) => { item._imgLoaded = true; };
        const onImgError = (item, ev) => {
          const img = ev?.target;
          if (img) { img.src = PLACEHOLDER; img.removeAttribute('srcset'); }
          item._imgLoaded = true;
        };

        // DATA
        const fetchAll = async (page = 1) => {
          const k = keyFor(page);
          if (cache.has(k)) {
            instantSwap.value = true;
            const { items, cats, total } = cache.get(k);
            projects.value   = items;
            totalPages.value = total;
            if (!categories.value.length && cats?.length) categories.value = cats;
            return;
          }

          if (ctrl) ctrl.abort();
          ctrl = new AbortController();
          loading.value = true;

          try {
            const url = new URL(API_BASE);
            url.searchParams.set('per_page', ITEMS_PER_PAGE.value);
            url.searchParams.set('page', page);

            const q = (CONFIG.ENABLE_SEARCH ? (searchTerm.value || '').trim() : '');
            if (q) url.searchParams.set('search', q);
            if (activeCategory.value) url.searchParams.set('category', activeCategory.value);

            const res  = await fetch(url.toString(), { signal: ctrl.signal, cache: 'no-store' });
            const data = await res.json();

            const items = (data.projects || []).map(post => {
              const noMedia = !post.image;
              return {
                ...post,
                image: noMedia ? PLACEHOLDER : post.image,
                srcset: post.srcset || '',
                _imgLoaded: noMedia ? true : false,
              };
            });

            instantSwap.value = false;

            projects.value   = items;
            totalPages.value = data.total_pages || 1;
            if (!categories.value.length && Array.isArray(data.categories)) {
              categories.value = data.categories;
            }

            cache.set(k, { items, cats: categories.value, total: totalPages.value });
          } catch (e) {
            if (e.name !== 'AbortError') {
              console.error('Error fetchAll:', e);
              projects.value = [];
              totalPages.value = 1;
            }
          } finally {
            loading.value = false;
          }
        };

        // UI
        const changeCategory = (categoryId) => {
          if (!CONFIG.ENABLE_FILTERS) return;
          activeCategory.value = categoryId;
          if (CONFIG.ENABLE_SEARCH && searchTerm.value) searchTerm.value = '';
          currentPage.value = 1;
          updateURL();
          fetchAll(currentPage.value);
        };

        const changePage = (page) => {
          if (!CONFIG.ENABLE_PAGINATOR) return;
          if (page >= 1 && page <= totalPages.value){
            currentPage.value = page;
            updateURL();
            fetchAll(page);
            document.getElementById(entry.containerId)?.scrollIntoView({ behavior: 'smooth' });
          }
        };

        const updateURL = () => {
          const sel = categories.value.find(c => c.id === activeCategory.value);
          const slug = sel ? sel.slug : null;
          const p = new URLSearchParams();

          if (CONFIG.ENABLE_PAGINATOR && currentPage.value > 1) p.set('page', currentPage.value);
          if (CONFIG.ENABLE_FILTERS && slug) p.set('cat', slug);
          if (CONFIG.ENABLE_SEARCH) {
            const q = (searchTerm.value || '').trim();
            if (q) p.set('search', q);
          }
          history.replaceState(null, '', `?${p.toString()}`);
        };

        const loadFromURL = () => {
          const p = new URLSearchParams(window.location.search);
          if (CONFIG.ENABLE_PAGINATOR && p.get('page')) currentPage.value = parseInt(p.get('page'), 10) || currentPage.value;
          const slug = (CONFIG.ENABLE_FILTERS ? p.get('cat') : null);
          if (CONFIG.ENABLE_SEARCH && p.get('search')) searchTerm.value = p.get('search');

          const tryResolveSlug = () => {
            if (!CONFIG.ENABLE_FILTERS || !slug || !categories.value.length) return;
            const m = categories.value.find(c => c.slug === slug);
            if (m) activeCategory.value = m.id;
          };
          return tryResolveSlug;
        };

        const debouncedSearch = () => {
          if (!CONFIG.ENABLE_SEARCH) return;
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => {
            currentPage.value = 1;
            updateURL();
            fetchAll(1);
          }, 650);
        };

        onMounted(async () => {
          const resolveSlug = loadFromURL();
          // Primer render ya está (SSR). Sólo refetch si hay categoría resuelta posterior.
          resolveSlug && resolveSlug();
          if (activeCategory.value !== (initial.active_category ?? null)) {
            await fetchAll(currentPage.value);
          }
        });

        return {
          CONFIG,
          loading, categories, activeCategory, currentPage, totalPages,
          projects, searchTerm, skeletonItems,
          listKey, instantSwap,
          changeCategory, changePage, debouncedSearch,
          onImgLoad, onImgError
        };
      }
    }).mount(el);
  }

  // Boot todas las instancias en la página
  if (window.VoyPortfolioBoot && Array.isArray(window.VoyPortfolioBoot)) {
    window.VoyPortfolioBoot.forEach(mountOne);
  }
  document.addEventListener('DOMContentLoaded', function(){
    if (window.VoyPortfolioBoot && Array.isArray(window.VoyPortfolioBoot)) {
      window.VoyPortfolioBoot.forEach(mountOne);
    }
  });
})();
