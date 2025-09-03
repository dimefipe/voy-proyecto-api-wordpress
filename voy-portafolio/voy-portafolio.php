<?php
/**
 * Plugin Name: VOY Portfolio (SSR + Vue)
 * Description: Portafolio con SSR (PHP) + Vue 3 y endpoint combinado. Shortcode: [voy_portfolio]
 * Version: 1.0.0
 * Author: VOY
 */

if (!defined('ABSPATH')) exit;

class VOY_Portfolio_Plugin {
  const SHORTCODE = 'voy_portfolio';
  const NS        = 'voy/v1';
  const CPT       = 'portfolio';
  const TAX       = 'project-cat';

  public function __construct() {
    add_action('init', [$this, 'register_shortcode']);
    add_action('rest_api_init', [$this, 'register_rest']);
    add_action('wp_enqueue_scripts', [$this, 'register_assets']);
  }

  public function register_assets() {
    // CSS (tu CSS completo)
    wp_register_style(
      'voy-portfolio-css',
      plugins_url('assets/css/portfolio.css', __FILE__),
      [],
      filemtime(__DIR__ . '/assets/css/portfolio.css')
    );

    // Vue 3 (CDN)
    wp_register_script(
      'vue3',
      'https://unpkg.com/vue@3/dist/vue.global.prod.js',
      [],
      null,
      true
    );

    // App JS
    wp_register_script(
      'voy-portfolio-app',
      plugins_url('assets/js/app.js', __FILE__),
      ['vue3'],
      filemtime(__DIR__ . '/assets/js/app.js'),
      true
    );
  }

  public function register_shortcode() {
    add_shortcode(self::SHORTCODE, [$this, 'shortcode_cb']);
  }

