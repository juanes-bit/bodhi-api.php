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

add_action('plugins_loaded', function () {
  $path = __DIR__ . '/rest-mobile.php';
  if (file_exists($path)) { require_once $path; }
});

// --- Bridge REST para la app móvil (carga no intrusiva) ---
add_action('plugins_loaded', function () {
  $bridge = __DIR__ . '/inc/rest-bridge.php';
  if (file_exists($bridge)) { require_once $bridge; } // registra /bm/v1/*
}, 1);
// ----------------------------------------------------------

// --- utils base ---
function bodhi_arr_get($a, $k, $d = null) {
  return (is_array($a) && array_key_exists($k, $a)) ? $a[$k] : $d;
}

function bodhi_truthy($v) {
  return $v === true || $v === 'true' || $v === 1 || $v === '1' || $v === 'yes';
}

function bodhi_abs_url($u) {
  if (!$u) return null;
  if (is_string($u) && preg_match('#^https?://#', $u)) return $u;
  $base = rtrim(get_site_url(), '/');
  $path = is_string($u) ? $u : (bodhi_arr_get($u, 'url') ?? bodhi_arr_get($u, 'src') ?? bodhi_arr_get($u, 'source')
    ?? bodhi_arr_get(bodhi_arr_get($u, 'sizes', []), 'full') ?? bodhi_arr_get(bodhi_arr_get($u, 'sizes', []), 'large'));
  return $path ? (preg_match('#^https?://#', $path) ? $path : ($base . '/' . ltrim($path, '/'))) : null;
}

function bodhi_normalize_id(array $c): int {
  return (int) (bodhi_arr_get($c, 'id') ?? bodhi_arr_get($c, 'ID') ?? bodhi_arr_get($c, 'course_id')
              ?? bodhi_arr_get($c, 'wp_post_id') ?? bodhi_arr_get($c, 'post_id') ?? 0);
}

function bodhi_flatten_union_item(array $x): array {
  $c = bodhi_arr_get($x, 'course');
  if (!is_array($c)) return $x;
  foreach (['owned_by_product','has_access','access','products'] as $k) {
    if (array_key_exists($k, $x) && !array_key_exists($k, $c)) {
      $c[$k] = $x[$k];
    }
  }
  return $c;
}

function bodhi_infer_is_owned(array $c, array $owned_set = []): bool {
  $id = bodhi_normalize_id($c);
  if ($id && isset($owned_set[$id])) return true;
  foreach (['isOwned','is_owned','owned','access_granted','has_access','owned_by_product'] as $k) {
    if (bodhi_truthy(bodhi_arr_get($c, $k))) return true;
  }
  $acc = strtolower((string) bodhi_arr_get($c, 'access', ''));
  if (in_array($acc, ['owned','member','free'], true)) return true;
  $prods = bodhi_arr_get($c, 'products', []);
  if (is_array($prods)) {
    foreach ($prods as $p) {
      if (is_array($p) && bodhi_truthy(bodhi_arr_get($p, 'has_access'))) return true;
    }
  }
  return false;
}

