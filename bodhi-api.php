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

// --- Bridge REST para la app móvil (carga no intrusiva) ---
add_action('plugins_loaded', function () {
  $bridge = __DIR__ . '/inc/rest-bridge.php';
  if (file_exists($bridge)) { require_once $bridge; } // registra /bm/v1/*
}, 1);
// ----------------------------------------------------------

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

function bodhi_parse_vimeo($url){
  if (!is_string($url) || $url === '') {
    return null;
  }
  if (preg_match('#player\.vimeo\.com/video/(\d+)(?:.*[?&]h=([A-Za-z0-9]+))?#', $url, $m)) {
    return ['id' => $m[1], 'h' => $m[2] ?? null];
  }
  if (preg_match('#vimeo\.com/(?:video/)?(\d+)(?:/([A-Za-z0-9]+))?#', $url, $m)) {
    return ['id' => $m[1], 'h' => $m[2] ?? null];
  }
  return null;
}

// Devuelve un ID entero desde múltiples shapes posibles
function bodhi_course_id_from($c) {
  if (is_array($c)) {
    foreach (['id','ID','course_id','post_id'] as $k) {
      if (!empty($c[$k])) return intval($c[$k]);
    }
    if (!empty($c['post']['ID']))   return intval($c['post']['ID']);
    if (!empty($c['course']['id'])) return intval($c['course']['id']);
    if (!empty($c['course']['ID'])) return intval($c['course']['ID']);
  }
  return 0;
}

// Devuelve un título “humano”
function bodhi_course_title_from($c) {
  if (is_array($c)) {
    foreach (['title','name','post_title'] as $k) {
      if (!empty($c[$k])) return (string)$c[$k];
    }
    if (!empty($c['post']['post_title']))  return (string)$c['post']['post_title'];
    if (!empty($c['course']['title']))     return (string)$c['course']['title'];
    if (!empty($c['course']['name']))      return (string)$c['course']['name'];
  }
  return 'Course';
}

// “Desempaqueta” respuestas { items: [...] } o { data: { items: [...] } }
function bodhi_unwrap_items($payload) {
  if (is_array($payload)) {
    if (isset($payload['items']) && is_array($payload['items'])) {
      return $payload['items'];
    }
    if (isset($payload['data']['items']) && is_array($payload['data']['items'])) {
      return $payload['data']['items'];
    }
    return $payload;
  }
  return [];
}

// === Helpers mínimos ===
function bodhi_fetch_json($path, $ttl = 120) {
  static $cache = [];
  $key = $path;
  if (isset($cache[$key])) {
    return $cache[$key];
  }

  $url  = home_url('/wp-json/' . ltrim($path, '/'));
  $resp = wp_remote_get($url, ['timeout' => 10]);
  if (is_wp_error($resp)) {
    return ['ok' => false, 'code' => 0, 'data' => null, 'err' => $resp->get_error_message()];
  }
  $code = wp_remote_retrieve_response_code($resp);
  $body = json_decode(wp_remote_retrieve_body($resp), true);
  $out  = ['ok' => ($code === 200), 'code' => $code, 'data' => $body];
  $cache[$key] = $out;
  return $out;
}

function bodhi_is_staging() {
  $host = parse_url(home_url(), PHP_URL_HOST);
  return (strpos($host, 'staging.') !== false) || (defined('WP_ENV') && WP_ENV === 'staging');
}

function bodhi_require_login() {
  return is_user_logged_in();
}

/**
 * Recolecta cursos visibles para el usuario actual.
 * - strict: solo cursos con acceso explícito en la respuesta principal de Thrive.
 * - union : strict + cursos concedidos vía productos asignados al usuario.
 *
 * @param int        $page
 * @param int        $per_page
 * @param bool       $owned
 * @param string     $mode
 * @param array|null $diag  Métricas de diagnóstico (debug union/strict).
 */