  public function shortcode_cb($atts = [], $content = '') {
    // Atributos → equivalentes a CONFIG
    $atts = shortcode_atts([
      'search'     => '1',  // ENABLE_SEARCH
      'filters'    => '1',  // ENABLE_FILTERS
      'paginator'  => '1',  // ENABLE_PAGINATOR
      'per_page'   => '8',  // ITEMS_PER_PAGE
      // Initial (deep-link opcionales)
      'category'   => '',   // id o slug
      'q'          => '',   // texto búsqueda
      'page'       => '',   // página inicial
    ], $atts, self::SHORTCODE);

    // Lee querystring si viene (?cat, ?search, ?page)
    $qs_page  = isset($_GET['page'])   ? intval($_GET['page']) : 0;
    $qs_q     = isset($_GET['search']) ? sanitize_text_field($_GET['search']) : '';
    $qs_cat   = isset($_GET['cat'])    ? sanitize_text_field($_GET['cat']) : '';

    $enable_search    = $atts['search']    === '1';
    $enable_filters   = $atts['filters']   === '1';
    $enable_paginator = $atts['paginator'] === '1';
    $per_page         = max(1, min(50, intval($atts['per_page'])));

    $initial_q     = $enable_search  ? ($qs_q ?: $atts['q']) : '';
    $initial_cat   = $enable_filters ? ($qs_cat ?: $atts['category']) : '';
    $initial_page  = $enable_paginator ? ($qs_page ?: intval($atts['page']) ?: 1) : 1;

    // Resolver categoría (id o slug) → id
    $active_cat_id = null;
    if ($enable_filters && $initial_cat !== '') {
      if (is_numeric($initial_cat)) {
        $active_cat_id = intval($initial_cat);
      } else {
        $t = get_term_by('slug', sanitize_title($initial_cat), self::TAX);
        if ($t && !is_wp_error($t)) $active_cat_id = intval($t->term_id);
      }
    }

    // Cargar categorías (para filtros)
    $terms = get_terms([
      'taxonomy'   => self::TAX,
      'hide_empty' => true,
    ]);
    $cats = [];
    if (!is_wp_error($terms) && is_array($terms)) {
      foreach ($terms as $t) {
        $cats[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];
      }
    }

    // Query de proyectos (SSR)
    $tax_query = [];
    if ($active_cat_id) {
      $tax_query[] = [
        'taxonomy' => self::TAX,
        'field'    => 'term_id',
        'terms'    => $active_cat_id,
      ];
    }

    $q = new WP_Query([
      'post_type'      => self::CPT,
      'post_status'    => 'publish',
      'paged'          => $initial_page,
      'posts_per_page' => $per_page,
      's'              => $initial_q,
      'tax_query'      => $tax_query,
      'no_found_rows'  => false,
    ]);

    $items = [];
    while ($q->have_posts()) {
      $q->the_post();
      $id    = get_the_ID();
      $title = get_the_title();
      $link  = get_permalink();

      $thumb_id = get_post_thumbnail_id($id);
      $src      = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium_large') : '';
      $srcset   = $thumb_id ? wp_get_attachment_image_srcset($thumb_id, 'full') : '';

      $ts = get_the_terms($id, self::TAX) ?: [];
      $cat_links = [];
      foreach ($ts as $t) {
        $cat_links[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];
      }

      $items[] = [
        'id'    => $id,
        'title' => ['rendered' => $title],
        'link'  => $link,
        'image' => $src ?: '',
        'srcset'=> $srcset ?: '',
        'project_cat_links' => $cat_links,
        '_imgLoaded' => true, // SSR ya "cargado"
      ];
    }
    wp_reset_postdata();

    $total       = (int)$q->found_posts;
    $total_pages = (int)$q->max_num_pages;

    // Encolar assets sólo si se usa el shortcode
    wp_enqueue_style('voy-portfolio-css');
    wp_enqueue_script('vue3');
    wp_enqueue_script('voy-portfolio-app');

    // Datos para el boot (por instancia)
    $uid       = uniqid('voyp_');
    $container = 'voy-portfolio-' . $uid;
    $state_id  = 'voy-portfolio-state-' . $uid;

    $bootstrap = [
      'config' => [
        'ENABLE_SEARCH'    => $enable_search,
        'ENABLE_FILTERS'   => $enable_filters,
        'ENABLE_PAGINATOR' => $enable_paginator,
        'ITEMS_PER_PAGE'   => $per_page,
      ],
      'initial' => [
        'projects'        => $items,
        'categories'      => $cats,
        'total_pages'     => $total_pages,
        'total'           => $total,
        'current_page'    => $initial_page,
        'active_category' => $active_cat_id,
        'search'          => $initial_q,
      ],
      'apiBase'     => esc_url_raw( rest_url(self::NS . '/portfolio') ),
      'placeholder' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR4nGNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
    ];

    ob_start();
    ?>
    <div id="<?php echo esc_attr($container); ?>" class="voy-portfolio-root" data-voy-portfolio="1">
      <!-- SSR: filtros -->
      <div class="portafolio__filtros">
        <?php if ($enable_search): ?>
          <div class="portafolio__buscador--box">
            <input class="portafolio__buscador" type="text"
              value="<?php echo esc_attr($initial_q); ?>" placeholder="Buscar proyectos..." />
          </div>
        <?php endif; ?>

        <?php if ($enable_filters): ?>
          <div class="portafolio__filtros--box">
            <button class="<?php echo $active_cat_id ? '' : 'active'; ?>">Todo</button>
            <?php foreach ($cats as $c): ?>
              <button class="<?php echo ($active_cat_id === $c['id']) ? 'active' : ''; ?>">
                <?php echo esc_html($c['name']); ?>
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- SSR: listado -->
      <div class="portafolio__listado">
        <div class="portafolio__proyectos-wrapper">
          <div class="portafolio__proyectos">
            <?php foreach ($items as $it): ?>
              <div class="portafolio__proyecto">
                <a href="<?php echo esc_url($it['link']); ?>" target="_blank" rel="noopener">
                  <div class="portafolio__img">
                    <img
                      src="<?php echo esc_url($it['image']); ?>"
                      <?php if (!empty($it['srcset'])): ?>
                        srcset="<?php echo esc_attr($it['srcset']); ?>"
                        sizes="(max-width:767px) 50vw, (max-width:1024px) 33vw, 25vw"
                      <?php endif; ?>
                      alt="<?php echo esc_attr($it['title']['rendered']); ?>"
                      class="is-loaded"
                      loading="lazy" decoding="async"
                    />
                    <!-- sin skeleton en SSR -->
                  </div>
                  <div class="portafolio__titulo"><h3><?php echo esc_html($it['title']['rendered']); ?></h3></div>
                </a>
                <div class="portafolio__categorias">
                  <?php foreach ($it['project_cat_links'] as $pc): ?>
                    <span class="portafolio__categoria"><?php echo esc_html($pc['name']); ?></span>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if (empty($items)): ?>
            <div class="no-results"><p>Sin resultados</p></div>
          <?php endif; ?>
        </div>

        <?php if ($enable_paginator && $total_pages > 1): ?>
          <div class="portafolio__paginador">
            <?php for ($p = 1; $p <= $total_pages; $p++): ?>
              <button class="<?php echo ($p === $initial_page) ? 'current' : ''; ?>"><?php echo $p; ?></button>
            <?php endfor; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Estado inicial para el JS -->
      <script type="application/json" id="<?php echo esc_attr($state_id); ?>">
        <?php echo wp_kses_post( wp_json_encode($bootstrap, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ); ?>
      </script>
    </div>
    <script>
      window.VoyPortfolioBoot = window.VoyPortfolioBoot || [];
      window.VoyPortfolioBoot.push({
        uid: "<?php echo esc_js($uid); ?>",
        containerId: "<?php echo esc_js($container); ?>",
        stateId: "<?php echo esc_js($state_id); ?>"
      });
    </script>
    <?php
    return ob_get_clean();
  }

  public function register_rest() {
    register_rest_route(self::NS, '/portfolio', [
      'methods'  => 'GET',
      'callback' => [$this, 'rest_portfolio'],
      'permission_callback' => '__return_true',
      'args' => [
        'page'      => ['default' => 1],
        'per_page'  => ['default' => 8],
        'search'    => [],
        'category'  => [], // id o slug
      ],
    ]);
  }

  public function rest_portfolio(WP_REST_Request $req) {
    $page     = max(1, intval($req->get_param('page')));
    $per_page = min(50, max(1, intval($req->get_param('per_page'))));
    $search   = sanitize_text_field($req->get_param('search'));
    $cat      = $req->get_param('category');

    $tax_query = [];
    if (!empty($cat)) {
      if (is_numeric($cat)) {
        $tax_query[] = [
          'taxonomy' => self::TAX,
          'field'    => 'term_id',
          'terms'    => intval($cat),
        ];
      } else {
        $term = get_term_by('slug', sanitize_title($cat), self::TAX);
        if ($term && !is_wp_error($term)) {
          $tax_query[] = [
            'taxonomy' => self::TAX,
            'field'    => 'term_id',
            'terms'    => intval($term->term_id),
          ];
        }
      }
    }

    $q = new WP_Query([
      'post_type'      => self::CPT,
      'post_status'    => 'publish',
      'paged'          => $page,
      'posts_per_page' => $per_page,
      's'              => $search,
      'tax_query'      => $tax_query,
      'no_found_rows'  => false,
    ]);

    $items = [];
    while ($q->have_posts()) {
      $q->the_post();
      $id    = get_the_ID();
      $title = get_the_title();
      $link  = get_permalink();

      $thumb_id = get_post_thumbnail_id($id);
      $src      = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium_large') : '';
      $srcset   = $thumb_id ? wp_get_attachment_image_srcset($thumb_id, 'full') : '';

      $terms = get_the_terms($id, self::TAX) ?: [];
      $cats  = [];
      foreach ($terms as $t) {
        $cats[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];
      }

      $items[] = [
        'id'    => $id,
        'title' => ['rendered' => $title],
        'link'  => $link,
        'image' => $src ?: '',
        'srcset'=> $srcset ?: '',
        'project_cat_links' => $cats,
      ];
    }
    wp_reset_postdata();

    $all_terms = get_terms([
      'taxonomy'   => self::TAX,
      'hide_empty' => true,
    ]);
    $cats_list = [];
    if (!is_wp_error($all_terms)) {
      foreach ($all_terms as $t) {
        $cats_list[] = ['id' => (int)$t->term_id, 'name' => $t->name, 'slug' => $t->slug];
      }
    }

    $total       = (int)$q->found_posts;
    $total_pages = (int)$q->max_num_pages;

    // Puedes habilitar cache público/CDN si quieres
    // header('Cache-Control: public, max-age=300, s-maxage=600');

    return new WP_REST_Response([
      'projects'     => $items,
      'categories'   => $cats_list,
      'total'        => $total,
      'total_pages'  => $total_pages,
    ], 200);
  }
}

new VOY_Portfolio_Plugin();