function bodhi_normalize_course_item(array $raw, array $owned_set = []): array {
  $r = bodhi_flatten_union_item($raw);
  $id = bodhi_normalize_id($r);
  $title = '';
  foreach (['title','name','post_title'] as $k) {
    $v = bodhi_arr_get($r, $k);
    if (is_string($v) && trim($v) !== '') {
      $title = trim($v);
      break;
    }
  }
  $image = bodhi_abs_url(bodhi_arr_get($r, 'cover_image'))
        ?: bodhi_abs_url(bodhi_arr_get($r, 'cover'))
        ?: bodhi_abs_url(bodhi_arr_get($r, 'image'))
        ?: bodhi_abs_url(bodhi_arr_get($r, 'thumbnail'))
        ?: bodhi_abs_url(bodhi_arr_get($r, 'featured_image'))
        ?: bodhi_abs_url(bodhi_arr_get($r, 'featured_image_url'))
        ?: bodhi_abs_url(bodhi_arr_get($r, 'thumb'));
  $summary = null;
  foreach (['summary','excerpt','description','text','post_excerpt','short_description'] as $k) {
    $v = bodhi_arr_get($r, $k);
    if (is_string($v) && trim($v) !== '') {
      $summary = trim(strip_tags($v));
      break;
    }
  }
  $owned = bodhi_infer_is_owned($r, $owned_set);
  $out = $r;
  $out['id'] = $id;
  $out['courseId'] = $id;
  $out['title'] = $title;
  if (!empty($image)) {
    $out['image'] = $image;
  } elseif (!empty($out['image'])) {
    $out['image'] = bodhi_abs_url($out['image']);
  } elseif (!empty($out['thumb'])) {
    $out['image'] = bodhi_abs_url($out['thumb']);
  } else {
    $out['image'] = null;
  }
  if (!empty($out['thumb']) && empty($image)) {
    $out['thumb'] = bodhi_abs_url($out['thumb']);
  }

  $out['summary'] = $summary;
  $out['excerpt'] = $summary;
  $out['isOwned'] = $owned;
  $out['is_owned'] = $owned;

  $percent = null;
  foreach (['percent','progress','percentage','percent_complete','completion'] as $pk) {
    $pv = bodhi_arr_get($out, $pk);
    if (is_array($pv)) {
      foreach (['percent','percentage','progress','value'] as $subk) {
        if (isset($pv[$subk])) {
          $pv = $pv[$subk];
          break;
        }
      }
    }
    if (is_numeric($pv)) {
      $percent = (float) $pv;
      break;
    }
  }
  if (!is_null($percent)) {
    if ($percent > 0 && $percent <= 1 && $percent != round($percent)) {
      $percent *= 100;
    }
    $percent = max(0, min(100, (int) round($percent)));
  } else {
    $percent = 0;
  }
  $out['percent'] = $percent;

  $modules_count = null;
  foreach (['modules_count','module_count','modules_total','modulesCount'] as $mk) {
    $mv = bodhi_arr_get($out, $mk);
    if (is_numeric($mv)) {
      $modules_count = (int) $mv;
      break;
    }
  }
  if ($modules_count === null) {
    $modules = bodhi_arr_get($out, 'modules');
    if (is_array($modules)) {
      $modules_count = count($modules);
    }
  }
  if ($modules_count === null) {
    $modules_count = 0;
  }
  $out['modules_count'] = $modules_count;

  $lessons_count = null;
  foreach (['lessons_count','lesson_count','lessons_total','lessonsCount','lessonTotal'] as $lk) {
    $lv = bodhi_arr_get($out, $lk);
    if (is_numeric($lv)) {
      $lessons_count = (int) $lv;
      break;
    }
  }
  if ($lessons_count === null) {
    $lessons = bodhi_arr_get($out, 'lessons');
    if (is_array($lessons)) {
      $lessons_count = count($lessons);
    }
  }
  if ($lessons_count === null) {
    $lessons_count = 0;
  }
  $out['lessons_count'] = $lessons_count;

  $access = strtolower((string) bodhi_arr_get($out, 'access', ''));
  if ($owned) {
    $out['access'] = 'owned';
  } else {
    if (in_array($access, ['owned','member','free','granted','enrolled','owned_by_product'], true)) {
      $out['access'] = 'owned';
    } else {
      $out['access'] = 'locked';
    }
  }

  return $out;
}

function bodhi_emit_courses(array $items, array $owned_ids = []): WP_REST_Response {
  $owned_ids = array_values(array_unique(array_filter(array_map('intval', $owned_ids), fn($id) => $id > 0)));
  $owned_set = array_fill_keys($owned_ids, true);
  $norm = array_map(fn($x) => bodhi_normalize_course_item((array) $x, $owned_set), (array) $items);

  $items_owned = $owned_ids;
  foreach ($norm as &$item) {
    $cid = isset($item['id']) ? (int) $item['id'] : 0;
    $is_owned = bodhi_truthy($item['is_owned'] ?? null) || bodhi_truthy($item['isOwned'] ?? null) || ($cid > 0 && isset($owned_set[$cid]));
    $item['isOwned'] = $is_owned;
    $item['is_owned'] = $is_owned;
    if (!isset($item['courseId'])) {
      $item['courseId'] = $cid;
    }
    if ($is_owned && $cid > 0) {
      $items_owned[] = $cid;
      $item['access'] = 'owned';
    } elseif (!$is_owned) {
      $item['access'] = 'locked';
    }
  }
  unset($item);

  $items_owned = array_values(array_unique(array_filter(array_map('intval', $items_owned), fn($id) => $id > 0)));
  sort($items_owned, SORT_NUMERIC);

  $data = [
    'total'      => count($norm),
    'owned'      => count($items_owned),
    'itemsOwned' => $items_owned,
    'owned_ids'  => $items_owned,
    'items'      => array_values($norm),
  ];

  if (function_exists('nocache_headers')) nocache_headers();
  return new WP_REST_Response($data, 200);
}

