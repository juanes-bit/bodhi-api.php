<?php
/**
 * Plugin Name: Bodhi API
 * Description: REST API para la app Bodhi (lee cursos de Thrive Apprentice o CPT propio) + WPML.
 * Version: 0.2.1
 * Author: JECT
 */
if ( ! defined('ABSPATH') ) exit;

define('BODHI_API_VERSION', '0.2.1');
define('BODHI_API_NS', 'bodhi/v1');

// === Forzar JSON en /bodhi/v1/courses aunque haya wp_die / fatales ===
add_filter('wp_die_handler', function () {
  return function ($message, $title = '', $args = []) {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (defined('REST_REQUEST') && REST_REQUEST && str_contains($uri, '/wp-json/bodhi/v1/courses')) {
      if (headers_sent() === false) {
        header('Content-Type: application/json; charset=utf-8', true, 500);
        nocache_headers();
      }
      echo wp_json_encode([
        'items'   => [],
        '__debug' => ['wp_die' => wp_strip_all_tags((string)$message), 'title' => (string)$title, 'args' => $args],
      ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
      exit;
    }
    _default_wp_die_handler($message, $title, $args);
  };
});

add_action('init', function(){ if (defined('REST_REQUEST') && REST_REQUEST) @ini_set('display_errors','0'); });

//  Solo REST + staging + rutas Thrive; evita TypeError y 403 de Thrive
add_filter('user_has_cap', function($allcaps, $caps, $args, $user){
  if (!defined('REST_REQUEST') || !REST_REQUEST) return $allcaps;

  $host = parse_url(home_url(), PHP_URL_HOST);
  if ($host === null || strpos($host, 'staging.') === false) return $allcaps;

  $uri      = $_SERVER['REQUEST_URI'] ?? '';
  $isThrive = str_contains($uri, '/wp-json/tva/v1/');
  $isForced = !empty($GLOBALS['bodhi_tva_force_cap_counter']);

  if ($isThrive || $isForced) {
    if (!is_array($allcaps)) $allcaps = [];
    $allcaps['read']           = true;
    $allcaps['edit_posts']     = true;
    $allcaps['manage_options'] = true; // staging: aseguramos pasar permission_callback
  }
  return $allcaps;
}, 10, 4);

// =============== Proxy REST con elevación controlada ===============
if (!isset($GLOBALS['bodhi_tva_force_cap_counter'])) $GLOBALS['bodhi_tva_force_cap_counter'] = 0;

function bodhi_rest_proxy_request(string $method, string $route, array $params = [], array $opts = []) {
  $impersonate = !empty($opts['impersonate_admin']);
  if ($impersonate) {
    $host = parse_url(home_url(), PHP_URL_HOST);
    if ($host === null || strpos($host, 'staging.') === false) {
      $impersonate = false;
    }
  }
  $prev        = wp_get_current_user(); $prev_id = $prev ? intval($prev->ID) : 0;

  $GLOBALS['bodhi_tva_force_cap_counter']++;

  if ($impersonate) {
    $admin_ids = get_users(['role'=>'administrator','number'=>1,'fields'=>'ID']);
    if (!empty($admin_ids)) wp_set_current_user(intval($admin_ids[0]));
  }

  try {
    $req = new WP_REST_Request($method, $route);
    foreach ($params as $k=>$v) $req->set_param($k,$v);
    $res = rest_do_request($req);

    if ($res instanceof WP_REST_Response) return ['ok'=>true,'status'=>$res->get_status(),'data'=>$res->get_data()];
    if (is_wp_error($res))             return ['ok'=>false,'status'=>500,'data'=>$res->get_error_message()];
    return ['ok'=>false,'status'=>500,'data'=>null];

  } catch (Throwable $e) {
    error_log('[bodhi_proxy] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    return ['ok'=>false,'status'=>500,'data'=>'proxy_exception: '.$e->getMessage()];
  } finally {
    if ($prev_id) wp_set_current_user($prev_id);
    $GLOBALS['bodhi_tva_force_cap_counter'] = max(0, (int)$GLOBALS['bodhi_tva_force_cap_counter'] - 1);
  }
}

function bodhi_path($item, string $path) {
  $cur = $item;
  foreach (explode('.', $path) as $seg) {
    if (is_array($cur) && array_key_exists($seg, $cur)) {
      $cur = $cur[$seg];
      continue;
    }
    if (is_object($cur)) {
      if (isset($cur->$seg) || property_exists($cur, $seg)) {
        $cur = $cur->$seg;
        continue;
      }
      if (method_exists($cur, $seg)) {
        $cur = $cur->$seg();
        continue;
      }
      $getter = 'get_'.$seg;
      if (method_exists($cur, $getter)) {
        $cur = $cur->$getter();
        continue;
      }
    }
    return null;
  }
  return $cur;
}

function bodhi_first($item, array $paths) {
  foreach ($paths as $p) {
    $v = bodhi_path($item, $p);
    if ($v !== null && $v !== '' && $v !== []) return $v;
  }
  return null;
}

/**
 * Detecta el post_type de cursos de Thrive (si existe) DESPUÉS de init.
 * Fallback a CPT propio "course".
 */
function bodhi_get_course_pt() {
  if ( post_type_exists('tva_course') )  return 'tva_course';
  if ( post_type_exists('tva_courses') ) return 'tva_courses';
  return 'course';
}

/**
 * Helper de acceso por usuario (MVP):
 * - Curso freemium (tva_freemium=1) => visible para todos.
 * - Si no es freemium: requiere que el ID esté en user_meta tc_access_courses (CSV o JSON).
 */
function bodhi_user_can_access_course($user_id, $course_id) {
  $is_freemium = (int) get_post_meta($course_id, 'tva_freemium', true) === 1;
  if ($is_freemium) return true;
  if (!$user_id) return false;
  $raw = get_user_meta($user_id, 'tc_access_courses', true);
  if (!$raw) return false;
  $arr = (is_string($raw) && strlen($raw) && $raw[0] === '[') ? json_decode($raw, true) : explode(',', (string)$raw);
  $arr = array_filter(array_map('intval', (array)$arr));
  return in_array((int)$course_id, $arr, true);
}

/** Registrar CPT propio solo si NO está Thrive */
add_action('init', function () {
  // Registrar CPT propio solo si Thrive no registró los suyos
  if ( bodhi_get_course_pt() !== 'course' ) return;
  register_post_type('course', [
    'labels'=>['name'=>'Courses','singular_name'=>'Course','menu_name'=>'Courses'],
    'public'=>true,'show_in_rest'=>true,
    'supports'=>['title','editor','thumbnail','excerpt','revisions'],
    'has_archive'=>true,'menu_icon'=>'dashicons-welcome-learn-more',
    'rewrite'=>['slug'=>'courses'],
  ]);
}, 20); // aseguramos que Thrive registre primero (si existe)
register_activation_hook(__FILE__, function(){ flush_rewrite_rules(); });
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });

add_action('rest_api_init', function () {

  // === LESSONS NORMALIZADAS ===
  register_rest_route(BODHI_API_NS, '/lessons', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $course_id = (int) $req->get_param('course_id');
      if (!$course_id) {
        return new WP_Error('bad_request', 'course_id requerido', ['status' => 400]);
      }

      // TODO: cambiar el origen por Thrive o CPT real según setup
      $lessons = get_posts([
        'post_type'   => 'lesson',
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [[
          'key'     => 'course_id',
          'value'   => $course_id,
          'compare' => '=',
        ]],
        'orderby'     => 'menu_order',
        'order'       => 'ASC',
      ]);

      $out = [];
      foreach ($lessons as $p) {
        $video  = get_post_meta($p->ID, 'video_url', true);
        $poster = get_post_meta($p->ID, 'video_poster', true);
        $blocks = [];

        $blocks[] = ['type' => 'heading', 'text' => get_the_title($p)];
        if (!empty($p->post_excerpt)) {
          $blocks[] = ['type' => 'paragraph', 'text' => wp_strip_all_tags($p->post_excerpt)];
        }

        $thumb = get_the_post_thumbnail_url($p->ID, 'full');
        if ($thumb) {
          $blocks[] = ['type' => 'image', 'url' => $thumb, 'alt' => get_the_title($p)];
        }

        if ($video) {
          $blocks[] = ['type' => 'video', 'url' => $video, 'poster' => $poster];
        }

        $out[] = [
          'id'     => $p->ID,
          'title'  => get_the_title($p),
          'blocks' => $blocks,
        ];
      }

      return rest_ensure_response($out);
    }
  ]);

  if (!function_exists('bodhi_token_key')) {
    function bodhi_token_key($token){ return 'bodhi_token_' . sanitize_key($token); }
  }

  register_rest_route(BODHI_API_NS, '/auth/token', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $username = (string)$req->get_param('username');
      $password = (string)$req->get_param('password');
      if (!$username || !$password) {
        return new WP_REST_Response(['error'=>'missing_credentials'], 400);
      }
      $user = wp_authenticate($username, $password);
      if (is_wp_error($user)) {
        return new WP_REST_Response(['error'=>'invalid_credentials','message'=>$user->get_error_message()], 401);
      }

      // === crear sesión WP + EMITIR COOKIES explícitamente ===
      wp_set_current_user( $user->ID );

      $domain = 'staging.bodhimedicine.com';
      $path   = '/';
      $secure = is_ssl();

      $session_token = wp_get_session_token();
      $expiration    = time() + 14 * DAY_IN_SECONDS;

      $logged_in_cookie_name  = 'wordpress_logged_in_' . COOKIEHASH;
      $logged_in_cookie_value = wp_generate_auth_cookie( $user->ID, $expiration, 'logged_in', $session_token );

      setcookie( $logged_in_cookie_name, $logged_in_cookie_value, [
        'expires'  => $expiration,
        'path'     => $path,
        'domain'   => $domain,
        'secure'   => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
      ]);
      if ( defined('SITECOOKIEPATH') ) {
        setcookie( $logged_in_cookie_name, $logged_in_cookie_value, [
          'expires'  => $expiration,
          'path'     => defined('SITECOOKIEPATH') ? SITECOOKIEPATH : $path,
          'domain'   => $domain,
          'secure'   => $secure,
          'httponly' => false,
          'samesite' => 'Lax',
        ]);
      }

      $auth_cookie_name  = ( $secure ? 'wordpress_sec_' : 'wordpress_' ) . COOKIEHASH;
      $auth_cookie_value = wp_generate_auth_cookie( $user->ID, $expiration, 'secure_auth', $session_token );
      setcookie( $auth_cookie_name, $auth_cookie_value, [
        'expires'  => $expiration,
        'path'     => defined('ADMIN_COOKIE_PATH') ? ADMIN_COOKIE_PATH : $path,
        'domain'   => $domain,
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
      ]);

      $logged_in_status_name = defined('LOGGED_IN_COOKIE') ? LOGGED_IN_COOKIE : $logged_in_cookie_name;
      setcookie( $logged_in_status_name, $logged_in_cookie_value, [
        'expires'  => $expiration,
        'path'     => $path,
        'domain'   => $domain,
        'secure'   => $secure,
        'httponly' => false,
        'samesite' => 'Lax',
      ]);

      wp_set_auth_cookie( $user->ID, true, $secure );

      $nonce = wp_create_nonce('wp_rest');

      $token = wp_generate_password(40, false, false);
      set_transient(bodhi_token_key($token), $user->ID, 2 * HOUR_IN_SECONDS);
      return new WP_REST_Response([
        'ok'    => true,
        'token' => $token,
        'user'  => ['id'=>$user->ID,'name'=>$user->display_name,'roles'=>$user->roles],
        'nonce' => $nonce,
      ], 200);
    }
  ]);

  register_rest_route(BODHI_API_NS, '/auth/token-logout', [
    'methods'  => 'POST',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $token = (string)$req->get_param('token');
      if ($token) delete_transient(bodhi_token_key($token));
      return new WP_REST_Response(['ok'=>true], 200);
    }
  ]);

  // ========== DEBUGS (para ver el payload real de Thrive) ==========
  register_rest_route(BODHI_API_NS, '/debug/products', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function(){
      $uid = get_current_user_id();
      $res = bodhi_rest_proxy_request('GET', '/tva/v1/customer/'.$uid.'/products', [], ['impersonate_admin' => true]);
      return $res['ok'] ? $res['data'] : $res;
    }
  ]);

  register_rest_route(BODHI_API_NS, '/debug/prod/(?P<pid>\d+)/courses', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function(WP_REST_Request $req){
      $pid = (int)$req->get_param('pid');
      $res = bodhi_rest_proxy_request('GET', '/tva/v1/products/'.$pid.'/courses', [
        'per_page' => 100,
        'context'  => 'edit',
      ], ['impersonate_admin' => true]);
      return $res['ok'] ? $res['data'] : $res;
    }
  ]);

  register_rest_route(BODHI_API_NS, '/debug/prod/(?P<pid>\d+)/sets', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function(WP_REST_Request $req){
      $pid = (int)$req->get_param('pid');
      $res = bodhi_rest_proxy_request('GET', '/tva/v1/products/'.$pid.'/sets', [
        'per_page' => 100,
        'context'  => 'edit',
      ], ['impersonate_admin' => true]);
      return $res['ok'] ? $res['data'] : $res;
    }
  ]);

  // /bodhi/v1/courses — usa cookie+nonce y reporta __debug de Thrive
  register_rest_route('bodhi/v1', '/courses', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {

      $__had_ob = ob_get_level() > 0;
      $noise = null;
      if (!$__had_ob) ob_start();

      try {
        if (!is_user_logged_in()) {
          throw new Exception('unauthorized', 401);
        }
        $uid   = get_current_user_id();
        $debugAllowed = ((string)$req->get_param('debug') === '1') && current_user_can('manage_options');
        $debug = $debugAllowed ? [] : null;
        $cacheKey = 'bodhi:courses:'.$uid;

        if (!$debugAllowed) {
          $cachedItems = get_transient($cacheKey);
          if (is_array($cachedItems)) {
            if (!$__had_ob) ob_end_clean();
            return new WP_REST_Response(['items'=>$cachedItems], 200);
          }
        }
        $out   = [];
        $seen  = [];

        $p = bodhi_rest_proxy_request('GET', '/tva/v1/customer/'.$uid.'/products', [], ['impersonate_admin'=>true]);
        if ($debugAllowed) $debug['customer_products_status'] = $p['status'];
        if (!$p['ok']) {
          $payload = ['items'=>[]];
          if ($debugAllowed) $payload['__debug'] = $debug;
          if ($debugAllowed && !$__had_ob) {
            $noise = ob_get_clean();
            if (is_string($noise) && strlen(trim($noise)) > 0) $payload['__noise'] = $noise;
          } elseif (!$__had_ob) {
            ob_end_clean();
          }
          return new WP_REST_Response($payload, 200);
        }

        $products = $p['data'];
        if (is_object($products)) {
          if (isset($products->items)) {
            $products = $products->items;
          } else {
            $products = [$products];
          }
        } elseif (is_array($products) && isset($products['items']) && is_array($products['items'])) {
          $products = $products['items'];
        } elseif (!is_array($products)) {
          $products = (array)$products;
        }
        if ($debugAllowed && (string)$req->get_param('debug') === '1') {
          $debug['customer_products_sample'] = $products;
        }

        $ids = [];
        $stack = [$products];
        while ($stack) {
          $node = array_pop($stack);
          if (!is_array($node)) continue;
          $assoc = array_keys($node) !== range(0, count($node)-1);
          if ($assoc) {
            foreach (['product_id','tva_product_id','ID','id','post_id'] as $k) {
              if (isset($node[$k]) && is_numeric($node[$k])) $ids[(int)$node[$k]] = true;
            }
            foreach ($node as $v) if (is_array($v)) $stack[] = $v;
          } else {
            foreach ($node as $v) if (is_array($v)) $stack[] = $v;
          }
        }
        $product_ids = array_keys($ids);
        $debug['product_ids_found'] = $product_ids;

        foreach ($product_ids as $pid) {
          $pc = bodhi_rest_proxy_request('GET', '/tva/v1/products/'.$pid.'/courses',
            ['per_page'=>100,'context'=>'edit'],
            ['impersonate_admin'=>true]
          );
          if ($debugAllowed) $debug['p_'.$pid.'_courses_status'] = $pc['status'];

          if ($pc['ok'] && !empty($pc['data']) && is_iterable($pc['data'])) {
            foreach ($pc['data'] as $ci) {
              $cid = (int)(bodhi_first($ci, ['course_id','ID','id','get_id','get_ID']) ?? 0);
              if ($cid <= 0 || isset($seen[$cid])) continue;

              $title = (string)(bodhi_first($ci, [
                'title.rendered','title','name','post_title','get_title','get_name'
              ]) ?? '');
              $slug = (string)(bodhi_first($ci, [
                'slug','post_name','identifier','get_slug','get_identifier'
              ]) ?? '');
              $thumb = (string)(bodhi_first($ci, [
                'featured_image','thumb','get_featured_image','get_thumb'
              ]) ?? '');

              if ($cid && ($title === '' || $slug === '')) {
                if ($p_obj = get_post($cid)) {
                  if ($title === '') $title = (string)$p_obj->post_title;
                  if ($slug === '') $slug = (string)$p_obj->post_name;
                }
              }
              if ($title === '') $title = 'Curso #'.$cid;

              $out[] = [
                'id'=>$cid,'title'=>$title,'slug'=>$slug,'thumb'=>$thumb ?: null,
                'type'=>'tva_course','access'=>'owned',
                'source'=>['product_id'=>$pid,'route'=>'/tva/v1/products/'.$pid.'/courses']
              ];
              $seen[$cid] = true;
            }
            continue;
          }

          $ps = bodhi_rest_proxy_request('GET', '/tva/v1/products/'.$pid.'/sets',
            ['per_page'=>100,'context'=>'edit'],
            ['impersonate_admin'=>true]
          );
          if ($debugAllowed) $debug['p_'.$pid.'_sets_status'] = $ps['status'];

          if ($ps['ok'] && !empty($ps['data']) && is_iterable($ps['data'])) {
            foreach ($ps['data'] as $s) {
              $sid = (int)(bodhi_first($s, ['ID','id','get_id','get_ID']) ?? 0);
              if ($sid <= 0 || isset($seen[$sid])) continue;

              $title = (string)(bodhi_first($s, [
                'title.rendered','title','name','post_title','get_title','get_name'
              ]) ?? ('Content Set #'.$sid));

              $out[] = [
                'id'=>$sid,'title'=>$title,'slug'=>'content-set-'.$sid,'thumb'=>null,
                'type'=>'tva_content_set','access'=>'owned',
                'source'=>['product_id'=>$pid,'route'=>'/tva/v1/products/'.$pid.'/sets']
              ];
              $seen[$sid] = true;
            }
          }
        }

        usort($out, fn($a,$b)=>strcasecmp($a['title'],$b['title']));

        if (!$debugAllowed) {
          set_transient($cacheKey, $out, 3 * MINUTE_IN_SECONDS);
        }

        $payload = ['items'=>$out];
        if ($debugAllowed) {
          $payload['__debug'] = $debug;
        }
        if (!$__had_ob) {
          $noise = ob_get_clean();
          if ($debugAllowed && is_string($noise) && strlen(trim($noise)) > 0) $payload['__noise'] = $noise;
        }
        return new WP_REST_Response($payload, 200);

      } catch (Throwable $e) {
        if (!$__had_ob) {
          $noise = ob_get_clean();
        }
        $payload = ['items' => []];
        if ($debugAllowed) {
          $payload['__debug'] = [
            'exception' => $e->getMessage(),
            'code'      => $e->getCode(),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
          ];
          if (!empty($noise)) $payload['__noise'] = $noise;
        }
        return new WP_REST_Response($payload, $e->getCode() ?: 500);
      }
    }
  ]);

  // === PERFIL / ACCESOS (JWT) =============================================
  // === ÍNDICE DEL CURSO (outline) =========================================
  register_rest_route(BODHI_API_NS, '/outline', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function(WP_REST_Request $req){
      $course_id = (int)$req->get_param('course_id');
      if (!$course_id) return new WP_Error('bad_request','course_id requerido',['status'=>400]);

      $module_type = post_type_exists('tva_module') ? 'tva_module' : 'tva_lesson';
      $lesson_type = post_type_exists('tva_lesson') ? 'tva_lesson' : 'lesson';

      $modules = get_posts([
        'post_type'   => $module_type,
        'post_status' => 'publish',
        'numberposts' => -1,
        'meta_query'  => [['key'=>'course_id','value'=>$course_id,'compare'=>'=']],
        'orderby'     => 'menu_order',
        'order'       => 'ASC'
      ]);

      $out = ['course_id'=>$course_id,'modules'=>[]];
      foreach ($modules as $m) {
        $lessons = get_posts([
          'post_type'   => $lesson_type,
          'post_status' => 'publish',
          'numberposts' => -1,
          'meta_query'  => [['key'=>'module_id','value'=>$m->ID,'compare'=>'=']],
          'orderby'     => 'menu_order',
          'order'       => 'ASC'
        ]);

        $out['modules'][] = [
          'id'    => $m->ID,
          'title' => get_the_title($m),
          'lessons' => array_map(function($l){
            return ['id'=>$l->ID,'title'=>get_the_title($l)];
          }, $lessons)
        ];
      }

      return rest_ensure_response($out);
    }
  ]);

  // DEBUG SOLO STAGING: inspeccionar acceso real de Thrive para el usuario logueado
  register_rest_route(BODHI_API_NS, '/_debug-thrive', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      try {
        $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '');
        if ( stripos($host, 'staging') === false ) {
          return new WP_Error('forbidden','Solo en staging (host detectado: '.$host.')',['status'=>403]);
        }

        $uid = get_current_user_id();
        $out = [
          'user_id' => $uid,
          'classes' => [
            'TVA\\Access\\Manager' => class_exists('\\TVA\\Access\\Manager'),
            'TVA\\Product'         => class_exists('\\TVA\\Product'),
          ],
          'methods' => [
            'AccessManager.has_access' => (class_exists('\\TVA\\Access\\Manager') && method_exists('\\TVA\\Access\\Manager','has_access')),
            'Product.get_for_user'     => (class_exists('\\TVA\\Product') && method_exists('\\TVA\\Product','get_for_user')),
            'Product.get_for_post'     => (class_exists('\\TVA\\Product') && method_exists('\\TVA\\Product','get_for_post')),
          ],
          'user_products' => [],
          'courses_probe' => [],
        ];

        if ($out['methods']['Product.get_for_user']) {
          try {
            $prods = \TVA\Product::get_for_user($uid);
            foreach ((array)$prods as $p) {
              $items = [];
              if (is_object($p)) {
                if (method_exists($p,'get_items')) {
                  foreach ((array)$p->get_items() as $it) {
                    $items[] = [
                      'type'    => is_object($it) ? ($it->type ?? null)     : ($it['type'] ?? null),
                      'id'      => (int)(is_object($it) ? ($it->id ?? 0)     : ($it['id'] ?? 0)),
                      'post_id' => (int)(is_object($it) ? ($it->post_id ?? 0): ($it['post_id'] ?? 0)),
                    ];
                  }
                } elseif (isset($p->items)) {
                  foreach ((array)$p->items as $it) {
                    $items[] = [
                      'type'    => is_object($it) ? ($it->type ?? null)     : ($it['type'] ?? null),
                      'id'      => (int)(is_object($it) ? ($it->id ?? 0)     : ($it['id'] ?? 0)),
                      'post_id' => (int)(is_object($it) ? ($it->post_id ?? 0): ($it['post_id'] ?? 0)),
                    ];
                  }
                }
              }
              $out['user_products'][] = [
                'id'    => (int)($p->ID ?? 0),
                'title' => (string)($p->name ?? $p->post_title ?? '(no title)'),
                'items' => $items,
                'has_access_method' => (is_object($p) && method_exists($p,'has_access')),
                'user_has_access_method' => (is_object($p) && method_exists($p,'user_has_access')),
              ];
            }
          } catch (\Throwable $e) {
            $out['user_products_error'] = $e->getMessage();
          }
        }

        $pts = [];
        if (post_type_exists('tva_course'))   $pts[]='tva_course';
        if (post_type_exists('bodhi_course')) $pts[]='bodhi_course';
        if (!$pts) $pts=['tva_course'];

        $courses = get_posts([
          'post_type'   => $pts,
          'post_status' => 'publish',
          'numberposts' => -1,
          'orderby'     => 'menu_order',
          'order'       => 'ASC',
        ]);

        foreach ($courses as $c) {
          $probe = [
            'id'    => $c->ID,
            'title' => get_the_title($c),
            'slug'  => $c->post_name,
            'freemium' => ((string)get_post_meta($c->ID,'tva_freemium',true) === '1'),
            'access' => [
              'via_access_manager' => null,
              'via_products_including_course' => [],
            ],
          ];

          if ($out['methods']['AccessManager.has_access']) {
            try {
              $probe['access']['via_access_manager'] = (bool)\TVA\Access\Manager::has_access($uid,(int)$c->ID);
            } catch (\Throwable $e) {
              $probe['access']['via_access_manager'] = 'error: '.$e->getMessage();
            }
          }

          if ($out['methods']['Product.get_for_post']) {
            try {
              $pps = \TVA\Product::get_for_post((int)$c->ID);
              foreach ((array)$pps as $pp) {
                $can = null;
                if (is_object($pp)) {
                  if (method_exists($pp,'has_access')) {
                    $can = (bool)$pp->has_access($uid);
                  }
                  if ($can === null && method_exists($pp,'user_has_access')) {
                    $can = (bool)$pp->user_has_access($uid);
                  }
                }
                $probe['access']['via_products_including_course'][] = [
                  'product_title' => (string)($pp->name ?? $pp->post_title ?? '(no title)'),
                  'grants_access' => $can,
                ];
              }
            } catch (\Throwable $e) {
              $probe['access']['via_products_error'] = $e->getMessage();
            }
          }

          $out['courses_probe'][] = $probe;
        }

        return rest_ensure_response($out);

      } catch (\Throwable $e) {
        return new WP_REST_Response(['debug_error'=>$e->getMessage()], 200);
      }
    }
  ]);

  // === DETALLE (con fallback si no existe traducción) ===
  register_rest_route(BODHI_API_NS, '/courses/(?P<id>\\d+)', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $pt   = bodhi_get_course_pt();
      $id   = (int)$req['id'];
      $lang = $req->get_param('lang');

      if ($lang && defined('ICL_SITEPRESS_VERSION')) {
        do_action('wpml_switch_language', $lang);
        $mapped = apply_filters('wpml_object_id', $id, $pt, true, $lang); // true => fallback a default
        if (!empty($mapped)) $id = (int)$mapped;
      }

      $post = get_post($id);
      if (!$post || get_post_type($post) !== $pt) {
        return new WP_Error('not_found', 'Course not found', ['status'=>404]);
      }

      // Protección de acceso (MVP)
      $uid = get_current_user_id();
      $allowed = bodhi_user_can_access_course($uid, $id);
      if (!$allowed) {
        return new WP_Error('forbidden', 'No tienes acceso a este curso', ['status'=>403]);
      }

      $cover = get_the_post_thumbnail_url($id, 'large') ?: null;

      /** —— LECCIONES: Thrive primero, ACF como fallback —— */
      $lessons = [];

      // 1) Si existe Thrive, localizar módulos por meta (tva_course_id/thrive_content_set/…)
      if ($pt !== 'course') {
        $candidate_keys = ['tva_course_id','tva_course','thrive_content_set','_thrive_content_set','course_id'];
        $modules = [];
        foreach ($candidate_keys as $mk) {
          $mq = new WP_Query([
            'post_type'      => 'tva_module',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'meta_query'     => [[ 'key'=>$mk, 'value'=>$id, 'compare'=>'=' ]],
            'orderby'        => 'menu_order',
            'order'          => 'ASC',
          ]);
          if ($mq->have_posts()) { $modules = $mq->posts; break; }
        }
        // fallback: jerarquía por parent (algunas setups la usan)
        if (empty($modules)) {
          $modules = get_children([
            'post_parent' => $id,
            'post_type'   => 'tva_module',
            'post_status' => 'publish',
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
          ]);
        }

        foreach ($modules as $mod) {
          $mod_id = is_object($mod) ? $mod->ID : (int)$mod;
          $ls_q = get_children([
            'post_parent' => $mod_id,
            'post_type'   => 'tva_lesson',
            'post_status' => 'publish',
            'orderby'     => 'menu_order',
            'order'       => 'ASC',
          ]);
          foreach ($ls_q as $l) {
            $lid = $l->ID;
            // URL candidata: tva_video > url > video_url > ACF url/video_url > permalink
            $url = get_post_meta($lid, 'tva_video', true)
                ?: get_post_meta($lid, 'url', true)
                ?: get_post_meta($lid, 'video_url', true)
                ?: (function_exists('get_field') ? (get_field('url', $lid) ?: get_field('video_url', $lid)) : '')
                ?: get_permalink($lid);
            // Tipo por meta o heurística
            $type = get_post_meta($lid, 'tva_lesson_type', true);
            if (!$type) {
              $type = (preg_match('~(youtube|vimeo|\.mp4)~i', $url) ? 'video'
                    : (preg_match('~/(form|formularios?)\\/?~i', $url) ? 'form' : 'article'));
            }
            $lessons[] = [
              'title'  => get_the_title($lid),
              'type'   => $type,
              'url'    => $url,
              'schema' => null,
            ];
          }
        }
      }

      // 2) Fallback ACF en el curso si no se encontraron lecciones en Thrive
      if (empty($lessons) && function_exists('get_field')) {
        $acf_lessons = get_field('lessons', $id);
        if ($acf_lessons && is_array($acf_lessons)) {
          foreach ($acf_lessons as $ls) {
            $lessons[] = [
              'title'  => isset($ls['title']) ? $ls['title'] : '',
              'type'   => isset($ls['type']) ? $ls['type'] : 'article',
              'url'    => isset($ls['url']) ? $ls['url'] : '',
              'schema' => (!empty($ls['schema']) && is_string($ls['schema']))
                ? json_decode($ls['schema'], true)
                : null,
            ];
          }
        }
      }

      // 3) Portada desde ACF (si existe)
      $cover_acf = function_exists('get_field') ? get_field('cover_image', $id) : null;
      if ($cover_acf && is_array($cover_acf) && !empty($cover_acf['url'])) {
        $cover = $cover_acf['url'];
      }

      return rest_ensure_response([
        'id'      => $id,
        'title'   => get_the_title($id),
        'cover'   => $cover,
        'link'    => get_permalink($id),
        'lessons' => $lessons,
      ]);
    }
  ]);

  // === NEWS ===
  register_rest_route(BODHI_API_NS, '/news', [
    'methods' => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      $lang = $req->get_param('lang');
      $args = [
        'post_type'=>'post','posts_per_page'=>10,'post_status'=>'publish',
        'orderby'=>'date','order'=>'DESC'
      ];
      if ($lang && defined('ICL_SITEPRESS_VERSION')) {
        do_action('wpml_switch_language', $lang);
        $args['suppress_filters'] = false;
      } elseif ($lang && function_exists('pll_the_languages')) {
        $args['lang'] = $lang;
      }
      $q = new WP_Query($args); $items=[];
      while ($q->have_posts()) { $q->the_post();
        $items[] = [
          'id'=>get_the_ID(),'title'=>get_the_title(),
          'excerpt'=>wp_strip_all_tags(get_the_excerpt()),
          'date'=>get_the_date('c'),'link'=>get_permalink(),
        ];
      }
      wp_reset_postdata();
      return rest_ensure_response($items);
    }
  ]);
});

