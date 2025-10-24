<?php
/**
 * Plugin Name: Bodhi Mobile Routes (MU)
 * Description: Rutas REST para la app móvil (cookie-only) y proxy al endpoint de cursos.
 */

add_action('rest_api_init', function () {

  register_rest_route('bodhi-mobile/v1', '/ping', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
    'callback' => function () {
      return rest_ensure_response([
        'ok'   => true,
        'user' => get_current_user_id(),
        'time' => current_time('mysql'),
      ]);
    },
  ]);

  register_rest_route('bodhi-mobile/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function () {
      return rest_ensure_response(['nonce' => wp_create_nonce('wp_rest')]);
    },
  ]);

  register_rest_route('bodhi-mobile/v1', '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function (WP_REST_Request $request) {

      if (!class_exists('WP_REST_Request')) {
        require_once ABSPATH . 'wp-includes/class-wp-rest-request.php';
      }

      $proxy = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $proxy->set_param('mode', 'union');
      $proxy->set_param('per_page', max(1, min(100, (int) $request->get_param('per_page'))));

      $response = rest_do_request($proxy);
      if (is_wp_error($response)) {
        return $response;
      }
      if (!$response instanceof WP_REST_Response) {
        return new WP_Error('bodhi_mobile_proxy', 'Respuesta inesperada en proxy de cursos', ['status' => 500]);
      }

      $data = $response->get_data();
      $items = [];
      if (is_array($data)) {
        if (isset($data['items']) && is_array($data['items'])) {
          $items = $data['items'];
        } else {
          $items = $data;
        }
      }

      $normalized = [];
      foreach ($items as $item) {
        if (!is_array($item)) {
          continue;
        }

        $course = isset($item['course']) && is_array($item['course']) ? $item['course'] : $item;

        $status = '';
        if (isset($item['access_status'])) {
          $status = (string) $item['access_status'];
        } elseif (isset($item['access'])) {
          $status = (string) $item['access'];
        }

        $owned = !empty($item['is_owned'])
          || !empty($item['user_has_access'])
          || !empty($item['access_granted'])
          || in_array(strtolower($status), ['owned', 'member', 'free', 'owned_by_product', 'granted', 'enrolled'], true);

        $normalized[] = [
          'id'      => isset($course['id']) ? (int) $course['id'] : (isset($item['id']) ? (int) $item['id'] : 0),
          'title'   => isset($course['title']) ? (string) $course['title'] : (isset($course['name']) ? (string) $course['name'] : ''),
          'image'   => isset($course['thumb']) ? $course['thumb'] : (isset($course['thumbnail']) ? $course['thumbnail'] : (isset($course['image']) ? $course['image'] : null)),
          'percent' => isset($course['percent']) ? (int) $course['percent'] : (isset($course['progress']) ? (int) $course['progress'] : 0),
          'access'  => $status !== '' ? $status : ($owned ? 'owned' : 'locked'),
          'isOwned' => $owned,
        ];
      }

      $ownedItems = array_values(array_filter($normalized, function ($c) {
        return !empty($c['isOwned']);
      }));

      return rest_ensure_response([
        'items'      => $normalized,
        'itemsOwned' => $ownedItems,
        'total'      => count($normalized),
        'owned'      => count($ownedItems),
      ]);
    },
  ]);

});