function bodhi_prepare_courses_payload(int $page, int $per_page, bool $owned_only, string $mode, bool $debug, &$diag = null) {
  $page = max(1, (int) $page);
  $per_page = max(1, min(50, (int) $per_page));
  $mode = in_array($mode, ['strict', 'union'], true) ? $mode : 'union';

  $items = bodhi_tva_get_user_courses_filtered($page, $per_page, $owned_only, $mode, $diag);
  if (is_wp_error($items)) {
    return $items;
  }

  $items_array = $items;
  if ($items_array instanceof WP_REST_Response) {
    $items_array = $items_array->get_data();
  }
  if (is_object($items_array)) {
    $items_array = (array) $items_array;
  }
  if (is_array($items_array) && isset($items_array['items']) && is_array($items_array['items'])) {
    $items_array = $items_array['items'];
  }

  $items_array = is_array($items_array) ? $items_array : [];
  $items_array = array_values($items_array);
  $items_array = array_map(function ($course) {
    if ($course instanceof WP_REST_Response) {
      $course = $course->get_data();
    }
    if (is_object($course)) {
      $course = (array) $course;
    }
    return is_array($course) ? $course : [];
  }, $items_array);

  $owned_ids = [];
  foreach ($items_array as $course) {
    $flat = bodhi_flatten_union_item($course);
    $cid = bodhi_normalize_id($flat);
    if ($cid <= 0) {
      continue;
    }
    if (bodhi_infer_is_owned($flat)) {
      $owned_ids[] = $cid;
      continue;
    }
    $access_flag = strtolower((string) bodhi_arr_get($flat, 'access', ''));
    if (
      bodhi_truthy(bodhi_arr_get($flat, 'has_access')) ||
      bodhi_truthy(bodhi_arr_get($flat, 'owned_by_product')) ||
      in_array($access_flag, ['owned', 'member', 'free'], true)
    ) {
      $owned_ids[] = $cid;
    }
  }

  $owned_ids = array_values(array_unique(array_map('intval', $owned_ids)));

  return [
    'items'     => $items_array,
    'owned_ids' => $owned_ids,
  ];
}

function bodhi_owned_course_ids_from_thrive(int $user_id): array {
  $user_id = max(0, $user_id);
  if ($user_id <= 0) {
    return [];
  }

  $owned = [];
  $product_routes = [
    ['/tva/v1/customer/' . $user_id . '/products', ['context' => 'edit', 'per_page' => 100]],
    ['/tva/v1/customers/' . $user_id . '/products', ['context' => 'edit', 'per_page' => 100]],
  ];

  $products = [];
  foreach ($product_routes as [$route, $params]) {
    $resp = bodhi_rest_proxy_request('GET', $route, $params, ['impersonate_admin' => true]);
    if (!is_array($resp) || empty($resp['ok'])) {
      continue;
    }
    $data = $resp['data'];
    if ($data instanceof WP_REST_Response) {
      $data = $data->get_data();
    }
    if (is_object($data)) {
      $data = (array) $data;
    }
    if (is_array($data) && isset($data['items']) && is_array($data['items'])) {
      $data = $data['items'];
    }
    if (is_array($data)) {
      $products = $data;
      break;
    }
  }

  if (!is_array($products)) {
    $products = [];
  }

  foreach ($products as $product) {
    if ($product instanceof WP_REST_Response) {
      $product = $product->get_data();
    }
    if (is_object($product)) {
      $product = (array) $product;
    }
    if (!is_array($product)) {
      continue;
    }

    $pid = (int) (bodhi_arr_get($product, 'ID') ?? bodhi_arr_get($product, 'id') ?? 0);
    if ($pid <= 0) {
      continue;
    }

    $course_routes = [
      ['/tva/v1/products/' . $pid . '/courses', ['context' => 'edit', 'per_page' => 100]],
      ['/tva/v1/product/' . $pid . '/courses', ['context' => 'edit', 'per_page' => 100]],
    ];

    foreach ($course_routes as [$route, $params]) {
      $course_found = false;
      $cres = bodhi_rest_proxy_request('GET', $route, $params, ['impersonate_admin' => true]);
      if (!is_array($cres) || empty($cres['ok'])) {
        continue;
      }
      $courses = $cres['data'];
      if ($courses instanceof WP_REST_Response) {
        $courses = $courses->get_data();
      }
      if (is_object($courses)) {
        $courses = (array) $courses;
      }
      if (is_array($courses) && isset($courses['items']) && is_array($courses['items'])) {
        $courses = $courses['items'];
      }
      if (!is_array($courses)) {
        continue;
      }
      foreach ($courses as $course) {
        if ($course instanceof WP_REST_Response) {
          $course = $course->get_data();
        }
        if (is_object($course)) {
          $course = (array) $course;
        }
        if (!is_array($course)) {
          continue;
        }
        $cid = (int) (bodhi_arr_get($course, 'wp_post_id') ?? bodhi_arr_get($course, 'post_id') ?? bodhi_arr_get($course, 'id') ?? 0);
        if ($cid > 0) {
          $owned[$cid] = true;
          $course_found = true;
        }
      }
      if ($course_found) {
        break;
      }
    }
  }

  return array_map('intval', array_keys($owned));
}

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
  if (is_numeric($c)) {
    return intval($c);
  }
  if (!is_array($c)) {
    return 0;
  }
  foreach (['id','ID','course_id','post_id'] as $k) {
    if (isset($c[$k]) && intval($c[$k]) > 0) {
      return intval($c[$k]);
    }
  }
  if (isset($c['course']['id'])) return intval($c['course']['id']);
  if (isset($c['post']['ID']))   return intval($c['post']['ID']);
  return 0;
}