add_action('rest_api_init', function () {
  // === PROGRESS ===
  register_rest_route(BODHI_API_NS, '/progress', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $req) {
      $uid = get_current_user_id();
      $course_id = (int) $req->get_param('course_id');
      if (!$course_id) {
        return new WP_Error('bad_request', 'course_id requerido', ['status' => 400]);
      }

      $key = "bodhi_progress_{$course_id}";
      $data = get_user_meta($uid, $key, true);
      return rest_ensure_response(is_array($data) ? $data : []);
    }
  ]);

  register_rest_route(BODHI_API_NS, '/progress', [
    'methods'  => 'POST',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $req) {
      $uid = get_current_user_id();
      $course_id = (int) $req->get_param('course_id');
      $lesson_id = (int) $req->get_param('lesson_id');
      $done = filter_var($req->get_param('done'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
      if (!$course_id || !$lesson_id || $done === null) {
        return new WP_Error('bad_request', 'course_id, lesson_id, done requeridos', ['status' => 400]);
      }

      $key = "bodhi_progress_{$course_id}";
      $data = get_user_meta($uid, $key, true);
      $data = is_array($data) ? $data : [];
      $data[$lesson_id] = (bool) $done;
      update_user_meta($uid, $key, $data);

      $total = max(1, (int) $req->get_param('total_lessons'));
      $done_count = count(array_filter($data));
      $pct = min(100, round(($done_count / $total) * 100));

      return rest_ensure_response([
        'course_id'   => $course_id,
        'progress'    => $data,
        'done_count'  => $done_count,
        'pct'         => $pct,
      ]);
    }
  ]);
});

