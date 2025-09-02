  import { createApp, ref, onMounted } from 'https://unpkg.com/vue@3/dist/vue.esm-browser.js'

  createApp({ 
    setup() {
      const projects = ref([])
      const categories = ref([])
      const activeCategory = ref(null)
      const currentPage = ref(1)
      const totalPages = ref(1)
      const loading = ref(false)
      const itemsPerPage = 8
      const searchTerm = ref('')
      let searchTimeout = null

      const fetchCategories = async () => {
        try {
          const res = await fetch('https://voy.digital/wp-json/wp/v2/project-cat')
          const data = await res.json()
          const projectsRes = await fetch('https://voy.digital/wp-json/wp/v2/portfolio?_embed&per_page=100')
          const projectsData = await projectsRes.json()
          const usedCategoryIds = new Set()
          projectsData.forEach(p => (p['project-cat'] || []).forEach(id => usedCategoryIds.add(id)))
          categories.value = data.filter(cat => usedCategoryIds.has(cat.id))
        } catch (error) {
          console.error('Error al cargar categorías:', error)
        }
      }

      const fetchProjects = async (page = 1) => {
        loading.value = true
        try {
          const url = new URL('https://voy.digital/wp-json/wp/v2/portfolio')
          url.searchParams.append('_embed', '')
          url.searchParams.append('per_page', itemsPerPage)
          url.searchParams.append('page', page)

          if (activeCategory.value) {
            url.searchParams.append('project-cat', activeCategory.value)
          }

          if (searchTerm.value.trim()) {
            url.searchParams.append('search', searchTerm.value.trim())
          }

          const res = await fetch(url)
          const data = await res.json()
          const total = res.headers.get('X-WP-TotalPages')
          totalPages.value = parseInt(total) || 1

          projects.value = data.map(post => {
            const media = post._embedded?.['wp:featuredmedia']?.[0]?.source_url || ''
            const catObjects = (post['project-cat'] || [])
              .map(id => categories.value.find(c => c.id === id))
              .filter(Boolean)
            return { ...post, image: media, project_cat_links: catObjects }
          })
        } catch (error) {
          projects.value = []
          totalPages.value = 1
          console.error('Error al cargar proyectos:', error)
        } finally {
          loading.value = false
        }
      }

      const changeCategory = (categoryId) => {
        activeCategory.value = categoryId
        currentPage.value = 1
        updateURL()
        fetchProjects(currentPage.value)
      }

      const changePage = (page) => {
        if (page >= 1 && page <= totalPages.value) {
          currentPage.value = page
          updateURL()
          fetchProjects(page)
          scrollToTop()
        }
      }

      const updateURL = () => {
        const selectedCat = categories.value.find(c => c.id === activeCategory.value)
        const slug = selectedCat ? selectedCat.slug : null
        const params = new URLSearchParams()
        if (currentPage.value > 1) params.set('page', currentPage.value)
        if (slug) params.set('cat', slug)
        if (searchTerm.value.trim()) params.set('search', searchTerm.value.trim())
        history.replaceState(null, '', `?${params.toString()}`)
      }

      const loadFromURL = () => {
        const params = new URLSearchParams(window.location.search)
        if (params.get('page')) currentPage.value = parseInt(params.get('page'))

        const catSlug = params.get('cat')
        if (catSlug && categories.value.length > 0) {
          const match = categories.value.find(c => c.slug === catSlug)
          if (match) activeCategory.value = match.id
        }

        if (params.get('search')) searchTerm.value = params.get('search')
      }

      const debouncedSearch = () => {
        clearTimeout(searchTimeout)
        searchTimeout = setTimeout(() => {
          currentPage.value = 1
          updateURL()
          fetchProjects(1)
        }, 600)
      }

      const scrollToTop = () => {
        const el = document.getElementById('portafolioApp')
        if (el) el.scrollIntoView({ behavior: 'smooth' })
      }

      onMounted(async () => {
        await fetchCategories()
        loadFromURL()
        await fetchProjects(currentPage.value)
      })

      return {
        loading,
        categories,
        activeCategory,
        currentPage,
        totalPages,
        projects,
        changeCategory,
        changePage,
        searchTerm,
        debouncedSearch
      }
    }
  }).mount('#portafolioApp')