// Devuelve un título “humano”
function bodhi_course_title_from($c) {
  if (is_array($c)) {
    foreach (['title','name','post_title'] as $k) {
      if (!empty($c[$k])) return (string)$c[$k];
    }
    if (!empty($c['course']['title']))     return (string)$c['course']['title'];
    if (!empty($c['post']['post_title']))  return (string)$c['post']['post_title'];
    if (!empty($c['course']['name']))      return (string)$c['course']['name'];
  }
  return 'Course';
}

// “Desempaqueta” respuestas { items: [...] } o { data: { items: [...] } }
function bodhi_unwrap_items($payload) {
  if (is_wp_error($payload)) {
    return [];
  }
  if (is_array($payload) && isset($payload['body'])) {
    $decoded = json_decode($payload['body'], true);
  } elseif (is_string($payload)) {
    $decoded = json_decode($payload, true);
  } else {
    $decoded = $payload;
  }
  if (is_array($decoded) && isset($decoded['items']) && is_array($decoded['items'])) {
    return $decoded['items'];
  }
  if (is_array($decoded) && isset($decoded['data']['items']) && is_array($decoded['data']['items'])) {
    return $decoded['data']['items'];
  }
  return is_array($decoded) ? $decoded : [];
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

function bodhi_cookie_domain_value() {
  if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN) {
    return COOKIE_DOMAIN;
  }
  $host = parse_url(home_url(), PHP_URL_HOST);
  if (!is_string($host) || $host === '' || $host === 'localhost' || strpos($host, '.') === false) {
    return '';
  }
  return $host;
}

function bodhi_cookie_samesite_value($secure) {
  return $secure ? 'None' : 'Lax';
}

