<?php
if (!defined('ABSPATH')) {
  exit;
}

add_action('rest_api_init', function () {
  register_rest_route('bodhi/v1', '/nonce', [
    'methods'  => 'GET',
    'permission_callback' => '__return_true',
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
      $user = wp_get_current_user();
      if (!$user || 0 === (int) $user->ID) {
        return new WP_Error('forbidden', 'No logueado', ['status' => 401]);
      }

      $posts = get_posts([
        'post_type'   => 'courses', // Ajusta según tu CPT real.
        'numberposts' => 20,
        'post_status' => 'publish',
      ]);

      $items = [];
      foreach ($posts as $post) {
        $owned = false;
        if (function_exists('bodhi_user_can_access_course')) {
          $owned = bodhi_user_can_access_course($user->ID, $post->ID);
        }

        $items[] = [
          'id'      => $post->ID,
          'title'   => get_the_title($post),
          'image'   => get_the_post_thumbnail_url($post, 'medium') ?: null,
          'percent' => (int) get_user_meta($user->ID, "course_{$post->ID}_progress", true),
          'is_owned'=> (bool) $owned,
          'access'  => $owned ? 'owned' : 'locked',
        ];
      }

      $owned_count = 0;
      foreach ($items as $item) {
        if (!empty($item['is_owned'])) {
          $owned_count++;
        }
      }

      return rest_ensure_response([
        'items' => $items,
        'total' => count($items),
        'owned' => $owned_count,
      ]);
    },
  ]);
});