function bodhi_tva_get_user_courses_filtered($page = 1, $per_page = 20, $owned = true, $mode = 'strict', &$diag = null) {
  $uid = get_current_user_id();
  if (!$uid) return [];

  $page = max(1, (int) $page);
  $per_page = max(1, min(50, (int) $per_page));
  $mode = in_array($mode, ['strict', 'union'], true) ? $mode : 'strict';

  $out  = [];
  $seen = [];

  $diag = [
    'uid'                 => $uid,
    'page'                => $page,
    'per'                 => $per_page,
    'owned'               => $owned,
    'mode'                => $mode,
    'tvacount'            => 0,              // cursos recibidos (tras unwrap)
    'kept_strict'         => 0,
    'products'            => 0,              // productos recibidos (tras unwrap)
    'added_from_products' => 0,
    'dropped_no_id'       => 0,
    'dropped_access'      => 0,
    'errors'              => [],
  ];
  $GLOBALS['__bodhi_diag'] = &$diag;

  // A) /tva/v2/courses (status=published) + unwrap shape {items:[]}
  $tvacourses = bodhi_unwrap_items(bodhi_tva_fetch_courses_fallback($page, $per_page));
  if (is_wp_error($tvacourses)) {
    $diag['errors'][] = $tvacourses->get_error_message();
    return $tvacourses;
  }
  if (!is_array($tvacourses)) {
    $tvacourses = [];
  }
  $diag['tvacount'] = count($tvacourses);

  foreach ($tvacourses as $course) {
    $cid = bodhi_course_id_from($course);
    if ($cid <= 0) {
      $diag['dropped_no_id']++;
      continue;
    }

    $has_access = null;
    if (isset($course['has_access'])) {
      $has_access = (bool) $course['has_access'];
    } elseif (isset($course['access'])) {
      $has_access = in_array((string) $course['access'], ['owned', 'member', 'free'], true);
    }
    $access_code = $course['access'] ?? null;

    if ($owned) {
      if ($has_access === false) {
        $diag['dropped_access']++;
        continue;
      }
      if ($access_code !== null && !in_array((string) $access_code, ['owned', 'member', 'free'], true)) {
        $diag['dropped_access']++;
        continue;
      }
    }

    $mapped = bodhi_map_course($course);
    if (empty($mapped) || empty($mapped['id'])) {
      $mapped = [
        'id'    => $cid,
        'title' => bodhi_course_title_from($course),
      ];
    }
    $mapped['id'] = $cid;

    if ($has_access !== null && !isset($mapped['has_access'])) {
      $mapped['has_access'] = $has_access;
    }
    if (!isset($mapped['access']) && $access_code !== null) {
      $mapped['access'] = (string) $access_code;
    }
    $mapped['access_reason'] = 'thrive_flag';

    $out[] = $mapped;
    $seen[$mapped['id']] = true;
    $diag['kept_strict']++;
  }

  // B) Productos → courses (solo “union”)
  if ($mode === 'union') {
    $products = bodhi_unwrap_items(bodhi_tva_fetch_user_products($uid));
    if (!is_array($products)) {
      $diag['errors'][] = 'products_empty_or_error';
      $products = [];
    }
    $diag['products'] = count($products);

    foreach ($products as $product) {
      if (is_object($product)) {
        $product = (array) $product;
      }
      $pid = (int) ($product['id'] ?? $product['ID'] ?? 0);
      if ($pid <= 0) {
        continue;
      }

      $pcourses = bodhi_unwrap_items(bodhi_tva_fetch_product_courses($pid));
      if (!is_array($pcourses)) {
        continue;
      }

      foreach ($pcourses as $pc) {
        $cid = bodhi_course_id_from($pc);
        if ($cid <= 0) {
          $diag['dropped_no_id']++;
          continue;
        }
        if (isset($seen[$cid])) {
          continue;
        }
        if (!empty($pc['status']) && $pc['status'] !== 'publish') {
          continue;
        }
        if (!empty($pc['post_status']) && $pc['post_status'] !== 'publish') {
          continue;
        }

        $mapped = bodhi_map_course($pc);
        if (empty($mapped) || empty($mapped['id'])) {
          $mapped = [
            'id'    => $cid,
            'title' => bodhi_course_title_from($pc),
          ];
        }
        $mapped['id'] = $cid;
        $mapped['title'] = $mapped['title'] ?? bodhi_course_title_from($pc);
        $mapped['slug'] = $mapped['slug'] ?? ($pc['slug'] ?? ($pc['post_name'] ?? ($pc['course']['slug'] ?? null)));
        $thumb_guess = $mapped['thumb'] ?? ($pc['thumb'] ?? $pc['thumbnail'] ?? $pc['featured_image'] ?? $pc['featured_image_url'] ?? null);
        if (is_array($thumb_guess)) {
          $thumb_guess = $thumb_guess['url'] ?? null;
        }
        if ($thumb_guess) {
          $mapped['thumb'] = $thumb_guess;
        }
        $mapped['status'] = $mapped['status'] ?? ($pc['status'] ?? ($pc['post_status'] ?? 'publish'));
        $mapped['has_access'] = true;
        $mapped['access'] = 'owned_by_product';
        $mapped['access_reason'] = 'product_grant';

        $out[] = $mapped;
        $seen[$cid] = true;
        $diag['added_from_products']++;
      }
    }
  }

  return $out;
}