/** ACF local solo si usamos CPT propio "course" */
add_action('acf/init', function(){
  if ( bodhi_get_course_pt() !== 'course' ) return;
  if( function_exists('acf_add_local_field_group') ) {
    acf_add_local_field_group([
      'key'=>'group_bodhi_course','title'=>'Bodhi: Course',
      'fields'=>[
        ['key'=>'field_cover_image','label'=>'Cover Image','name'=>'cover_image','type'=>'image','return_format'=>'array','preview_size'=>'medium','library'=>'all'],
        ['key'=>'field_lessons','label'=>'Lessons','name'=>'lessons','type'=>'repeater','layout'=>'row','button_label'=>'Add Lesson',
          'sub_fields'=>[
            ['key'=>'field_lesson_title','label'=>'Title','name'=>'title','type'=>'text'],
            ['key'=>'field_lesson_type','label'=>'Type','name'=>'type','type'=>'select','choices'=>['video'=>'Video','form'=>'Form','article'=>'Article'],'ui'=>1],
            ['key'=>'field_lesson_url','label'=>'URL','name'=>'url','type'=>'url'],
            ['key'=>'field_lesson_schema','label'=>'Schema (JSON)','name'=>'schema','type'=>'textarea','instructions'=>'E.g. {"mood":"1-5","notes":"text"}']
          ]
        ],
      ],
      'location'=>[[['param'=>'post_type','operator'=>'==','value'=>'course']]]
    ]);
  }
});

