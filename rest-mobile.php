<?php
if (!defined('ABSPATH')) {
  exit;
}

// Permite autenticación sólo por cookie en /wp-json/bodhi/v1/* cuando el usuario está logueado.
add_filter('rest_authentication_errors', function ($result) {
  if ($result instanceof WP_Error) {
    $route = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($route, '/wp-json/bodhi/v1/') !== false && is_user_logged_in()) {
      return true; // Saltamos el chequeo de nonce para nuestra API móvil.
    }
  }
  return $result;
}, 5);

add_action('rest_api_init', function () {
  register_rest_route('bodhi/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      return is_user_logged_in();
    },
    'callback' => function () {
      return rest_ensure_response(['nonce' => wp_create_nonce('wp_rest')]);
    },
  ]);

  register_rest_route('bodhi/v1', '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () {
      return is_user_logged_in();
    },
    'callback' => function (WP_REST_Request $req) {
      // Reutilizamos la lógica consolidada de /bodhi/v1/courses.
      $proxy = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $proxy->set_param('mode', 'union');
      $proxy->set_param('per_page', 100);

      $response = rest_do_request($proxy);
      if ($response instanceof WP_Error) {
        return $response;
      }
      if (!$response instanceof WP_REST_Response) {
        return new WP_Error('bodhi_mobile_unexpected', 'Respuesta inesperada de /bodhi/v1/courses', ['status' => 500]);
      }

      $server = rest_get_server();
      if (!$server) {
        return new WP_Error('bodhi_mobile_server', 'REST server no disponible', ['status' => 500]);
      }

      $data = $server->response_to_data($response, false);
      $items = [];
      if (is_array($data)) {
        if (isset($data['items']) && is_array($data['items'])) {
          $items = $data['items'];
        } else {
          $items = $data;
        }
      }

      $normalized = [];
      foreach ($items as $it) {
        if (!is_array($it)) {
          continue;
        }
        $id = intval($it['id'] ?? 0);
        $title = wp_strip_all_tags((string)($it['title'] ?? ($it['name'] ?? '')));
        $raw_image = $it['image']
          ?? $it['thumb']
          ?? $it['thumbnail']
          ?? $it['cover']
          ?? $it['featured_image']
          ?? $it['featured_image_url']
          ?? '';
        if (is_array($raw_image)) {
          $raw_image = $raw_image['url'] ?? '';
        }
        $excerpt = wp_strip_all_tags((string)($it['excerpt'] ?? ($it['description'] ?? ($it['summary'] ?? ''))));

        $percent = 0;
        if (isset($it['percent'])) {
          $percent = (int) $it['percent'];
        } elseif (isset($it['progress'])) {
          if (is_array($it['progress']) && isset($it['progress']['pct'])) {
            $percent = (int) $it['progress']['pct'];
          } else {
            $percent = (int) $it['progress'];
          }
        }
        $percent = max(0, min(100, $percent));

        $access = isset($it['access']) ? (string) $it['access'] : '';
        $owned_flags = [
          $it['isOwned'] ?? null,
          $it['is_owned'] ?? null,
          $it['owned'] ?? null,
          $it['has_access'] ?? null,
        ];

        $is_owned = false;
        foreach ($owned_flags as $flag) {
          if (!empty($flag) && $flag !== 'locked') {
            $is_owned = true;
            break;
          }
        }
        if (!$is_owned && $access !== '') {
          $is_owned = in_array($access, ['owned', 'owned_by_product', 'member', 'free', 'granted', 'owned_by_membership'], true);
        }

        $normalized[] = [
          'id'      => $id,
          'title'   => $title,
          'image'   => $raw_image ? (string) $raw_image : '',
          'excerpt' => $excerpt,
          'percent' => $percent,
          'access'  => $is_owned ? 'owned' : 'locked',
          'isOwned' => $is_owned,
        ];
      }

      $owned = array_values(array_filter($normalized, function ($course) {
        return !empty($course['isOwned']);
      }));

      return rest_ensure_response([
        'items'      => $normalized,
        'itemsOwned' => $owned,
        'total'      => count($normalized),
        'owned'      => count($owned),
      ]);
    },
  ]);
});