/**
 * Normaliza el payload de Thrive Apprentice a la estructura usada por la app.
 */
function bodhi_map_course($course) {
  if ($course instanceof WP_REST_Response) {
    $course = $course->get_data();
  }
  if (is_object($course)) {
    $course = (array) $course;
  }
  if (!is_array($course)) {
    return [];
  }

  $course_id = (int) ($course['id'] ?? $course['ID'] ?? 0);
  if ($course_id <= 0) {
    return [];
  }

  $title = '';
  if (isset($course['title'])) {
    if (is_array($course['title']) && isset($course['title']['rendered'])) {
      $title = wp_strip_all_tags((string) $course['title']['rendered']);
    } else {
      $title = wp_strip_all_tags((string) $course['title']);
    }
  } elseif (isset($course['post_title'])) {
    $title = wp_strip_all_tags((string) $course['post_title']);
  }
  if ($title === '') {
    $title = 'Curso #' . $course_id;
  }

  $thumb = null;
  if (isset($course['cover']['url'])) {
    $thumb = $course['cover']['url'];
  } elseif (isset($course['featured_image'])) {
    $thumb = $course['featured_image'];
  } elseif (isset($course['thumbnail'])) {
    $thumb = $course['thumbnail'];
  } elseif (isset($course['featured_image_url'])) {
    $thumb = $course['featured_image_url'];
  }
  if (is_array($thumb)) {
    $thumb = $thumb['url'] ?? null;
  }

  $has_access = null;
  if (isset($course['has_access'])) {
    $has_access = (bool) $course['has_access'];
  } elseif (isset($course['access'])) {
    $has_access = in_array((string) $course['access'], ['owned', 'member', 'free'], true);
  }

  $access = (string) ($course['access'] ?? ($has_access ? 'owned' : 'locked'));

  $out = [
    'id'     => $course_id,
    'title'  => $title,
    'slug'   => isset($course['slug']) ? (string) $course['slug'] : (isset($course['post_name']) ? (string) $course['post_name'] : ''),
    'thumb'  => $thumb ?: null,
    'access' => $access,
  ];

  if ($has_access !== null) {
    $out['has_access'] = $has_access;
  }
  if (isset($course['status'])) {
    $out['status'] = (string) $course['status'];
  } elseif (isset($course['post_status'])) {
    $out['status'] = (string) $course['post_status'];
  }
  if (isset($course['permalink'])) {
    $out['permalink'] = (string) $course['permalink'];
  }

  return $out;
}