function bodhi_emit_login_cookies($user, $remember = true) {
  if (!($user instanceof WP_User)) {
    return new WP_Error('bodhi_login_invalid_user', 'Usuario inválido para emitir cookies.');
  }

  $remember = (bool) $remember;
  $secure = is_ssl();
  if (function_exists('wp_is_using_https') && wp_is_using_https()) {
    $secure = true;
  }

  $expiration = time() + apply_filters('auth_cookie_expiration', ($remember ? 14 : 2) * DAY_IN_SECONDS, $user->ID, $remember);
  $expire     = $remember ? $expiration + (12 * HOUR_IN_SECONDS) : 0;

  $secure_logged_in_cookie = $secure && 'https' === parse_url(get_option('home'), PHP_URL_SCHEME);
  $secure                  = apply_filters('secure_auth_cookie', $secure, $user->ID);
  $secure_logged_in_cookie = apply_filters('secure_logged_in_cookie', $secure_logged_in_cookie, $user->ID, $secure);

  $manager      = WP_Session_Tokens::get_instance($user->ID);
  $session_token = $manager->create($expiration);

  $scheme             = $secure ? 'secure_auth' : 'auth';
  $auth_cookie_name   = $secure ? SECURE_AUTH_COOKIE : AUTH_COOKIE;
  $auth_cookie_value  = wp_generate_auth_cookie($user->ID, $expiration, $scheme, $session_token);
  $logged_in_value    = wp_generate_auth_cookie($user->ID, $expiration, 'logged_in', $session_token);

  do_action('set_auth_cookie', $auth_cookie_value, $expire, $expiration, $user->ID, $scheme, $session_token);
  do_action('set_logged_in_cookie', $logged_in_value, $expire, $expiration, $user->ID, 'logged_in', $session_token);

  wp_set_current_user($user->ID);
  nocache_headers();
  do_action('wp_login', $user->user_login, $user);

  $domain         = bodhi_cookie_domain_value();
  $auth_samesite  = bodhi_cookie_samesite_value($secure);
  $login_samesite = bodhi_cookie_samesite_value($secure_logged_in_cookie);

  $cookie_domain = $domain ?: '';
  $plugin_path   = defined('PLUGINS_COOKIE_PATH') ? PLUGINS_COOKIE_PATH : COOKIEPATH;
  $admin_path    = defined('ADMIN_COOKIE_PATH') ? ADMIN_COOKIE_PATH : COOKIEPATH;
  $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
  $site_path     = defined('SITECOOKIEPATH') ? SITECOOKIEPATH : $cookie_path;

  $auth_cookie_options = [
    'expires'  => $expire,
    'path'     => $plugin_path,
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => $auth_samesite,
  ];
  if ($cookie_domain !== '') {
    $auth_cookie_options['domain'] = $cookie_domain;
  }

  setcookie($auth_cookie_name, $auth_cookie_value, $auth_cookie_options);
  if ($admin_path !== $plugin_path) {
    $admin_cookie_options = $auth_cookie_options;
    $admin_cookie_options['path'] = $admin_path;
    setcookie($auth_cookie_name, $auth_cookie_value, $admin_cookie_options);
  }

  $logged_in_options = [
    'expires'  => $expire,
    'path'     => $cookie_path,
    'secure'   => $secure_logged_in_cookie,
    'httponly' => false,
    'samesite' => $login_samesite,
  ];
  if ($cookie_domain !== '') {
    $logged_in_options['domain'] = $cookie_domain;
  }

  setcookie(LOGGED_IN_COOKIE, $logged_in_value, $logged_in_options);
  if ($site_path !== $cookie_path) {
    $site_cookie_options = $logged_in_options;
    $site_cookie_options['path'] = $site_path;
    setcookie(LOGGED_IN_COOKIE, $logged_in_value, $site_cookie_options);
  }

  return [
    'token'      => $session_token,
    'expiration' => $expiration,
    'secure'     => $secure,
  ];
}

/**
 * Valida las cookies estándar de WordPress y prepara el usuario global.
 */
function bodhi_validate_cookie_user() {
  $uid = wp_validate_auth_cookie('', 'logged_in');
  if (!$uid) {
    $scheme = is_ssl() ? 'secure_auth' : 'auth';
    $uid = wp_validate_auth_cookie('', $scheme);
  }
  $uid = intval($uid);
  if ($uid > 0) {
    wp_set_current_user($uid);
    return $uid;
  }
  return 0;
}

/**
 * Callback legado usado por rutas antiguas que confían en la cookie.
 */
function bodhi_require_login() {
  if (is_user_logged_in()) {
    return true;
  }
  return bodhi_validate_cookie_user() > 0;
}

/**
 * Permiso REST que devuelve WP_Error 401 cuando no hay sesión válida.
 */
