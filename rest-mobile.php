<?php
/**
 * Rutas REST para la app móvil (cookie-only) y proxy interno seguro.
 */
if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function () {

  register_rest_route('bodhi/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $req) {
      return new WP_REST_Response(['nonce' => wp_create_nonce('wp_rest')], 200);
    },
  ]);

  register_rest_route('bodhi/v1', '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $req) {

      $inner = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $inner->set_param('mode', 'union');

      $resp = rest_do_request($inner);
      if (is_wp_error($resp)) {
        return $resp;
      }
      if (!$resp instanceof WP_REST_Response) {
        return new WP_Error('bodhi_mobile_proxy', 'Respuesta inesperada en proxy de cursos', ['status' => 500]);
      }

      $data = $resp->get_data();
      $raw = is_array($data) ? ($data['items'] ?? $data) : [];
      $items = array_map(function ($x) {
        if (!is_array($x)) {
          return [
            'id' => 0,
            'title' => '',
            'image' => null,
            'percent' => 0,
            'access' => 'locked',
            'isOwned' => false,
            'excerpt' => '',
            'video' => null,
          ];
        }

        $access = $x['access'] ?? ($x['access_status'] ?? '');
        $owned = !empty($x['is_owned'])
          || !empty($x['isOwned'])
          || in_array($access, ['granted','owned','enrolled','allowed','member','free'], true);

        return [
          'id'      => isset($x['id']) ? intval($x['id']) : 0,
          'title'   => $x['title'] ?? '',
          'image'   => $x['image'] ?? ($x['thumbnail'] ?? ($x['thumb'] ?? null)),
          'percent' => isset($x['percent']) ? intval($x['percent']) : 0,
          'access'  => $access ?: ($owned ? 'granted' : 'locked'),
          'isOwned' => $owned,
          'excerpt' => $x['excerpt'] ?? '',
          'video'   => $x['video'] ?? null,
        ];
      }, is_array($raw) ? $raw : []);

      $itemsOwned = array_values(array_filter($items, fn($i) => !empty($i['isOwned'])));

      return new WP_REST_Response([
        'items'      => $items,
        'itemsOwned' => $itemsOwned,
        'total'      => count($items),
        'owned'      => count($itemsOwned),
      ], 200);
    },
  ]);

});