/**
 * Fallback local si no existiera bodhi_tva_fetch_courses().
 */
function bodhi_tva_fetch_courses_fallback($page = 1, $per_page = 20) {
  $req = new WP_REST_Request('GET', '/tva/v2/courses');
  $req->set_param('status', 'published');
  $req->set_param('page', max(1, (int) $page));
  $req->set_param('per_page', max(1, min(50, (int) $per_page)));

  $res = rest_do_request($req);
  return $res instanceof WP_REST_Response ? ($res->get_data() ?: []) : [];
}

function bodhi_tva_fetch_user_products($uid) {
  $uid = intval($uid);
  foreach ([
    ["/tva/v1/customer/$uid/products",  'view'],
    ["/tva/v1/customer/$uid/products",  'edit'],
    ["/tva/v1/customers/$uid/products", 'view'],
    ["/tva/v1/customers/$uid/products", 'edit'],
  ] as $opt) {
    [$route, $ctx] = $opt;
    $req = new WP_REST_Request('GET', $route);
    $req->set_param('context', $ctx);
    $res = rest_do_request($req);
    if ($res instanceof WP_REST_Response) {
      $data = $res->get_data();
      if ($data) {
        return $data;
      }
    } elseif (is_wp_error($res) && isset($GLOBALS['__bodhi_diag'])) {
      $GLOBALS['__bodhi_diag']['errors'][] = 'products:' . $res->get_error_code();
    }
  }
  return [];
}

function bodhi_tva_fetch_product_courses($pid) {
  $pid = intval($pid);
  foreach ([
    ["/tva/v1/products/$pid/courses", 'view'],
    ["/tva/v1/products/$pid/courses", 'edit'],
  ] as $opt) {
    [$route, $ctx] = $opt;
    $req = new WP_REST_Request('GET', $route);
    $req->set_param('context', $ctx);
    $res = rest_do_request($req);
    if ($res instanceof WP_REST_Response) {
      $data = $res->get_data();
      if ($data) {
        return $data;
      }
    } elseif (is_wp_error($res) && isset($GLOBALS['__bodhi_diag'])) {
      $GLOBALS['__bodhi_diag']['errors'][] = 'pcourses:' . $res->get_error_code();
    }
  }
  return [];
}

function bodhi_create_enrollments_table() {
  global $wpdb;
  $table = $wpdb->prefix . 'bodhi_enrollments';
  $charset = $wpdb->get_charset_collate();
  $sql = "CREATE TABLE IF NOT EXISTS {$table} (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    course_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'owned',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY user_course (user_id, course_id),
    KEY course_user (course_id, user_id),
    KEY status (status)
  ) {$charset};";
  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
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
register_activation_hook(__FILE__, function(){
  bodhi_create_enrollments_table();
  flush_rewrite_rules();
});
register_deactivation_hook(__FILE__, function(){ flush_rewrite_rules(); });