function bodhi_rest_permission_cookie() {
  $uid = bodhi_validate_cookie_user();
  if ($uid > 0) {
    return true;
  }
  return new WP_Error('rest_forbidden', __('Lo siento, no tienes permisos para hacer eso.'), ['status' => 401]);
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
    $diag['products'] = is_countable($products) ? count($products) : 0;

    foreach ($products as $product) {
      if (is_object($product)) {
        $product = (array) $product;
      }
      $pid = (int) ($product['id'] ?? $product['ID'] ?? 0);
      if ($pid <= 0) {
        continue;
      }

      $pc_resp  = wp_remote_get(home_url("/wp-json/tva/v1/products/$pid/courses?context=edit&per_page=100"));
      if (is_wp_error($pc_resp)) {
        $diag['errors'][] = 'pcourses:' . $pc_resp->get_error_code();
        continue;
      }
      $pcourses = bodhi_unwrap_items($pc_resp);
      if (!is_array($pcourses)) {
        $pcourses = [];
      }
  $pcourses = array_values($pcourses);
      if (!empty($pcourses)) {
        $sample = [];
        foreach (array_slice($pcourses, 0, 3) as $x) {
          $sample[] = is_array($x) ? array_keys($x) : gettype($x);
        }
        $diag['product_course_sample_keys'] = $sample;
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
        $title = bodhi_course_title_from($pc);
        $thumb = is_array($pc) ? ($pc['thumb'] ?? $pc['thumbnail'] ?? null) : null;
        if (is_array($thumb)) {
          $thumb = $thumb['url'] ?? null;
        }

        $mapped = [
          'id'            => $cid,
          'title'         => $title,
          'thumb'         => $thumb,
          'status'        => 'publish',
          'has_access'    => true,
          'access'        => 'owned_by_product',
          'access_reason' => 'product_grant',
        ];

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
    ["/tva/v1/products/$pid/courses", 'edit'],
    ["/tva/v1/products/$pid/courses", 'view'],
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

      $cookie_meta = bodhi_emit_login_cookies($user, true);
      if (is_wp_error($cookie_meta)) {
        return new WP_REST_Response(['error' => 'cookie_issue', 'message' => $cookie_meta->get_error_message()], 500);
      }

      $nonce = wp_create_nonce('wp_rest');

      $token = wp_generate_password(40, false, false);
      set_transient(bodhi_token_key($token), $user->ID, 2 * HOUR_IN_SECONDS);
      return new WP_REST_Response([
        'ok'    => true,
        'token' => $token,
        'user'  => ['id'=>$user->ID,'name'=>$user->display_name,'roles'=>$user->roles],
        'session' => $cookie_meta,
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

  $mode = isset($_GET['mode']) ? sanitize_key((string) $_GET['mode']) : 'union';
  if (!in_array($mode, ['strict','union'], true)) {
    $mode = 'union';
  }
  $req->set_param('mode', $mode);

  $debug = (bool) filter_var($req->get_param('debug'), FILTER_VALIDATE_BOOLEAN);

  $diag = null;
  $payload = bodhi_prepare_courses_payload($page, $per_page, $owned, $mode, $debug, $diag);

  if (is_wp_error($payload)) {
    if ($debug && $diag !== null && is_array($diag)) {
      $payload->add_data(['__debug' => $diag]);
    }
    return $payload;
  }

  $items = is_array($payload['items'] ?? null) ? $payload['items'] : [];
  $owned_ids = bodhi_owned_course_ids_from_thrive($uid);

  $response = bodhi_emit_courses($items, $owned_ids);

  if ($debug && is_array($diag)) {
    $data = $response->get_data();
    $data['__debug'] = $diag;
    $response->set_data($data);
  }

  return $response;
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

  $items_resp = bodhi_fetch_json("tva-public/v1/course/{$course_id}/items");
  if (empty($items_resp['ok'])) {
    return new WP_Error(
      'bodhi_upstream',
      'Thrive items error',
      ['status' => 502, 'upstream_code' => $items_resp['code'] ?? 0]
    );
  }

  $items = is_array($items_resp['data']) ? $items_resp['data'] : [];
  $modules_src = isset($items['modules']) && is_array($items['modules']) ? $items['modules'] : [];
  $lessons_src = isset($items['lessons']) && is_array($items['lessons']) ? $items['lessons'] : [];
  $course_meta = [];
  if (isset($items['course']) && is_array($items['course'])) {
    $course_meta = $items['course'];
  } else {
    $course_resp = bodhi_fetch_json("tva-public/v1/course/{$course_id}");
    if (!empty($course_resp['ok']) && is_array($course_resp['data'])) {
      $course_meta = $course_resp['data'];
    }
  }

  $resolve_string = function ($source, array $keys, $default = '') {
    foreach ($keys as $key) {
      $value = bodhi_arr_get($source, $key);
      if (is_array($value)) {
        if (isset($value['rendered']) && is_string($value['rendered'])) {
          $value = $value['rendered'];
        } elseif (isset($value['value']) && is_string($value['value'])) {
          $value = $value['value'];
        } elseif (isset($value['url']) && is_string($value['url'])) {
          $value = $value['url'];
        } else {
          continue;
        }
      }
      if (is_string($value) && trim($value) !== '') {
        return trim($value);
      }
    }
    return $default;
  };

  $post = get_post($course_id);
  $title = $resolve_string($course_meta, ['title', 'name', 'post_title'], '');
  if ($title === '' && $post instanceof WP_Post) {
    $title = get_the_title($post);
  }
  $title = $title !== '' ? wp_strip_all_tags($title) : 'Curso #' . $course_id;

  $summary_raw = $resolve_string($course_meta, ['summary','excerpt','description','short_description','post_excerpt','text'], '');
  if ($summary_raw === '' && isset($items['course']['excerpt'])) {
    $summary_raw = $resolve_string($items['course'], ['excerpt'], '');
  }
  if ($summary_raw === '' && $post instanceof WP_Post) {
    $excerpt = $post->post_excerpt ?: $post->post_content;
    $summary_raw = is_string($excerpt) ? $excerpt : '';
  }
  $summary = trim(wp_strip_all_tags($summary_raw));

  $cover = bodhi_abs_url($resolve_string($course_meta, ['cover_image','featured_image','featured_image_url','thumbnail','image'], null));
  if (!$cover && isset($items['course'])) {
    $cover = bodhi_abs_url($resolve_string($items['course'], ['cover_image','featured_image','featured_image_url','thumbnail','image'], null));
  }

  $trailer = $resolve_string($course_meta, ['trailer','trailer_url','preview_video','preview_video_url','promo_video','promoVideo','video_preview','video_preview_url'], '');
  if ($trailer === '' && isset($course_meta['trailer']) && is_array($course_meta['trailer'])) {
    $trailer = $resolve_string($course_meta['trailer'], ['url','source'], '');
  }
  if ($trailer === '' && isset($items['course'])) {
    $trailer = $resolve_string($items['course'], ['trailer','trailer_url','preview_video','preview_video_url','promo_video','promoVideo'], '');
  }
  $trailer = $trailer !== '' ? bodhi_abs_url($trailer) : null;

  $modules_map = [];
  $module_key_map = [];
  foreach ($modules_src as $idx => $module_src) {
    $mid = $module_src['id'] ?? ($module_src['ID'] ?? null);
    $mid = $mid !== null ? (int) $mid : null;
    $key = $mid ?? ('idx_' . $idx);
    $module_item = [
      'id'           => $mid,
      'title'        => wp_strip_all_tags((string) bodhi_arr_get($module_src, 'post_title', '')),
      'order'        => isset($module_src['order']) ? (int) $module_src['order'] : 0,
      'cover_image'  => bodhi_abs_url(bodhi_arr_get($module_src, 'cover_image')),
      'publish_date' => $module_src['publish_date'] ?? null,
      'schema'       => (object)[],
      'lessons'      => [],
    ];
    if (!$module_item['title']) {
      $module_item['title'] = 'Módulo ' . ($idx + 1);
    }
    $modules_map[$key] = $module_item;
    if ($mid !== null) {
      $module_key_map[$mid] = $key;
    }
  }

  $lessons = [];
  $orphans = [];
  foreach ($lessons_src as $idx => $lesson_src) {
    $lid = $lesson_src['id'] ?? ($lesson_src['ID'] ?? null);
    $lid = $lid !== null ? (int) $lid : null;
    $module_id = $lesson_src['module_id'] ?? ($lesson_src['post_parent'] ?? null);
    $module_id = $module_id !== null ? (int) $module_id : null;
    $type = $lesson_src['lesson_type'] ?? null;
    if (!$type && !empty($lesson_src['video']['source'])) {
      $type = 'video';
    }
    if (!$type) {
      $type = 'article';
    }
    $video_url = isset($lesson_src['video']['source']) ? (string) $lesson_src['video']['source'] : '';
    if ($video_url === '' && isset($lesson_src['video_url'])) {
      $video_url = (string) $lesson_src['video_url'];
    }
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

    $duration = null;
    foreach (['duration','video_duration','length','duration_seconds','lesson_duration'] as $dk) {
      $dv = bodhi_arr_get($lesson_src, $dk);
      if (is_numeric($dv)) {
        $duration = (int) $dv;
        break;
      }
    }
    if ($duration === null && isset($lesson_src['video']) && is_array($lesson_src['video'])) {
      foreach (['duration','length','seconds'] as $dk) {
        $dv = $lesson_src['video'][$dk] ?? null;
        if (is_numeric($dv)) {
          $duration = (int) $dv;
          break;
        }
      }
    }
    if ($duration === null) {
      $duration = 0;
    }

    $download = false;
    $download_url = null;
    foreach (['download_url','download_link','downloadUrl','downloadLink'] as $dlk) {
      $dv = bodhi_arr_get($lesson_src, $dlk);
      if (is_string($dv) && trim($dv) !== '') {
        $download_url = bodhi_abs_url($dv);
        $download = true;
        break;
      }
    }
    if (!$download) {
      foreach (['download','downloadable','allow_download','download_enabled','can_download'] as $flag) {
        if (bodhi_truthy(bodhi_arr_get($lesson_src, $flag))) {
          $download = true;
          break;
        }
      }
    }

    $lesson = [
      'id'           => $lid,
      'moduleId'     => $module_id,
      'title'        => isset($lesson_src['post_title']) ? (string) $lesson_src['post_title'] : '',
      'type'         => (string) $type,
      'url'          => $video_url,
      'order'        => isset($lesson_src['order']) ? (int) $lesson_src['order'] : 0,
      'preview_url'  => isset($lesson_src['preview_url']) ? (string) $lesson_src['preview_url'] : '',
      'media'        => $media,
      'duration'     => $duration,
      'vimeo_id'     => $media['id'] ?? null,
      'download'     => $download,
      'download_url' => $download_url,
    ];
    if ($lesson['title'] === '') {
      $lesson['title'] = 'Lección ' . ($idx + 1);
    }

    $lessons[] = $lesson;

    $module_key = null;
    if ($module_id !== null && isset($module_key_map[$module_id])) {
      $module_key = $module_key_map[$module_id];
    } elseif ($module_id !== null && isset($modules_map[$module_id])) {
      $module_key = $module_id;
    }

    if ($module_key !== null && isset($modules_map[$module_key])) {
      $modules_map[$module_key]['lessons'][] = $lesson;
    } else {
      $orphans[] = $lesson;
    }
  }

  if (empty($modules_map) && !empty($lessons)) {
    $single_id = (int) ($course_id . '001');
    foreach ($lessons as &$lesson_ref) {
      if (empty($lesson_ref['moduleId'])) {
        $lesson_ref['moduleId'] = $single_id;
      }
    }
    unset($lesson_ref);
    $modules_map['fallback'] = [
      'id'           => $single_id,
      'title'        => 'Contenido',
      'order'        => 0,
      'cover_image'  => '',
      'publish_date' => null,
      'schema'       => (object)[],
      'lessons'      => $lessons,
    ];
  } elseif (!empty($orphans)) {
    $fallback_id = (int) ($course_id . '999');
    foreach ($orphans as &$orphan_ref) {
      if (empty($orphan_ref['moduleId'])) {
        $orphan_ref['moduleId'] = $fallback_id;
      }
    }
    unset($orphan_ref);
    $modules_map['orphans'] = [
      'id'           => $fallback_id,
      'title'        => 'Contenido',
      'order'        => max(array_map(fn($m) => $m['order'] ?? 0, $modules_map)) + 1,
      'cover_image'  => '',
      'publish_date' => null,
      'schema'       => (object)[],
      'lessons'      => $orphans,
    ];
  }

  $modules = array_values($modules_map);
  usort($modules, function ($a, $b) {
    return ($a['order'] <=> $b['order']) ?: ((int) $a['id'] <=> (int) $b['id']);
  });

  $progress_source = bodhi_progress_summary($course_id);
  $progress = [
    'done'    => isset($progress_source['done']) ? (int) $progress_source['done'] : 0,
    'total'   => isset($progress_source['total']) ? (int) $progress_source['total'] : (int) count($lessons),
    'percent' => isset($progress_source['pct']) ? (int) $progress_source['pct'] : 0,
  ];
  if ($progress['total'] <= 0) {
    $progress['total'] = count($lessons);
  }
  if ($progress['total'] > 0 && $progress['percent'] <= 0 && $progress['done'] > 0) {
    $progress['percent'] = (int) round(($progress['done'] / $progress['total']) * 100);
  }

  $base = [
    'id'            => $course_id,
    'title'         => $title,
    'slug'          => $post instanceof WP_Post ? $post->post_name : '',
    'cover'         => $cover ?: '',
    'summary'       => $summary,
    'trailer'       => $trailer,
    'lessons_count' => count($lessons),
    'access'        => 'granted',
  ];

  return rest_ensure_response(array_merge(
    $base,
    [
      'modules' => array_values($modules),
      'lessons' => array_values($lessons),
      'progress' => $progress,
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

  $user = wp_authenticate($username, $password);

  if (is_wp_error($user)) {
    wp_send_json(['ok'=>false, 'error'=>'invalid_credentials', 'message'=>$user->get_error_message()], 401);
  }

  $cookie_meta = bodhi_emit_login_cookies($user, true);
  if (is_wp_error($cookie_meta)) {
    wp_send_json(['ok'=>false, 'error'=>'cookie_issue', 'message'=>$cookie_meta->get_error_message()], 500);
  }

  $nonce = wp_create_nonce('wp_rest');

  wp_send_json([
    'ok'   => true,
    'user' => ['id'=>$user->ID, 'name'=>$user->display_name, 'roles'=>$user->roles],
    'nonce'=> $nonce,
    'session' => $cookie_meta,
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