/** ===================== DEBUG: THRIVE INTROSPECT (quitar en prod) ===================== */
add_action('rest_api_init', function () {
  register_rest_route(BODHI_API_NS, '/thrive-introspect', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function (WP_REST_Request $req) {
      global $wpdb;
      // post types Thrive detectados
      $available = get_post_types([], 'names');
      $pts = array_values(array_intersect(
        ['tva_course','tva_courses','tva_module','tva_lesson','tva_chapter'],
        $available
      ));
      // meta keys por PT (muestra hasta 200)
      $meta_keys = [];
      foreach ($pts as $ptx) {
        $meta_keys[$ptx] = $wpdb->get_col($wpdb->prepare("
          SELECT DISTINCT pm.meta_key
          FROM {$wpdb->postmeta} pm
          JOIN {$wpdb->posts} p ON p.ID = pm.post_id
          WHERE p.post_type = %s
          LIMIT 200
        ", $ptx));
      }
      // árbol módulos/lecciones para un curso concreto
      $course_id = intval($req->get_param('course_id'));
      $tree = null;
      if ($course_id) {
        $candidate_keys = ['tva_course_id','tva_course','thrive_content_set','_thrive_content_set','course_id'];
        $modules = [];
        foreach ($candidate_keys as $mk) {
          $mq = new WP_Query([
            'post_type'=>'tva_module','post_status'=>'publish','posts_per_page'=>-1,
            'meta_query'=>[[ 'key'=>$mk,'value'=>$course_id,'compare'=>'=' ]],
            'orderby'=>'menu_order','order'=>'ASC'
          ]);
          if ($mq->have_posts()) { $modules = $mq->posts; break; }
        }
        if (empty($modules)) {
          $ids = $wpdb->get_col($wpdb->prepare("
            SELECT p.ID
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
            WHERE p.post_type='tva_module'
              AND p.post_status='publish'
              AND pm.meta_value = %d
              AND (pm.meta_key LIKE '%%course%%' OR pm.meta_key LIKE '%%set%%')
          ", $course_id));
          if ($ids) {
            $modules = get_posts([
              'post_type'=>'tva_module','post__in'=>$ids,'posts_per_page'=>-1,
              'orderby'=>'menu_order','order'=>'ASC'
            ]);
          }
        }
        $mods_out = [];
        foreach ($modules as $m) {
          $lessons = get_posts([
            'post_type'=>'tva_lesson','posts_per_page'=>-1,'post_status'=>'publish',
            'orderby'=>'menu_order','order'=>'ASC','post_parent'=>$m->ID,
          ]);
          $mods_out[] = [
            'id'=>$m->ID,'title'=>get_the_title($m->ID),
            'meta_keys_sample'=>array_slice(array_keys(get_post_meta($m->ID)),0,8),
            'lessons'=>array_map(function($l){
              $lid=$l->ID;
              $url = get_post_meta($lid,'tva_video',true)
                  ?: get_post_meta($lid,'url',true)
                  ?: get_post_meta($lid,'video_url',true)
                  ?: (function_exists('get_field') ? (get_field('url',$lid) ?: get_field('video_url',$lid)) : '')
                  ?: get_permalink($lid);
              $type = get_post_meta($lid,'tva_lesson_type',true) ?: (
                preg_match('~(youtube|vimeo|\.mp4)~i',$url) ? 'video' :
                (preg_match('~/(form|formularios?)\/?~i',$url) ? 'form' : 'article')
              );
              return [
                'id'=>$lid,'title'=>get_the_title($lid),
                'type_guess'=>$type,'url_detected'=>$url,
                'meta_keys_sample'=>array_slice(array_keys(get_post_meta($lid)),0,6),
              ];
            }, $lessons),
          ];
        }
        $tree = ['course_id'=>$course_id,'modules'=>$mods_out];
      }
      return rest_ensure_response([
        'thrive_post_types_detected' => $pts,
        'meta_keys' => $meta_keys,
        'tree_for_course' => $tree,
      ]);
    }
  ]);
});
/** =================== /DEBUG: THRIVE INTROSPECT (quitar en prod) ===================== */


// === 1) LOGIN POR AJAX (EMITE COOKIES + DEVUELVE NONCE) ===
add_action('wp_ajax_nopriv_bodhi_login', 'bodhi_ajax_login');
function bodhi_ajax_login() {
  nocache_headers();

  $username = isset($_POST['username']) ? sanitize_text_field($_POST['username']) : '';
  $password = isset($_POST['password']) ? $_POST['password'] : '';

  if (!$username || !$password) {
    wp_send_json(['ok'=>false,'error'=>'missing_credentials'], 400);
  }

  if (is_user_logged_in()) { wp_logout(); }

  $user = wp_signon([
    'user_login'    => $username,
    'user_password' => $password,
    'remember'      => true,
  ], is_ssl());

  if (is_wp_error($user)) {
    wp_send_json(['ok'=>false, 'error'=>'invalid_credentials', 'message'=>$user->get_error_message()], 401);
  }

  wp_set_current_user($user->ID);
  wp_set_auth_cookie($user->ID, true, is_ssl());

  $nonce = wp_create_nonce('wp_rest');

  wp_send_json([
    'ok'   => true,
    'user' => ['id'=>$user->ID, 'name'=>$user->display_name, 'roles'=>$user->roles],
    'nonce'=> $nonce
  ], 200);
}

// === 2) /bodhi/v1/me — quién está logueado, valida por cookie ===
add_action('rest_api_init', function(){
  register_rest_route('bodhi/v1', '/me', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function(){
      $u = wp_get_current_user();
      if (!$u || 0 === $u->ID) return ['logged_in'=>false];
      return ['logged_in'=>true, 'id'=>$u->ID, 'name'=>$u->display_name, 'roles'=>$u->roles];
    }
  ]);
});
