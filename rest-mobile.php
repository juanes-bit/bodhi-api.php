<?php
// Archivo: rest-mobile.php
if (!defined('ABSPATH')) { exit; }

add_action('rest_api_init', function () {
  $namespace = 'bodhi-mobile/v1';

  register_rest_route($namespace, '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function () {
      return ['nonce' => wp_create_nonce('wp_rest')];
    },
  ]);

  register_rest_route($namespace, '/my-courses', [
    'methods'  => 'GET',
    'permission_callback' => function () { return is_user_logged_in(); },
    'callback' => function () {
      $req = new WP_REST_Request('GET', '/bodhi/v1/courses');
      $req->set_param('mode', 'union');
      $req->set_param('per_page', 50);

      $resp = rest_do_request($req);
      if (is_wp_error($resp)) {
        return $resp;
      }

      $data = $resp instanceof WP_REST_Response ? $resp->get_data() : $resp;

      $source = [];
      if (is_array($data)) {
        if (isset($data['items']) && is_array($data['items'])) {
          $source = $data['items'];
        } else {
          $source = $data;
        }
      }

      $items = [];
      foreach ($source as $entry) {
        if (!is_array($entry)) {
          continue;
        }

        $course = isset($entry['course']) && is_array($entry['course']) ? $entry['course'] : [];

        $id = isset($entry['id']) ? (int) $entry['id'] : (isset($course['id']) ? (int) $course['id'] : 0);
        $title = isset($entry['title']) ? (string) $entry['title'] : (
          isset($course['name']) ? (string) $course['name'] : (
            isset($course['title']) ? (string) $course['title'] : ''
          )
        );
        $image = $entry['image'] ?? $course['thumb'] ?? $course['image'] ?? null;
        $percent = isset($entry['percent']) ? (int) $entry['percent'] : (
          isset($course['percent']) ? (int) $course['percent'] : 0
        );
        $status = $entry['access'] ?? $course['access'] ?? $course['access_status'] ?? 'locked';

        $flags = [
          !empty($entry['is_owned']),
          !empty($entry['owned']),
          !empty($entry['access_granted']),
          !empty($entry['user_has_access']),
          !empty($course['is_owned']),
          !empty($course['owned']),
          !empty($course['access_granted']),
          !empty($course['user_has_access']),
        ];

        $is_owned = in_array(true, $flags, true)
          || in_array(strtolower((string) $status), ['owned', 'member', 'free', 'owned_by_product'], true);

        $items[] = [
          'id'       => $id,
          'title'    => $title,
          'image'    => $image,
          'percent'  => $percent,
          'access'   => $is_owned ? 'owned' : 'locked',
          'is_owned' => $is_owned,
        ];
      }

      $itemsOwned = array_values(array_filter($items, function ($course) {
        return !empty($course['is_owned']);
      }));

      return [
        'items'      => array_values($items),
        'itemsOwned' => $itemsOwned,
        'total'      => count($items),
        'owned'      => count($itemsOwned),
      ];
    },
  ]);
});

