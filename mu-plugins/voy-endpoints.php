<?php
/**
 * (Opcional) Cabecera si lo pones como plugin normal.
 * En mu-plugins no es necesario, pero no molesta.
 *
 * Plugin Name: VOY - Endpoint Portfolio (REST)
 * Description: Endpoint REST que devuelve proyectos + categorías en una sola llamada.
 */

/**
 * Registramos el endpoint REST:
 *   GET /wp-json/voy/v1/portfolio
 *
 * Soporta args: page, per_page, search, category (id o slug).
 */
add_action('rest_api_init', function () {
  register_rest_route('voy/v1', '/portfolio', [
    'methods'  => 'GET',
    'callback' => 'voy_rest_portfolio',        // función que construye la respuesta
    'permission_callback' => '__return_true',  // público (ajusta si necesitas auth)
    'args' => [
      'page'      => ['default' => 1],   // página actual (paginación)
      'per_page'  => ['default' => 8],   // ítems por página
      'search'    => [],                 // término de búsqueda
      'category'  => [],                 // id numérico o slug de la taxonomía project-cat
    ],
  ]);
});

/**
 * Callback del endpoint.
 * Arma una query de WP con filtros y devuelve:
 * - projects: array de posts formateados
 * - categories: todas las categorías (para los filtros)
 * - total, total_pages: info para paginar en frontend
 *
 * @param WP_REST_Request $req
 * @return WP_REST_Response
 */
function voy_rest_portfolio(WP_REST_Request $req) {
  // ---- 1) Sanitizar & Normalizar parámetros -------------------------------

  // Página mínima 1
  $page     = max(1, intval($req->get_param('page')));

  // per_page entre 1 y 50 (evita peticiones gigantes)
  $per_page = min(50, max(1, intval($req->get_param('per_page'))));

  // Término de búsqueda (limpiado)
  $search   = sanitize_text_field($req->get_param('search'));

  // category puede ser id o slug (no sanitizamos aún porque depende del tipo)
  $cat      = $req->get_param('category');

  // ---- 2) tax_query según category (id o slug) ---------------------------

  $tax_query = [];
  if (!empty($cat)) {
    if (is_numeric($cat)) {
      // Si vino ID → filtramos por term_id directamente
      $tax_query = [[
        'taxonomy' => 'project-cat',
        'field'    => 'term_id',
        'terms'    => intval($cat),
      ]];
    } else {
      // Si vino slug → buscamos el término por slug y usamos su term_id
      $term = get_term_by('slug', sanitize_title($cat), 'project-cat');
      if ($term && !is_wp_error($term)) {
        $tax_query = [[
          'taxonomy' => 'project-cat',
          'field'    => 'term_id',
          'terms'    => intval($term->term_id),
        ]];
      }
      // Si no existe el slug, no agregamos tax_query (equivale a "Todo")
    }
  }

  // ---- 3) Query de proyectos (WP_Query) -----------------------------------

  $q = new WP_Query([
    'post_type'      => 'portfolio',   // CPT (ajústalo si usas otro)
    'post_status'    => 'publish',
    'paged'          => $page,         // página actual
    'posts_per_page' => $per_page,     // tamaño de página
    's'              => $search,       // búsqueda por título/contenido
    'tax_query'      => $tax_query,    // filtro por categoría si aplica
    'no_found_rows'  => false,         // necesitamos máximos para paginación
  ]);

  // ---- 4) Formatear cada post para el frontend ----------------------------

  $items = [];
  while ($q->have_posts()) {
    $q->the_post();

    $id    = get_the_ID();
    $title = get_the_title();
    $link  = get_permalink();

    // Imagen destacada (featured image)
    $thumb_id = get_post_thumbnail_id($id);

    // URL "principal" (elegimos un tamaño equilibrado para el grid)
    // Cambia 'medium_large' por el size que te funcione mejor.
    $src    = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'medium_large') : '';

    // srcset completo a partir del original 'full' (WP genera el set)
    $srcset = $thumb_id ? wp_get_attachment_image_srcset($thumb_id, 'full') : '';

    // Categorías del post (id, name, slug) para pintar pills clicables
    $terms = get_the_terms($id, 'project-cat') ?: [];
    $cats  = array_values(array_map(function($t){
      return [
        'id'   => (int) $t->term_id,
        'name' => $t->name,
        'slug' => $t->slug
      ];
    }, $terms));

    // Estructura final del proyecto, alineada con tu frontend Vue
    $items[] = [
      'id'    => $id,
      // Mantengo el shape "title.rendered" para que no tengas que tocar el JS
      'title' => ['rendered' => $title],
      'link'  => $link,
      'image' => $src ?: '',     // si no hay imagen, queda string vacío (tu frontend pone placeholder)
      'srcset'=> $srcset ?: '',  // idem
      'project_cat_links' => $cats,
    ];
  }
  wp_reset_postdata();

  // ---- 5) Traer todas las categorías para la barra de filtros -------------

  $all_terms = get_terms([
    'taxonomy'   => 'project-cat',
    'hide_empty' => true, // solo categorías con posts
  ]);

  $cats = array_values(array_map(function($t){
    return [
      'id'   => (int) $t->term_id,
      'name' => $t->name,
      'slug' => $t->slug
    ];
  }, $all_terms ?: []));

  // ---- 6) Totales para paginación -----------------------------------------

  $total       = (int) $q->found_posts;    // total de posts encontrados
  $total_pages = (int) $q->max_num_pages;  // páginas totales

  // ---- 7) (Opcional) headers de cache público -----------------------------
  // Si usas WP REST Cache, ya tendrás caché en servidor. Estos headers habilitan
  // cache en CDN/navegador para respuestas idénticas.
  // header('Cache-Control: public, max-age=300, s-maxage=600');

  // ---- 8) Respuesta JSON ---------------------------------------------------

  return new WP_REST_Response([
    'projects'     => $items,       // array de proyectos formateados
    'categories'   => $cats,        // array de categorías (para filtros)
    'total'        => $total,       // total de resultados (para UI)
    'total_pages'  => $total_pages, // total de páginas (para UI)
  ], 200);
}