add_action('rest_api_init', function () {

  // === LESSONS (deprecated, lectura vía Thrive) ===
  register_rest_route(BODHI_API_NS, '/lessons', [
    'methods'  => 'GET',
    'callback' => 'bodhi_lessons_from_thrive',
    'permission_callback' => 'bodhi_require_login',
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
    'permission_callback' => 'bodhi_require_login',
    'callback' => function(){
      if (!bodhi_is_staging() || !current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'debug solo en staging/admin', ['status' => 403]);
      }
      $uid = get_current_user_id();
      $res = bodhi_rest_proxy_request('GET', '/tva/v1/customer/'.$uid.'/products', [], ['impersonate_admin' => true]);
      return $res['ok'] ? $res['data'] : $res;
    }
  ]);

  register_rest_route(BODHI_API_NS, '/debug/prod/(?P<pid>\d+)/courses', [
    'methods' => 'GET',
    'permission_callback' => 'bodhi_require_login',
    'callback' => function(WP_REST_Request $req){
      if (!bodhi_is_staging() || !current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'debug solo en staging/admin', ['status' => 403]);
      }
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
    'permission_callback' => 'bodhi_require_login',
    'callback' => function(WP_REST_Request $req){
      if (!bodhi_is_staging() || !current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'debug solo en staging/admin', ['status' => 403]);
      }
      $pid = (int)$req->get_param('pid');
      $res = bodhi_rest_proxy_request('GET', '/tva/v1/products/'.$pid.'/sets', [
        'per_page' => 100,
        'context'  => 'edit',
      ], ['impersonate_admin' => true]);
      return $res['ok'] ? $res['data'] : $res;
    }
  ]);

  register_rest_route('bodhi/v1', '/courses', [
    'methods'  => WP_REST_Server::READABLE,
    'callback' => 'bodhi_rest_get_courses',
    'permission_callback' => function(){ return is_user_logged_in(); },
    'args' => [
      'page' => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
      'per_page' => ['type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 50],
      'owned' => ['type' => 'boolean', 'default' => true],
      'mode' => ['type' => 'string', 'enum' => ['strict','union'], 'default' => 'strict'],
      'debug' => ['type' => 'boolean', 'default' => false],
    ],
  ]);

  // === PERFIL / ACCESOS (JWT) =============================================
  // === ÍNDICE DEL CURSO (outline) =========================================
  register_rest_route(BODHI_API_NS, '/outline', [
    'methods'  => 'GET',
    'callback' => 'bodhi_outline_from_thrive',
    'permission_callback' => 'bodhi_require_login',
  ]);

  // DEBUG SOLO STAGING: inspeccionar acceso real de Thrive para el usuario logueado
  register_rest_route(BODHI_API_NS, '/_debug-thrive', [
    'methods'  => 'GET',
    'permission_callback' => 'bodhi_require_login',
    'callback' => function (WP_REST_Request $req) {
      if (!bodhi_is_staging() || !current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'debug solo en staging/admin', ['status' => 403]);
      }
      try {
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

  // === DETALLE NORMALIZADO (Thrive) ===
  register_rest_route(BODHI_API_NS, '/courses/(?P<id>\\d+)', [
    'methods'  => 'GET',
    'callback' => 'bodhi_course_detail_normalized',
    'permission_callback' => 'bodhi_require_login',
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

// === Progress summary centralizado ===
function bodhi_progress_summary($course_id, $user_id = 0) {
  $uid = $user_id ?: get_current_user_id();
  $meta_key = 'bodhi_progress_' . (int) $course_id;
  $progress = get_user_meta($uid, $meta_key, true);
  if (!is_array($progress)) {
    $progress = [];
  }

  $r = bodhi_fetch_json("tva-public/v1/course/{$course_id}/items");
  $total = 0;
  if (!empty($r['ok']) && is_array($r['data'] ?? null) && !empty($r['data']['lessons'])) {
    $total = count($r['data']['lessons']);
  }
  if ($total <= 0) {
    $total = max(1, count($progress));
  }

  $done = 0;
  foreach ($progress as $v) {
    if ($v) {
      $done++;
    }
  }
  $pct = (int) round(($done / $total) * 100);

  return [
    'pct'      => $pct,
    'total'    => $total,
    'done'     => $done,
    'progress' => (object) $progress,
  ];
}

add_action('rest_api_init', function () {
  register_rest_route(BODHI_API_NS, '/progress', [
    'methods'  => ['GET', 'POST'],
    'callback' => 'bodhi_progress_handler',
    'permission_callback' => 'bodhi_require_login',
  ]);
});

function bodhi_progress_handler(WP_REST_Request $req) {
  $uid = get_current_user_id();
  $course_id = (int) $req->get_param('course_id');
  if ($course_id <= 0) {
    return new WP_Error('bodhi_bad_req', 'course_id requerido', ['status' => 400]);
  }

  $meta_key = 'bodhi_progress_' . $course_id;

  if ($req->get_method() === 'POST') {
    $lesson_id = (int) $req->get_param('lesson_id');
    if ($lesson_id <= 0) {
      return new WP_Error('bodhi_bad_req', 'lesson_id requerido', ['status' => 400]);
    }

    $done = $req->get_param('done');
    if ($done === null) {
      $done = true;
    }
    $done = filter_var($done, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($done === null) {
      $done = true;
    }

    $progress = get_user_meta($uid, $meta_key, true);
    if (!is_array($progress)) {
      $progress = [];
    }
    $progress[(string) $lesson_id] = (bool) $done;
    update_user_meta($uid, $meta_key, $progress);
  }

  $summary = bodhi_progress_summary($course_id, $uid);
  $summary['course_id'] = $course_id;
  return rest_ensure_response($summary);
}

function bodhi_enrollments_get_course_ids($user_id, $page, $per_page, $owned_only = true) {
  global $wpdb;
  $table = $wpdb->prefix . 'bodhi_enrollments';
  static $table_exists = null;
  if ($table_exists === null) {
    $table_exists = ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table);
  }
  if (!$table_exists) {
    return [[], 0];
  }

  $offset = max(0, ($page - 1) * $per_page);
  $where = ['user_id = %d'];
  $params = [$user_id];
  if ($owned_only) {
    $where[] = "status = 'owned'";
  }
  $where_sql = implode(' AND ', $where);

  $total = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}",
    ...$params
  ));

  $params_ids = array_merge($params, [$per_page, $offset]);
  $ids = $wpdb->get_col($wpdb->prepare(
    "SELECT course_id
     FROM {$table}
     WHERE {$where_sql}
     ORDER BY course_id DESC
     LIMIT %d OFFSET %d",
    ...$params_ids
  ));

  $ids = array_map('intval', $ids ?: []);
  return [$ids, $total];
}

function bodhi_rest_get_courses(WP_REST_Request $req) {
  $uid = get_current_user_id();
  if (!$uid) {
    return new WP_Error('bodhi_auth', 'No autenticado', ['status' => 401]);
  }

  $page     = max(1, (int) ($req->get_param('page') ?: 1));
  $per_page = max(1, min(50, (int) ($req->get_param('per_page') ?: 20)));

  // si true => "solo con acceso" (comportamiento histórico)
  $owned = $req->get_param('owned');
  $owned = is_null($owned) ? true : (bool) filter_var($owned, FILTER_VALIDATE_BOOLEAN);

  // "strict" = solo has_access (histórico), "union" = has_access + productos->courses
  $mode = $req->get_param('mode');
  if (!in_array($mode, ['strict','union'], true)) {
    $mode = 'strict';
  }

  $debug = (bool) filter_var($req->get_param('debug'), FILTER_VALIDATE_BOOLEAN);

  $cache_key = "bodhi:courses:v3:uid={$uid}:m={$mode}:o=" . ($owned ? '1' : '0') . ":p={$page}:n={$per_page}";
  if (!$debug) {
    $cached = wp_cache_get($cache_key, 'bodhi');
    if ($cached !== false) {
      return $cached;
    }
  }

  $diag = null;
  $items = bodhi_tva_get_user_courses_filtered($page, $per_page, $owned, $mode, $diag);

  if (is_wp_error($items)) {
    if ($debug && $diag !== null && is_array($diag)) {
      $items->add_data(['__debug' => $diag]);
    }
    return $items;
  }

  if ($debug) {
    return ['__debug' => $diag, 'items' => $items];
  }

  wp_cache_set($cache_key, $items, 'bodhi', 180);
  return $items;
}

function bodhi_outline_from_thrive(WP_REST_Request $req) {
  $course_id = (int) $req->get_param('course_id');
  if ($course_id <= 0) {
    return new WP_Error('bodhi_bad_req', 'course_id requerido', ['status' => 400]);
  }

  $r = bodhi_fetch_json("tva-public/v1/course/{$course_id}/items");
  if (empty($r['ok'])) {
    return new WP_Error(
      'bodhi_upstream',
      'Thrive items error',
      ['status' => 502, 'upstream_code' => $r['code'] ?? 0]
    );
  }

  $items = is_array($r['data']) ? $r['data'] : [];
  $modules_src = isset($items['modules']) && is_array($items['modules']) ? $items['modules'] : [];
  $lessons_src = isset($items['lessons']) && is_array($items['lessons']) ? $items['lessons'] : [];

  $modules = array_map(function ($m) {
    $id = $m['id'] ?? ($m['ID'] ?? null);
    return [
      'id'      => $id !== null ? (int) $id : null,
      'title'   => isset($m['post_title']) ? (string) $m['post_title'] : '',
      'lessons' => [],
    ];
  }, $modules_src);

  $by_id = [];
  foreach ($modules as $idx => $_module) {
    $module_id = $modules[$idx]['id'];
    if ($module_id !== null) {
      $by_id[(string) $module_id] = &$modules[$idx];
    }
  }
  unset($_module);

  foreach ($lessons_src as $lesson) {
    $lesson_id = $lesson['id'] ?? ($lesson['ID'] ?? null);
    $module_id = $lesson['post_parent'] ?? null;
    $entry = [
      'id'    => $lesson_id !== null ? (int) $lesson_id : null,
      'title' => isset($lesson['post_title']) ? (string) $lesson['post_title'] : '',
    ];
    if ($module_id !== null && isset($by_id[(string) $module_id])) {
      $by_id[(string) $module_id]['lessons'][] = $entry;
    }
  }

  return rest_ensure_response([
    'deprecated' => true,
    'course_id'  => $course_id,
    'modules'    => array_values($modules),
  ]);
}

function bodhi_lessons_from_thrive(WP_REST_Request $req) {
  $course_id = (int) $req->get_param('course_id');
  if ($course_id <= 0) {
    return new WP_Error('bodhi_bad_req', 'course_id requerido', ['status' => 400]);
  }

  $r = bodhi_fetch_json("tva-public/v1/course/{$course_id}/items");
  if (empty($r['ok'])) {
    return new WP_Error(
      'bodhi_upstream',
      'Thrive items error',
      ['status' => 502, 'upstream_code' => $r['code'] ?? 0]
    );
  }

  $lessons_src = $r['data']['lessons'] ?? [];
  if (!is_array($lessons_src)) {
    $lessons_src = [];
  }

  $lessons = array_map(function ($lesson) {
    $id = $lesson['id'] ?? ($lesson['ID'] ?? null);
    $video_url = isset($lesson['video']['source']) ? (string) $lesson['video']['source'] : '';
    $media = null;
    if ($video_url !== '') {
      $parsed = bodhi_parse_vimeo($video_url);
      if ($parsed) {
        $media = [
          'provider' => 'vimeo',
          'id'       => (string) $parsed['id'],
          'h'        => $parsed['h'] ?? null,
        ];
      }
    }
    return [
      'id'     => $id !== null ? (int) $id : null,
      'title'  => isset($lesson['post_title']) ? (string) $lesson['post_title'] : '',
      'blocks' => [],
      'media'  => $media,
    ];
  }, $lessons_src);

  return rest_ensure_response([
    'deprecated' => true,
    'course_id'  => $course_id,
    'items'      => array_values($lessons),
  ]);
}

function bodhi_course_detail_normalized(WP_REST_Request $req) {
  $course_id = (int) $req['id'];
  if ($course_id <= 0) {
    return new WP_Error('bad_request', 'ID de curso inválido', ['status' => 400]);
  }

  $r = bodhi_fetch_json("tva-public/v1/course/{$course_id}/items");
  if (empty($r['ok'])) {
    return new WP_Error(
      'bodhi_upstream',
      'Thrive items error',
      ['status' => 502, 'upstream_code' => $r['code'] ?? 0]
    );
  }

  $items = is_array($r['data']) ? $r['data'] : [];
  $modules_src = isset($items['modules']) && is_array($items['modules']) ? $items['modules'] : [];
  $lessons_src = isset($items['lessons']) && is_array($items['lessons']) ? $items['lessons'] : [];

  $modules = array_map(function ($m) {
    $id = $m['id'] ?? ($m['ID'] ?? null);
    return [
      'id'           => $id !== null ? (int) $id : null,
      'title'        => isset($m['post_title']) ? (string) $m['post_title'] : '',
      'order'        => isset($m['order']) ? (int) $m['order'] : 0,
      'cover_image'  => isset($m['cover_image']) ? (string) $m['cover_image'] : '',
      'publish_date' => $m['publish_date'] ?? null,
      'schema'       => (object)[],
    ];
  }, $modules_src);

  $lessons = array_map(function ($l) {
    $id = $l['id'] ?? ($l['ID'] ?? null);
    $module_id = $l['module_id'] ?? ($l['post_parent'] ?? null);
    $type = $l['lesson_type'] ?? null;
    if (!$type && !empty($l['video']['source'])) {
      $type = 'video';
    }
    if (!$type) {
      $type = 'article';
    }
    $video_url = isset($l['video']['source']) ? (string) $l['video']['source'] : '';
    $media = null;
    if ($video_url !== '') {
      $parsed = bodhi_parse_vimeo($video_url);
      if ($parsed) {
        $media = [
          'provider' => 'vimeo',
          'id'       => (string) $parsed['id'],
          'h'        => $parsed['h'] ?? null,
        ];
      }
    }
    return [
      'id'          => $id !== null ? (int) $id : null,
      'moduleId'    => $module_id !== null ? (int) $module_id : null,
      'title'       => isset($l['post_title']) ? (string) $l['post_title'] : '',
      'type'        => (string) $type,
      'url'         => $video_url,
      'order'       => isset($l['order']) ? (int) $l['order'] : 0,
      'preview_url' => isset($l['preview_url']) ? (string) $l['preview_url'] : '',
      'media'       => $media,
    ];
  }, $lessons_src);

  if (empty($modules) && !empty($lessons)) {
    $single_id = (int) ($course_id . '001');
    $modules = [[
      'id'           => $single_id,
      'title'        => 'Contenido',
      'order'        => 0,
      'cover_image'  => '',
      'publish_date' => null,
      'schema'       => (object)[],
    ]];
    foreach ($lessons as &$lesson_ref) {
      if (empty($lesson_ref['moduleId'])) {
        $lesson_ref['moduleId'] = $single_id;
      }
    }
    unset($lesson_ref);
  }

  $base = [
    'id'            => $course_id,
    'title'         => '',
    'slug'          => '',
    'cover'         => '',
    'summary'       => '',
    'lessons_count' => count($lessons),
    'access'        => 'granted',
  ];

  return rest_ensure_response(array_merge(
    $base,
    [
      'modules' => array_values($modules),
      'lessons' => array_values($lessons),
    ]
  ));
}

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
    'permission_callback' => 'bodhi_require_login',
    'callback' => function (WP_REST_Request $req) {
      if (!bodhi_is_staging() || !current_user_can('manage_options')) {
        return new WP_Error('forbidden', 'debug solo en staging/admin', ['status' => 403]);
      }
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
    'permission_callback' => 'bodhi_require_login',
    'callback' => function(){
      $u = wp_get_current_user();
      if (!$u || 0 === $u->ID) return ['logged_in'=>false];
      return ['logged_in'=>true, 'id'=>$u->ID, 'name'=>$u->display_name, 'roles'=>$u->roles];
    }
  ]);
});
